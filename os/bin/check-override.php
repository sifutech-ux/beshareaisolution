<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\CoexistenceSync;
use BesharOs\MetaCallException;
use BesharOs\MetaGraph;
use BesharOs\MetaTransport;
use BesharOs\PhoneOverride;
use BesharOs\PhoneOverrideGate;
use BesharOs\RateLimit;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;
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

/** @param array<string,string> $env @param list<string> $args @return array{0:int,1:string} */
function runCli(array $env, array $args = []): array
{
    $command = 'env';
    foreach ([
        PhoneOverrideGate::ENV,
        'BESHARE_COEXISTENCE_SYNC_ENABLED',
        'BESHARE_OS_TOKEN_KEY',
        'BESHARE_OS_TOKEN_KEY_VERSION',
        'BESHARE_OS_WEBHOOK_CONFIG',
        'BESHARE_OS_DB_CONFIG',
        'BESHARE_OS_DSN',
        'BESHARE_OS_DB_USER',
        'BESHARE_OS_DB_PASSWORD',
    ] as $name) {
        $command .= ' -u ' . $name;
    }
    foreach ($env as $key => $value) {
        $command .= ' ' . $key . '=' . escapeshellarg($value);
    }
    $command .= ' php ' . escapeshellarg(__DIR__ . '/phone-override.php');
    foreach ($args as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $command .= ' 2>&1';
    $output = [];
    exec($command, $output, $code);
    return [$code, implode("\n", $output)];
}

function catchStatus(callable $action): int
{
    try {
        $action();
    } catch (AuthException $error) {
        return $error->status();
    } catch (MetaCallException $error) {
        return $error->httpStatus;
    }
    return 0;
}

/** @param list<array{method:string,url:string,body:string}> $calls */
function postCount(array $calls): int
{
    $count = 0;
    foreach ($calls as $call) {
        if ($call['method'] === 'POST') {
            $count++;
        }
    }
    return $count;
}

/** @param list<array{method:string,url:string,body:string}> $calls */
function forbiddenGraph(array $calls): bool
{
    foreach ($calls as $call) {
        if ($call['method'] === 'DELETE') {
            return true;
        }
        if (str_contains($call['url'], 'subscribed_apps') && $call['method'] !== 'GET') {
            return true;
        }
    }
    return false;
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

/** @return array{status:int,json:array<string,mixed>} */
function appsResponse(string $appId, string $override = '', bool $present = true): array
{
    $data = [[
        'whatsapp_business_api_data' => ['id' => '888888888888888', 'name' => 'Aplikasi lain'],
        'override_callback_uri' => 'https://example.test/lain',
    ]];
    if ($present) {
        $row = ['whatsapp_business_api_data' => ['id' => $appId, 'name' => 'BeShare Ai']];
        if ($override !== '') {
            $row['override_callback_uri'] = $override;
        }
        $data[] = $row;
    }
    return ['status' => 200, 'json' => ['data' => $data]];
}

/** @return array{status:int,json:array<string,mixed>} */
function phoneResponse(string $phoneId, string $phoneUrl, string $wabaUrl = '', string $application = PhoneOverride::APP_CALLBACK): array
{
    return ['status' => 200, 'json' => [
        'id' => $phoneId,
        'webhook_configuration' => [
            'application' => $application,
            'whatsapp_business_account' => $wabaUrl,
            'phone_number' => $phoneUrl,
        ],
    ]];
}

/** @return array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} */
function actor(int $userId, int $businessId, string $role = 'owner'): array
{
    return [
        'user_id' => $role === 'owner' ? $userId : $userId + 100000,
        'business_id' => $businessId,
        'role' => $role,
        'owner_user_id' => $userId,
        'status' => 'active',
    ];
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
    $server->exec('DROP DATABASE IF EXISTS override_lab');
    $server->exec('CREATE DATABASE override_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE override_lab');
    foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql'] as $name) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/' . $name);
        foreach (SqlScript::statements($sql) as $statement) {
            $server->exec($statement);
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=override_lab;charset=utf8mb4', 'root', $password, [
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

function insertLink(PDO $pdo, TokenSeal $seal, int $businessId, string $waba, string $phone, string $display, string $webhook, bool $coexistence, string $token): int
{
    $sealed = $seal->seal($token, 100);
    $insert = $pdo->prepare(
        'INSERT INTO whatsapp_connections (
            business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
            registration_state, messaging_state, sync_contacts, sync_history, token_kind,
            token_ciphertext, token_nonce, token_tag, token_key_version, refresh_supported,
            subscribed_at, created_at, updated_at, onboarded_at, sync_deadline_at
         ) VALUES (
            ?, ?, ?, ?, \'verified\', ?, \'registered\', \'unverified\',
            CASE WHEN ? = 1 THEN \'pending\' ELSE \'not_applicable\' END,
            CASE WHEN ? = 1 THEN \'pending\' ELSE \'not_applicable\' END,
            \'system_user\',
            UNHEX(?), UNHEX(?), UNHEX(?), ?, 0,
            CASE WHEN ? = \'subscribed\' THEN UTC_TIMESTAMP() ELSE NULL END,
            UTC_TIMESTAMP(), UTC_TIMESTAMP(),
            CASE WHEN ? = 1 THEN UTC_TIMESTAMP() ELSE NULL END,
            CASE WHEN ? = 1 THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) ELSE NULL END
         )'
    );
    $flag = $coexistence ? 1 : 0;
    $insert->execute([
        $businessId,
        $waba,
        $phone,
        $display,
        $webhook,
        $flag,
        $flag,
        bin2hex($sealed['ciphertext']),
        bin2hex($sealed['nonce']),
        bin2hex($sealed['tag']),
        $sealed['version'],
        $webhook,
        $flag,
        $flag,
    ]);
    return (int) $pdo->lastInsertId();
}

/** @return array{webhook_state:string,callback:?string,fields:?string,token_state:string,disconnected:int,messaging_state:string} */
function linkState(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(
        'SELECT webhook_state, callback_ready_at, fields_ready_at, token_state, messaging_state,
                disconnected_at IS NOT NULL AS disconnected
         FROM whatsapp_connections WHERE id = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return ['webhook_state' => '', 'callback' => '', 'fields' => '', 'token_state' => '', 'disconnected' => 0, 'messaging_state' => ''];
    }
    return [
        'webhook_state' => (string) $row['webhook_state'],
        'callback' => is_string($row['callback_ready_at'] ?? null) ? (string) $row['callback_ready_at'] : null,
        'fields' => is_string($row['fields_ready_at'] ?? null) ? (string) $row['fields_ready_at'] : null,
        'token_state' => (string) $row['token_state'],
        'disconnected' => (int) $row['disconnected'],
        'messaging_state' => (string) $row['messaging_state'],
    ];
}

$root = dirname(__DIR__, 2);
$cliSource = (string) file_get_contents(__DIR__ . '/phone-override.php');
$serviceSource = (string) file_get_contents(dirname(__DIR__) . '/src/PhoneOverride.php');
$graphSource = (string) file_get_contents(dirname(__DIR__) . '/src/MetaGraph.php');
$gatewaySource = (string) file_get_contents(dirname(__DIR__) . '/src/WhatsAppGateway.php');
$legacy = (string) file_get_contents($root . '/webhook/index.php');
$osHook = (string) file_get_contents($root . '/whatsapp/sambungan/webhook.php');
$bindAt = strpos($gatewaySource, 'private function bind(');
$coexistAt = strpos($gatewaySource, 'private function bindCoexistence(');
$eligibleAt = strpos($gatewaySource, 'private function eligibleCoexistencePhones(');
$coexistBind = is_int($coexistAt) && is_int($eligibleAt) ? substr($gatewaySource, $coexistAt, $eligibleAt - $coexistAt) : '';
$standardBind = is_int($bindAt) && is_int($coexistAt) ? substr($gatewaySource, $bindAt, $coexistAt - $bindAt) : '';
$gateAt = strpos($cliSource, 'PhoneOverrideGate::enabled()');
$bootAt = strpos($cliSource, "require dirname(__DIR__) . '/src/bootstrap.php'");
$check(
    'override command stays disabled before bootstrap',
    is_int($gateAt) && is_int($bootAt) && $gateAt < $bootAt
        && str_contains($cliSource, 'Panggilan telefon dimatikan. Tiada panggilan Meta.')
        && substr_count($cliSource, 'new StreamMetaTransport()') === 1
        && !str_contains($cliSource, '->subscribe(')
        && !str_contains($cliSource, 'DELETE')
);
$check(
    'phone override service does not post or delete a WABA subscription',
    !str_contains($serviceSource, 'subscribed_apps')
        && !str_contains($serviceSource, 'DELETE')
        && !str_contains($serviceSource, '->subscribe(')
        && str_contains($graphSource, 'function setPhoneOverride')
        && str_contains($graphSource, 'function clearPhoneOverride')
        && str_contains($graphSource, 'function subscribedApps')
        && !str_contains($graphSource, 'DELETE')
        && !str_contains($graphSource, '/register')
        && str_contains($standardBind, '$this->graph->subscribe(')
        && !str_contains($coexistBind, 'subscribed_apps')
        && !str_contains($coexistBind, 'graph->subscribe')
);
$check(
    'existing callback files do not gain a relay',
    !str_contains($legacy, 'ingestVerifiedLifecycle')
        && !str_contains($legacy, 'PhoneOverride')
        && str_contains($osHook, '->ingest($raw)')
        && !str_contains($osHook, 'ingestVerifiedLifecycle')
        && !str_contains((string) file_get_contents($root . '/whatsapp/akaun/pintu.php'), 'PhoneOverride')
);

$secretPlant = 'rahsia-panggilan-log';
$closed = [
    'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
    'BESHARE_OS_DB_USER' => 'x',
    'BESHARE_OS_DB_PASSWORD' => $secretPlant,
];
[$disabledCode, $disabledOut] = runCli($closed, ['inspect', '--connection=1', '--owner=1']);
$check(
    'default phone override stays disabled and does not connect',
    $disabledCode === 0 && str_contains($disabledOut, 'Panggilan telefon dimatikan') && !str_contains($disabledOut, $secretPlant)
);
foreach (['TRUE', '1', 'yes', 'false', ''] as $flag) {
    [$code, $out] = runCli([
        PhoneOverrideGate::ENV => $flag,
        'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
        'BESHARE_OS_DB_PASSWORD' => $secretPlant,
    ], ['apply', '--confirm', '--connection=1', '--owner=1']);
    $label = $flag === '' ? 'empty' : $flag;
    $check(
        'malformed override flag ' . $label . ' stays disabled',
        $code === 0 && str_contains($out, 'Panggilan telefon dimatikan') && !str_contains($out, $secretPlant)
    );
}
[$bareCode, $bareOut] = runCli($closed);
$check('an unknown override command makes no call', $bareCode === 0 && str_contains($bareOut, 'Panggilan telefon dimatikan') && !str_contains($bareOut, $secretPlant));
[$refusedCode, $refusedOut] = runCli([
    PhoneOverrideGate::ENV => 'true',
    'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
    'BESHARE_OS_DB_PASSWORD' => $secretPlant,
], ['apply', '--connection=1', '--owner=1']);
$check(
    'apply without confirmation does not connect',
    $refusedCode === 1 && str_contains($refusedOut, 'Panggilan tidak diubah') && !str_contains($refusedOut, $secretPlant)
);
[$noteCode, $noteOut] = runCli($closed, ['note-subscribed', '--connection=1', '--owner=1']);
$check(
    'subscription note without confirmation does not connect',
    $noteCode === 1 && str_contains($noteOut, 'Panggilan tidak diubah') && !str_contains($noteOut, $secretPlant)
);
[$missingCode, $missingOut] = runCli([
    PhoneOverrideGate::ENV => 'true',
    'BESHARE_OS_WEBHOOK_CONFIG' => '/tmp/beshare-missing-override-config.php',
    'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
    'BESHARE_OS_DB_PASSWORD' => $secretPlant,
], ['inspect', '--connection=1', '--owner=1']);
$check(
    'enabled override without private config makes no call',
    $missingCode === 1 && str_contains($missingOut, 'Konfigurasi pelayan tidak lengkap') && !str_contains($missingOut, $secretPlant)
);
$check('override accepts only the exact true flag', PhoneOverrideGate::enabled('true') && PhoneOverrideGate::enabled('TRUE') === false && PhoneOverrideGate::enabled(null) === false);

$pdo = lab();
if (!$pdo instanceof PDO) {
    fwrite(STDOUT, "FAIL lab database\n");
    exit(1);
}
$check('override tests use an isolated lab', (string) $pdo->query('SELECT DATABASE()')->fetchColumn() === 'override_lab');

$appId = BESHARE_META_APP_ID;
$secret = 'tandatangan-panggilan';
$access = 'EAA-panggilan-makmal';
$verify = 'pengesah-makmal-satu';
$osUrl = PhoneOverride::OS_CALLBACK;
$seal = new TokenSeal(random_bytes(32), 1);
$rates = new RateLimit(sys_get_temp_dir() . '/beshare-os-override-rate-' . bin2hex(random_bytes(4)), 40, 900);
$http = new AuthHttp($pdo, $rates, new WhatsAppGateway($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, new ScriptedMeta()), $seal, $appId));
$session = [];
$issued = $http->handle('GET', 'sesi', $session, [], [], '127.0.0.1', 'lab.test', 'override-owner');
$session['csrf'] = $issued['body']['csrf'];
$http->handle('POST', 'daftar', $session, [
    'name' => 'Pemilik Panggilan',
    'email' => 'panggilan.coex@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Panggilan',
], ['x-csrf-token' => $session['csrf']], '127.0.0.1', 'lab.test', 'override-owner');
$other = [];
$otherIssued = $http->handle('GET', 'sesi', $other, [], [], '127.0.0.1', 'lab.test', 'override-other');
$other['csrf'] = $otherIssued['body']['csrf'];
$http->handle('POST', 'daftar', $other, [
    'name' => 'Pemilik Jiran',
    'email' => 'jiran.coex@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Jiran',
], ['x-csrf-token' => $other['csrf']], '127.0.0.1', 'lab.test', 'override-other');
$businessId = (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Panggilan\'')->fetchColumn();
$otherBusiness = (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Jiran\'')->fetchColumn();
$ownerId = (int) $pdo->query('SELECT id FROM users WHERE email = \'panggilan.coex@kedai.test\'')->fetchColumn();
$otherOwner = (int) $pdo->query('SELECT id FROM users WHERE email = \'jiran.coex@kedai.test\'')->fetchColumn();
$owner = actor($ownerId, $businessId);
$foreign = actor($otherOwner, $otherBusiness);
$staff = actor($ownerId, $businessId, 'staff');

