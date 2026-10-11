<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\CoexistenceArchive;
use BesharOs\CoexistenceSync;
use BesharOs\MetaCallException;
use BesharOs\MetaGraph;
use BesharOs\MetaTransport;
use BesharOs\RateLimit;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;
use BesharOs\WebhookReadiness;
use BesharOs\WhatsAppGateway;
use BesharOs\WhatsAppWebhook;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/SqlScript.php';
require dirname(__DIR__, 2) . '/whatsapp/sambungan/ids.php';

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    fwrite(STDOUT, ($ok ? 'OK ' : 'FAIL ') . $name . "\n");
    if (!$ok) {
        $failures++;
    }
};

final class ScriptedMeta implements MetaTransport
{
    /** @var list<array{status:int,json:array<string,mixed>}> */
    public array $script = [];

    /** @var list<array{method:string,url:string,headers:array<string,string>,body:string}> */
    public array $calls = [];

    public function call(string $method, string $url, array $headers, string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->script);
        if (!is_array($next)) {
            return ['status' => 500, 'json' => ['error' => ['code' => 1]]];
        }
        return $next;
    }
}

/** @param array<string,mixed> $session */
function post(AuthHttp $http, array &$session, string $action, array $body, string $remote, string $host, string $key): array
{
    return $http->handle('POST', $action, $session, $body, ['x-csrf-token' => (string) ($session['csrf'] ?? '')], $remote, $host, $key);
}

/** @return array<string,mixed> */
function registerOwner(AuthHttp $http, string $name, string $email, string $business, string $remote, string $host, string $key): array
{
    $session = [];
    $issued = $http->handle('GET', 'sesi', $session, [], [], $remote, $host, $key);
    $session['csrf'] = $issued['body']['csrf'];
    post($http, $session, 'daftar', [
        'name' => $name,
        'email' => $email,
        'password' => 'kata-laluan-yang-panjang',
        'business' => $business,
    ], $remote, $host, $key);
    return $session;
}

/** @return array{status:int,json:array<string,mixed>} */
function oauthResponse(string $token): array
{
    return ['status' => 200, 'json' => ['access_token' => $token, 'token_type' => 'bearer']];
}

/** @return array{status:int,json:array<string,mixed>} */
function debugResponse(string $appId, string $waba): array
{
    return ['status' => 200, 'json' => ['data' => [
        'app_id' => $appId,
        'type' => 'SYSTEM_USER',
        'is_valid' => true,
        'expires_at' => 0,
        'granular_scopes' => [
            ['scope' => 'whatsapp_business_management', 'target_ids' => [$waba]],
            ['scope' => 'whatsapp_business_messaging', 'target_ids' => [$waba]],
        ],
    ]]];
}

function businessId(PDO $pdo, string $name): int
{
    $statement = $pdo->prepare('SELECT id FROM businesses WHERE name = ?');
    $statement->execute([$name]);
    return (int) $statement->fetchColumn();
}

function userId(PDO $pdo, string $email): int
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $statement->execute([$email]);
    return (int) $statement->fetchColumn();
}

/** @param array<string,mixed> $value */
function envelope(string $waba, string $field, array $value): string
{
    $json = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => $waba,
            'changes' => [[
                'field' => $field,
                'value' => $value,
            ]],
        ]],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('fixture');
    }
    return $json;
}

/** @return array{stored:int,replayed:int} */
function signedIngest(WhatsAppWebhook $webhook, string $raw, string $secret): array
{
    $header = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    if (!WhatsAppWebhook::signatureMatches($raw, $header, $secret)) {
        throw new AuthException('Forbidden', 403);
    }
    return $webhook->ingest($raw);
}

function openPayload(PDO $pdo, TokenSeal $seal, int $id): string
{
    $statement = $pdo->prepare(
        'SELECT HEX(payload_ciphertext) AS payload_ciphertext, HEX(payload_nonce) AS payload_nonce,
                HEX(payload_tag) AS payload_tag, payload_key_version
         FROM webhook_events WHERE id = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return '';
    }
    return $seal->open(
        hex2bin((string) $row['payload_ciphertext']) ?: '',
        hex2bin((string) $row['payload_nonce']) ?: '',
        hex2bin((string) $row['payload_tag']) ?: '',
        (int) $row['payload_key_version']
    );
}

function latestEventId(PDO $pdo, string $kind): int
{
    $statement = $pdo->prepare('SELECT id FROM webhook_events WHERE event_kind = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$kind]);
    return (int) $statement->fetchColumn();
}

function catchStatus(callable $action): int
{
    try {
        $action();
    } catch (AuthException $error) {
        return $error->status();
    }
    return 0;
}

function insertLink(PDO $pdo, TokenSeal $seal, int $businessId, string $waba, string $phone, string $display, string $webhook, string $deadline): int
{
    $sealed = $seal->seal('token-makmal-bukan-live', 100);
    $insert = $pdo->prepare(
        'INSERT INTO whatsapp_connections (
            business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
            registration_state, messaging_state, sync_contacts, sync_history, token_kind,
            token_ciphertext, token_nonce, token_tag, token_key_version, refresh_supported,
            subscribed_at, created_at, updated_at, onboarded_at, sync_deadline_at
         ) VALUES (
            ?, ?, ?, ?, \'verified\', ?, \'registered\', \'unverified\', \'pending\', \'pending\', \'system_user\',
            UNHEX(?), UNHEX(?), UNHEX(?), ?, 0,
            CASE WHEN ? = \'subscribed\' THEN UTC_TIMESTAMP() ELSE NULL END,
            UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
            CASE WHEN ? = \'past\' THEN DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) ELSE DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) END
         )'
    );
    $insert->execute([
        $businessId,
        $waba,
        $phone,
        $display,
        $webhook,
        bin2hex($sealed['ciphertext']),
        bin2hex($sealed['nonce']),
        bin2hex($sealed['tag']),
        $sealed['version'],
        $webhook,
        $deadline,
    ]);
    return (int) $pdo->lastInsertId();
}

function markReady(PDO $pdo, string $phone): void
{
    global $webhook, $secret;
    $statement = $pdo->prepare('SELECT id, business_id, waba_id FROM whatsapp_connections WHERE phone_number_id = ?');
    $statement->execute([$phone]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('sambungan ujian tiada');
    }
    $id = (int) $row['id'];
    if (!CoexistenceSync::hasDeliveryEvidence($pdo, $id, (int) $row['business_id'])) {
        $raw = envelope((string) $row['waba_id'], 'smb_message_echoes', [
            'metadata' => ['phone_number_id' => $phone],
            'message_echoes' => [[
                'from' => '60120000000',
                'to' => '60120000001',
                'id' => 'wamid.BUKTI.' . $id,
                'timestamp' => '1710000000',
                'type' => 'text',
                'text' => ['body' => 'bukti-gema-' . $id],
            ]],
        ]);
        signedIngest($webhook, $raw, $secret);
    }
    CoexistenceSync::record($pdo, $id, 'callback');
    CoexistenceSync::record($pdo, $id, 'fields');
}

