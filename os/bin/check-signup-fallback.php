<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\MetaGraph;
use BesharOs\MetaTransport;
use BesharOs\RateLimit;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;
use BesharOs\WhatsAppGateway;

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

final class FallbackScript implements MetaTransport
{
    /** @var list<array{status:int,json:array<string,mixed>}> */
    public array $script = [];

    /** @var list<array{method:string,url:string}> */
    public array $calls = [];

    public function call(string $method, string $url, array $headers, string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url];
        $next = array_shift($this->script);
        if (!is_array($next)) {
            return ['status' => 500, 'json' => ['error' => ['code' => 1]]];
        }
        return $next;
    }
}

/** @return list<string> */
function fallbackPaths(FallbackScript $script): array
{
    $paths = [];
    foreach ($script->calls as $call) {
        $path = parse_url($call['url'], PHP_URL_PATH);
        $paths[] = $call['method'] . ' ' . (is_string($path) ? $path : '');
    }
    return $paths;
}

/** @return array{status:int,json:array<string,mixed>} */
function fallbackOauth(string $token): array
{
    return ['status' => 200, 'json' => ['access_token' => $token, 'token_type' => 'bearer']];
}

/** @param list<string> $targets @return array{status:int,json:array<string,mixed>} */
function fallbackDebug(string $appId, array $targets): array
{
    return ['status' => 200, 'json' => ['data' => [
        'app_id' => $appId,
        'type' => 'SYSTEM_USER',
        'is_valid' => true,
        'expires_at' => 0,
        'granular_scopes' => [
            ['scope' => 'whatsapp_business_management', 'target_ids' => $targets],
            ['scope' => 'whatsapp_business_messaging', 'target_ids' => $targets],
        ],
    ]]];
}

/** @param list<array<string,mixed>> $phones @return list<array{status:int,json:array<string,mixed>}> */
function fallbackReads(string $appId, string $waba, array $phones): array
{
    return [
        fallbackDebug($appId, [$waba]),
        ['status' => 200, 'json' => ['id' => $waba, 'name' => 'NDL Ujian']],
        ['status' => 200, 'json' => ['data' => $phones]],
    ];
}

/** @param array<string,mixed> $phone */
function fallbackPhone(string $id, string $display): array
{
    return [
        'id' => $id,
        'display_phone_number' => $display,
        'verified_name' => 'NDL Ujian',
        'code_verification_status' => 'VERIFIED',
        'status' => 'CONNECTED',
    ];
}

function fallbackCatch(callable $action): int
{
    try {
        $action();
    } catch (AuthException $error) {
        return $error->status();
    }
    return 0;
}

/** @return array<string,mixed>|false */
function fallbackSession(PDO $pdo, string $state): array|false
{
    $statement = $pdo->prepare(
        'SELECT status, finish_event, meta_error_code, reported_waba_id, reported_phone_number_id,
                token_ciphertext IS NULL AS token_cleared
         FROM onboarding_sessions WHERE correlation_hash = ?'
    );
    $statement->execute([hash('sha256', $state)]);
    $row = $statement->fetch();
    return is_array($row) ? $row : false;
}

function fallbackLab(): ?PDO
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
    $server->exec('DROP DATABASE IF EXISTS signup_fallback_lab');
    $server->exec('CREATE DATABASE signup_fallback_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE signup_fallback_lab');
    foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql'] as $name) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/' . $name);
        foreach (SqlScript::statements($sql) as $statement) {
            $server->exec($statement);
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=signup_fallback_lab;charset=utf8mb4', 'root', $password, [
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

$pdo = fallbackLab();
if (!$pdo instanceof PDO) {
    fwrite(STDOUT, "FAIL lab database\n");
    exit(1);
}

$appId = BESHARE_META_APP_ID;
$secret = 'fallback-app-secret';
$script = new FallbackScript();
$graph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $script);
$seal = new TokenSeal(random_bytes(32), 1);
$http = new AuthHttp(
    $pdo,
    new RateLimit(sys_get_temp_dir() . '/beshare-os-fallback-rate-' . bin2hex(random_bytes(4)), 20, 900),
    new WhatsAppGateway($pdo, $graph, $seal, $appId)
);
$remote = '127.0.0.1';
$host = 'lab.test';
$owner = [];
$issued = $http->handle('GET', 'sesi', $owner, [], [], $remote, $host, 'owner-session');
$created = $http->handle('POST', 'daftar', $owner, [
    'name' => 'Pemilik Fallback',
    'email' => 'fallback@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Fallback',
], ['x-csrf-token' => $issued['body']['csrf']], $remote, $host, 'owner-session');
$businessId = (string) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Fallback\'')->fetchColumn();