$bare = new MetaGraph($appId, $secret, BESHARE_META_GRAPH);
$disabledGraph = catchStatus(static function () use ($bare): void {
    $bare->subscribedApps('token', '100000000001001');
});
$disabledPhone = catchStatus(static function () use ($bare): void {
    $bare->webhookConfiguration('token', '100000000001002');
});
$disabledSet = catchStatus(static function () use ($bare, $osUrl, $verify): void {
    $bare->setPhoneOverride('token', '100000000001002', $osUrl, $verify);
});
$disabledClear = catchStatus(static function () use ($bare): void {
    $bare->clearPhoneOverride('token', '100000000001002');
});
$check('override graph methods stay disabled without an injected transport', $disabledGraph === 409 && $disabledPhone === 409 && $disabledSet === 409 && $disabledClear === 409);

$subscribeTransport = new ScriptedMeta();
$subscribeTransport->script = [['status' => 200, 'json' => ['success' => true]]];
$subscribed = (new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $subscribeTransport))->subscribe($access, '100000000001001');
$subscribeCall = $subscribeTransport->calls[0] ?? ['method' => '', 'url' => '', 'body' => ''];
$check(
    'standard Cloud API subscription still posts an empty body',
    $subscribed === true
        && $subscribeCall['method'] === 'POST'
        && str_ends_with($subscribeCall['url'], '/100000000001001/subscribed_apps')
        && $subscribeCall['body'] === ''
        && !str_contains($subscribeCall['body'], 'webhook_configuration')
);