/** @return array<string,mixed>|false */
function connectionRow(PDO $pdo, string $phone): array|false
{
    $statement = $pdo->prepare(
        'SELECT id, business_id, token_state, webhook_state, messaging_state, sync_contacts, sync_history,
                last_error_code, sync_deadline_at, disconnected_at IS NOT NULL AS disconnected,
                token_ciphertext IS NULL AS token_cleared,
                sync_deadline_at > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 23 HOUR)
                  AND sync_deadline_at < DATE_ADD(UTC_TIMESTAMP(), INTERVAL 25 HOUR) AS deadline_open
         FROM whatsapp_connections WHERE phone_number_id = ?'
    );
    $statement->execute([$phone]);
    $row = $statement->fetch();
    return is_array($row) ? $row : false;
}

function lab(): ?PDO
{
    $path = '/tmp/maria-lab.pw';
    if (!is_file($path)) {
        fwrite(STDOUT, "FAIL lab password file\n");
        return null;
    }
    $password = trim((string) file_get_contents($path));
    $server = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $server->exec('DROP DATABASE IF EXISTS coexistence_lab');
    $server->exec('CREATE DATABASE coexistence_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE coexistence_lab');
    foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql'] as $name) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/' . $name);
        foreach (SqlScript::statements($sql) as $statement) {
            $server->exec($statement);
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=coexistence_lab;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES'");
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

$pdo = lab();
if (!$pdo instanceof PDO) {
    fwrite(STDOUT, "FAIL lab database\n");
    exit(1);
}
$check('coexistence tests use an isolated lab', (string) $pdo->query('SELECT DATABASE()')->fetchColumn() === 'coexistence_lab');

$appId = BESHARE_META_APP_ID;
$secret = 'tandatangan-ujian';
$token = 'EAA-coexist-token-makmal';
$phrase = 'RAHSIA-MESEJ-UJIAN';
$plant = 'token-tanaman-ujian';
$appWaba = '100000000000711';
$appPhone = '100000000000611';
$stdWaba = '100000000000101';
$stdPhone = '100000000000202';
$host = 'lab.test';
$remote = '127.0.0.1';
$seal = new TokenSeal(random_bytes(32), 1);
$rates = new RateLimit(sys_get_temp_dir() . '/beshare-os-coex-rate-' . bin2hex(random_bytes(4)), 40, 900);
$script = new ScriptedMeta();
$graph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $script);
$gateway = new WhatsAppGateway($pdo, $graph, $seal, $appId);
$http = new AuthHttp($pdo, $rates, $gateway);
$webhook = new WhatsAppWebhook($pdo, $seal);
$archive = new CoexistenceArchive($pdo, $seal);

$check('webhook processor lists the coexistence fields', WebhookReadiness::ready() && WebhookReadiness::covers(['messages']) === false);
$ready = $gateway->subscriptionReadiness();
$check(
    'subscription readiness does not call graph',
    ($ready['ready'] ?? false) === true && ($ready['calls_graph'] ?? true) === false && $script->calls === []
);
$blockedTransport = new ScriptedMeta();
$blocked = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $blockedTransport, static fn (): bool => false);
$check('subscription waits until the processor is ready', $blocked->subscribe($token, $appWaba) === false && $blockedTransport->calls === []);
$disabled = false;
try {
    (new MetaGraph($appId, $secret, BESHARE_META_GRAPH))->smbAppData($token, $appPhone, 'history');
} catch (MetaCallException $error) {
    $disabled = $error->metaCode === 'disabled' && $error->getMessage() === 'meta';
}
$check('live sync stays disabled without an injected transport', $disabled);

$app = registerOwner($http, 'Pemilik Aplikasi', 'aplikasi.coex@kedai.test', 'Kedai Aplikasi', $remote, $host, 'app-owner');
$std = registerOwner($http, 'Pemilik Piawai', 'piawai.coex@kedai.test', 'Kedai Piawai', $remote, $host, 'std-owner');
$declineOwner = registerOwner($http, 'Pemilik Tolak', 'tolak.coex@kedai.test', 'Kedai Tolak', $remote, $host, 'decline-owner');
$deadlineOwner = registerOwner($http, 'Pemilik Tamat', 'tamat.coex@kedai.test', 'Kedai Tamat', $remote, $host, 'deadline-owner');
$retryOwner = registerOwner($http, 'Pemilik Cuba', 'cuba.coex@kedai.test', 'Kedai Cuba', $remote, $host, 'retry-owner');
$failOwner = registerOwner($http, 'Pemilik Gagal', 'gagal.coex@kedai.test', 'Kedai Gagal', $remote, $host, 'fail-owner');
$dropOwner = registerOwner($http, 'Pemilik Putus', 'putus.coex@kedai.test', 'Kedai Putus', $remote, $host, 'drop-owner');
$otherOwner = registerOwner($http, 'Pemilik Lain', 'lain.coex@kedai.test', 'Kedai Lain', $remote, $host, 'other-owner');
$staffSession = [];
$staffIssued = $http->handle('GET', 'sesi', $staffSession, [], [], $remote, $host, 'staff-session');
$staffSession['csrf'] = $staffIssued['body']['csrf'];
post($http, $app, 'ahli', [
    'name' => 'Staf Aplikasi',
    'email' => 'staf.coex@kedai.test',
    'password' => 'staf-laluan-panjang',
    'role' => 'staff',
], $remote, $host, 'app-owner');
$staffLogin = post($http, $staffSession, 'masuk', [
    'email' => 'staf.coex@kedai.test',
    'password' => 'staf-laluan-panjang',
], $remote, $host, 'staff-session');
$staffDenied = catchStatus(function () use ($http, &$staffSession, $remote, $host): void {
    post($http, $staffSession, 'segera', ['sync_type' => 'history'], $remote, $host, 'staff-session');
});
$check('staff cannot authorize synchronization', $staffDenied === 403 && ($staffLogin['status'] ?? 0) === 200);

