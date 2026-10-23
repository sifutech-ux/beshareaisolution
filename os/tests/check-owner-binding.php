<?php
declare(strict_types=1);

// Synthetic fixtures only. Never uses Db::connect(), private config, or a network transport.
require_once __DIR__ . '/../src/AuthException.php';
require_once __DIR__ . '/../src/Membership.php';
require_once __DIR__ . '/../src/TokenSeal.php';
require_once __DIR__ . '/../src/MetaGraph.php';
require_once __DIR__ . '/../src/OwnerConnectionImporter.php';
require_once __DIR__ . '/../src/SqlScript.php';
require_once __DIR__ . '/../bin/bind-owner-whatsapp.php';

use BesharOs\MetaGraph;
use BesharOs\MetaTransport;
use BesharOs\OwnerBindingException;
use BesharOs\OwnerConnectionImporter;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;

const OWNER_TEST_APP = '10000000001';
const OWNER_TEST_NOW = 1700000000;
$ownerTestChecks = 0;

function ownerCheck(bool $condition, string $label): void
{
    global $ownerTestChecks;
    if (!$condition) throw new RuntimeException($label);
    $ownerTestChecks++;
}

function ownerDenied(string $reason, Closure $action): void
{
    try {
        $action();
    } catch (OwnerBindingException $error) {
        ownerCheck($error->reason === $reason, 'Expected safe failure: ' . $reason);
        return;
    }
    throw new RuntimeException('Expected denial: ' . $reason);
}