$cloudPhone = '100000000001111';
$cloudWaba = '100000000001011';
$cloudId = insertLink($pdo, $seal, $businessId, $cloudWaba, $cloudPhone, '+60 12 700 0111', 'subscribed', false, $access);
$webhook = new WhatsAppWebhook($pdo, $seal);
$cloudRaw = envelope($cloudWaba, 'messages', [
    'messaging_product' => 'whatsapp',
    'metadata' => ['phone_number_id' => $cloudPhone],
    'messages' => [[
        'from' => '60129999999',
        'id' => 'wamid.AWAN000000000001',
        'timestamp' => '1710000100',
        'type' => 'text',
        'text' => ['body' => 'mesej-awan'],
    ]],
]);
$beforeCloud = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$rejectedCloud = catchStatus(function () use ($webhook, $cloudRaw, $secret): void {
    $webhook->ingestVerifiedLifecycle($cloudRaw, $secret, 'sha256=' . str_repeat('ab', 32));
});
$rejectedField = catchStatus(function () use ($webhook, $cloudRaw, $secret): void {
    $webhook->ingestVerifiedLifecycle($cloudRaw, $secret, 'sha256=' . hash_hmac('sha256', $cloudRaw, $secret));
});
$cloudStored = $webhook->ingest($cloudRaw);
$cloudState = linkState($pdo, $cloudId);
$cloudEvents = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events WHERE event_kind = \'message\' AND connection_id = ' . $cloudId)->fetchColumn();
$check(
    'existing Cloud API message handling still verifies a subscribed connection',
    $rejectedCloud === 403
        && $rejectedField === 400
        && (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === $beforeCloud + 1
        && $cloudStored['stored'] === 1
        && $cloudEvents === 1
        && $cloudState['messaging_state'] === 'receive_verified'
        && $cloudState['webhook_state'] === 'subscribed'
);

$mainPhone = '100000000001211';
$mainWaba = '100000000001021';
$mainId = insertLink($pdo, $seal, $businessId, $mainWaba, $mainPhone, '+60 12 700 0211', 'not_subscribed', true, $access);
$idle = new ScriptedMeta();
$unarmed = new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $idle), $seal, $appId, false);
$unarmedResult = $unarmed->apply($owner, $mainId, $verify);
$check('an unarmed override returns disabled before any graph call', ($unarmedResult['status'] ?? '') === 'disabled' && ($unarmedResult['calls_graph'] ?? true) === false && $idle->calls === []);