$script->calls = [];
$script->script = [oauthResponse($token)];
$started = post($http, $app, 'sambung', [], $remote, $host, 'app-owner');
$state = (string) ($started['body']['state'] ?? '');
post($http, $app, 'tukar', ['state' => $state, 'code' => 'coex-code-abcdefgh'], $remote, $host, 'app-owner');
$script->calls = [];
$script->script = [
    debugResponse($appId, $appWaba),
    ['status' => 200, 'json' => ['id' => $appWaba, 'name' => 'Kedai Aplikasi']],
    ['status' => 200, 'json' => ['data' => [[
        'id' => $appPhone,
        'display_phone_number' => '+60 12 300 0611',
        'status' => 'CONNECTED',
        'is_on_biz_app' => true,
        'platform_type' => 'CLOUD_API',
    ]]]],
];
$finished = post($http, $app, 'peristiwa', [
    'state' => $state,
    'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
    'waba_id' => $appWaba,
], $remote, $host, 'app-owner');
$finishedJson = json_encode($finished['body'], JSON_UNESCAPED_SLASHES);
$appRow = connectionRow($pdo, $appPhone);
$targets = '';
foreach ($script->calls as $call) {
    $targets .= $call['method'] . ' ' . $call['url'] . "\n";
}
$jobCount = (string) $pdo->query('SELECT COUNT(*) FROM coexistence_sync_jobs')->fetchColumn();
$check(
    'phase A onboarding binds coexistence without sync or subscription',
    is_array($appRow)
        && $appRow['webhook_state'] === 'not_subscribed'
        && $appRow['messaging_state'] === 'unverified'
        && $appRow['sync_contacts'] === 'pending'
        && $appRow['sync_history'] === 'pending'
        && (int) $appRow['deadline_open'] === 1
        && (string) ($finished['body']['connection']['webhook'] ?? '') === 'Belum dilanggan'
        && (string) ($finished['body']['connection']['messaging'] ?? '') === 'Belum'
        && $jobCount === '0'
        && !str_contains($targets, 'subscribed_apps')
        && !str_contains($targets, 'smb_app_data')
        && !str_contains($targets, '/register')
        && is_string($finishedJson)
        && !str_contains($finishedJson, $token)
        && !str_contains($finishedJson, $secret)
);

$script->calls = [];
$script->script = [oauthResponse($token . '-piawai')];
$stdStarted = post($http, $std, 'sambung', [], $remote, $host, 'std-owner');
$stdState = (string) ($stdStarted['body']['state'] ?? '');
post($http, $std, 'tukar', ['state' => $stdState, 'code' => 'std-code-abcdefgh'], $remote, $host, 'std-owner');
$script->calls = [];
$script->script = [
    debugResponse($appId, $stdWaba),
    ['status' => 200, 'json' => ['id' => $stdWaba, 'name' => 'Kedai Piawai']],
    ['status' => 200, 'json' => ['data' => [[
        'id' => $stdPhone,
        'display_phone_number' => '+1 555 000 1001',
        'status' => 'CONNECTED',
    ]]]],
    ['status' => 200, 'json' => ['success' => true]],
];
$stdFinished = post($http, $std, 'peristiwa', [
    'state' => $stdState,
    'event' => 'FINISH',
    'waba_id' => $stdWaba,
    'phone_number_id' => $stdPhone,
], $remote, $host, 'std-owner');
$stdTargets = '';
foreach ($script->calls as $call) {
    $stdTargets .= $call['url'] . "\n";
}
$stdRow = connectionRow($pdo, $stdPhone);
$check(
    'standard cloud api still subscribes and stays outside coexistence sync',
    is_array($stdRow)
        && $stdRow['webhook_state'] === 'subscribed'
        && $stdRow['sync_contacts'] === 'not_applicable'
        && $stdRow['sync_history'] === 'not_applicable'
        && $stdRow['sync_deadline_at'] === null
        && (string) ($stdFinished['body']['connection']['webhook'] ?? '') === 'Dilanggan'
        && str_contains($stdTargets, 'subscribed_apps')
        && !str_contains($stdTargets, 'smb_app_data')
);

$echoRaw = envelope($stdWaba, 'smb_message_echoes', [
    'metadata' => ['phone_number_id' => $stdPhone],
    'message_echoes' => [[
        'from' => '15550001001',
        'to' => '15550001009',
        'id' => 'wamid.ECHOSTANDARD0001',
        'timestamp' => '1739230955',
        'type' => 'text',
        'text' => ['body' => $phrase],
    ]],
]);
$graphCalls = count($script->calls);
ob_start();
$echoStored = signedIngest($webhook, $echoRaw, $secret);
$echoNoise = ob_get_clean();
$stdAfterEcho = connectionRow($pdo, $stdPhone);
$check(
    'message echoes do not verify standard messaging',
    $echoStored['stored'] === 1
        && is_array($stdAfterEcho)
        && $stdAfterEcho['messaging_state'] === 'unverified'
        && !str_contains((string) $echoNoise, $phrase)
        && !str_contains((string) $echoNoise, $token)
        && count($script->calls) === $graphCalls
);
$messageRaw = envelope($stdWaba, 'messages', [
    'metadata' => ['phone_number_id' => $stdPhone],
    'messages' => [[
        'from' => '15550001009',
        'id' => 'wamid.STANDARDMESSAGE01',
        'timestamp' => '1739231000',
        'type' => 'text',
        'text' => ['body' => 'Harga biasa'],
    ]],
]);
signedIngest($webhook, $messageRaw, $secret);
$statusRaw = envelope($stdWaba, 'messages', [
    'metadata' => ['phone_number_id' => $stdPhone],
    'statuses' => [[
        'id' => 'wamid.STANDARDMESSAGE01',
        'status' => 'delivered',
        'timestamp' => '1739231001',
        'recipient_id' => '15550001009',
    ]],
]);
signedIngest($webhook, $statusRaw, $secret);
$stdAfterMessage = connectionRow($pdo, $stdPhone);
$stdKinds = $pdo->query(
    'SELECT GROUP_CONCAT(event_kind ORDER BY id) FROM webhook_events WHERE connection_id = ' . (int) $stdRow['id']
)->fetchColumn();
$check(
    'standard message and status handling still verifies receipt',
    is_array($stdAfterMessage)
        && $stdAfterMessage['messaging_state'] === 'receive_verified'
        && is_string($stdKinds)
        && str_contains($stdKinds, 'message')
        && str_contains($stdKinds, 'status')
);