function ownerTestDatabase(): PDO
{
    $dsn = getenv('BESHARE_OWNER_TEST_DSN');
    if (is_string($dsn) && $dsn !== '') {
        if (preg_match('/\Amysql:/', $dsn) !== 1 || preg_match('/(?:^|;)dbname=beshare_owner_binding_test(?:;|$)/', substr($dsn, 6)) !== 1) {
            throw new RuntimeException('Only the disposable beshare_owner_binding_test database is allowed');
        }
        $pdo = new PDO($dsn, (string) getenv('BESHARE_OWNER_TEST_USER'), (string) getenv('BESHARE_OWNER_TEST_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'beshare_owner_binding_test'
            || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
            throw new RuntimeException('Owner binding test database must be empty and disposable');
        }
        $pdo->exec("SET time_zone = '+00:00'");
        // Existing runtime migrations establish the fixture; only the new importer is tested.
        foreach (['0001_foundation', '0002_coexistence_sync', '0003_coexistence_worker'] as $migration) {
            $sql = file_get_contents(__DIR__ . '/../migrations/' . $migration . '.up.sql');
            foreach (SqlScript::statements((string) $sql) as $statement) $pdo->exec($statement);
        }
        return $pdo;
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
 name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE businesses (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'provisioning', owner_user_id INTEGER REFERENCES users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE business_users (business_id INTEGER REFERENCES businesses(id), user_id INTEGER REFERENCES users(id), role TEXT NOT NULL,
 created_at TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY(business_id,user_id));
CREATE TABLE whatsapp_connections (
 id INTEGER PRIMARY KEY AUTOINCREMENT, business_id INTEGER NOT NULL REFERENCES businesses(id), waba_id TEXT NOT NULL,
 phone_number_id TEXT NOT NULL UNIQUE, display_phone TEXT, token_state TEXT NOT NULL, webhook_state TEXT NOT NULL DEFAULT 'not_subscribed',
 registration_state TEXT NOT NULL DEFAULT 'unknown', messaging_state TEXT NOT NULL DEFAULT 'unverified', token_kind TEXT NOT NULL DEFAULT 'unknown',
 token_ciphertext BLOB, token_nonce BLOB, token_tag BLOB, token_key_version INTEGER, token_expires_at TEXT,
 refresh_supported INTEGER NOT NULL DEFAULT 0, last_verified_at TEXT, last_error_code TEXT, subscribed_at TEXT, disconnected_at TEXT,
 created_at TEXT NOT NULL, updated_at TEXT NOT NULL, sync_contacts TEXT NOT NULL DEFAULT 'not_applicable',
 sync_history TEXT NOT NULL DEFAULT 'not_applicable', onboarded_at TEXT, sync_deadline_at TEXT, callback_ready_at TEXT, fields_ready_at TEXT,
 CHECK ((token_state IN ('stored','verified') AND token_ciphertext IS NOT NULL AND token_nonce IS NOT NULL AND token_tag IS NOT NULL AND token_key_version IS NOT NULL)
 OR (token_state IN ('expired','revoked','error','cleared') AND token_ciphertext IS NULL AND token_nonce IS NULL AND token_tag IS NULL AND token_key_version IS NULL)),
 CHECK (messaging_state = 'unverified' OR token_state = 'verified'),
 CHECK (messaging_state NOT IN ('receive_verified','send_and_receive_verified') OR webhook_state = 'subscribed'));
CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, business_id INTEGER REFERENCES businesses(id), actor_user_id INTEGER REFERENCES users(id),
 actor_kind TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER, meta_json TEXT, created_at TEXT NOT NULL);
SQL);
    return $pdo;
}

final class OwnerFixtureTransport implements MetaTransport
{
    public array $calls = [];
    public array $debug;
    public array $owned;
    public array $phoneList;
    public array $apps;
    public array $pageOverrides = [];
    public ?Closure $afterRead = null;

    public function __construct(private array $assets)
    {
        $this->debug = ['data' => ['is_valid' => true, 'app_id' => OWNER_TEST_APP, 'type' => 'SYSTEM_USER', 'expires_at' => 0,
            'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging']]];
        $this->owned = ['data' => [['id' => $assets['waba_id']]]];
        $this->phoneList = ['data' => [['id' => $assets['phone_number_id'], 'display_phone_number' => '+60 (11) 5555-0018', 'status' => 'CONNECTED']]];
        $this->apps = ['data' => [['whatsapp_business_api_data' => ['id' => OWNER_TEST_APP]]]];
    }

    public function call(string $method, string $url, array $headers, string $body): array
    {
        $parts = parse_url($url);
        if ($method !== 'GET' || $body !== '' || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.facebook.com') {
            throw new RuntimeException('Fixture rejected non-read or external-host request');
        }
        $path = preg_replace('#\A/v[0-9]+\.[0-9]+/#', '', (string) ($parts['path'] ?? ''));
        parse_str((string) ($parts['query'] ?? ''), $query);
        $this->calls[] = ['method' => $method, 'path' => $path, 'after' => $query['after'] ?? null];
        $key = $path . ':' . ($query['after'] ?? '');
        if (array_key_exists($key, $this->pageOverrides)) {
            $json = $this->pageOverrides[$key];
        } else {
            $json = match ($path) {
                'debug_token' => $this->debug,
                $this->assets['portfolio_id'] . '/owned_whatsapp_business_accounts' => $this->owned,
                $this->assets['waba_id'] => ['id' => $this->assets['waba_id']],
                $this->assets['waba_id'] . '/phone_numbers' => $this->phoneList,
                $this->assets['waba_id'] . '/subscribed_apps' => $this->apps,
                default => throw new RuntimeException('Unexpected synthetic Graph path'),
            };
        }
        if ($this->afterRead !== null) ($this->afterRead)($path);
        return ['status' => 200, 'json' => $json];
    }
}