$staffTransport = new ScriptedMeta();
$staffStatus = catchStatus(function () use ($pdo, $staffTransport, $seal, $appId, $secret, $staff, $mainId): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $staffTransport), $seal, $appId, true))->inspect($staff, $mainId);
});
$foreignTransport = new ScriptedMeta();
$foreignStatus = catchStatus(function () use ($pdo, $foreignTransport, $seal, $appId, $secret, $foreign, $mainId): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $foreignTransport), $seal, $appId, true))->inspect($foreign, $mainId);
});
$standardTransport = new ScriptedMeta();
$standardStatus = catchStatus(function () use ($pdo, $standardTransport, $seal, $appId, $secret, $owner, $cloudId): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $standardTransport), $seal, $appId, true))->inspect($owner, $cloudId);
});
$check(
    'staff, another tenant, and a standard connection are rejected before graph',
    $staffStatus === 403
        && $foreignStatus === 404
        && $standardStatus === 404
        && $staffTransport->calls === []
        && $foreignTransport->calls === []
        && $standardTransport->calls === []
);

$protectedDisplay = insertLink($pdo, $seal, $businessId, '100000000001031', '100000000001311', '01154320018', 'not_subscribed', true, $access);
$protectedInternational = insertLink($pdo, $seal, $businessId, '100000000001032', '100000000001312', '+60 11 5432 0018', 'not_subscribed', true, $access);
$protectedGraph = insertLink($pdo, $seal, $businessId, '100000000001033', '1443799975474056', '+60 12 700 0999', 'not_subscribed', true, $access);
$protectedTransport = new ScriptedMeta();
$protectedOverride = new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $protectedTransport), $seal, $appId, true);
$protectedStatuses = [
    catchStatus(function () use ($protectedOverride, $owner, $protectedDisplay, $verify): void {
        $protectedOverride->apply($owner, $protectedDisplay, $verify);
    }),
    catchStatus(function () use ($protectedOverride, $owner, $protectedInternational, $verify): void {
        $protectedOverride->apply($owner, $protectedInternational, $verify);
    }),
    catchStatus(function () use ($protectedOverride, $owner, $protectedGraph, $verify): void {
        $protectedOverride->apply($owner, $protectedGraph, $verify);
    }),
];
$check('protected numbers are rejected before graph', $protectedStatuses === [409, 409, 409] && $protectedTransport->calls === []);