$tampered = $messageRaw . ' ';
$tamperHeader = 'sha256=' . hash_hmac('sha256', $messageRaw, $secret);
$beforeTamper = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$check('invalid webhook signature is rejected', WhatsAppWebhook::signatureMatches($tampered, $tamperHeader, $secret) === false);
$check('valid webhook signature matches', WhatsAppWebhook::signatureMatches($messageRaw, $tamperHeader, $secret) === true);
$check('rejected signature stores nothing', (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === $beforeTamper);

$added = envelope($appWaba, 'account_update', [
    'event' => 'PARTNER_ADDED',
    'access_token' => $plant,
]);
ob_start();
$addedResult = signedIngest($webhook, $added, $secret);
$addedNoise = (string) ob_get_clean();
$addedPlain = openPayload($pdo, $seal, latestEventId($pdo, 'account_update'));
$addedRow = $pdo->query('SELECT business_id, dedupe_key FROM webhook_events WHERE event_kind = \'account_update\' ORDER BY id DESC LIMIT 1')->fetch();
$check(
    'account update keeps the waba and drops the token',
    $addedResult['stored'] === 1
        && is_array($addedRow)
        && (int) $addedRow['business_id'] === (int) $appRow['business_id']
        && str_contains($addedPlain, $appWaba)
        && !str_contains($addedPlain, $plant)
        && !str_contains((string) $addedRow['dedupe_key'], $plant)
        && !str_contains($addedNoise, $plant)
        && count($script->calls) === $graphCalls
);

$historyPartial = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => $appPhone],
    'history' => [[
        'metadata' => ['phase' => 1, 'chunk_order' => 2, 'progress' => 40],
        'threads' => [[
            'id' => '60111111111',
            'messages' => [[
                'from' => '60111111111',
                'to' => '60123000611',
                'id' => 'wamid.HISTORYCHUNK0002',
                'timestamp' => '1739232002',
                'type' => 'text',
                'text' => ['body' => $phrase],
                'history_context' => ['status' => 'read'],
            ], [
                'from' => '60123000611',
                'id' => 'wamid.MEDIAPLACEHOLDER1',
                'timestamp' => '1739232003',
                'type' => 'media_placeholder',
                'history_context' => ['status' => 'delivered'],
            ]],
        ]],
    ]],
]);
$historyEarly = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => $appPhone],
    'history' => [[
        'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 15],
        'threads' => [[
            'id' => '60122222222',
            'messages' => [[
                'from' => '60122222222',
                'id' => 'wamid.HISTORYCHUNK0001',
                'timestamp' => '1739231001',
                'type' => 'text',
                'text' => ['body' => 'mesej awal'],
            ]],
        ]],
    ]],
]);
$contactsRaw = envelope($appWaba, 'smb_app_state_sync', [
    'metadata' => ['phone_number_id' => $appPhone],
    'state_sync' => [[
        'type' => 'contact',
        'contact' => ['full_name' => 'Kenalan Ujian', 'first_name' => 'Kenalan', 'phone_number' => '+60 12 345 6789'],
        'action' => 'add',
        'metadata' => ['timestamp' => '1739233000'],
    ]],
]);
$echoApp = envelope($appWaba, 'smb_message_echoes', [
    'metadata' => ['phone_number_id' => $appPhone],
    'message_echoes' => [[
        'from' => '60123000611',
        'to' => '60111111111',
        'id' => 'wamid.ECHOAPP000000001',
        'timestamp' => '1739234000',
        'type' => 'text',
        'text' => ['body' => $phrase],
    ]],
]);
ob_start();
$storedHistory = signedIngest($webhook, $historyPartial, $secret);
$replayHistory = signedIngest($webhook, $historyPartial, $secret);
$storedEarly = signedIngest($webhook, $historyEarly, $secret);
$storedContacts = signedIngest($webhook, $contactsRaw, $secret);
$storedEcho = signedIngest($webhook, $echoApp, $secret);
$eventNoise = (string) ob_get_clean();
$appAfterEvents = connectionRow($pdo, $appPhone);
$check(
    'each coexistence event is stored once and echoes do not verify messaging',
    $storedHistory['stored'] === 1
        && $replayHistory['replayed'] === 1
        && $storedEarly['stored'] === 1
        && $storedContacts['stored'] === 1
        && $storedEcho['stored'] === 1
        && is_array($appAfterEvents)
        && $appAfterEvents['messaging_state'] === 'unverified'
        && $appAfterEvents['sync_contacts'] === 'in_progress'
        && $appAfterEvents['sync_history'] === 'in_progress'
        && !str_contains($eventNoise, $phrase)
        && !str_contains($eventNoise, $token)
        && count($script->calls) === $graphCalls
);
$historyPlain = '';
$historyIds = $pdo->query('SELECT id FROM webhook_events WHERE event_kind = \'history\' AND business_id = ' . (int) $appRow['business_id'])->fetchAll();
foreach ($historyIds as $historyId) {
    if (is_array($historyId)) {
        $historyPlain .= openPayload($pdo, $seal, (int) $historyId['id']);
    }
}
$contactPlain = openPayload($pdo, $seal, latestEventId($pdo, 'smb_app_state_sync'));
$echoPlain = openPayload($pdo, $seal, latestEventId($pdo, 'smb_message_echoes'));
$check(
    'complete coexistence payloads stay sealed with nested data',
    str_contains($historyPlain, $phrase)
        && str_contains($historyPlain, '1739232002')
        && str_contains($historyPlain, '60111111111')
        && str_contains($historyPlain, 'wamid.MEDIAPLACEHOLDER1')
        && str_contains($historyPlain, 'mesej awal')
        && str_contains($contactPlain, 'Kenalan Ujian')
        && str_contains($echoPlain, $phrase)
        && !str_contains($historyPlain, $plant)
);
$unknown = envelope('100000000000999', 'history', [
    'metadata' => ['phone_number_id' => '100000000000999'],
    'history' => [[
        'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 1],
        'threads' => [[
            'id' => '60199999999',
            'messages' => [[
                'from' => '60199999999',
                'id' => 'wamid.UNKNOWNWABA00001',
                'timestamp' => '1739235000',
                'type' => 'text',
                'text' => ['body' => 'bukan tenant'],
            ]],
        ]],
    ]],
]);
$missingPhone = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => '100000000000998'],
    'history' => [[
        'metadata' => ['phase' => 0, 'chunk_order' => 3, 'progress' => 2],
        'threads' => [],
    ]],
]);
signedIngest($webhook, $unknown, $secret);
signedIngest($webhook, $missingPhone, $secret);
$unknownRow = $pdo->query('SELECT business_id, connection_id FROM webhook_events WHERE dedupe_key LIKE \'history:%\' ORDER BY id DESC LIMIT 1')->fetch();
$unassigned = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events WHERE business_id IS NULL AND event_kind = \'history\'')->fetchColumn();
$appUntouched = connectionRow($pdo, $appPhone);
$check(
    'unknown waba and unknown phone stay unscoped',
    $unassigned === 2
        && is_array($unknownRow)
        && $unknownRow['business_id'] === null
        && $unknownRow['connection_id'] === null
        && is_array($appUntouched)
        && $appUntouched['sync_history'] === 'in_progress'
);
$otherHistory = (int) $pdo->query(
    'SELECT COUNT(*) FROM webhook_events WHERE business_id = ' . businessId($pdo, 'Kedai Piawai') . ' AND event_kind = \'history\''
)->fetchColumn();
$check('coexistence history is not exposed to another tenant', $otherHistory === 0);

