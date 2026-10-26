<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-fixture.php';
require_once __DIR__ . '/../src/AiProductionSchema.php';

use BesharOs\AiProductionSchema;
use BesharOs\AiReplyStore;

$fixture = adminFixture();
$pdo = $fixture['pdo'];
$tenant = $fixture['tenantA'];
$sql = (string) file_get_contents(__DIR__ . '/../migrations/0007_customer_ai.up.sql');
$schema = new AiProductionSchema($pdo, $sql);
$token = $fixture['seal']->seal('synthetic-schema-token', 2048);

adminSql($pdo, "INSERT INTO whatsapp_connections
    (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,registration_state,messaging_state,
     token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,created_at,updated_at)
    VALUES (?,'7000000000099','8000000000099','+15550000099','verified','subscribed','registered','unverified',
      'system_user',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
    [$tenant['business_id'], $token['ciphertext'], $token['nonce'], $token['tag'], $token['version']]);
$connectionId = (int) $pdo->lastInsertId();
$config = ['owner_ai_business_id' => (string)$tenant['business_id'], 'owner_ai_enabled' => 'true',
    'gemini_api_key' => 'synthetic-no-provider-key', 'gemini_model' => 'synthetic-model'];
(new AiReplyStore($pdo, $fixture['seal'], $config))->save($tenant['member'], [
    'connection_id' => $connectionId, 'expected_revision' => 0, 'daily_limit' => 37,
    'enabled' => true, 'knowledge' => 'Synthetic approved legacy business facts.',
    'allowlist' => ['15550000111'], 'approved' => true,
]);
adminSql($pdo, 'UPDATE ai_reply_settings SET quota_date=UTC_DATE(),quota_used=9 WHERE business_id=?', [$tenant['business_id']]);
$before = $pdo->query('SELECT * FROM ai_reply_settings')->fetch();

adminTest('Migration is explicit and preserves the approved pilot settings', function () use ($pdo, $schema, $sql, $before): void {
    adminAssert(!AiProductionSchema::available($pdo), 'Production unavailable before migration');
    adminAssert($schema->inspect()['status'] === 'ready', 'Existing foundation is ready');
    adminDenied(fn() => $schema->apply(str_repeat('0', 64)), 409, 'Wrong checksum is refused before DDL');
    adminAssert($schema->inspect()['status'] === 'ready', 'Rejected checksum leaves no partial install');
    $installed = $schema->apply(hash('sha256', $sql));
    adminAssert($installed['status'] === 'installed' && AiProductionSchema::available($pdo), 'Explicit installation is verified');
    $after = $pdo->query('SELECT * FROM ai_reply_settings')->fetch();
    adminAssert(array_intersect_key($after, $before) === $before, 'Every original setting, ciphertext, approval, timestamp and quota is preserved');
    adminAssert($after['audience_mode'] === 'test' && $after['audience_approved_by'] === null && $after['audience_approved_at'] === null, 'Existing account keeps test audience without broader consent');
    adminAssert($schema->apply(hash('sha256', $sql)) === $installed, 'Repeat apply is idempotent');
    adminAssert((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='0007'")->fetchColumn() === 1, 'Exactly one migration ledger entry');
    adminAssert((int)$pdo->query("SELECT last_business_id FROM ai_reply_dispatch_state WHERE name='all'")->fetchColumn() === 0, 'Fair dispatcher starts with explicit zero cursor');
});

adminTest('Database rejects invalid audience and missing customer consent', function () use ($pdo): void {
    foreach ([
        "UPDATE ai_reply_settings SET audience_mode='invalid'",
        "UPDATE ai_reply_settings SET audience_mode='all'",
        "UPDATE ai_reply_settings SET audience_mode='all',audience_approved_by=999999999,audience_approved_at=UTC_TIMESTAMP()",
    ] as $statement) {
        $rejected = false;
        try { $pdo->exec($statement); } catch (PDOException) { $rejected = true; }
        adminAssert($rejected, 'Invalid audience write is rejected by database');
    }
    adminAssert($pdo->query('SELECT audience_mode FROM ai_reply_settings')->fetchColumn() === 'test', 'Rejected writes preserve original audience');
});

adminTest('Drift and partial installation fail closed without repair or data loss', function () use ($pdo, $schema, $sql, $before): void {
    adminDenied(fn() => (new AiProductionSchema($pdo, $sql . "\n-- changed bytes"))->inspect(), 409, 'Changed migration bytes are refused');
    $pdo->exec("DELETE FROM ai_reply_dispatch_state WHERE name='all'");
    adminAssert(!AiProductionSchema::available($pdo), 'Missing dispatcher state fails closed');
    $pdo->exec("INSERT INTO ai_reply_dispatch_state VALUES ('all',0,UTC_TIMESTAMP())");
    adminAssert(AiProductionSchema::available($pdo), 'Exact restored fixture state is readable');
    $pdo->exec("DELETE FROM schema_migrations WHERE version='0007'");
    adminDenied(fn() => $schema->inspect(), 409, 'Unrecorded partial DDL is refused');
    adminDenied(fn() => $schema->apply(hash('sha256', $sql)), 409, 'Apply never resets partial tables or approvals');
    adminAssert(array_intersect_key($pdo->query('SELECT * FROM ai_reply_settings')->fetch(), $before) === $before, 'Rejected repair preserves every existing setting');
});

fwrite(STDOUT, 'Customer AI schema: ' . $adminChecks . " assertions passed.\n");