$conflictPhone = '100000000001411';
$conflictId = insertLink($pdo, $seal, $businessId, '100000000001041', $conflictPhone, '+60 12 700 0411', 'not_subscribed', true, $access);
$conflictTransport = new ScriptedMeta();
$conflictTransport->script = [
    appsResponse($appId),
    phoneResponse($conflictPhone, 'https://example.test/sedia'),
];
$conflictStatus = catchStatus(function () use ($pdo, $conflictTransport, $seal, $appId, $secret, $owner, $conflictId, $verify): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $conflictTransport), $seal, $appId, true))->apply($owner, $conflictId, $verify);
});
$wabaConflict = new ScriptedMeta();
$wabaConflict->script = [appsResponse($appId, 'https://example.test/waba')];
$wabaStatus = catchStatus(function () use ($pdo, $wabaConflict, $seal, $appId, $secret, $owner, $conflictId, $verify): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $wabaConflict), $seal, $appId, true))->apply($owner, $conflictId, $verify);
});
$wabaUrlConflict = new ScriptedMeta();
$wabaUrlConflict->script = [
    appsResponse($appId),
    phoneResponse($conflictPhone, '', 'https://example.test/waba-url'),
];
$wabaUrlStatus = catchStatus(function () use ($pdo, $wabaUrlConflict, $seal, $appId, $secret, $owner, $conflictId, $verify): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $wabaUrlConflict), $seal, $appId, true))->apply($owner, $conflictId, $verify);
});
$missingApp = new ScriptedMeta();
$missingApp->script = [appsResponse($appId, '', false)];
$missingStatus = catchStatus(function () use ($pdo, $missingApp, $seal, $appId, $secret, $owner, $conflictId, $verify): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $missingApp), $seal, $appId, true))->apply($owner, $conflictId, $verify);
});
$movedApp = new ScriptedMeta();
$movedApp->script = [
    appsResponse($appId),
    phoneResponse($conflictPhone, '', '', $osUrl),
];
$movedStatus = catchStatus(function () use ($pdo, $movedApp, $seal, $appId, $secret, $owner, $conflictId, $verify): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $movedApp), $seal, $appId, true))->apply($owner, $conflictId, $verify);
});
$check(
    'existing or unknown overrides are rejected without a post',
    $conflictStatus === 409
        && $wabaStatus === 409
        && $wabaUrlStatus === 409
        && $missingStatus === 409
        && $movedStatus === 409
        && postCount($conflictTransport->calls) === 0
        && postCount($wabaConflict->calls) === 0
        && postCount($wabaUrlConflict->calls) === 0
        && postCount($missingApp->calls) === 0
        && postCount($movedApp->calls) === 0
        && forbiddenGraph(array_merge($conflictTransport->calls, $wabaConflict->calls, $wabaUrlConflict->calls, $missingApp->calls, $movedApp->calls)) === false
);