$notReady = catchStatus(function () use ($http, &$app, $remote, $host): void {
    post($http, $app, 'segera', ['sync_type' => 'smb_app_state_sync'], $remote, $host, 'app-owner');
});
$jobsAfterRefuse = (string) $pdo->query('SELECT COUNT(*) FROM coexistence_sync_jobs')->fetchColumn();
$check('sync waits for a subscribed webhook', $notReady === 409 && $jobsAfterRefuse === '0' && count($script->calls) === $graphCalls);
$pdo->prepare('UPDATE whatsapp_connections SET webhook_state = \'subscribed\', subscribed_at = UTC_TIMESTAMP() WHERE phone_number_id = ?')->execute([$appPhone]);
$authorized = post($http, $app, 'segera', ['sync_type' => 'smb_app_state_sync'], $remote, $host, 'app-owner');
$authorizedAgain = post($http, $app, 'segera', ['sync_type' => 'smb_app_state_sync'], $remote, $host, 'app-owner');
$check(
    'contact authorization is explicit and idempotent',
    (int) ($authorized['body']['sync']['job_id'] ?? 0) > 0
        && ($authorized['body']['sync']['status'] ?? '') === 'authorized'
        && ($authorized['body']['sync']['calls_graph'] ?? true) === false
        && ($authorizedAgain['body']['sync']['job_id'] ?? 0) === ($authorized['body']['sync']['job_id'] ?? -1)
        && count($script->calls) === $graphCalls
        && !str_contains((string) json_encode($authorized['body']), $token)
);
$contactJob = (int) $authorized['body']['sync']['job_id'];
$armedTransport = new ScriptedMeta();
$armedGraph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $armedTransport);
$armed = new CoexistenceSync($pdo, $armedGraph, $seal, true);
$unarmed = (new CoexistenceSync($pdo, $graph, $seal, false))->dispatch((int) $appRow['business_id'], $contactJob, time());
$check('unarmed dispatch makes no graph call', ($unarmed['status'] ?? '') === 'disabled' && ($unarmed['calls_graph'] ?? true) === false && $armedTransport->calls === [] && count($script->calls) === $graphCalls);
$tooSoon = catchStatus(function () use ($http, &$app, $remote, $host): void {
    post($http, $app, 'selesai-kenalan', [], $remote, $host, 'app-owner');
});
$armedTransport->script = [[
    'status' => 200,
    'json' => ['messaging_product' => 'whatsapp', 'request_id' => 'req-kenalan-1'],
]];
markReady($pdo, $appPhone);
$dispatched = $armed->dispatch((int) $appRow['business_id'], $contactJob, time());
$secondDispatch = $armed->dispatch((int) $appRow['business_id'], $contactJob, time());
$contactCall = $armedTransport->calls[0] ?? ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
$check(
    'contact sync posts once and does not mark itself complete',
    $tooSoon === 409
        && ($dispatched['status'] ?? '') === 'in_progress'
        && ($dispatched['calls_graph'] ?? false) === true
        && ($secondDispatch['calls_graph'] ?? true) === false
        && count($armedTransport->calls) === 1
        && $contactCall['method'] === 'POST'
        && str_contains($contactCall['url'], '/' . $appPhone . '/smb_app_data')
        && $contactCall['body'] === '{"messaging_product":"whatsapp","sync_type":"smb_app_state_sync"}'
        && ($contactCall['headers']['authorization'] ?? '') === 'Bearer ' . $token
);
$settled = post($http, $app, 'selesai-kenalan', [], $remote, $host, 'app-owner');
$appContacts = connectionRow($pdo, $appPhone);
$check(
    'contact sync completes only after an observed webhook',
    ($settled['body']['sync']['status'] ?? '') === 'completed'
        && is_array($appContacts)
        && $appContacts['sync_contacts'] === 'completed'
        && $appContacts['sync_history'] === 'in_progress'
);

