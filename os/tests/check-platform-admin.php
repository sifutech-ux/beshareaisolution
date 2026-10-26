<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-fixture.php';

use BesharOs\AiReplyStore;
use BesharOs\AiReplyWorker;
use BesharOs\AuthHttp;
use BesharOs\Csrf;
use BesharOs\PlatformAccess;
use BesharOs\PlatformAdminHttp;
use BesharOs\PlatformAdminSchema;
use BesharOs\PlatformAdminStore;
use BesharOs\RateLimit;
use BesharOs\SqlScript;
use BesharOs\WhatsAppWebhook;

// Only new platform boundaries are exercised. Provider callbacks below are synthetic traps.
function platformRequest(PlatformAdminHttp $http, string $method, string $action, array $session, array $input = [], array $headers = []): array
{
    return $http->handle($method, $action, $session, $input, $headers, '127.0.0.1', 'admin-fixture.invalid');
}

function platformConnection(array $fixture, array $tenant, string $suffix, string $registration): int
{
    $token = $fixture['seal']->seal('synthetic-private-admin-token-' . $suffix, 2048);
    adminSql($fixture['pdo'], "INSERT INTO whatsapp_connections
        (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,registration_state,
         messaging_state,token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,subscribed_at,created_at,updated_at)
        VALUES (?,?,?,?,'verified','subscribed',?,'unverified','system_user',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
        [$tenant['business_id'], '7000000000000' . $suffix, '8000000000000' . $suffix, '+1555000000' . $suffix,
            $registration, $token['ciphertext'], $token['nonce'], $token['tag'], $token['version']]);
    return (int) $fixture['pdo']->lastInsertId();
}

function platformPrivateResponse(array $value): void
{
    $json = json_encode($value, JSON_THROW_ON_ERROR);
    foreach (['token_ciphertext', 'token_nonce', 'token_tag', 'token_key_version', 'pin_ciphertext', 'pin_nonce',
        'pin_tag', 'pin_key_version', 'knowledge_ciphertext', 'draft_ciphertext', 'published_ciphertext',
        'body_ciphertext', 'meta_json', 'allowlist_json', 'password_hash', 'gemini_api_key',
        'synthetic-private-admin-token', 'synthetic-private-knowledge', 'synthetic-private-message',
        'synthetic-private-audit', 'synthetic-private-gemini-key', '975310'] as $private) {
        adminAssert(!str_contains($json, $private), 'Admin response excludes ' . $private);
    }
}

$fixture = adminFixture(false);
$pdo = $fixture['pdo'];
$schema = $fixture['schema'];
$admin = $fixture['admin'];
$a = $fixture['tenantA'];
$b = $fixture['tenantB'];
$users = $fixture['users'];
$sql = (string) file_get_contents(__DIR__ . '/../migrations/0006_platform_admin.up.sql');

adminTest('Additive installation requires reviewed checksum and never grants platform authority', function () use ($pdo, $schema, $sql, $a, $users): void {
    $before = $pdo->query('SELECT id,public_id,name,status,owner_user_id FROM businesses ORDER BY id')->fetchAll();
    adminAssert(!PlatformAccess::installed($pdo), 'Admin installation absent before explicit apply');
    adminAssert(PlatformAccess::businessStatus($pdo, $a['business_id']) === 'active', 'Uninstalled additive schema preserves existing tenant access');
    adminAssert($schema->inspect()['status'] === 'ready', 'Complete 0001–0005 foundation is ready');
    adminDenied(fn () => $schema->apply(str_repeat('0', 64)), 409, 'Wrong checksum cannot create tables');
    adminAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'platform_%'")->fetchColumn() === 0,
        'Rejected installation creates no platform tables');
    $installed = $schema->apply(hash('sha256', $sql));
    adminAssert($installed['status'] === 'installed' && PlatformAccess::installed($pdo), 'Reviewed installation completes');
    adminAssert((int) $pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn() === 0, 'Migration grants no user automatically');
    adminAssert(PlatformAccess::admin($pdo, $users['ownerA']) === null, 'Tenant ownership grants no platform access');
    adminAssert($schema->apply(hash('sha256', $sql)) === $installed, 'Matching installation is idempotent');
    adminAssert((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='0006'")->fetchColumn() === 1, 'Migration has exactly one ledger entry');
    adminAssert($before === $pdo->query('SELECT id,public_id,name,status,owner_user_id FROM businesses ORDER BY id')->fetchAll(), 'Existing businesses and ownership are preserved');
    adminDenied(fn () => (new PlatformAdminSchema($pdo, $sql . "\n-- altered fixture"))->inspect(), 409, 'Changed migration bytes cannot masquerade as installed');
});

// Grant only the explicitly designated synthetic platform user, outside all HTTP flows.
adminSql($pdo, "INSERT INTO platform_admins (user_id,role,status,created_at,updated_at) VALUES (?,'owner','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$users['platform']]);
$connectionA = platformConnection($fixture, $a, '1', 'registered');
$connectionB = platformConnection($fixture, $b, '2', 'not_registered');
$config = ['owner_ai_business_id' => (string) $a['business_id'], 'owner_ai_enabled' => 'true',
    'gemini_api_key' => 'synthetic-private-gemini-key', 'gemini_model' => 'gemini-synthetic-fixture'];
$store = new PlatformAdminStore($pdo, $config, true);
$rateDirectory = sys_get_temp_dir() . '/beshare-platform-test-' . bin2hex(random_bytes(8));
$rates = new RateLimit($rateDirectory, 1000);
$http = new PlatformAdminHttp($pdo, $rates, $store);
$session = ['user_id' => $users['platform']];
$csrf = Csrf::issue($session);
$headers = ['origin' => 'https://admin-fixture.invalid', 'x-csrf-token' => $csrf];
$accessInput = ['confirmed' => true, 'public_id' => $a['public_id'], 'status' => 'suspended', 'expected_status' => 'active'];

adminTest('Tenant roles, forged session flags, revoked grants and disabled users cannot enter platform administration', function () use ($pdo, $users, $a, $b, $store, $http, $session, $headers, $accessInput, $admin): void {
    foreach (['ownerA', 'ownerB', 'tenantAdmin', 'staff'] as $key) {
        $forged = ['user_id' => $users[$key], 'business_id' => $a['business_id'], 'platform_admin' => true, 'platform_role' => 'owner', 'csrf' => $session['csrf']];
        adminDenied(fn () => platformRequest($http, 'GET', 'pelanggan', $forged), 403, $key . ' cannot list clients');
        adminDenied(fn () => platformRequest($http, 'GET', 'butiran', $forged, ['public_id' => $b['public_id']]), 403, $key . ' cannot inspect another client');
        adminDenied(fn () => platformRequest($http, 'POST', 'access', $forged, $accessInput + ['user_id' => $users['platform'], 'role' => 'owner'], $headers), 403, $key . ' cannot mutate or impersonate platform user');
        adminDenied(fn () => platformRequest($http, 'POST', 'grant', $forged, ['confirmed' => true, 'user_id' => $users[$key]], $headers), 403, $key . ' cannot grant platform authority');
    }
    adminDenied(fn () => platformRequest($http, 'GET', 'sesi', []), 401, 'Anonymous session rejected');
    adminSql($pdo, "UPDATE platform_admins SET status='revoked' WHERE user_id=?", [$users['platform']]);
    adminDenied(fn () => $store->customers($admin, '', 'all', 1), 403, 'Stale admin context rejected after revocation');
    adminDenied(fn () => platformRequest($http, 'POST', 'access', $session, $accessInput, $headers), 403, 'Revoked session cannot mutate');
    adminSql($pdo, "UPDATE platform_admins SET status='active' WHERE user_id=?", [$users['platform']]);
    adminSql($pdo, "UPDATE users SET status='disabled' WHERE id=?", [$users['platform']]);
    adminDenied(fn () => platformRequest($http, 'GET', 'sesi', $session), 403, 'Disabled platform user rejected');
    adminSql($pdo, "UPDATE users SET status='active' WHERE id=?", [$users['platform']]);
    adminAssert((int) $pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn() === 1, 'Denied requests never grant platform roles');
    adminAssert(PlatformAccess::businessStatus($pdo, $a['business_id']) === 'active', 'Denied requests never suspend tenant');
});

adminTest('HTTP controls reject unsafe methods, cross-origin requests, missing CSRF and unconfirmed actions', function () use ($pdo, $http, $session, $headers, $accessInput): void {
    adminDenied(fn () => platformRequest($http, 'PUT', 'access', $session, $accessInput, $headers), 405, 'PUT cannot mutate');
    adminDenied(fn () => platformRequest($http, 'GET', 'access', $session, $accessInput, $headers), 405, 'GET cannot mutate');
    adminDenied(fn () => platformRequest($http, 'POST', 'access', $session, $accessInput, ['origin' => 'https://admin-fixture.invalid']), 403, 'Missing CSRF rejected');
    adminDenied(fn () => platformRequest($http, 'POST', 'access', $session, $accessInput, array_replace($headers, ['x-csrf-token' => str_repeat('0', 64)])), 403, 'Wrong CSRF rejected');
    foreach (['https://foreign.invalid', 'https://admin-fixture.invalid.evil.invalid', 'https://admin-fixture.invalid:444', 'https://user@admin-fixture.invalid', 'http://admin-fixture.invalid'] as $origin) {
        adminDenied(fn () => platformRequest($http, 'POST', 'access', $session, $accessInput, array_replace($headers, ['origin' => $origin])), 403, 'Foreign or malformed origin rejected');
    }
    adminDenied(fn () => platformRequest($http, 'POST', 'access', $session, array_replace($accessInput, ['confirmed' => 'true']), $headers), 400, 'String confirmation rejected');
    adminDenied(fn () => platformRequest($http, 'POST', 'grant', $session, ['confirmed' => true], $headers), 404, 'Platform grants are not an HTTP action');
    adminAssert((int) $pdo->query('SELECT COUNT(*) FROM platform_business_access')->fetchColumn() === 0, 'Rejected HTTP requests leave access unchanged');
    $response = platformRequest($http, 'GET', 'sesi', $session);
    adminAssert($response['status'] === 200 && $response['body']['admin']['role'] === 'owner' && strlen($response['body']['csrf']) === 64, 'Authorized session returns bounded identity and CSRF');
});

// Insert encrypted, private data so metadata-only responses are checked against real storage.
$secret = $fixture['seal']->seal('synthetic-private-knowledge', 8000);
adminSql($pdo, 'INSERT INTO knowledge_centres (business_id,draft_ciphertext,draft_nonce,draft_tag,draft_key_version,draft_hash,updated_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
    [$a['business_id'], $secret['ciphertext'], $secret['nonce'], $secret['tag'], $secret['version'], hash('sha256', 'synthetic-private-knowledge'), $a['owner_id']]);
$pin = $fixture['seal']->seal('975310', 6);
adminSql($pdo, "INSERT INTO platform_whatsapp_operations
    (connection_id,business_id,operation_id,actor_user_id,status,pin_ciphertext,pin_nonce,pin_tag,pin_key_version,registration_started_at,finished_at,created_at,updated_at)
    VALUES (?,?,?,?,'succeeded',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
    [$connectionA, $a['business_id'], bin2hex(random_bytes(16)), $users['platform'], $pin['ciphertext'], $pin['nonce'], $pin['tag'], $pin['version']]);
adminSql($pdo, "INSERT INTO ai_conversations (business_id,connection_id,recipient,mode,last_inbound_at,created_at,updated_at)
    VALUES (?,?,'15550000999','human',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$a['business_id'], $connectionA]);
$conversation = (int) $pdo->lastInsertId();
$message = $fixture['seal']->seal('synthetic-private-message', 8000);
adminSql($pdo, "INSERT INTO ai_messages (business_id,connection_id,conversation_id,direction,wamid,body_ciphertext,body_nonce,body_tag,body_key_version,message_at,status,created_at,updated_at)
    VALUES (?,?,?,'in','wamid.admin.metadata.fixture',?,?,?,?,UTC_TIMESTAMP(),'received',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
    [$a['business_id'], $connectionA, $conversation, $message['ciphertext'], $message['nonce'], $message['tag'], $message['version']]);
PlatformAccess::audit($pdo, $a['business_id'], $users['platform'], 'fixture_metadata', $connectionA, ['private' => 'synthetic-private-audit']);

adminTest('Client list and details expose operational metadata without tokens, PIN, knowledge or conversations', function () use ($pdo, $store, $admin, $a, $b, $connectionA, $connectionB): void {
    $list = $store->customers($admin, '', 'all', 1);
    adminAssert($list['pagination']['total'] === 2 && count($list['customers']) === 2, 'Both synthetic clients are listed');
    adminAssert($list['summary']['ready_connections'] === 1 && $list['summary']['pending_connections'] === 1, 'Readiness summary distinguishes ready and pending numbers');
    $details = $store->details($admin, $a['public_id']);
    adminAssert($details['customer']['public_id'] === $a['public_id'] && $details['connections'][0]['id'] === $connectionA, 'Details select the exact requested tenant');
    adminAssert($details['connections'][0]['readiness'] === 'ready' && !$details['connections'][0]['can_activate'], 'Ready number cannot be reactivated');
    platformPrivateResponse($list);
    platformPrivateResponse($details);
    adminAssert($store->customers($admin, 'ownerb@admin-fixture.invalid', 'all', 1)['customers'][0]['public_id'] === $b['public_id'], 'Owner search finds exact tenant');
    adminAssert($store->customers($admin, '%', 'all', 1)['pagination']['total'] === 0, 'Search wildcards are literal');
    adminAssert($store->customers($admin, '', 'ready', 1)['pagination']['total'] === 1, 'Ready filter includes only ready client');
    adminAssert($store->customers($admin, '', 'attention', 1)['pagination']['total'] === 1, 'Attention filter includes pending client');
    adminSql($pdo, "UPDATE users SET status='disabled' WHERE id=?", [$a['owner_id']]);
    adminAssert($store->customers($admin, '', 'all', 1)['summary']['ready_connections'] === 0, 'Summary does not count a disabled owner as ready');
    adminSql($pdo, "UPDATE users SET status='active' WHERE id=?", [$a['owner_id']]);
    adminSql($pdo, "UPDATE platform_whatsapp_operations SET status='unknown' WHERE connection_id=?", [$connectionA]);
    $unknown = $store->customers($admin, '', 'all', 1);
    adminAssert($unknown['summary']['ready_connections'] === 0 && $unknown['summary']['pending_connections'] === 2,
        'Unknown operation has consistent non-ready summary');
    adminAssert($store->details($admin, $a['public_id'])['connections'][0]['readiness'] === 'attention', 'Unknown operation cannot appear ready in details');
    adminSql($pdo, "UPDATE platform_whatsapp_operations SET status='succeeded' WHERE connection_id=?", [$connectionA]);
    adminDenied(fn () => $store->details($admin, "' OR 1=1"), 400, 'Malformed public ID rejected');
    adminDenied(fn () => $store->assertTargetPhone($a['public_id'], $connectionB, '+15550000002'), 409, 'Tenant A cannot target tenant B connection');
    adminDenied(fn () => $store->assertTargetPhone($a['public_id'], $connectionA, '+15550000009'), 409, 'Stale displayed number rejected');
    $store->assertTargetPhone($a['public_id'], $connectionA, '+15550000001');
    adminAssert(true, 'Exact tenant and displayed number accepted');
    adminSql($pdo, 'UPDATE whatsapp_connections SET disconnected_at=UTC_TIMESTAMP() WHERE id=?', [$connectionA]);
    adminDenied(fn () => $store->assertTargetPhone($a['public_id'], $connectionA, '+15550000001'), 409, 'Disconnected connection cannot be targeted');
    adminSql($pdo, 'UPDATE whatsapp_connections SET disconnected_at=NULL WHERE id=?', [$connectionA]);
});

adminSql($pdo, "INSERT INTO ai_reply_settings (business_id,connection_id,enabled,allowlist_json,created_at,updated_at)
    VALUES (?,?,0,'[]',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$a['business_id'], $connectionA]);
$ownerAi = new AiReplyStore($pdo, $fixture['seal'], $config);
$ownerInput = ['connection_id' => $connectionA, 'expected_revision' => 1, 'daily_limit' => 17,
    'enabled' => false, 'knowledge' => 'synthetic-private-knowledge approved by fixture owner',
    'allowlist' => ['15550000999'], 'approved' => true];

adminTest('Admin AI enable respects deployment scope and existing owner approval', function () use ($pdo, $store, $admin, $a, $b, $ownerAi, $ownerInput): void {
    adminDenied(fn () => (new PlatformAdminStore($pdo, [], true))->setAi($admin, $a['public_id'], true, 1), 409, 'Unconfigured deployment cannot enable AI');
    adminDenied(fn () => $store->setAi($admin, $a['public_id'], true, 1), 409, 'Admin cannot invent owner approval');
    adminAssert($store->details($admin, $b['public_id'])['ai']['available'] === false, 'Other tenant is outside existing AI pilot scope');
    $ownerAi->save($a['member'], $ownerInput);
    adminSql($pdo, 'UPDATE ai_reply_settings SET quota_date=UTC_DATE(),quota_used=9 WHERE business_id=?', [$a['business_id']]);
    adminDenied(fn () => $store->setAi($admin, $a['public_id'], true, 1), 409, 'Stale settings revision rejected');
});

adminTest('AI enable refuses expired, disconnected, unregistered or unsubscribed numbers and invalid approvals', function () use ($pdo, $store, $admin, $a, $b, $connectionA): void {
    foreach ([
        ['token_expires_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR', 'token_expires_at=NULL', 'Expired token'],
        ["registration_state='not_registered'", "registration_state='registered'", 'Unregistered number'],
        ["webhook_state='not_subscribed'", "webhook_state='subscribed'", 'Unsubscribed webhook'],
        ['disconnected_at=UTC_TIMESTAMP()', 'disconnected_at=NULL', 'Disconnected number'],
    ] as [$change, $restore, $label]) {
        adminSql($pdo, 'UPDATE whatsapp_connections SET ' . $change . ' WHERE id=?', [$connectionA]);
        adminDenied(fn () => $store->setAi($admin, $a['public_id'], true, 2), 409, $label . ' blocks enable');
        adminSql($pdo, 'UPDATE whatsapp_connections SET ' . $restore . ' WHERE id=?', [$connectionA]);
    }
    adminSql($pdo, 'UPDATE ai_reply_settings SET approved_by=? WHERE business_id=?', [$b['owner_id'], $a['business_id']]);
    adminDenied(fn () => $store->setAi($admin, $a['public_id'], true, 2), 409, 'Approval from another owner rejected');
    adminSql($pdo, 'UPDATE ai_reply_settings SET approved_by=? WHERE business_id=?', [$a['owner_id'], $a['business_id']]);
    adminSql($pdo, "UPDATE ai_reply_settings SET allowlist_json='[]' WHERE business_id=?", [$a['business_id']]);
    adminDenied(fn () => $store->setAi($admin, $a['public_id'], true, 2), 409, 'Empty recipient approval rejected');
    adminSql($pdo, 'UPDATE ai_reply_settings SET allowlist_json=? WHERE business_id=?', ['["15550000999"]', $a['business_id']]);
    adminSql($pdo, "UPDATE users SET status='disabled' WHERE id=?", [$a['owner_id']]);
    adminDenied(fn () => $store->setAi($admin, $a['public_id'], true, 2), 409, 'Disabled business owner blocks enable');
    adminSql($pdo, "UPDATE users SET status='active' WHERE id=?", [$a['owner_id']]);
    adminAssert((int) adminSql($pdo, 'SELECT enabled FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetchColumn() === 0, 'All failed enable requests leave AI off');
});

adminTest('Ownership transfer between pre-read and transaction cannot reuse the former owner approval', function () use ($pdo, $config, $admin, $a, $b): void {
    [$dsn, $user, $password] = adminTestConnection();
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
    $writer = new PDO($dsn, $user, $password, $options);
    $race = new class($dsn, $user, $password, $options) extends PDO {
        public ?Closure $beforeBegin = null;
        public function beginTransaction(): bool
        {
            $hook = $this->beforeBegin;
            $this->beforeBegin = null;
            if ($hook !== null) { $hook(); }
            return parent::beginTransaction();
        }
    };
    $transfer = static function (int $from, int $to) use ($writer, $a): void {
        $writer->beginTransaction();
        try {
            adminSql($writer, "UPDATE businesses SET status='provisioning' WHERE id=?", [$a['business_id']]);
            adminSql($writer, "UPDATE business_users SET role='staff' WHERE business_id=? AND user_id=?", [$a['business_id'], $from]);
            adminSql($writer, "INSERT INTO business_users (business_id,user_id,role,created_at,updated_at)
                VALUES (?,?,'owner',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE role='owner'", [$a['business_id'], $to]);
            adminSql($writer, 'UPDATE businesses SET owner_user_id=? WHERE id=?', [$to, $a['business_id']]);
            adminSql($writer, "UPDATE businesses SET status='active' WHERE id=?", [$a['business_id']]);
            $writer->commit();
        } catch (Throwable $error) {
            if ($writer->inTransaction()) { $writer->rollBack(); }
            throw $error;
        }
    };
    $transferred = false;
    $race->beforeBegin = static function () use ($transfer, $a, $b, &$transferred): void {
        $transfer($a['owner_id'], $b['owner_id']);
        $transferred = true;
    };
    try {
        $racingStore = new PlatformAdminStore($race, $config, true);
        adminDenied(fn () => $racingStore->setAi($admin, $a['public_id'], true, 2), 409, 'Fresh locked ownership invalidates old owner approval');
        adminAssert($transferred && (int) adminSql($pdo, 'SELECT owner_user_id FROM businesses WHERE id=?', [$a['business_id']])->fetchColumn() === $b['owner_id'],
            'Actual ownership transfer committed between pre-read and transaction');
        $settings = adminSql($pdo, 'SELECT enabled,approved_by,knowledge_revision FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetch();
        adminAssert((int) $settings['enabled'] === 0 && (int) $settings['approved_by'] === $a['owner_id'] && (int) $settings['knowledge_revision'] === 2,
            'Denied race leaves AI disabled and does not rewrite approval or revision');
    } finally {
        if ($race->inTransaction()) { $race->rollBack(); }
        if ($transferred) {
            $transfer($b['owner_id'], $a['owner_id']);
            adminSql($writer, 'DELETE FROM business_users WHERE business_id=? AND user_id=?', [$a['business_id'], $b['owner_id']]);
        }
    }
});

adminTest('Admin AI toggles preserve approved content, recipients, quota and exact revision', function () use ($pdo, $store, $admin, $a): void {
    $before = adminSql($pdo, 'SELECT knowledge_ciphertext,approved_by,approved_at,allowlist_json,daily_limit,quota_date,quota_used FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetch();
    $store->setAi($admin, $a['public_id'], true, 2);
    $store->setAi($admin, $a['public_id'], false, 3);
    $store->setAi($admin, $a['public_id'], true, 4);
    $store->setAi($admin, $a['public_id'], true, 5);
    $after = adminSql($pdo, 'SELECT knowledge_ciphertext,approved_by,approved_at,allowlist_json,daily_limit,quota_date,quota_used FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetch();
    adminAssert($before === $after, 'Off/on toggles preserve approval, allowlist, daily limit and consumed quota');
    $settings = adminSql($pdo, 'SELECT enabled,knowledge_revision FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetch();
    adminAssert((int) $settings['enabled'] === 1 && (int) $settings['knowledge_revision'] === 5, 'Repeated same state is idempotent without revision bump');
    adminAssert((int) adminSql($pdo, "SELECT COUNT(*) FROM audit_logs WHERE business_id=? AND action IN ('platform_ai_dihidupkan','platform_ai_dihentikan')", [$a['business_id']])->fetchColumn() === 3, 'Each changed state has one audit event');
});

adminTest('Platform suspension stops AI independently and cannot be undone through tenant controls', function () use ($pdo, $store, $admin, $a, $b, $connectionA, $fixture, $ownerAi, $ownerInput, $rates): void {
    $beforeToken = adminSql($pdo, 'SELECT token_ciphertext FROM whatsapp_connections WHERE id=?', [$connectionA])->fetchColumn();
    $store->changeAccess($admin, $a['public_id'], 'suspended', 'active');
    adminAssert(PlatformAccess::businessStatus($pdo, $a['business_id']) === 'suspended', 'Platform suspension persisted');
    adminAssert(adminSql($pdo, 'SELECT status FROM businesses WHERE id=?', [$a['business_id']])->fetchColumn() === 'active', 'Tenant business status remains independent');
    adminAssert(PlatformAccess::businessStatus($pdo, $b['business_id']) === 'active', 'Other tenant remains active');
    adminAssert(adminSql($pdo, 'SELECT token_ciphertext FROM whatsapp_connections WHERE id=?', [$connectionA])->fetchColumn() === $beforeToken, 'Suspension preserves the Meta connection');
    adminAssert((int) adminSql($pdo, 'SELECT enabled FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetchColumn() === 0, 'Suspension switches AI off');
    adminDenied(fn () => $store->changeAccess($admin, $a['public_id'], 'active', 'active'), 409, 'Stale access state cannot resume');
    adminDenied(fn () => $ownerAi->save($a['member'], array_replace($ownerInput, ['expected_revision' => 6, 'enabled' => true])), 403, 'Owner cannot restore AI while platform suspended');
    $tenantSession = ['user_id' => $a['owner_id'], 'business_id' => $a['business_id']];
    $token = Csrf::issue($tenantSession);
    $tenantHttp = new AuthHttp($pdo, $rates, null, $ownerAi);
    $request = static function (string $method, string $action, array $input = []) use ($tenantHttp, $tenantSession, $token): array {
        $session = $tenantSession;
        return $tenantHttp->handle($method, $action, $session, $input,
            ['origin' => 'https://admin-fixture.invalid', 'x-csrf-token' => $token], '127.0.0.1', 'admin-fixture.invalid');
    };
    adminDenied(fn () => $request('POST', 'status', ['kind' => 'resume', 'next_status' => 'active']), 403, 'Tenant status action cannot undo platform suspension');
    adminDenied(fn () => $request('GET', 'peti'), 403, 'Suspended workspace inbox is denied');
    $state = $request('GET', 'sesi');
    adminAssert($state['body']['business']['access_status'] === 'suspended' && $state['body']['platform_admin'] === false,
        'Owner can still see accurate suspension state without platform authority');
    adminAssert($request('POST', 'keluar')['body']['user'] === null, 'Suspended owner may still sign out');

    // A stale/incorrect enabled flag must not override the independent worker restriction.
    adminSql($pdo, 'UPDATE ai_reply_settings SET enabled=1,activation_at=UTC_TIMESTAMP() WHERE business_id=?', [$a['business_id']]);
    $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '70000000000001', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '80000000000001'],
            'messages' => [['id' => 'wamid.admin.suspension.fixture', 'from' => '15550000999', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Synthetic suspended workspace question']]],
        ]]],
    ]]], JSON_THROW_ON_ERROR);
    (new WhatsAppWebhook($pdo, $fixture['seal']))->ingest($raw);
    $calls = 0;
    $trap = static function () use (&$calls): array { $calls++; throw new RuntimeException('Synthetic provider must not be called'); };
    $result = (new AiReplyWorker($pdo, $fixture['seal'], $trap, $trap))->run($a['business_id']);
    adminAssert($calls === 0 && $result['generated'] === 0 && $result['sent'] === 0, 'Worker cannot call Gemini or WhatsApp while platform suspended');
    adminAssert($result['handoff'] + $result['skipped'] > 0, 'Queued synthetic message reached the suspension gate');
    adminSql($pdo, 'UPDATE ai_reply_settings SET enabled=0,activation_at=NULL WHERE business_id=?', [$a['business_id']]);
    $store->changeAccess($admin, $a['public_id'], 'active', 'suspended');
    adminAssert((int) adminSql($pdo, 'SELECT enabled FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetchColumn() === 0, 'Resuming access never enables AI automatically');
    adminAssert((int) adminSql($pdo, 'SELECT quota_used FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetchColumn() === 9, 'Suspension and recovery preserve quota');
});

adminTest('Partially installed additive schema fails closed without deleting existing tables', function (): void {
    $partial = adminFixture(false);
    $sql = (string) file_get_contents(__DIR__ . '/../migrations/0006_platform_admin.up.sql');
    $partial['pdo']->exec(SqlScript::statements($sql)[0]);
    adminDenied(fn () => $partial['schema']->inspect(), 409, 'Partial installation requires operator review');
    adminAssert((int) $partial['pdo']->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='platform_admins'")->fetchColumn() === 1,
        'Partial schema is preserved');
    adminAssert(!PlatformAccess::installed($partial['pdo']), 'Incomplete installation never gains an installed marker');
});

foreach (glob($rateDirectory . '/*.json') ?: [] as $file) { unlink($file); }
rmdir($rateDirectory);
fwrite(STDOUT, 'PASS platform admin: ' . $adminChecks . " assertions; synthetic local MySQL only; no provider calls.\n");