/** @return array<string,mixed> */
function ownerFixture(PDO $pdo): array
{
    static $sequence = 0;
    $sequence++;
    $stamp = gmdate('Y-m-d H:i:s', OWNER_TEST_NOW);
    $user = $pdo->prepare('INSERT INTO users (email,password_hash,name,status,created_at,updated_at) VALUES (?,?,?,?,?,?)');
    $user->execute(['owner' . $sequence . '@fixture.invalid', 'synthetic-not-a-password', 'Fixture owner', 'active', $stamp, $stamp]);
    $ownerId = (int) $pdo->lastInsertId();
    $business = $pdo->prepare('INSERT INTO businesses (public_id,name,status,owner_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?)');
    $business->execute([str_pad((string) $sequence, 26, '0', STR_PAD_LEFT), 'Fixture business', 'provisioning', $ownerId, $stamp, $stamp]);
    $businessId = (int) $pdo->lastInsertId();
    $member = $pdo->prepare('INSERT INTO business_users (business_id,user_id,role,created_at,updated_at) VALUES (?,?,?,?,?)');
    $member->execute([$businessId, $ownerId, 'owner', $stamp, $stamp]);
    $pdo->prepare('UPDATE businesses SET status = ? WHERE id = ?')->execute(['active', $businessId]);
    $user->execute(['staff' . $sequence . '@fixture.invalid', 'synthetic-not-a-password', 'Fixture staff', 'active', $stamp, $stamp]);
    $staffId = (int) $pdo->lastInsertId();
    $member->execute([$businessId, $staffId, 'staff', $stamp, $stamp]);
    $suffix = str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    $assets = ['portfolio_id' => '900000' . $suffix, 'waba_id' => '700000' . $suffix,
        'phone_number_id' => '800000' . $suffix, 'display_phone' => '+601155550018'];
    $transport = new OwnerFixtureTransport($assets);
    $seal = new TokenSeal(str_repeat('s', 32), 1);
    $importer = new OwnerConnectionImporter($pdo, new MetaGraph(OWNER_TEST_APP, 'synthetic-app-secret', 'v23.0', $transport), $seal, OWNER_TEST_APP, static fn (): int => OWNER_TEST_NOW);
    return ['pdo' => $pdo, 'actor' => ['user_id' => $ownerId, 'business_id' => $businessId], 'staff_id' => $staffId,
        'assets' => $assets, 'transport' => $transport, 'seal' => $seal, 'importer' => $importer,
        'token' => 'synthetic-owner-token-' . str_repeat('x', 40) . $suffix];
}

function ownerCount(array $fixture, string $table): int
{
    if (!in_array($table, ['whatsapp_connections', 'audit_logs'], true)) throw new RuntimeException('Invalid fixture table');
    $query = $fixture['pdo']->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE business_id = ?');
    $query->execute([$fixture['actor']['business_id']]);
    return (int) $query->fetchColumn();
}

function ownerRow(PDO $pdo, int $id): array
{
    $query = $pdo->prepare('SELECT * FROM whatsapp_connections WHERE id = ?');
    $query->execute([$id]);
    $row = $query->fetch();
    if (!is_array($row)) throw new RuntimeException('Missing fixture row');
    return $row;
}