$historyAuth = post($http, $app, 'segera', ['sync_type' => 'history'], $remote, $host, 'app-owner');
$historyJob = (int) ($historyAuth['body']['sync']['job_id'] ?? 0);
$historyTransport = new ScriptedMeta();
$historySync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $historyTransport), $seal, true);
$historyTransport->script = [[
    'status' => 200,
    'json' => ['messaging_product' => 'whatsapp', 'request_id' => 'req-sejarah-1'],
]];
$historyDispatch = $historySync->dispatch((int) $appRow['business_id'], $historyJob, time());
$phaseOnly = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => $appPhone],
    'history' => [[
        'metadata' => ['phase' => 2, 'chunk_order' => 4, 'progress' => 50],
        'threads' => [],
    ]],
]);
signedIngest($webhook, $phaseOnly, $secret);
$afterPhase = connectionRow($pdo, $appPhone);
$historyDone = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => $appPhone],
    'history' => [[
        'metadata' => ['phase' => 2, 'chunk_order' => 5, 'progress' => 100],
        'threads' => [],
    ]],
]);
signedIngest($webhook, $historyDone, $secret);
$lateChunk = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => $appPhone],
    'history' => [[
        'metadata' => ['phase' => 1, 'chunk_order' => 6, 'progress' => 20],
        'threads' => [[
            'id' => '60133333333',
            'messages' => [[
                'from' => '60133333333',
                'id' => 'wamid.LATECHUNK0000001',
                'timestamp' => '1739236000',
                'type' => 'text',
                'text' => ['body' => 'lewat'],
            ]],
        ]],
    ]],
]);
signedIngest($webhook, $lateChunk, $secret);
$afterHistory = connectionRow($pdo, $appPhone);
$historyJobStatus = (string) $pdo->query('SELECT status FROM coexistence_sync_jobs WHERE id = ' . $historyJob)->fetchColumn();
$check(
    'history sync completes at progress 100 and ignores a late chunk',
    ($historyDispatch['status'] ?? '') === 'in_progress'
        && count($historyTransport->calls) === 1
        && ($historyTransport->calls[0]['body'] ?? '') === '{"messaging_product":"whatsapp","sync_type":"history"}'
        && is_array($afterPhase)
        && $afterPhase['sync_history'] === 'in_progress'
        && is_array($afterHistory)
        && $afterHistory['sync_history'] === 'completed'
        && $historyJobStatus === 'completed'
);
$mediaSha = hash('sha256', 'rujukan-media');
$mediaRaw = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => $appPhone],
    'messages' => [[
        'id' => 'wamid.MEDIAPLACEHOLDER1',
        'timestamp' => '1739232003',
        'type' => 'image',
        'image' => ['id' => '123456789012345', 'sha256' => $mediaSha, 'url' => 'https://example.invalid/secret-media'],
    ]],
]);
$mediaPlainNoise = '';
ob_start();
signedIngest($webhook, $mediaRaw, $secret);
$mediaPlainNoise = (string) ob_get_clean();
$drained = $archive->drain(50);
$placeholder = $pdo->query('SELECT media_id, media_sha256, body_text, source_kind FROM coexistence_messages WHERE wamid = \'wamid.MEDIAPLACEHOLDER1\'')->fetch();
$textRow = $pdo->query('SELECT body_text, message_timestamp, from_phone, peer_phone FROM coexistence_messages WHERE wamid = \'wamid.HISTORYCHUNK0002\'')->fetch();
$earlyRow = $pdo->query('SELECT id FROM coexistence_messages WHERE wamid = \'wamid.HISTORYCHUNK0001\'')->fetch();
$echoRow = $pdo->query('SELECT from_phone, to_phone, source_kind FROM coexistence_messages WHERE wamid = \'wamid.ECHOAPP000000001\'')->fetch();
$contactRow = $pdo->query('SELECT full_name, phone_number, sync_action FROM coexistence_contacts WHERE phone_number = \'60123456789\'')->fetch();
$otherContacts = (int) $pdo->query('SELECT COUNT(*) FROM coexistence_contacts WHERE business_id = ' . businessId($pdo, 'Kedai Piawai'))->fetchColumn();
$mediaPlain = openPayload($pdo, $seal, latestEventId($pdo, 'history'));
$check(
    'archive keeps messages, contacts, and media references without inventing files',
    $drained > 0
        && is_array($placeholder)
        && $placeholder['media_id'] === '123456789012345'
        && $placeholder['media_sha256'] === $mediaSha
        && $placeholder['source_kind'] === 'history'
        && is_array($textRow)
        && $textRow['body_text'] === $phrase
        && $textRow['message_timestamp'] === '1739232002'
        && $textRow['from_phone'] === '60111111111'
        && is_array($earlyRow)
        && is_array($echoRow)
        && $echoRow['source_kind'] === 'echo'
        && $echoRow['to_phone'] === '60111111111'
        && is_array($contactRow)
        && $contactRow['full_name'] === 'Kenalan Ujian'
        && $contactRow['sync_action'] === 'add'
        && $otherContacts === 0
        && !str_contains($mediaPlain, 'secret-media')
        && !str_contains($mediaPlainNoise, $phrase)
);
$duplicateContact = envelope($appWaba, 'smb_app_state_sync', [
    'metadata' => ['phone_number_id' => $appPhone],
    'state_sync' => [[
        'type' => 'contact',
        'contact' => ['full_name' => 'Kenalan Ujian', 'first_name' => 'Kenalan', 'phone_number' => '60123456789'],
        'action' => 'add',
        'metadata' => ['timestamp' => '1739233000'],
    ], [
        'type' => 'contact',
        'contact' => ['phone_number' => '60123456789'],
        'action' => 'remove',
        'metadata' => ['timestamp' => '1739233001'],
    ]],
]);
signedIngest($webhook, $duplicateContact, $secret);
$archive->drain(50);
$contactCount = (int) $pdo->query('SELECT COUNT(*) FROM coexistence_contacts WHERE phone_number = \'60123456789\' AND sync_action = \'add\'')->fetchColumn();
$removed = (int) $pdo->query('SELECT COUNT(*) FROM coexistence_contacts WHERE phone_number = \'60123456789\' AND sync_action = \'remove\' AND full_name IS NULL')->fetchColumn();
$check('repeated contact sync does not duplicate the same change', $contactCount === 1 && $removed === 1);

$declineId = insertLink($pdo, $seal, businessId($pdo, 'Kedai Tolak'), '100000000000721', '100000000000621', '+60 12 300 0621', 'subscribed', 'future');
$declineAuth = post($http, $declineOwner, 'segera', ['sync_type' => 'history'], $remote, $host, 'decline-owner');
$declineJob = (int) ($declineAuth['body']['sync']['job_id'] ?? 0);
$declineTransport = new ScriptedMeta();
$declineSync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $declineTransport), $seal, true);
$declineTransport->script = [[
    'status' => 200,
    'json' => ['messaging_product' => 'whatsapp', 'request_id' => 'req-tolak-1'],
]];
markReady($pdo, '100000000000621');
$declineSync->dispatch(businessId($pdo, 'Kedai Tolak'), $declineJob, time());
$refusal = envelope('100000000000721', 'history', [
    'metadata' => ['phone_number_id' => '100000000000621'],
    'history' => [[
        'errors' => [[
            'code' => 2593109,
            'title' => 'History sync is turned off by the business from the WhatsApp Business App',
            'message' => 'History sync is turned off by the business from the WhatsApp Business App',
        ]],
    ]],
]);
signedIngest($webhook, $refusal, $secret);
$declined = connectionRow($pdo, '100000000000621');
$declineStatus = (string) $pdo->query('SELECT status FROM coexistence_sync_jobs WHERE id = ' . $declineJob)->fetchColumn();
$declineNote = (string) ($http->handle('GET', 'sesi', $declineOwner, [], [], $remote, $host, 'decline-owner')['body']['connection']['note'] ?? '');
signedIngest($webhook, envelope('100000000000721', 'history', [
    'metadata' => ['phone_number_id' => '100000000000621'],
    'history' => [['metadata' => ['phase' => 2, 'chunk_order' => 1, 'progress' => 100], 'threads' => []]],
]), $secret);
$stillDeclined = connectionRow($pdo, '100000000000621');
$check(
    'history sharing refusal is declined rather than a technical failure',
    $declineId > 0
        && is_array($declined)
        && $declined['sync_history'] === 'declined'
        && $declined['last_error_code'] === '2593109'
        && $declineStatus === 'declined'
        && $declineNote === 'Perniagaan tidak berkongsi sejarah sembang.'
        && is_array($stillDeclined)
        && $stillDeclined['sync_history'] === 'declined'
);

