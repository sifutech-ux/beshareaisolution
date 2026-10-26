<?php
declare(strict_types=1);

// Shared, destructive fixture for the two new admin checks only. No private config is read.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/SqlScript.php';
require_once __DIR__ . '/../src/PlatformAdminSchema.php';
require_once __DIR__ . '/../src/PlatformAdminStore.php';
require_once __DIR__ . '/../src/PlatformAdminHttp.php';

use BesharOs\AuthException;
use BesharOs\Identifiers;
use BesharOs\Membership;
use BesharOs\PlatformAdminSchema;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;

const ADMIN_TEST_DATABASE = 'beshare_platform_admin_test';
const ADMIN_TEST_MARKER = 'beshare-platform-admin-synthetic-v1';
$adminChecks = 0;

function adminAssert(bool $condition, string $label): void
{
    global $adminChecks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $adminChecks++;
}

function adminDenied(callable $operation, int $status, string $label): void
{
    try { $operation(); }
    catch (AuthException $error) {
        adminAssert($error->status() === $status, $label . ' (HTTP status ' . $error->status() . ')');
        return;
    }
    throw new RuntimeException('FAIL: ' . $label . ' was allowed');
}

function adminTest(string $label, callable $operation): void
{
    $operation();
    fwrite(STDOUT, 'PASS ' . $label . "\n");
}

function adminSql(PDO $pdo, string $sql, array $values = []): PDOStatement
{
    $query = $pdo->prepare($sql);
    $query->execute($values);
    return $query;
}

/** Refuse all remote hosts, other schemas, missing credentials and connection-string injection. */
function adminTestConnection(): array
{
    $dsn = getenv('BESHARE_ADMIN_TEST_DSN');
    $user = getenv('BESHARE_ADMIN_TEST_USER');
    $password = getenv('BESHARE_ADMIN_TEST_PASSWORD');
    if (!is_string($dsn)
        || preg_match('/\Amysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=beshare_platform_admin_test(?:;charset=utf8mb4)?\z/D', $dsn, $match) !== 1
        || (int) $match[1] < 1 || (int) $match[1] > 65535
        || !is_string($user) || $user === '' || !is_string($password)) {
        throw new RuntimeException('Refused: explicit loopback beshare_platform_admin_test DSN and synthetic credentials required.');
    }
    return [$dsn, $user, $password, (int) $match[1]];
}

/**
 * Resets only an empty database or one marked as this exact synthetic fixture.
 * Run test files sequentially: every call recreates the dedicated test schema.
 *
 * @return array{pdo:PDO,seal:TokenSeal,schema:PlatformAdminSchema,admin:array,
 *   users:array,tenantA:array,tenantB:array}
 */
function adminFixture(bool $installAdmin = true): array
{
    [$dsn, $user, $password, $port] = adminTestConnection();
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
    $server = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $user, $password, $options);
    $exists = adminSql($server, 'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME=?', [ADMIN_TEST_DATABASE])->fetchColumn();
    if ($exists !== false) {
        $old = new PDO($dsn, $user, $password, $options);
        $tables = $old->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables !== []) {
            if (!in_array('admin_fixture_guard', $tables, true)
                || $old->query('SELECT purpose FROM admin_fixture_guard WHERE id=1')->fetchColumn() !== ADMIN_TEST_MARKER) {
                throw new RuntimeException('Refused: existing database is not an identified synthetic admin fixture.');
            }
        }
        $old = null;
        $server->exec('DROP DATABASE ' . ADMIN_TEST_DATABASE);
    }
    $server->exec('CREATE DATABASE ' . ADMIN_TEST_DATABASE . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn, $user, $password, $options);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== ADMIN_TEST_DATABASE) {
        throw new RuntimeException('Refused: fixture database identity changed.');
    }
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('CREATE TABLE admin_fixture_guard (id TINYINT UNSIGNED PRIMARY KEY,purpose VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    adminSql($pdo, 'INSERT INTO admin_fixture_guard VALUES (1,?)', [ADMIN_TEST_MARKER]);
    foreach (['0001_foundation', '0002_coexistence_sync', '0003_coexistence_worker', '0004_ai_replies', '0005_knowledge_centre'] as $migration) {
        $sql = file_get_contents(__DIR__ . '/../migrations/' . $migration . '.up.sql');
        if (!is_string($sql)) { throw new RuntimeException('Missing fixture migration: ' . $migration); }
        foreach (SqlScript::statements($sql) as $statement) { $pdo->exec($statement); }
        adminSql($pdo, 'INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,UTC_TIMESTAMP())',
            [substr($migration, 0, 4), $migration, hash('sha256', $sql)]);
    }

    $users = [];
    foreach (['ownerA', 'ownerB', 'platform', 'tenantAdmin', 'staff'] as $name) {
        adminSql($pdo, "INSERT INTO users (email,password_hash,name,status,created_at,updated_at)
            VALUES (?,?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [strtolower($name) . '@admin-fixture.invalid', 'synthetic-unused-password-hash', 'Fixture ' . $name]);
        $users[$name] = (int) $pdo->lastInsertId();
    }
    $tenants = [];
    foreach (['A', 'B'] as $suffix) {
        $owner = $users['owner' . $suffix];
        $publicId = Identifiers::publicId();
        adminSql($pdo, "INSERT INTO businesses (public_id,name,status,owner_user_id,created_at,updated_at)
            VALUES (?,?,'provisioning',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$publicId, 'Fixture Business ' . $suffix, $owner]);
        $businessId = (int) $pdo->lastInsertId();
        adminSql($pdo, "INSERT INTO business_users (business_id,user_id,role,created_at,updated_at)
            VALUES (?,?,'owner',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$businessId, $owner]);
        adminSql($pdo, "UPDATE businesses SET status='active' WHERE id=?", [$businessId]);
        $tenants['tenant' . $suffix] = ['business_id' => $businessId, 'public_id' => $publicId,
            'owner_id' => $owner, 'member' => Membership::require($pdo, $owner, $businessId)];
    }
    foreach (['tenantAdmin' => 'admin', 'staff' => 'staff'] as $key => $role) {
        adminSql($pdo, 'INSERT INTO business_users (business_id,user_id,role,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            [$tenants['tenantA']['business_id'], $users[$key], $role]);
    }

    $sql = file_get_contents(__DIR__ . '/../migrations/0006_platform_admin.up.sql');
    if (!is_string($sql)) { throw new RuntimeException('Missing platform admin fixture migration.'); }
    $schema = new PlatformAdminSchema($pdo, $sql);
    if ($installAdmin) {
        $schema->apply(hash('sha256', $sql));
        adminSql($pdo, "INSERT INTO platform_admins (user_id,role,status,created_at,updated_at)
            VALUES (?,'owner','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$users['platform']]);
    }
    return array_merge(['pdo' => $pdo, 'seal' => new TokenSeal(str_repeat('F', 32), 1), 'schema' => $schema,
        'admin' => ['user_id' => $users['platform'], 'name' => 'Fixture platform',
            'email' => 'platform@admin-fixture.invalid', 'platform_role' => 'owner'], 'users' => $users], $tenants);
}