function ownerExisting(array $fixture, ?string $phone = null, ?int $businessId = null, ?string $token = null): int
{
    $sealed = $fixture['seal']->seal($token ?? $fixture['token'], 1800);
    $values = [$businessId ?? $fixture['actor']['business_id'], $fixture['assets']['waba_id'], $phone ?? $fixture['assets']['phone_number_id'],
        $phone === null ? $fixture['assets']['display_phone'] : '+601122223333', 'verified', 'subscribed', 'registered', 'unverified', 'system_user',
        $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version'], gmdate('Y-m-d H:i:s', OWNER_TEST_NOW),
        gmdate('Y-m-d H:i:s', OWNER_TEST_NOW), gmdate('Y-m-d H:i:s', OWNER_TEST_NOW)];
    $insert = $fixture['pdo']->prepare('INSERT INTO whatsapp_connections (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,
        registration_state,messaging_state,token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,subscribed_at,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($values as $index => $value) $insert->bindValue($index + 1, $value, in_array($index, [9,10,11], true) ? PDO::PARAM_LOB : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
    $insert->execute();
    return (int) $fixture['pdo']->lastInsertId();
}

function ownerInspect(array $f, ?array $assets = null): array
{
    return $f['importer']->inspect($f['actor'], $f['token'], $assets ?? $f['assets']);
}

function ownerApply(array $f, array $proof, ?array $assets = null): array
{
    return $f['importer']->apply($f['actor'], $f['token'], $assets ?? $f['assets'], $proof['fingerprint']);
}

$pdo = ownerTestDatabase();
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

// Preflight never writes; a fresh import seals the token and claims no messaging proof.
$f = ownerFixture($pdo);
$proof = ownerInspect($f);
ownerCheck($proof['change'] === 'would_insert' && $proof['status'] === 'preflight', 'Preflight reports intended local change');
ownerCheck(ownerCount($f, 'whatsapp_connections') === 0 && ownerCount($f, 'audit_logs') === 0, 'Preflight has no writes');
ownerCheck(count($f['transport']->calls) === 5 && array_unique(array_column($f['transport']->calls, 'method')) === ['GET'], 'Only required Meta GETs');
ownerCheck(!str_contains(json_encode($proof, JSON_THROW_ON_ERROR), $f['token']), 'Receipt contains no credential');
$bound = ownerApply($f, $proof);
$row = ownerRow($pdo, $bound['connection_id']);
ownerCheck($bound['status'] === 'bound' && ownerCount($f, 'whatsapp_connections') === 1 && ownerCount($f, 'audit_logs') === 1, 'Single connection and audit');
ownerCheck($row['token_ciphertext'] !== $f['token'] && $f['seal']->open($row['token_ciphertext'], $row['token_nonce'], $row['token_tag'], (int) $row['token_key_version']) === $f['token'], 'Encrypted token round trip');
ownerCheck($row['messaging_state'] === 'unverified' && $row['callback_ready_at'] === null && $row['fields_ready_at'] === null, 'No fabricated send/receive/callback proof');
ownerCheck($row['sync_contacts'] === 'not_applicable' && $row['sync_history'] === 'not_applicable', 'Cloud API import does not enable coexistence');
ownerCheck($row['webhook_state'] === 'subscribed' && $row['registration_state'] === 'registered' && $row['display_phone'] === '+601155550018', 'Observed subscription and normalized phone');
$pdo->prepare('UPDATE whatsapp_connections SET messaging_state = ?, callback_ready_at = ?, fields_ready_at = ? WHERE id = ?')
    ->execute(['send_and_receive_verified', gmdate('Y-m-d H:i:s', OWNER_TEST_NOW), gmdate('Y-m-d H:i:s', OWNER_TEST_NOW), $bound['connection_id']]);
$before = ownerRow($pdo, $bound['connection_id']);
$again = ownerApply($f, $proof);
ownerCheck($again['status'] === 'already_bound' && ownerRow($pdo, $bound['connection_id']) === $before && ownerCount($f, 'audit_logs') === 1, 'Idempotent import preserves all proven state and audit count');

// Authorization comes from existing membership, never actor-supplied role.
$f = ownerFixture($pdo);
ownerDenied('owner_denied', fn () => $f['importer']->inspect(['user_id' => $f['staff_id'], 'business_id' => $f['actor']['business_id'], 'role' => 'owner'], $f['token'], $f['assets']));
ownerCheck($f['transport']->calls === [], 'Staff rejection precedes provider calls');
$pdo->prepare('UPDATE businesses SET status = ? WHERE id = ?')->execute(['suspended', $f['actor']['business_id']]);
ownerDenied('owner_denied', fn () => ownerInspect($f));
$f = ownerFixture($pdo);
$pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['disabled', $f['actor']['user_id']]);
ownerDenied('owner_denied', fn () => ownerInspect($f));

// The credential proof rejects app/type/scope/expiry ambiguity, including data expiry.
foreach ([
    ['app_id', '99999999999', 'token_app_invalid'], ['is_valid', false, 'token_app_invalid'],
    ['type', 'APP', 'token_type_invalid'], ['expires_at', OWNER_TEST_NOW, 'token_expired'],
    ['expires_at', null, 'token_expiry_unknown'], ['expires_at', 999999999999, 'token_expiry_unknown'],
    ['data_access_expires_at', OWNER_TEST_NOW - 1, 'token_expired'],
    ['scopes', ['whatsapp_business_management'], 'token_scopes_missing'],
] as [$key, $value, $reason]) {
    $f = ownerFixture($pdo);
    $f['transport']->debug['data'][$key] = $value;
    ownerDenied($reason, fn () => ownerInspect($f));
    ownerCheck(ownerCount($f, 'whatsapp_connections') === 0, 'Invalid proof cannot bind');
}
$f = ownerFixture($pdo);
$f['transport']->debug['data']['type'] = 'USER';
ownerDenied('user_token_expiry_required', fn () => ownerInspect($f));
$f['transport']->debug['data']['expires_at'] = OWNER_TEST_NOW + 10000;
$f['transport']->debug['data']['data_access_expires_at'] = OWNER_TEST_NOW + 5000;
$proof = ownerInspect($f);
$bound = ownerApply($f, $proof);
ownerCheck(ownerRow($pdo, $bound['connection_id'])['token_expires_at'] === gmdate('Y-m-d H:i:s', OWNER_TEST_NOW + 5000), 'Earliest effective expiry is stored');
$f = ownerFixture($pdo);
$f['transport']->debug['data']['granular_scopes'] = [['scope' => 'whatsapp_business_messaging', 'target_ids' => ['77777777777']]];
ownerDenied('token_waba_scope_mismatch', fn () => ownerInspect($f));
$f['transport']->debug['data']['granular_scopes'][0]['target_ids'] = [['malformed']];
ownerDenied('token_waba_scope_mismatch', fn () => ownerInspect($f));

// The actual owned WABA and exact phone/display must match the requested internal mapping.
$f = ownerFixture($pdo);
$f['transport']->owned = ['data' => []];
ownerDenied('portfolio_waba_mismatch', fn () => ownerInspect($f));
$f = ownerFixture($pdo);
$f['transport']->phoneList['data'][0]['id'] = '89999999999';
ownerDenied('phone_waba_mismatch', fn () => ownerInspect($f));
$f = ownerFixture($pdo);
$f['transport']->phoneList['data'][0]['display_phone_number'] = '+601155550019';
ownerDenied('display_phone_mismatch', fn () => ownerInspect($f));
$f = ownerFixture($pdo);
$f['transport']->apps = ['data' => []];
$f['transport']->phoneList['data'][0]['status'] = 'NEW_UNKNOWN_STATUS';
$bound = ownerApply($f, ownerInspect($f));
$row = ownerRow($pdo, $bound['connection_id']);
ownerCheck($row['webhook_state'] === 'not_subscribed' && $row['subscribed_at'] === null && $row['registration_state'] === 'unknown', 'Unproven subscription or registration stays unproven');
ownerCheck($bound['meta_mutations'] === false && $bound['message_sent'] === false && $bound['auto_reply_enabled_by_import'] === false, 'Binding is not activation or messaging');

// Fixed-edge bounded pagination accepts a later match without following supplied URLs.
$f = ownerFixture($pdo);
$path = $f['assets']['portfolio_id'] . '/owned_whatsapp_business_accounts';
$f['transport']->pageOverrides[$path . ':'] = ['data' => [['id' => '79999999999']], 'paging' => ['next' => 'https://attacker.invalid/do-not-follow', 'cursors' => ['after' => 'page2']]];
$f['transport']->pageOverrides[$path . ':page2'] = $f['transport']->owned;
ownerCheck(ownerInspect($f)['observed_app_subscribed'] === true && count($f['transport']->calls) === 6, 'Later-page ownership is verified on fixed Graph path');
$f['transport']->pageOverrides[$path . ':page2'] = ['data' => [], 'paging' => ['next' => 'https://attacker.invalid/do-not-follow', 'cursors' => ['after' => 'page2']]];
ownerDenied('meta_ownership_read_failed', fn () => ownerInspect($f));
$f = ownerFixture($pdo);
$f['transport']->phoneList['data'][] = $f['transport']->phoneList['data'][0];
ownerDenied('phone_waba_mismatch', fn () => ownerInspect($f));

// Stale preflight, unrelated tenant bindings, and existing credentials fail closed.
$f = ownerFixture($pdo);
$proof = ownerInspect($f);
$f['transport']->phoneList['data'][0]['status'] = 'PENDING';
ownerDenied('preflight_changed', fn () => ownerApply($f, $proof));
ownerDenied('preflight_fingerprint_required', fn () => $f['importer']->apply($f['actor'], $f['token'], $f['assets'], 'invalid'));
ownerCheck(ownerCount($f, 'whatsapp_connections') === 0 && ownerCount($f, 'audit_logs') === 0, 'Stale preflight makes no writes');
$f = ownerFixture($pdo);
$foreign = ownerFixture($pdo);
ownerExisting($f, null, $foreign['actor']['business_id']);
ownerDenied('phone_already_bound_elsewhere', fn () => ownerInspect($f));
$f = ownerFixture($pdo);
ownerExisting($f, null, null, 'different-synthetic-credential-needs-explicit-rotation');
ownerDenied('existing_token_requires_rotation', fn () => ownerInspect($f));
$f = ownerFixture($pdo);
$oldId = ownerExisting($f);
$pdo->prepare('UPDATE whatsapp_connections SET disconnected_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', OWNER_TEST_NOW), $oldId]);
ownerDenied('existing_connection_requires_recovery', fn () => ownerInspect($f));