$pastId = insertLink($pdo, $seal, businessId($pdo, 'Kedai Tamat'), '100000000000731', '100000000000631', '+60 12 300 0631', 'subscribed', 'past');
$pastStatus = catchStatus(function () use ($http, &$deadlineOwner, $remote, $host): void {
    post($http, $deadlineOwner, 'segera', ['sync_type' => 'history'], $remote, $host, 'deadline-owner');
});
$pastRow = connectionRow($pdo, '100000000000631');
$pastJobs = (int) $pdo->query('SELECT COUNT(*) FROM coexistence_sync_jobs WHERE connection_id = ' . $pastId)->fetchColumn();
$check(
    'a closed 24 hour window refuses synchronization',
    $pastStatus === 409 && is_array($pastRow) && $pastRow['sync_history'] === 'unavailable' && $pastRow['sync_contacts'] === 'unavailable' && $pastJobs === 0
);
$retryBusiness = businessId($pdo, 'Kedai Cuba');
$retryConnection = insertLink($pdo, $seal, $retryBusiness, '100000000000741', '100000000000641', '+60 12 300 0641', 'subscribed', 'future');
$retryAuth = post($http, $retryOwner, 'segera', ['sync_type' => 'smb_app_state_sync'], $remote, $host, 'retry-owner');
$retryJob = (int) ($retryAuth['body']['sync']['job_id'] ?? 0);
$pdo->prepare('UPDATE whatsapp_connections SET sync_deadline_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?')->execute([$retryConnection]);
$retryTransport = new ScriptedMeta();
$retrySync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $retryTransport), $seal, true);
$expiredDispatch = $retrySync->dispatch($retryBusiness, $retryJob, time());
$expiredRow = connectionRow($pdo, '100000000000641');
$expiredJob = (string) $pdo->query('SELECT status FROM coexistence_sync_jobs WHERE id = ' . $retryJob)->fetchColumn();
$check(
    'dispatch after the deadline expires the job without a graph call',
    ($expiredDispatch['status'] ?? '') === 'expired'
        && ($expiredDispatch['calls_graph'] ?? true) === false
        && $retryTransport->calls === []
        && $expiredJob === 'expired'
        && is_array($expiredRow)
        && $expiredRow['sync_contacts'] === 'unavailable'
);

$failBusiness = businessId($pdo, 'Kedai Gagal');
insertLink($pdo, $seal, $failBusiness, '100000000000751', '100000000000651', '+60 12 300 0651', 'subscribed', 'future');
$failAuth = post($http, $failOwner, 'segera', ['sync_type' => 'history'], $remote, $host, 'fail-owner');
$failJob = (int) ($failAuth['body']['sync']['job_id'] ?? 0);
$failTransport = new ScriptedMeta();
$failSync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $failTransport), $seal, true);
$failTransport->script = [
    ['status' => 500, 'json' => ['error' => ['code' => 131000]]],
    ['status' => 200, 'json' => ['messaging_product' => 'whatsapp', 'request_id' => 'req-cuba-1']],
];
$now = time();
markReady($pdo, '100000000000651');
$firstFail = $failSync->dispatch($failBusiness, $failJob, $now);
$duringBackoff = $failSync->dispatch($failBusiness, $failJob, $now + 30);
$recovered = $failSync->dispatch($failBusiness, $failJob, $now + 61);
$attempt = $pdo->query('SELECT attempt_count, status, request_id FROM coexistence_sync_jobs WHERE id = ' . $failJob)->fetch();
$partialRow = connectionRow($pdo, '100000000000651');
$check(
    'a failed sync retries once and stays incomplete until webhooks arrive',
    ($firstFail['status'] ?? '') === 'authorized'
        && ($firstFail['calls_graph'] ?? false) === true
        && ($duringBackoff['calls_graph'] ?? true) === false
        && ($recovered['status'] ?? '') === 'in_progress'
        && count($failTransport->calls) === 2
        && is_array($attempt)
        && (int) $attempt['attempt_count'] === 1
        && $attempt['status'] === 'in_progress'
        && $attempt['request_id'] === 'req-cuba-1'
        && is_array($partialRow)
        && $partialRow['sync_history'] === 'in_progress'
);
$terminalBusiness = businessId($pdo, 'Kedai Putus');
$dropPhone = '100000000000661';
$dropWaba = '100000000000761';
$otherPhone = '100000000000662';
insertLink($pdo, $seal, $terminalBusiness, $dropWaba, $dropPhone, '+60 12 300 0661', 'subscribed', 'future');
insertLink($pdo, $seal, businessId($pdo, 'Kedai Lain'), $dropWaba, $otherPhone, '+60 12 300 0662', 'subscribed', 'future');
$ambiguous = envelope($dropWaba, 'account_update', ['event' => 'PARTNER_REMOVED', 'secret' => $plant]);
signedIngest($webhook, $ambiguous, $secret);
$ambiguousRow = $pdo->query('SELECT business_id FROM webhook_events WHERE event_kind = \'account_update\' ORDER BY id DESC LIMIT 1')->fetch();
$bothLive = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE waba_id = \'100000000000761\' AND token_state = \'verified\' AND disconnected_at IS NULL')->fetchColumn();
$check('ambiguous partner removal stays unscoped', is_array($ambiguousRow) && $ambiguousRow['business_id'] === null && $bothLive === 2);