$begin = static function () use ($http, &$owner, $remote, $host): string {
    $started = $http->handle('POST', 'sambung', $owner, [], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
    return (string) ($started['body']['state'] ?? '');
};

$finishWaba = '200000000000101';
$finishPhone = '200000000000102';
$otherPhone = '200000000000103';
$script->calls = [];
$script->script = array_merge(
    [fallbackOauth('EAA-finish-primary-token')],
    fallbackReads($appId, $finishWaba, [
        fallbackPhone($finishPhone, '+60 17 000 0102'),
        fallbackPhone($otherPhone, '+60 18 000 0103'),
    ]),
    [['status' => 200, 'json' => ['success' => true]]]
);
$finishState = $begin();
$http->handle('POST', 'peristiwa', $owner, [
    'state' => $finishState,
    'event' => 'FINISH',
    'waba_id' => $finishWaba,
    'phone_number_id' => $finishPhone,
], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
$finishDone = $http->handle('POST', 'tukar', $owner, [
    'state' => $finishState,
    'code' => 'finish-code-abcdefgh',
], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
$finishPaths = fallbackPaths($script);
$finishRow = $pdo->query('SELECT business_id, phone_number_id, webhook_state, sync_contacts FROM whatsapp_connections WHERE waba_id = \'200000000000101\'')->fetch();
$check(
    'finish event remains the selected phone when several numbers exist',
    is_array($finishRow)
        && (string) $finishRow['business_id'] === $businessId
        && $finishRow['phone_number_id'] === $finishPhone
        && $finishRow['webhook_state'] === 'subscribed'
        && $finishRow['sync_contacts'] === 'not_applicable'
        && ($finishDone['body']['connection']['phone'] ?? '') === '+60 17 000 0102'
        && $finishPaths === [
            'GET /v26.0/oauth/access_token',
            'GET /v26.0/debug_token',
            'GET /v26.0/' . $finishWaba,
            'GET /v26.0/' . $finishWaba . '/phone_numbers',
            'POST /v26.0/' . $finishWaba . '/subscribed_apps',
        ]
        && (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE phone_number_id = \'200000000000103\'')->fetchColumn() === '0'
);

$uniqueWaba = '200000000000201';
$uniquePhone = '200000000000202';
$uniqueToken = 'EAA-unique-fallback-token';
$uniquePhones = [fallbackPhone($uniquePhone, '+60 17 000 0202')];
$script->calls = [];
$script->script = array_merge(
    [fallbackOauth($uniqueToken)],
    fallbackReads($appId, $uniqueWaba, $uniquePhones),
    fallbackReads($appId, $uniqueWaba, $uniquePhones),
    [['status' => 200, 'json' => ['success' => true]]]
);
$uniqueState = $begin();
$uniqueDone = $http->handle('POST', 'tukar', $owner, [
    'state' => $uniqueState,
    'code' => 'unique-code-abcdefgh',
], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
$uniqueJson = json_encode($uniqueDone['body'], JSON_UNESCAPED_SLASHES);
$uniqueSession = fallbackSession($pdo, $uniqueState);
$uniqueRow = $pdo->query(
    'SELECT business_id, waba_id, phone_number_id, webhook_state, registration_state, sync_contacts, sync_history,
            subscribed_at IS NULL AS unsubscribed
     FROM whatsapp_connections WHERE phone_number_id = \'200000000000202\''
)->fetch();
$uniquePaths = fallbackPaths($script);
$check(
    'a single granted waba and phone binds through the existing flow',
    is_array($uniqueSession)
        && $uniqueSession['status'] === 'completed'
        && $uniqueSession['finish_event'] === null
        && $uniqueSession['reported_waba_id'] === $uniqueWaba
        && $uniqueSession['reported_phone_number_id'] === $uniquePhone
        && (string) $uniqueSession['token_cleared'] === '1'
        && is_array($uniqueRow)
        && (string) $uniqueRow['business_id'] === $businessId
        && $uniqueRow['waba_id'] === $uniqueWaba
        && $uniqueRow['phone_number_id'] === $uniquePhone
        && $uniqueRow['webhook_state'] === 'subscribed'
        && $uniqueRow['registration_state'] === 'registered'
        && $uniqueRow['sync_contacts'] === 'not_applicable'
        && $uniqueRow['sync_history'] === 'not_applicable'
        && (string) $uniqueRow['unsubscribed'] === '0'
        && ($uniqueDone['body']['connection']['token'] ?? '') === 'Disahkan'
        && ($uniqueDone['body']['connection']['webhook'] ?? '') === 'Dilanggan'
        && ($uniqueDone['body']['connection']['phone'] ?? '') === '+60 17 000 0202'
        && is_string($uniqueJson)
        && !str_contains($uniqueJson, $uniqueToken)
        && !str_contains($uniqueJson, 'unique-code-abcdefgh')
        && $uniquePaths === [
            'GET /v26.0/oauth/access_token',
            'GET /v26.0/debug_token',
            'GET /v26.0/' . $uniqueWaba,
            'GET /v26.0/' . $uniqueWaba . '/phone_numbers',
            'GET /v26.0/debug_token',
            'GET /v26.0/' . $uniqueWaba,
            'GET /v26.0/' . $uniqueWaba . '/phone_numbers',
            'POST /v26.0/' . $uniqueWaba . '/subscribed_apps',
        ]
);

$manyState = $begin();
$script->calls = [];
$script->script = array_merge(
    [fallbackOauth('EAA-many-phones-token')],
    fallbackReads($appId, '200000000000301', [
        fallbackPhone('200000000000302', '+60 17 000 0302'),
        fallbackPhone('200000000000303', '+60 18 000 0303'),
    ])
);
$manyDone = $http->handle('POST', 'tukar', $owner, [
    'state' => $manyState,
    'code' => 'many-code-abcdefghij',
], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
$manySession = fallbackSession($pdo, $manyState);
$manyPaths = implode("\n", fallbackPaths($script));
$check(
    'two phones stay unresolved without a subscription',
    is_array($manySession)
        && $manySession['status'] === 'token_held'
        && $manySession['finish_event'] === null
        && $manySession['reported_waba_id'] === null
        && $manySession['reported_phone_number_id'] === null
        && (string) $manySession['token_cleared'] === '0'
        && !str_contains($manyPaths, 'subscribed_apps')
        && !str_contains($manyPaths, 'smb_app_data')
        && (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE waba_id = \'200000000000301\'')->fetchColumn() === '0'
);

$wideState = $begin();
$script->calls = [];
$script->script = [
    fallbackOauth('EAA-many-waba-token'),
    fallbackDebug($appId, ['200000000000401', '200000000000402']),
];
$wideDone = $http->handle('POST', 'tukar', $owner, [
    'state' => $wideState,
    'code' => 'wide-code-abcdefghij',
], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
$wideSession = fallbackSession($pdo, $wideState);
$check(
    'two granted wabas are not chosen automatically',
    is_array($wideSession)
        && $wideSession['status'] === 'token_held'
        && $wideSession['reported_waba_id'] === null
        && (string) $wideSession['token_cleared'] === '0'
        && fallbackPaths($script) === [
            'GET /v26.0/oauth/access_token',
            'GET /v26.0/debug_token',
        ]
        && (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE waba_id IN (\'200000000000401\', \'200000000000402\')')->fetchColumn() === '0'
);

$heldState = $begin();
$script->calls = [];
$script->script = [
    fallbackOauth('EAA-graph-error-token'),
    ['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'hidden']]],
];
$errorDone = $http->handle('POST', 'tukar', $owner, [
    'state' => $heldState,
    'code' => 'error-code-abcdefgh',
], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
$errorSession = fallbackSession($pdo, $heldState);
$errorJson = json_encode($errorDone['body'], JSON_UNESCAPED_SLASHES);
$check(
    'a graph failure leaves the held token for a later finish event',
    is_array($errorSession)
        && $errorSession['status'] === 'token_held'
        && $errorSession['meta_error_code'] === null
        && (string) $errorSession['token_cleared'] === '0'
        && is_string($errorJson)
        && !str_contains($errorJson, 'EAA-graph-error-token')
        && !str_contains($errorJson, 'hidden')
        && fallbackPaths($script) === [
            'GET /v26.0/oauth/access_token',
            'GET /v26.0/debug_token',
        ]
);

$pdo->prepare('UPDATE onboarding_sessions SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE correlation_hash = ?')->execute([hash('sha256', $heldState)]);
$script->calls = [];
$script->script = array_merge(
    [fallbackOauth('EAA-should-not-exchange')],
    fallbackReads($appId, '200000000000501', [fallbackPhone('200000000000502', '+60 17 000 0502')]),
    fallbackReads($appId, '200000000000501', [fallbackPhone('200000000000502', '+60 17 000 0502')]),
    [['status' => 200, 'json' => ['success' => true]]]
);
$revived = fallbackCatch(function () use ($http, &$owner, $heldState, $remote, $host): void {
    $http->handle('POST', 'tukar', $owner, [
        'state' => $heldState,
        'code' => 'revive-code-abcdefgh',
    ], ['x-csrf-token' => (string) $owner['csrf']], $remote, $host, 'owner-session');
});
$revivedSession = fallbackSession($pdo, $heldState);
$check(
    'an expired held session is not resolved or rebound',
    $revived === 409
        && is_array($revivedSession)
        && $revivedSession['status'] === 'expired'
        && (string) $revivedSession['token_cleared'] === '1'
        && $script->calls === []
        && (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE phone_number_id = \'200000000000502\'')->fetchColumn() === '0'
);

exit($failures === 0 ? 0 : 1);