// Replacement is explicit, tenant-scoped, preflight-bound, atomic, and idempotent.
$f = ownerFixture($pdo);
$oldId = ownerExisting($f, '60000000001');
ownerDenied('business_has_other_active_phone', fn () => ownerInspect($f));
$replace = $f['assets'] + ['replace_connection_id' => $oldId];
$proof = ownerInspect($f, $replace);
ownerCheck($proof['replace_connection']['id'] === $oldId && $proof['replace_connection']['phone_number_id'] === '60000000001', 'Preflight exposes exactly the old mapping being replaced');
ownerCheck(ownerRow($pdo, $oldId)['disconnected_at'] === null && ownerCount($f, 'audit_logs') === 0, 'Replacement preflight is read-only');
$pdo->prepare('UPDATE whatsapp_connections SET messaging_state = ? WHERE id = ?')->execute(['send_verified', $oldId]);
ownerDenied('preflight_changed', fn () => ownerApply($f, $proof, $replace));
$proof = ownerInspect($f, $replace);
$bound = ownerApply($f, $proof, $replace);
$old = ownerRow($pdo, $oldId);
ownerCheck($old['disconnected_at'] !== null && $old['token_state'] === 'cleared' && $old['token_ciphertext'] === null
    && $old['token_nonce'] === null && $old['token_tag'] === null && $old['token_key_version'] === null && $old['messaging_state'] === 'unverified', 'Only reviewed old local credential is cleared');
