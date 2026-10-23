<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/AuthException.php';
require_once __DIR__ . '/../src/SqlScript.php';
require_once __DIR__ . '/../src/Membership.php';
require_once __DIR__ . '/../src/Accounts.php';
require_once __DIR__ . '/../src/TokenSeal.php';
require_once __DIR__ . '/../src/AiReplySchema.php';
require_once __DIR__ . '/../src/AiReplyStore.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/RateLimit.php';
require_once __DIR__ . '/../src/Tenants.php';
require_once __DIR__ . '/../src/AuthHttp.php';

use BesharOs\Accounts;
use BesharOs\AiReplySchema;
use BesharOs\AiReplyStore;
use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\RateLimit;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;

// This test destroys only its explicitly named, loopback-only disposable schema.
// It never loads production configuration or calls Meta, Gemini, or a webhook.
const AI_SETTINGS_TEST_DATABASE = 'beshare_ai_settings_test';

function aiSettingsCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
}

function aiSettingsDenied(callable $operation, array $statuses, string $label): void
{
    try {
        $operation();
    } catch (AuthException $error) {
        aiSettingsCheck(in_array($error->status(), $statuses, true), $label . ': unexpected denial status');
        return;
    }
    throw new RuntimeException($label . ': operation was allowed');
}

/** @param array<string,mixed> $values */
function aiSettingsInsert(PDO $pdo, string $table, array $values): void
{
    aiSettingsCheck(in_array($table, ['users', 'businesses', 'business_users', 'whatsapp_connections', 'ai_conversations', 'ai_messages'], true), 'Fixture table allowlist');
    $columns = array_keys($values);
    foreach ($columns as $column) {
        aiSettingsCheck(preg_match('/\A[a-z_]+\z/', $column) === 1, 'Fixture column');
    }
    $statement = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach (array_values($values) as $index => $value) {
        $type = $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        if (preg_match('/_(?:ciphertext|nonce|tag)\z/', $columns[$index]) === 1 && $value !== null) { $type = PDO::PARAM_LOB; }
        $statement->bindValue($index + 1, $value, $type);
    }
    $statement->execute();
}

function aiSettingsTableCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ai_reply_settings','ai_conversations','ai_messages','ai_reply_jobs')")->fetchColumn();
}

/** Session is copied per request; AuthHttp still receives its real by-reference contract. */
function aiSettingsHttp(AuthHttp $http, string $method, string $action, array $session, array $body, array $headers): array
{
    return $http->handle($method, $action, $session, $body, $headers, '127.0.0.1', 'owner-settings.example.invalid');
}

function aiSettingsPublicResponse(array $response): void
{
    aiSettingsCheck($response['status'] === 200 && $response['body']['ok'] === true, 'AI HTTP action succeeds');
    $json = json_encode($response['body'], JSON_THROW_ON_ERROR);
    foreach (['synthetic_google_settings_key_123', 'synthetic_settings_fixture_token_123',
        'gemini_api_key', 'meta_app_secret', 'owner_whatsapp_access_token',
        'token_ciphertext', 'token_nonce', 'token_tag', 'token_key_version',
        'knowledge_ciphertext', 'knowledge_nonce', 'knowledge_tag', 'knowledge_key_version'] as $secret) {
        aiSettingsCheck(!str_contains($json, $secret), 'AI HTTP response does not expose credentials or sealed fields');
    }
}