$foreignRollback = new ScriptedMeta();
$foreignRollback->script = [
    appsResponse($appId),
    phoneResponse($conflictPhone, 'https://example.test/sedia'),
];
$foreignRollbackStatus = catchStatus(function () use ($pdo, $foreignRollback, $seal, $appId, $secret, $owner, $conflictId): void {
    (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $foreignRollback), $seal, $appId, true))->rollback($owner, $conflictId);
});
$check('rollback leaves a foreign phone override untouched', $foreignRollbackStatus === 409 && postCount($foreignRollback->calls) === 0);

$expectedBody = json_encode([
    'webhook_configuration' => [
        'override_callback_uri' => $osUrl,
        'verify_token' => $verify,
    ],
], JSON_UNESCAPED_SLASHES);
$clearBody = '{"webhook_configuration":{"override_callback_uri":""}}';
$transport = new ScriptedMeta();
$transport->script = [
    appsResponse($appId),
    phoneResponse($mainPhone, '', '', 'https://beshareaisolution.com/webhook'),
];
$override = new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $transport), $seal, $appId, true);
$inspected = $override->inspect($owner, $mainId);
$transport->script = [
    appsResponse($appId),
    phoneResponse($mainPhone, ''),
    ['status' => 200, 'json' => ['success' => true]],
    phoneResponse($mainPhone, $osUrl),
];
$applied = $override->apply($owner, $mainId, $verify);
$afterApply = linkState($pdo, $mainId);
$post = null;
foreach ($transport->calls as $call) {
    if ($call['method'] === 'POST') {
        $post = $call;
    }
}
$urls = array_map(static fn (array $call): string => $call['method'] . ' ' . $call['url'], $transport->calls);
$check(
    'phone override posts only the documented phone configuration',
    ($inspected['status'] ?? '') === 'inspected'
        && ($inspected['calls_graph'] ?? false) === true
        && ($applied['status'] ?? '') === 'configured'
        && ($applied['calls_graph'] ?? false) === true
        && is_array($post)
        && $post['method'] === 'POST'
        && str_ends_with($post['url'], '/v26.0/' . $mainPhone)
        && $post['body'] === $expectedBody
        && ($post['headers']['authorization'] ?? '') === 'Bearer ' . $access
        && ($post['headers']['content-type'] ?? '') === 'application/json'
        && $urls[0] === 'GET https://graph.facebook.com/v26.0/' . $mainWaba . '/subscribed_apps'
        && str_contains($urls[1], '/v26.0/' . $mainPhone . '?fields=webhook_configuration')
        && $afterApply['webhook_state'] === 'not_subscribed'
        && $afterApply['callback'] === null
        && $afterApply['fields'] === null
        && postCount($transport->calls) === 1
        && forbiddenGraph($transport->calls) === false
);
$readyStatus = catchStatus(function () use ($pdo, $mainId): void {
    CoexistenceSync::record($pdo, $mainId, 'callback');
});
$noteStatus = catchStatus(function () use ($override, $owner, $mainId): void {
    $override->noteSubscribed($owner, $mainId);
});
$afterReject = linkState($pdo, $mainId);
$check(
    'graph success does not mark webhook readiness',
    $readyStatus === 409
        && $noteStatus === 409
        && $afterReject['webhook_state'] === 'not_subscribed'
        && $afterReject['callback'] === null
        && $afterReject['fields'] === null
        && postCount($transport->calls) === 1
);

$beforeAlready = count($transport->calls);
$transport->script = [
    appsResponse($appId),
    phoneResponse($mainPhone, $osUrl),
];
$already = $override->apply($owner, $mainId, $verify);
$check(
    'an override that already matches is not posted again',
    ($already['status'] ?? '') === 'already_set' && count($transport->calls) === $beforeAlready + 2 && postCount(array_slice($transport->calls, $beforeAlready)) === 0
);