ownerCheck($old['webhook_state'] === 'subscribed' && ownerRow($pdo, $bound['connection_id'])['disconnected_at'] === null, 'No fictitious Meta unsubscribe; new row remains active');
$again = ownerApply($f, $proof, $replace);
ownerCheck($again['status'] === 'already_bound' && $again['fingerprint'] === $proof['fingerprint'] && ownerCount($f, 'audit_logs') === 1, 'Replacement retries are idempotent, including MySQL JSON ordering');
$f = ownerFixture($pdo);
$foreign = ownerFixture($pdo);
$foreignId = ownerExisting($foreign);
$replace = $f['assets'] + ['replace_connection_id' => $foreignId];
ownerDenied('replacement_not_same_tenant_other_phone', fn () => ownerInspect($f, $replace));
ownerCheck(ownerRow($pdo, $foreignId)['disconnected_at'] === null, 'Another tenant cannot be disconnected');

// Recheck authorization and the replacement snapshot after GETs and under write locks.
$f = ownerFixture($pdo);
$proof = ownerInspect($f);
$f['transport']->afterRead = function (string $path) use ($f, $pdo): void {
    if (str_ends_with($path, '/subscribed_apps')) $pdo->prepare('UPDATE businesses SET status = ? WHERE id = ?')->execute(['suspended', $f['actor']['business_id']]);
};
ownerDenied('owner_denied', fn () => ownerApply($f, $proof));
ownerCheck(ownerCount($f, 'whatsapp_connections') === 0, 'Owner revoked during preflight cannot bind');
$f = ownerFixture($pdo);
$oldId = ownerExisting($f, '60000000002');
$replace = $f['assets'] + ['replace_connection_id' => $oldId];
$proof = ownerInspect($f, $replace);
$f['transport']->afterRead = function (string $path) use ($oldId, $pdo): void {
    if (str_ends_with($path, '/subscribed_apps')) $pdo->prepare('UPDATE whatsapp_connections SET messaging_state = ? WHERE id = ?')->execute(['send_verified', $oldId]);
};
ownerDenied('replacement_changed', fn () => ownerApply($f, $proof, $replace));
ownerCheck(ownerRow($pdo, $oldId)['disconnected_at'] === null && ownerCount($f, 'whatsapp_connections') === 1, 'Changed old connection is preserved');