$dsn = getenv('BESHARE_AI_SETTINGS_TEST_DSN');
$user = getenv('BESHARE_AI_TEST_USER');
$password = getenv('BESHARE_AI_TEST_PASSWORD');
if (!is_string($dsn) || preg_match('/\Amysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=beshare_ai_settings_test(?:;charset=utf8mb4)?\z/', $dsn, $match) !== 1
    || (int) $match[1] < 1 || (int) $match[1] > 65535 || !is_string($user) || $user === '' || !is_string($password)) {
    throw new RuntimeException('Explicit loopback beshare_ai_settings_test DSN and synthetic test credentials required');
}
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
];
$admin = new PDO('mysql:host=127.0.0.1;port=' . $match[1] . ';charset=utf8mb4', $user, $password, $options);
$admin->exec('DROP DATABASE IF EXISTS ' . AI_SETTINGS_TEST_DATABASE);
$admin->exec('CREATE DATABASE ' . AI_SETTINGS_TEST_DATABASE . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = null;
$rateDirectory = null;

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->exec("SET time_zone='+00:00'");
    aiSettingsCheck($pdo->query('SELECT DATABASE()')->fetchColumn() === AI_SETTINGS_TEST_DATABASE, 'Disposable database identity');
    $sql = file_get_contents(__DIR__ . '/../migrations/0004_ai_replies.up.sql');
    aiSettingsCheck(is_string($sql), 'AI migration exists');
    $schema = new AiReplySchema($pdo, $sql);
    aiSettingsDenied(fn () => $schema->inspect(), [409], 'Foundation missing blocks AI install');
    aiSettingsCheck(aiSettingsTableCount($pdo) === 0, 'Missing foundation creates no AI tables');

    foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql'] as $filename) {
        $foundation = file_get_contents(__DIR__ . '/../migrations/' . $filename);
        aiSettingsCheck(is_string($foundation), 'Foundation migration exists');
        foreach (SqlScript::statements($foundation) as $statement) { $pdo->exec($statement); }
        $record = $pdo->prepare('INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,UTC_TIMESTAMP())');
        $record->execute([substr($filename, 0, 4), $filename, hash('sha256', $foundation)]);
    }

    $now = gmdate('Y-m-d H:i:s');
    $seal = new TokenSeal(str_repeat('S', 32), 1);
    for ($id = 1; $id <= 4; $id++) {
        aiSettingsInsert($pdo, 'users', [
            'id' => $id, 'email' => 'settings-fixture-' . $id . '@example.invalid',
            'password_hash' => 'synthetic-unused-password-hash', 'name' => 'Fixture ' . $id,
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    foreach ([1 => 1, 2 => 3] as $businessId => $ownerId) {
        aiSettingsInsert($pdo, 'businesses', [
            'id' => $businessId, 'public_id' => str_pad((string) $businessId, 26, '0', STR_PAD_LEFT),
            'name' => 'Fixture business ' . $businessId, 'status' => 'provisioning', 'owner_user_id' => $ownerId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        aiSettingsInsert($pdo, 'business_users', [
            'business_id' => $businessId, 'user_id' => $ownerId, 'role' => 'owner',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $activate = $pdo->prepare("UPDATE businesses SET status='active' WHERE id=?");
        $activate->execute([$businessId]);
    }
    foreach ([2 => 'staff', 4 => 'admin'] as $userId => $role) {
        aiSettingsInsert($pdo, 'business_users', ['business_id' => 1, 'user_id' => $userId, 'role' => $role, 'created_at' => $now, 'updated_at' => $now]);
    }
    $token = $seal->seal('synthetic_settings_fixture_token_123', 1800);
    foreach ([1, 2] as $businessId) {
        aiSettingsInsert($pdo, 'whatsapp_connections', [
            'id' => $businessId, 'business_id' => $businessId, 'waba_id' => '91000000000000' . $businessId,
            'phone_number_id' => '92000000000000' . $businessId, 'display_phone' => '+60110000000' . $businessId,
            'token_state' => 'verified', 'webhook_state' => 'subscribed', 'registration_state' => 'registered',
            'messaging_state' => 'unverified', 'token_kind' => 'system_user', 'token_ciphertext' => $token['ciphertext'],
            'token_nonce' => $token['nonce'], 'token_tag' => $token['tag'], 'token_key_version' => $token['version'],
            'subscribed_at' => $now, 'callback_ready_at' => $now, 'fields_ready_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $foundationToken = $pdo->query('SELECT HEX(token_ciphertext) FROM whatsapp_connections WHERE id=1')->fetchColumn();
    $ready = $schema->inspect();
    aiSettingsCheck($ready['status'] === 'ready' && $ready['checksum'] === hash('sha256', $sql), 'Schema inspect returns content checksum');
    aiSettingsDenied(fn () => $schema->apply(str_repeat('0', 64)), [409], 'Wrong preflight checksum blocks DDL');
    aiSettingsCheck(aiSettingsTableCount($pdo) === 0, 'Wrong checksum creates no AI tables');

    $pdo->exec('CREATE TABLE ai_reply_settings (id INT PRIMARY KEY,marker VARCHAR(40))');
    $pdo->exec("INSERT INTO ai_reply_settings VALUES (1,'partial-data-must-survive')");
    aiSettingsDenied(fn () => $schema->inspect(), [409], 'Partial install blocks inspect');
    aiSettingsDenied(fn () => $schema->apply($ready['checksum']), [409], 'Partial install blocks apply');
    aiSettingsCheck($pdo->query('SELECT marker FROM ai_reply_settings WHERE id=1')->fetchColumn() === 'partial-data-must-survive'
        && aiSettingsTableCount($pdo) === 1, 'Partial install is preserved without auto cleanup');
    // Explicit fixture cleanup only; production schema runner performs no DROP.
    $pdo->exec('DROP TABLE ai_reply_settings');
    $installed = $schema->apply($ready['checksum']);
    aiSettingsCheck($installed['status'] === 'installed' && aiSettingsTableCount($pdo) === 4, 'Four additive AI tables installed');
    aiSettingsCheck($schema->apply($ready['checksum']) === $installed, 'Repeated apply is idempotent');
    aiSettingsCheck((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='0004'")->fetchColumn() === 1,
        'Idempotent migration record');
    aiSettingsCheck((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4
        && $pdo->query('SELECT HEX(token_ciphertext) FROM whatsapp_connections WHERE id=1')->fetchColumn() === $foundationToken,
        'AI install preserves foundation rows and sealed token');
    $changedSchema = new AiReplySchema($pdo, $sql . "\n-- changed migration fixture\n");
    aiSettingsDenied(fn () => $changedSchema->inspect(), [409], 'Installed checksum drift blocks inspection');
    aiSettingsCheck(aiSettingsTableCount($pdo) === 4, 'Checksum drift does not alter tables');

    $config = ['owner_ai_business_id' => '1', 'owner_ai_enabled' => 'true',
        'gemini_api_key' => 'synthetic_google_settings_key_123', 'gemini_model' => 'gemini-2.5-flash'];
    $store = new AiReplyStore($pdo, $seal, $config);
    $owner = ['user_id' => 1, 'business_id' => 1];
    $staff = ['user_id' => 2, 'business_id' => 1];
    $adminActor = ['user_id' => 4, 'business_id' => 1];
    $foreignOwner = ['user_id' => 3, 'business_id' => 2];
    $forgedOwner = $staff + ['role' => 'owner', 'owner_user_id' => 2, 'status' => 'active'];
    $pdo->exec("UPDATE schema_migrations SET checksum='" . str_repeat('0', 64) . "' WHERE version='0004'");
    aiSettingsCheck(!(new AiReplyStore($pdo, $seal, $config))->view($owner)['available'],
        'AI availability requires the installed migration checksum');
    $restoreLedger = $pdo->prepare("UPDATE schema_migrations SET checksum=? WHERE version='0004'");
    $restoreLedger->execute([$ready['checksum']]);
    $knowledge = 'SYNTHETIC_PRIVATE_KNOWLEDGE: Shop hours 09:00-17:00. Ask a human for bookings.';
    $input = static fn (int $revision, array $patch = []): array => array_replace([
        'connection_id' => 1, 'knowledge' => $knowledge, 'approved' => true,
        'allowlist' => ['601200000001'], 'daily_limit' => 20, 'enabled' => true, 'expected_revision' => $revision,
    ], $patch);
    $empty = $store->view($owner);
    aiSettingsCheck($empty['available'] && $empty['can_manage'] && !$empty['enabled'] && $empty['revision'] === 0, 'New owner settings start disabled');
    aiSettingsCheck(!$store->view($forgedOwner)['can_manage'], 'Stored role overrides forged owner role');
    aiSettingsCheck(!$store->view($foreignOwner)['available'], 'Owner pilot is not exposed to another tenant');
    aiSettingsDenied(fn () => $store->save($forgedOwner, $input(0)), [403], 'Forged staff owner cannot save');
    aiSettingsDenied(fn () => $store->save($adminActor, $input(0)), [403], 'Admin cannot change owner settings');
    aiSettingsDenied(fn () => $store->save($foreignOwner, $input(0)), [409], 'Cross-tenant owner cannot enable pilot');
    aiSettingsDenied(fn () => $store->view(['user_id' => 3, 'business_id' => 1, 'role' => 'owner']), [401], 'Non-member cannot read owner settings');
    aiSettingsDenied(fn () => $store->save($owner, $input(0, ['connection_id' => 2])), [404], 'Cross-tenant connection ID denied');

    foreach ([
        ['approved' => false], ['approved' => 'true'], ['allowlist' => []], ['allowlist' => ['*']],
        ['allowlist' => [601200000001]], ['allowlist' => array_fill(0, 11, '601200000001')],
        ['allowlist' => ['601100000001']], ['daily_limit' => 0], ['daily_limit' => 1001],
        ['daily_limit' => '20'], ['expected_revision' => '0'], ['enabled' => 'true'],
        ['knowledge' => '  '], ['knowledge' => str_repeat('x', 8001)], ['knowledge' => "invalid\xFF"],
    ] as $patch) {
        aiSettingsDenied(fn () => $store->save($owner, $input(0, $patch)), [400], 'Unapproved or invalid settings denied');
    }
    aiSettingsCheck((int) $pdo->query('SELECT COUNT(*) FROM ai_reply_settings')->fetchColumn() === 0, 'Rejected settings do not create a row');
    $pdo->exec("UPDATE whatsapp_connections SET registration_state='unknown' WHERE id=1");
    aiSettingsDenied(fn () => $store->save($owner, $input(0)), [409], 'Unready registration cannot enable AI');
    $pdo->exec("UPDATE whatsapp_connections SET registration_state='registered',token_expires_at=UTC_TIMESTAMP()-INTERVAL 1 DAY WHERE id=1");
    aiSettingsDenied(fn () => $store->save($owner, $input(0)), [409], 'Expired connection cannot enable AI');
    $pdo->exec('UPDATE whatsapp_connections SET token_expires_at=NULL WHERE id=1');
    $offStore = new AiReplyStore($pdo, $seal, array_replace($config, ['owner_ai_enabled' => 'false']));
    aiSettingsDenied(fn () => $offStore->save($owner, $input(0)), [409], 'Server switch blocks activation');

    $saved = $store->save($owner, $input(0, ['allowlist' => ['+601200000002', '601200000001', '601200000001']]));
    aiSettingsCheck($saved['enabled'] && $saved['revision'] === 1 && $saved['knowledge'] === $knowledge
        && $saved['allowlist'] === ['601200000001', '601200000002'], 'Approved owner settings persist with canonical allowlist');
    $saveAudit = $pdo->query("SELECT actor_user_id,entity_id,meta_json FROM audit_logs WHERE action='ai_tetapan_disimpan' ORDER BY id DESC LIMIT 1")->fetch();
    aiSettingsCheck((int) $saveAudit['actor_user_id'] === 1 && (int) $saveAudit['entity_id'] === 1
        && json_decode($saveAudit['meta_json'], true, 16, JSON_THROW_ON_ERROR)
            == ['revision' => 1, 'enabled' => true, 'recipients' => 2], 'Settings audit records exact revision, enabled state and recipient count');
    $row = $pdo->query('SELECT * FROM ai_reply_settings WHERE business_id=1')->fetch();
    aiSettingsCheck(is_array($row) && $row['knowledge_ciphertext'] !== $knowledge
        && !str_contains((string) $row['knowledge_ciphertext'], 'SYNTHETIC_PRIVATE_KNOWLEDGE')
        && $seal->open($row['knowledge_ciphertext'], $row['knowledge_nonce'], $row['knowledge_tag'], (int) $row['knowledge_key_version']) === $knowledge,
        'Knowledge is encrypted at rest and decryptable');
    $staffView = $store->view($forgedOwner);
    aiSettingsCheck(!$staffView['can_manage'] && !array_key_exists('knowledge', $staffView) && !array_key_exists('allowlist', $staffView),
        'Staff view does not disclose editable knowledge or allowlist');
    aiSettingsDenied(fn () => $store->save($owner, $input(0, ['knowledge' => 'Stale overwrite'])), [409], 'Stale revision cannot overwrite settings');
    aiSettingsCheck($store->view($owner)['knowledge'] === $knowledge, 'Stale overwrite preserves approved facts');

    $pdo->exec("UPDATE ai_reply_settings SET quota_date=UTC_DATE(),quota_used=17 WHERE business_id=1");
    $saved = $store->save($owner, $input(1, ['daily_limit' => 10]));
    aiSettingsCheck($saved['revision'] === 2 && $saved['daily_limit'] === 10 && $saved['quota_used'] === 17,
        'Editing limits cannot reset consumed daily quota');

    foreach ([1, 2] as $businessId) {
        aiSettingsInsert($pdo, 'ai_conversations', [
            'id' => $businessId, 'business_id' => $businessId, 'connection_id' => $businessId,
            'recipient' => '60120000000' . $businessId, 'mode' => 'human', 'last_inbound_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $message = $seal->seal(json_encode([
            'text' => $businessId === 1 ? 'OWNER_PRIVATE_HISTORY_SENTINEL' : 'OTHER_TENANT_PRIVATE_SENTINEL',
            'name' => $businessId === 1 ? 'Owner customer' : 'Other customer',
        ], JSON_THROW_ON_ERROR), 8000);
        aiSettingsInsert($pdo, 'ai_messages', [
            'business_id' => $businessId, 'connection_id' => $businessId, 'conversation_id' => $businessId,
            'source_event_id' => null, 'direction' => 'in', 'wamid' => 'wamid.settings_in_' . $businessId,
            'body_ciphertext' => $message['ciphertext'], 'body_nonce' => $message['nonce'], 'body_tag' => $message['tag'],
            'body_key_version' => $message['version'], 'message_at' => $now, 'status' => 'received',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $historyCipher = $pdo->query('SELECT HEX(body_ciphertext) FROM ai_messages WHERE business_id=1')->fetchColumn();
    $inbox = $store->inbox($owner, ['conversations' => [], 'contacts' => []]);
    aiSettingsCheck(count($inbox['conversations']) === 1 && $inbox['conversations'][0]['id'] === 'ai-1'
        && $inbox['conversations'][0]['messages'][0]['text'] === 'OWNER_PRIVATE_HISTORY_SENTINEL'
        && !str_contains(json_encode($inbox, JSON_THROW_ON_ERROR), 'OTHER_TENANT_PRIVATE_SENTINEL'), 'Inbox decrypts only the current tenant history');
    aiSettingsCheck(!str_contains(hex2bin((string) $historyCipher), 'OWNER_PRIVATE_HISTORY_SENTINEL'), 'Message history remains encrypted at rest');
    aiSettingsDenied(fn () => $store->inbox(['user_id' => 3, 'business_id' => 1], []), [401], 'Non-member cannot read encrypted inbox');
    aiSettingsDenied(fn () => $store->setMode($owner, 'ai-2', 'human'), [404], 'Cross-tenant conversation ID denied');
    aiSettingsCheck($pdo->query('SELECT mode FROM ai_conversations WHERE id=2')->fetchColumn() === 'human', 'Foreign conversation remains unchanged');
    aiSettingsDenied(fn () => $store->setMode($owner, 'ai-1', 'broadcast'), [400], 'Unknown conversation mode denied');
    aiSettingsDenied(fn () => $store->setMode($owner, 'wa-1', 'ai'), [400], 'Legacy identifier cannot bypass AI conversation scope');
    $store->setMode($staff, 'ai-1', 'ai');
    aiSettingsCheck($pdo->query('SELECT mode FROM ai_conversations WHERE id=1')->fetchColumn() === 'ai', 'Authorized member may resume conversation AI');
    aiSettingsCheck($store->inbox($owner, [])['conversations'][0]['mode'] === 'ai_active', 'Ready enabled conversation is effectively active');
    $pdo->exec('UPDATE whatsapp_connections SET disconnected_at=UTC_TIMESTAMP() WHERE id=1');
    aiSettingsCheck($store->inbox($owner, [])['conversations'][0]['mode'] === 'ai_paused', 'Disconnected connection is effectively paused in inbox');
    $pdo->exec('UPDATE whatsapp_connections SET disconnected_at=NULL WHERE id=1');
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE whatsapp_connections SET token_state='revoked',token_ciphertext=NULL,token_nonce=NULL,token_tag=NULL,token_key_version=NULL,messaging_state='unverified' WHERE id=1");
        aiSettingsCheck($store->inbox($owner, [])['conversations'][0]['mode'] === 'ai_paused', 'Revoked token is effectively paused in inbox');
    } finally {
        $pdo->rollBack();
    }
    $store->setMode($staff, 'ai-1', 'human');
    aiSettingsCheck($pdo->query('SELECT mode FROM ai_conversations WHERE id=1')->fetchColumn() === 'human', 'Authorized member takeover is persistent');

    $legacy = ['conversations' => [[
        'id' => 'wa-920000000000001-601200000001', 'name' => 'Pending name', 'phone' => '+601200000001',
        'messages' => [
            ['wamid' => 'wamid.settings_in_1', 'text' => 'Duplicate pending body', 'from' => 'customer'],
            ['wamid' => 'wamid.settings_pending', 'text' => 'NEW_SIGNED_PENDING_MESSAGE', 'from' => 'customer'],
        ],
    ]], 'contacts' => []];
    $merged = $store->inbox($owner, $legacy);
    aiSettingsCheck(count($merged['conversations']) === 1 && count($merged['conversations'][0]['messages']) === 2
        && $merged['conversations'][0]['messages'][0]['text'] === 'OWNER_PRIVATE_HISTORY_SENTINEL'
        && $merged['conversations'][0]['messages'][1]['text'] === 'NEW_SIGNED_PENDING_MESSAGE', 'Inbox merges pending events without duplicating durable history');

    $pdo->exec("UPDATE businesses SET status='suspended' WHERE id=1");
    aiSettingsDenied(fn () => $store->save($owner, $input(2)), [403], 'Suspended business cannot save settings');
    aiSettingsDenied(fn () => $store->setMode($owner, 'ai-1', 'ai'), [400, 403], 'Suspended business cannot resume AI');
    $pdo->exec("UPDATE businesses SET status='active' WHERE id=1");
    $pdo->exec("UPDATE users SET status='disabled' WHERE id=1");
    aiSettingsDenied(fn () => $store->stop($owner), [401, 403], 'Disabled actor cannot mutate settings');
    $pdo->exec("UPDATE users SET status='active' WHERE id=1");
    aiSettingsDenied(fn () => $store->stop($forgedOwner), [403], 'Forged owner cannot stop tenant settings');
    $stopped = $store->stop($owner);
    aiSettingsCheck(!$stopped['enabled'] && $stopped['revision'] === 3 && $stopped['knowledge'] === $knowledge && $stopped['quota_used'] === 17,
        'Stop disables replies and invalidates pending revision without erasing facts or quota');
    aiSettingsDenied(fn () => $store->setMode($staff, 'ai-1', 'ai'), [409], 'Stopped settings cannot be bypassed by conversation resume');
    aiSettingsCheck($pdo->query('SELECT HEX(body_ciphertext) FROM ai_messages WHERE business_id=1')->fetchColumn() === $historyCipher
        && $store->inbox($owner, [])['conversations'][0]['messages'][0]['text'] === 'OWNER_PRIVATE_HISTORY_SENTINEL', 'Stop retains encrypted readable history');

    $draft = $offStore->save($owner, $input(3, ['enabled' => false, 'daily_limit' => 1000]));
    aiSettingsCheck(!$draft['enabled'] && !$draft['deployment_ready'] && $draft['revision'] === 4
        && $draft['daily_limit'] === 1000 && $draft['quota_used'] === 17, 'Facts can be saved while deployment disabled, without quota reset');

    // Exercise only the three new mutation routes with real session and CSRF validation.
    // A null WhatsApp gateway makes all of these requests strictly local to the fixture.
    $rateDirectory = sys_get_temp_dir() . '/beshare-ai-settings-' . bin2hex(random_bytes(8));
    $http = new AuthHttp($pdo, new RateLimit($rateDirectory), null, $store);
    $csrf = str_repeat('a', 64);
    $httpOwner = $owner + ['csrf' => $csrf];
    $httpStaff = $staff + ['csrf' => $csrf];
    $headers = ['origin' => 'https://owner-settings.example.invalid', 'x-csrf-token' => $csrf];
    $spoof = ['business_id' => 2, 'user_id' => 3, 'owner_user_id' => 3, 'role_id' => 'owner'];
    $requests = ['aiconfig' => $input(4), 'aistop' => [], 'aimode' => ['conversation_id' => 'ai-1', 'mode' => 'ai']];
    $beforeHttp = $store->view($owner);
    $beforeHttpAudits = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    foreach ($requests as $action => $body) {
        aiSettingsDenied(fn () => aiSettingsHttp($http, 'GET', $action, $httpOwner, $body, $headers), [405], 'AI mutations require POST');
        aiSettingsDenied(fn () => aiSettingsHttp($http, 'POST', $action, $httpOwner, $body, []), [403], 'AI mutations require CSRF');
        aiSettingsDenied(fn () => aiSettingsHttp($http, 'POST', $action, $httpOwner, $body,
            array_replace($headers, ['x-csrf-token' => str_repeat('b', 64)])), [403], 'Wrong CSRF cannot mutate AI');
        aiSettingsDenied(fn () => aiSettingsHttp($http, 'POST', $action, ['csrf' => $csrf],
            $body + ['user_id' => 1, 'business_id' => 1], $headers), [401], 'Body identity cannot replace an authenticated session');
        aiSettingsDenied(fn () => aiSettingsHttp($http, 'POST', $action, $httpOwner, $body,
            array_replace($headers, ['origin' => 'https://foreign.example.invalid'])), [403], 'Cross-origin AI mutation denied');
    }
    aiSettingsCheck($store->view($owner) === $beforeHttp
        && (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $beforeHttpAudits,
        'Rejected HTTP requests do not mutate settings or audit');
    foreach (['aiconfig', 'aistop'] as $action) {
        aiSettingsDenied(fn () => aiSettingsHttp($http, 'POST', $action, $httpStaff,
            $requests[$action] + ['user_id' => 1, 'business_id' => 1, 'role' => 'owner', 'owner_user_id' => 1], $headers),
            [403], 'HTTP body cannot promote staff to owner');
    }
    $response = aiSettingsHttp($http, 'POST', 'aiconfig', $httpOwner, $input(4) + $spoof, $headers);
    aiSettingsPublicResponse($response);
    aiSettingsCheck($response['body']['business']['public_id'] === str_pad('1', 26, '0', STR_PAD_LEFT)
        && $response['body']['user']['email'] === 'settings-fixture-1@example.invalid'
        && $response['body']['ai']['enabled'] && $response['body']['ai']['revision'] === 5,
        'HTTP settings use the authenticated owner and business despite body spoofing');
    $httpAudit = $pdo->query("SELECT business_id,actor_user_id,meta_json FROM audit_logs WHERE action='ai_tetapan_disimpan' ORDER BY id DESC LIMIT 1")->fetch();
    aiSettingsCheck((int) $httpAudit['business_id'] === 1 && (int) $httpAudit['actor_user_id'] === 1
        && json_decode($httpAudit['meta_json'], true, 16, JSON_THROW_ON_ERROR)
            == ['revision' => 5, 'enabled' => true, 'recipients' => 1], 'HTTP save audit uses session identity and exact metadata');
    aiSettingsDenied(fn () => aiSettingsHttp($http, 'POST', 'aimode', $httpOwner,
        ['conversation_id' => 'ai-2', 'mode' => 'ai'] + $spoof, $headers), [404], 'HTTP body cannot select a foreign conversation');
    $response = aiSettingsHttp($http, 'POST', 'aimode', $httpOwner, ['conversation_id' => 'ai-1', 'mode' => 'ai'] + $spoof, $headers);
    aiSettingsPublicResponse($response);
    aiSettingsCheck($pdo->query('SELECT mode FROM ai_conversations WHERE id=1')->fetchColumn() === 'ai'
        && $pdo->query('SELECT mode FROM ai_conversations WHERE id=2')->fetchColumn() === 'human', 'HTTP mode change remains inside session tenant');
    $response = aiSettingsHttp($http, 'POST', 'aistop', $httpOwner, $spoof, $headers);
    aiSettingsPublicResponse($response);
    aiSettingsCheck(!$response['body']['ai']['enabled'] && $response['body']['ai']['revision'] === 6
        && $response['body']['conversations'][0]['mode'] === 'ai_paused'
        && $response['body']['ai']['quota_used'] === 17, 'HTTP stop pauses the authenticated tenant and preserves quota');
    $auditRows = $pdo->query('SELECT action,meta_json FROM audit_logs WHERE business_id=1 ORDER BY id')->fetchAll();
    $auditText = json_encode($auditRows, JSON_THROW_ON_ERROR);
    aiSettingsCheck(count($auditRows) >= 4 && !str_contains($auditText, $knowledge)
        && !str_contains($auditText, 'OWNER_PRIVATE_HISTORY_SENTINEL')
        && !str_contains($auditText, 'synthetic_google_settings_key_123'), 'Audit contains no knowledge, history, or API key');
    aiSettingsCheck((int) $pdo->query('SELECT COUNT(*) FROM ai_reply_settings WHERE business_id=2')->fetchColumn() === 0,
        'Owner actions never create settings for another tenant');

    echo "AI schema and settings checks passed (disposable loopback MySQL; synthetic data; no provider calls).\n";
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS ' . AI_SETTINGS_TEST_DATABASE);
    if (is_string($rateDirectory) && is_dir($rateDirectory)) { rmdir($rateDirectory); }
}