$transport->script = [
    appsResponse($appId),
    phoneResponse($mainPhone, $osUrl),
    ['status' => 200, 'json' => ['success' => true]],
    phoneResponse($mainPhone, ''),
];
$rolled = $override->rollback($owner, $mainId);
$clear = null;
foreach (array_slice($transport->calls, $beforeAlready + 2) as $call) {
    if ($call['method'] === 'POST') {
        $clear = $call;
    }
}
$afterRollback = linkState($pdo, $mainId);
$check(
    'rollback clears only the targeted phone override',
    ($rolled['status'] ?? '') === 'cleared'
        && is_array($clear)
        && $clear['body'] === $clearBody
        && str_ends_with($clear['url'], '/v26.0/' . $mainPhone)
        && ($clear['headers']['authorization'] ?? '') === 'Bearer ' . $access
        && $afterRollback['webhook_state'] === 'not_subscribed'
        && forbiddenGraph($transport->calls) === false
);
$beforeClear = count($transport->calls);
$transport->script = [
    appsResponse($appId),
    phoneResponse($mainPhone, ''),
];
$alreadyClear = $override->rollback($owner, $mainId);
$check(
    'a clear phone override is not posted again',
    ($alreadyClear['status'] ?? '') === 'already_clear' && postCount(array_slice($transport->calls, $beforeClear)) === 0
);

$unverifiedPhone = '100000000001511';
$unverifiedId = insertLink($pdo, $seal, $businessId, '100000000001051', $unverifiedPhone, '+60 12 700 0511', 'not_subscribed', true, $access);
$unverifiedTransport = new ScriptedMeta();
$unverifiedTransport->script = [
    appsResponse($appId),
    phoneResponse($unverifiedPhone, ''),
    ['status' => 200, 'json' => ['success' => true]],
    phoneResponse($unverifiedPhone, ''),
];
$unverified = (new PhoneOverride($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $unverifiedTransport), $seal, $appId, true))->apply($owner, $unverifiedId, $verify);
$unverifiedState = linkState($pdo, $unverifiedId);
$check(
    'a success flag without the inspected URL stays unverified',
    ($unverified['status'] ?? '') === 'unverified'
        && ($unverified['webhook_state'] ?? '') === 'not_subscribed'
        && $unverifiedState['webhook_state'] === 'not_subscribed'
        && $unverifiedState['callback'] === null
);

$echoRaw = envelope($mainWaba, 'smb_message_echoes', [
    'metadata' => ['phone_number_id' => $mainPhone],
    'message_echoes' => [[
        'from' => '60127000211',
        'to' => '60120000001',
        'id' => 'wamid.BUKTI.PANGGILAN1',
        'timestamp' => '1710000200',
        'type' => 'text',
        'text' => ['body' => 'bukti-gema-panggilan'],
    ]],
]);
$badEcho = catchStatus(function () use ($webhook, $echoRaw): void {
    $webhook->ingestVerifiedLifecycle($echoRaw, 'tandatangan-panggilan', 'sha256=' . hash_hmac('sha256', $echoRaw, 'tandatangan-panggilan'));
});
$echoStored = $webhook->ingest($echoRaw);
$noted = $override->noteSubscribed($owner, $mainId);
$notedState = linkState($pdo, $mainId);
$recorded = catchStatus(function () use ($pdo, $mainId): void {
    CoexistenceSync::record($pdo, $mainId, 'callback');
    CoexistenceSync::record($pdo, $mainId, 'fields');
});
$notedAgain = linkState($pdo, $mainId);
$check(
    'webhook readiness follows signed delivery evidence',
    $badEcho === 400
        && $echoStored['stored'] === 1
        && ($noted['status'] ?? '') === 'subscribed'
        && ($noted['calls_graph'] ?? true) === false
        && $notedState['webhook_state'] === 'subscribed'
        && $notedState['callback'] === null
        && $recorded === 0
        && $notedAgain['callback'] !== null
        && $notedAgain['fields'] !== null
        && postCount(array_slice($transport->calls, $beforeClear)) === 0
);