// A local audit failure rolls back both the replacement and new token storage.
$f = ownerFixture($pdo);
$oldId = ownerExisting($f, '60000000003');
$replace = $f['assets'] + ['replace_connection_id' => $oldId];
$proof = ownerInspect($f, $replace);
$before = ownerRow($pdo, $oldId);
$businessId = $f['actor']['business_id'];
if ($driver === 'mysql') {
    $pdo->exec("CREATE TRIGGER owner_fixture_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.business_id = " . $businessId . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic_audit_failure'; END IF; END");
} else {
    $pdo->exec("CREATE TRIGGER owner_fixture_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.business_id = " . $businessId . " BEGIN SELECT RAISE(ABORT, 'synthetic_audit_failure'); END");
}
ownerDenied('binding_write_failed', fn () => ownerApply($f, $proof, $replace));
ownerCheck(ownerRow($pdo, $oldId) === $before && ownerCount($f, 'whatsapp_connections') === 1 && ownerCount($f, 'audit_logs') === 0, 'Failed audit rolls back replacement and insertion');
$pdo->exec('DROP TRIGGER owner_fixture_audit_failure');

// Credential-file helpers receive only synthetic private files, never production paths.
$f = ownerFixture($pdo);
ownerCheck(ownerBindingToken(['owner_whatsapp_access_token' => $f['token']], $f['assets']) === $f['token'], 'Configured private token source');
ownerDenied('credential_source_ambiguous', fn () => ownerBindingToken(['owner_whatsapp_access_token' => $f['token'], 'owner_whatsapp_legacy_token_path' => '/synthetic/path'], $f['assets']));
ownerDenied('configured_target_mismatch', fn () => ownerBindingSetting(['phone' => '88888888888'], ['owner_whatsapp_phone_number_id' => $f['assets']['phone_number_id']], 'phone', 'phone_number_id'));
ownerDenied('local_identity_invalid', fn () => ownerBindingId('01'));
$temporary = sys_get_temp_dir() . '/beshare-owner-fixture-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Private credential fixture directory unavailable');
$legacyFile = $temporary . '/synthetic.json';
$legacyLink = $temporary . '/symlink.json';
try {
    file_put_contents($legacyFile, json_encode(['phone_number_id' => $f['assets']['phone_number_id'], 'paparan' => $f['assets']['display_phone'], 'token_meta' => $f['token']], JSON_THROW_ON_ERROR));
    chmod($legacyFile, 0600);
    $config = ['owner_whatsapp_legacy_token_path' => $legacyFile];
    ownerCheck(ownerBindingToken($config, $f['assets']) === $f['token'], 'Protected canonical owner credential JSON accepted');
    chmod($legacyFile, 0644);
    clearstatcache(true, $legacyFile);
    ownerDenied('legacy_token_file_unprotected', fn () => ownerBindingToken($config, $f['assets']));
    chmod($legacyFile, 0600);
    clearstatcache(true, $legacyFile);
    symlink($legacyFile, $legacyLink);
    ownerDenied('legacy_token_path_denied', fn () => ownerBindingToken(['owner_whatsapp_legacy_token_path' => $legacyLink], $f['assets']));
    ownerDenied('legacy_token_path_denied', fn () => ownerBindingToken(['owner_whatsapp_legacy_token_path' => realpath(__FILE__)], $f['assets']));
    $wrongAssets = $f['assets'];
    $wrongAssets['phone_number_id'] = '88888888888';
    ownerDenied('legacy_token_target_mismatch', fn () => ownerBindingToken($config, $wrongAssets));
} finally {
    if (is_link($legacyLink)) unlink($legacyLink);
    if (is_file($legacyFile)) unlink($legacyFile);
    rmdir($temporary);
}

// CLI parsing rejects accidental credential arguments before any config or connection.
$command = [PHP_BINARY, __DIR__ . '/../bin/bind-owner-whatsapp.php', '--token=synthetic-credential-do-not-print'];
$pipes = [];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('CLI fixture unavailable');
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
ownerCheck($exit === 1 && $stderr === '' && !str_contains($stdout, 'synthetic-credential')
    && (json_decode($stdout, true)['reason'] ?? '') === 'arguments_invalid', 'CLI rejects and redacts token arguments before loading configuration');

fwrite(STDOUT, 'owner binding: ' . $ownerTestChecks . ' focused assertions passed (' . $driver . ", synthetic GET transport; no production calls)\n");