$dropJobAuth = post($http, $dropOwner, 'segera', ['sync_type' => 'history'], $remote, $host, 'drop-owner');
$dropJob = (int) ($dropJobAuth['body']['sync']['job_id'] ?? 0);
$dropJobBusiness = (int) $pdo->query('SELECT business_id FROM coexistence_sync_jobs WHERE id = ' . $dropJob)->fetchColumn();
$check('sync authorization stays inside the caller tenant', $dropJob > 0 && $dropJob !== $failJob && $dropJobBusiness === $terminalBusiness);
$removed = envelope($dropWaba, 'account_update', [
    'event' => 'PARTNER_REMOVED',
    'phone_number' => '+60 12 300 0661',
    'disconnection_info' => ['reason' => 'ACCOUNT_DISCONNECTED', 'initiated_by' => 'USER'],
]);
ob_start();
$removedOnce = signedIngest($webhook, $removed, $secret);
$removedTwice = signedIngest($webhook, $removed, $secret);
$removeNoise = (string) ob_get_clean();
$dropped = connectionRow($pdo, $dropPhone);
$otherLive = connectionRow($pdo, $otherPhone);
$dropAudit = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action = \'rakan_diputuskan\' AND business_id = ' . $terminalBusiness)->fetchColumn();
$dropJobStatus = (string) $pdo->query('SELECT status FROM coexistence_sync_jobs WHERE id = ' . $dropJob)->fetchColumn();
$dropView = $http->handle('GET', 'sesi', $dropOwner, [], [], $remote, $host, 'drop-owner');
$delayed = envelope($dropWaba, 'account_update', [
    'event' => 'ACCOUNT_OFFBOARDED',
    'phone_number' => '60123000661',
]);
signedIngest($webhook, $delayed, $secret);
$auditAfterDelay = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action = \'rakan_diputuskan\' AND business_id = ' . $terminalBusiness)->fetchColumn();
$reconnect = envelope($dropWaba, 'account_update', [
    'event' => 'ACCOUNT_RECONNECTED',
    'phone_number' => '60123000661',
]);
signedIngest($webhook, $reconnect, $secret);
$afterReconnect = connectionRow($pdo, $dropPhone);
$check(
    'partner removal revokes only the matching tenant and can repeat safely',
    $removedOnce['stored'] === 1
        && $removedTwice['replayed'] === 1
        && is_array($dropped)
        && $dropped['token_state'] === 'revoked'
        && (int) $dropped['token_cleared'] === 1
        && $dropped['messaging_state'] === 'unverified'
        && (int) $dropped['disconnected'] === 1
        && $dropped['sync_history'] === 'unavailable'
        && $dropJobStatus === 'cancelled'
        && $dropAudit === 1
        && $auditAfterDelay === 1
        && is_array($otherLive)
        && $otherLive['token_state'] === 'verified'
        && (int) $otherLive['disconnected'] === 0
        && ($dropView['body']['connection']['linked'] ?? true) === false
        && ($dropView['body']['connection']['note'] ?? '') === 'Sambungan platform perniagaan diputuskan.'
        && is_array($afterReconnect)
        && $afterReconnect['token_state'] === 'revoked'
        && $afterReconnect['messaging_state'] === 'unverified'
        && !str_contains($removeNoise, $plant)
        && !str_contains($removeNoise, $token)
);

$terminalAuth = post($http, $otherOwner, 'segera', ['sync_type' => 'smb_app_state_sync'], $remote, $host, 'other-owner');
$terminalJob = (int) ($terminalAuth['body']['sync']['job_id'] ?? 0);
$terminalTransport = new ScriptedMeta();
$terminalSync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $terminalTransport), $seal, true);
$terminalTransport->script = [
    ['status' => 400, 'json' => ['error' => ['code' => 1, 'message' => $token . ' ' . $phrase]]],
    ['status' => 400, 'json' => ['error' => ['code' => 1]]],
    ['status' => 400, 'json' => ['error' => ['code' => 1]]],
];
$terminalNow = time();
markReady($pdo, $otherPhone);
$terminalSync->dispatch(businessId($pdo, 'Kedai Lain'), $terminalJob, $terminalNow);
$terminalSync->dispatch(businessId($pdo, 'Kedai Lain'), $terminalJob, $terminalNow + 61);
$third = $terminalSync->dispatch(businessId($pdo, 'Kedai Lain'), $terminalJob, $terminalNow + 122);
$fourth = $terminalSync->dispatch(businessId($pdo, 'Kedai Lain'), $terminalJob, $terminalNow + 183);
$terminalState = $pdo->query('SELECT status, attempt_count, last_error_code FROM coexistence_sync_jobs WHERE id = ' . $terminalJob)->fetch();
$terminalConnection = connectionRow($pdo, $otherPhone);
$check(
    'three technical failures become a terminal sync failure',
    ($third['status'] ?? '') === 'failed'
        && ($fourth['calls_graph'] ?? true) === false
        && count($terminalTransport->calls) === 3
        && is_array($terminalState)
        && $terminalState['status'] === 'failed'
        && (int) $terminalState['attempt_count'] === 3
        && $terminalState['last_error_code'] === '1'
        && is_array($terminalConnection)
        && $terminalConnection['sync_contacts'] === 'failed'
        && $terminalConnection['sync_history'] === 'pending'
);

$bulk = [];
for ($i = 0; $i < 320; $i++) {
    $bulk[] = [
        'from' => '60144444444',
        'id' => 'wamid.HUGEPAYLOAD' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
        'timestamp' => '1739237000',
        'type' => 'text',
        'text' => ['body' => 'MESAJ-BESAR-' . str_repeat('B', 3500)],
    ];
}
$huge = envelope($appWaba, 'history', [
    'metadata' => ['phone_number_id' => $appPhone],
    'history' => [[
        'metadata' => ['phase' => 2, 'chunk_order' => 9, 'progress' => 100],
        'threads' => [[
            'id' => '60144444444',
            'messages' => $bulk,
        ]],
    ]],
]);
$hugeResult = signedIngest($webhook, $huge, $secret);
$hugePlain = openPayload($pdo, $seal, latestEventId($pdo, 'history'));
$check(
    'an oversized coexistence payload is retained as metadata instead of being discarded',
    $hugeResult['stored'] === 1 && str_contains($hugePlain, '"truncated":true') && !str_contains($hugePlain, 'MESAJ-BESAR')
);

$cli = [];
exec('php ' . escapeshellarg(dirname(__DIR__) . '/bin/sync-coexistence.php') . ' 2>&1', $cli, $cliCode);
$cliText = implode("\n", $cli);
$check(
    'the sync command makes no live call',
    $cliCode === 0 && str_contains($cliText, 'Penyegerakan langsung dimatikan') && !str_contains($cliText, $token) && !str_contains($cliText, $phrase)
);

$worker = (string) file_get_contents(dirname(__DIR__) . '/bin/sync-coexistence.php');
$migrator = (string) file_get_contents(dirname(__DIR__) . '/bin/migrate.php');
$check(
    'production migrator does not apply the coexistence migration',
    !str_contains($migrator, '0002_coexistence') && !str_contains($worker, 'graph.facebook.com') && !str_contains($worker, 'smbAppData')
);

fwrite(STDOUT, $failures === 0 ? "PASS\n" : "FAIL {$failures}\n");
exit($failures === 0 ? 0 : 1);