$lifeWaba = '100000000001061';
$lifePhone = '100000000001611';
$siblingPhone = '100000000001612';
$lifeId = insertLink($pdo, $seal, $businessId, $lifeWaba, $lifePhone, '+60 12 400 0811', 'subscribed', true, $access);
$siblingId = insertLink($pdo, $seal, $otherBusiness, $lifeWaba, $siblingPhone, '+60 12 400 0812', 'subscribed', true, $access);
$job = $pdo->prepare(
    'INSERT INTO coexistence_sync_jobs (
        business_id, connection_id, authorized_by, sync_type, status, attempt_count, max_attempts,
        deadline_at, authorized_at, created_at, updated_at
     ) VALUES (?, ?, ?, \'history\', \'authorized\', 0, 3, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
);
$job->execute([$businessId, $lifeId, $ownerId]);
$jobId = (int) $pdo->lastInsertId();
$ambiguous = envelope($lifeWaba, 'account_update', ['event' => 'PARTNER_REMOVED']);
$ambiguousHeader = 'sha256=' . hash_hmac('sha256', $ambiguous, $secret);
$eventsBefore = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$tampered = catchStatus(function () use ($webhook, $ambiguous): void {
    $webhook->ingestVerifiedLifecycle($ambiguous, 'tandatangan-panggilan', 'sha256=' . str_repeat('cd', 32));
});
$eventsAfterTamper = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$ambiguousResult = $webhook->ingestVerifiedLifecycle($ambiguous, $secret, $ambiguousHeader);
$eventsAfterAmbiguous = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$ambiguousRow = $pdo->query('SELECT business_id FROM webhook_events WHERE event_kind = \'account_update\' ORDER BY id DESC LIMIT 1')->fetch();
$bothLive = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE id IN (' . $lifeId . ',' . $siblingId . ') AND token_state = \'verified\' AND disconnected_at IS NULL')->fetchColumn();
$removed = envelope($lifeWaba, 'account_update', [
    'event' => 'PARTNER_REMOVED',
    'phone_number' => '60124000811',
]);
$removedHeader = 'sha256=' . hash_hmac('sha256', $removed, $secret);
$removedOnce = $webhook->ingestVerifiedLifecycle($removed, $secret, $removedHeader);
$removedTwice = $webhook->ingestVerifiedLifecycle($removed, $secret, $removedHeader);
$lifeState = linkState($pdo, $lifeId);
$siblingState = linkState($pdo, $siblingId);
$jobStatus = (string) $pdo->query('SELECT status FROM coexistence_sync_jobs WHERE id = ' . $jobId)->fetchColumn();
$auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action = \'rakan_diputuskan\' AND business_id = ' . $businessId)->fetchColumn();
$offboarded = envelope($lifeWaba, 'account_update', [
    'event' => 'ACCOUNT_OFFBOARDED',
    'phone_number' => '60124000811',
]);
$webhook->ingestVerifiedLifecycle($offboarded, $secret, 'sha256=' . hash_hmac('sha256', $offboarded, $secret));
$auditAfter = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action = \'rakan_diputuskan\' AND business_id = ' . $businessId)->fetchColumn();
$siblingAfter = linkState($pdo, $siblingId);
$reconnect = envelope($lifeWaba, 'account_update', [
    'event' => 'ACCOUNT_RECONNECTED',
    'phone_number' => '60124000811',
]);
$reconnectStatus = catchStatus(function () use ($webhook, $reconnect, $secret): void {
    $webhook->ingestVerifiedLifecycle($reconnect, $secret, 'sha256=' . hash_hmac('sha256', $reconnect, $secret));
});
$lifeAfter = linkState($pdo, $lifeId);
$check(
    'verified account updates revoke only the matching connection once',
        $tampered === 403
        && $eventsAfterTamper === $eventsBefore
        && $eventsAfterAmbiguous === $eventsBefore + 1
        && $ambiguousResult['stored'] === 1
        && is_array($ambiguousRow)
        && $ambiguousRow['business_id'] === null
        && $bothLive === 2
        && $removedOnce['stored'] === 1
        && $removedTwice['replayed'] === 1
        && $lifeState['token_state'] === 'revoked'
        && $lifeState['disconnected'] === 1
        && $lifeState['messaging_state'] === 'unverified'
        && $siblingState['token_state'] === 'verified'
        && $siblingState['disconnected'] === 0
        && $jobStatus === 'cancelled'
        && $auditCount === 1
        && $auditAfter === 1
        && $siblingAfter['token_state'] === 'verified'
        && $reconnectStatus === 400
        && $lifeAfter['token_state'] === 'revoked'
);

$auditBlob = (string) ($pdo->query('SELECT COALESCE(GROUP_CONCAT(meta_json), \'\') FROM audit_logs')->fetchColumn() ?: '');
$check(
    'override audit omits tokens and message text',
    !str_contains($auditBlob, $access)
        && !str_contains($auditBlob, $verify)
        && !str_contains($auditBlob, 'mesej-awan')
        && !str_contains($auditBlob, 'bukti-gema-panggilan')
        && !str_contains($auditBlob, $secretPlant)
);

fwrite(STDOUT, $failures === 0 ? "PASS\n" : "FAIL {$failures}\n");
exit($failures === 0 ? 0 : 1);
