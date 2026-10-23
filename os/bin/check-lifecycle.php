<?php
declare(strict_types=1);

use BesharOs\AuthHttp;
use BesharOs\Contract;
use BesharOs\LifecycleBridge;
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

final class ScriptedMeta implements MetaTransport
{
    public array $script = [];
    public array $calls = [];

    public function call(string $method, string $url, array $headers, string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url];
        return ['status' => 500, 'json' => ['error' => ['code' => 1]]];
    }
}

$root = dirname(__DIR__, 2);
$indexPath = $root . '/webhook/index.php';
$index = (string) file_get_contents($indexPath);
$bridge = (string) file_get_contents(dirname(__DIR__) . '/src/LifecycleBridge.php');
$sendAt = strpos($index, 'rekod_perbualan');
$bridgeAt = strpos($index, 'LifecycleBridge::deliver');
preg_match("/const NOMBOR_011 = '(\\d+)'/", $index, $liveMatch);
preg_match("/const NOMBOR_DHERBS = '(\\d+)'/", $index, $otherMatch);
$livePhone = $liveMatch[1] ?? '';
$otherPhone = $otherMatch[1] ?? '';
$check(
    'verified intake precedes lifecycle bridge with outbound absent',
    $livePhone !== ''
        && $otherPhone !== ''
        && !str_contains($index, 'hantar_whatsapp')
        && is_int($sendAt)
        && is_int($bridgeAt)
        && $sendAt < $bridgeAt
        && str_contains($index, '$nombor !== NOMBOR_011')
        && str_contains($index, 'rekod_perbualan')
        && str_contains($index, "echo '{\"ok\":true}';")
        && !str_contains($index, 'ingestVerifiedLifecycle')
        && !str_contains($index, 'curl_')
        && !str_contains($index, 'graph.facebook.com')
        && !str_contains($bridge, 'graph.facebook.com')
        && !str_contains($bridge, 'curl_')
        && !str_contains($bridge, 'Deregister')
        && !str_contains($bridge, '/register')
        && !str_contains($bridge, 'subscribed_apps')
        && str_contains($bridge, 'ingestVerifiedLifecycle')
);

$seenPath = $root . '/admin/stor/webhook-dilihat.txt';
$talkPath = $root . '/admin/stor/perbualan.json';
$seenBefore = is_file($seenPath) ? hash_file('sha256', $seenPath) : '';
$talkBefore = is_file($talkPath);

$passwordPath = '/tmp/maria-lab.pw';
if (!is_file($passwordPath)) {
    fwrite(STDOUT, "FAIL lab password file\n");
    exit(1);
}
$password = trim((string) file_get_contents($passwordPath));
$server = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
register_shutdown_function(static function () use ($password): void {
    try {
        $closer = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $closer->exec('DROP DATABASE IF EXISTS `' . Contract::DATABASE . '`');
    } catch (Throwable) {
    }
});
$server->exec('DROP DATABASE IF EXISTS `' . Contract::DATABASE . '`');
$server->exec('CREATE DATABASE `' . Contract::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server->exec('USE `' . Contract::DATABASE . '`');
foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql'] as $name) {
    $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/' . $name);
    foreach (SqlScript::statements($sql) as $statement) {
        $server->exec($statement);
    }
}
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=' . Contract::DATABASE . ';charset=utf8mb4', 'root', $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_FOUND_ROWS => true,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
]);
$pdo->exec("SET SESSION sql_mode = 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES'");
$pdo->exec("SET time_zone = '+00:00'");
$check('lifecycle tests use the local lab database name', (string) $pdo->query('SELECT DATABASE()')->fetchColumn() === Contract::DATABASE);

$secret = 'rahsia-fasa-e-ujian';
$key = random_bytes(32);
$seal = new TokenSeal($key, 1);
$configPath = sys_get_temp_dir() . '/beshare-phase-e-config-' . bin2hex(random_bytes(4)) . '.php';
$spool = sys_get_temp_dir() . '/beshare-phase-e-spool-' . bin2hex(random_bytes(4));
$stor = sys_get_temp_dir() . '/beshare-phase-e-stor-' . bin2hex(random_bytes(4));
mkdir($stor, 0700, true);
file_put_contents($configPath, "<?php\nreturn " . var_export([
    'verify_token' => 'pengesah-fasa-e',
    'legacy_business_id' => '1',
    'legacy_waba_id' => '100000000002091',
    'app_secret' => $secret,
    'token_key' => base64_encode($key),
    'token_key_version' => '1',
], true) . ";\n");
chmod($configPath, 0600);
/** @return array{status:int,body:string} */
function runWebhook(string $method, string $body, string $signature): array
{
    $command = ['curl', '-sS', '-D', '-', '-o', '-', '-X', $method, 'http://127.0.0.1:8897/'];
    if ($signature !== '') {
        $command[] = '-H';
        $command[] = 'X-Hub-Signature-256: ' . $signature;
    }
    $command[] = '-H';
    $command[] = 'Content-Type: application/json';
    if ($method !== 'GET') {
        $command[] = '--data-binary';
        $command[] = '@-';
    }
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => ''];
    }
    if ($method !== 'GET') {
        fwrite($pipes[0], $body);
    }
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $parts = explode("\r\n\r\n", is_string($raw) ? $raw : '', 2);
    $status = 0;
    if (preg_match('#\AHTTP/\S+\s+(\d{3})#', $parts[0] ?? '', $match) === 1) {
        $status = (int) $match[1];
    }
    return ['status' => $status, 'body' => $parts[1] ?? ''];
}

function sign(string $raw, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', $raw, $secret);
}

/** @param list<array{id:string,changes:list<array<string,mixed>>}> $entries */
function batch(array $entries): string
{
    $json = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => $entries,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('fixture');
    }
    return $json;
}

/** @return array{webhook_state:string,token_state:string,disconnected:int,code:string} */
function linkState(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(
        'SELECT webhook_state, token_state, last_error_code, disconnected_at IS NOT NULL AS disconnected
         FROM whatsapp_connections WHERE id = ?'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return ['webhook_state' => '', 'token_state' => '', 'disconnected' => 0, 'code' => ''];
    }
    return [
        'webhook_state' => (string) $row['webhook_state'],
        'token_state' => (string) $row['token_state'],
        'disconnected' => (int) $row['disconnected'],
        'code' => (string) ($row['last_error_code'] ?? ''),
    ];
}

function insertLink(PDO $pdo, TokenSeal $seal, int $businessId, string $waba, string $phone, string $display): int
{
    $sealed = $seal->seal('token-makmal-bukan-live', 100);
    $insert = $pdo->prepare(
        'INSERT INTO whatsapp_connections (
            business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
            registration_state, messaging_state, sync_contacts, sync_history, token_kind,
            token_ciphertext, token_nonce, token_tag, token_key_version, refresh_supported,
            subscribed_at, created_at, updated_at, onboarded_at, sync_deadline_at
         ) VALUES (
            ?, ?, ?, ?, \'verified\', \'subscribed\', \'registered\', \'unverified\', \'pending\', \'pending\', \'system_user\',
            UNHEX(?), UNHEX(?), UNHEX(?), ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
            DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
         )'
    );
    $insert->execute([
        $businessId, $waba, $phone, $display,
        bin2hex($sealed['ciphertext']), bin2hex($sealed['nonce']), bin2hex($sealed['tag']), $sealed['version'],
    ]);
    return (int) $pdo->lastInsertId();
}

/** @return list<array<string,mixed>> */
function talks(string $stor, string $phone): array
{
    $path = $stor . '/perbualan.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    $rows = is_array($data) && is_array($data['perbualan'][$phone] ?? null) ? $data['perbualan'][$phone] : [];
    return array_values(array_filter($rows, 'is_array'));
}

$childBase = [
    'BESHARE_STOR' => $stor,
    'BESHARE_OS_WEBHOOK_CONFIG' => $configPath,
    'BESHARE_OS_LIFECYCLE_SPOOL' => $spool,
    'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=3307;dbname=' . Contract::DATABASE . ';charset=utf8mb4',
    'BESHARE_OS_DB_USER' => 'root',
    'BESHARE_OS_DB_PASSWORD' => $password,
];
$serverEnv = getenv();
if (!is_array($serverEnv)) {
    $serverEnv = [];
}
unset(
    $serverEnv['BESHARE_PHONE_OVERRIDE_ENABLED'],
    $serverEnv['BESHARE_COEXISTENCE_SYNC_ENABLED'],
    $serverEnv['BESHARE_OS_TOKEN_KEY'],
    $serverEnv['BESHARE_OS_DB_CONFIG'],
    $serverEnv['PHP_CLI_SERVER_WORKERS']
);
foreach ($childBase as $name => $value) {
    $serverEnv[$name] = $value;
}
$serverLog = sys_get_temp_dir() . '/beshare-phase-e-server.log';
$busy = @fsockopen('127.0.0.1', 8897, $errno, $error, 0.2);
if (is_resource($busy)) {
    fclose($busy);
    fwrite(STDOUT, "FAIL local webhook port is already in use\n");
    exit(1);
}
$serverProc = proc_open(
    ['php', '-d', 'display_errors=0', '-S', '127.0.0.1:8897', '-t', $root . '/webhook'],
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $serverPipes,
    null,
    $serverEnv
);
if (!is_resource($serverProc)) {
    fwrite(STDOUT, "FAIL local webhook server\n");
    exit(1);
}
$serverPid = (int) (proc_get_status($serverProc)['pid'] ?? 0);
$stopServer = static function () use ($serverProc, $serverPid): void {
    if ($serverPid > 0) {
        $children = [];
        exec('ps -o pid= --ppid ' . $serverPid, $children);
        foreach ($children as $child) {
            $childPid = (int) trim($child);
            if ($childPid > 0) {
                exec('kill ' . $childPid . ' >/dev/null 2>&1');
            }
        }
        exec('kill ' . $serverPid . ' >/dev/null 2>&1');
    }
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
};
register_shutdown_function($stopServer);
$serverUp = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8897, $errno, $error, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        $serverUp = true;
        break;
    }
    usleep(100000);
}
if (!$serverUp) {
    fwrite(STDOUT, "FAIL local webhook server did not start\n");
    exit(1);
}
putenv('BESHARE_OS_LIFECYCLE_SPOOL=' . $spool);
$post = static function (string $body, string $signature): array {
    return runWebhook('POST', $body, $signature);
};

$rates = new RateLimit(sys_get_temp_dir() . '/beshare-os-lifecycle-rate-' . bin2hex(random_bytes(4)), 40, 900);
$http = new AuthHttp($pdo, $rates, new WhatsAppGateway($pdo, new MetaGraph(BESHARE_META_APP_ID, $secret, BESHARE_META_GRAPH, new ScriptedMeta()), $seal, BESHARE_META_APP_ID));
$session = [];
$issued = $http->handle('GET', 'sesi', $session, [], [], '127.0.0.1', 'lab.test', 'life-owner');
$session['csrf'] = $issued['body']['csrf'];
$http->handle('POST', 'daftar', $session, [
    'name' => 'Pemilik Hayat',
    'email' => 'hayat.coex@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Hayat',
], ['x-csrf-token' => $session['csrf']], '127.0.0.1', 'lab.test', 'life-owner');
$other = [];
$otherIssued = $http->handle('GET', 'sesi', $other, [], [], '127.0.0.1', 'lab.test', 'life-other');
$other['csrf'] = $otherIssued['body']['csrf'];
$http->handle('POST', 'daftar', $other, [
    'name' => 'Pemilik Sebelah',
    'email' => 'sebelah.coex@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Sebelah',
], ['x-csrf-token' => $other['csrf']], '127.0.0.1', 'lab.test', 'life-other');
$businessId = (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Hayat\'')->fetchColumn();
$otherBusiness = (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Sebelah\'')->fetchColumn();
$ownerId = (int) $pdo->query('SELECT id FROM users WHERE email = \'hayat.coex@kedai.test\'')->fetchColumn();
$lifeWaba = '100000000002061';
$lifeId = insertLink($pdo, $seal, $businessId, $lifeWaba, '100000000002161', '+60 12 810 0101');
$siblingId = insertLink($pdo, $seal, $otherBusiness, $lifeWaba, '100000000002162', '+60 12 810 0102');
$strangerId = insertLink($pdo, $seal, $businessId, '100000000002071', '100000000002171', '+60 12 810 0171');
$offboardId = insertLink($pdo, $seal, $businessId, '100000000002081', '100000000002181', '+60 12 810 0181');
$job = $pdo->prepare(
    'INSERT INTO coexistence_sync_jobs (
        business_id, connection_id, authorized_by, sync_type, status, attempt_count, max_attempts,
        deadline_at, authorized_at, created_at, updated_at
     ) VALUES (?, ?, ?, \'history\', \'authorized\', 0, 3, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
);
$job->execute([$businessId, $lifeId, $ownerId]);
$jobId = (int) $pdo->lastInsertId();

$graph = new ScriptedMeta();
$check('setup graph transport was not called', $graph->calls === []);

$get = runWebhook('GET', '', '');
$invalid = $post('bukan-json', '');
$statusRaw = batch([[
    'id' => '100000000002091',
    'changes' => [[
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $livePhone],
            'statuses' => [[
                'id' => 'wamid.STATUSUJIAN0001',
                'status' => 'delivered',
                'timestamp' => '1710000300',
                'recipient_id' => '60198765432',
            ]],
        ],
    ]],
]]);
$statusHit = $post($statusRaw, sign($statusRaw, $secret));
$eventsAfterStatus = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$check(
    'ordinary status flow keeps the existing acknowledgement',
    $get['status'] === 403
        && $get['body'] === 'Tidak sah.'
        && $invalid['status'] === 403
        && $invalid['body'] === '{"ok":false}'
        && $statusHit['status'] === 200
        && $statusHit['body'] === '{"ok":true}'
        && talks($stor, '60198765432') === []
        && $eventsAfterStatus === 0
);

$message = [
    'field' => 'messages',
    'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => $livePhone],
        'contacts' => [['profile' => ['name' => 'Pelanggan'], 'wa_id' => '60198765432']],
        'messages' => [[
            'from' => '60198765432',
            'id' => 'wamid.BIASA0000000001',
            'timestamp' => '1710000301',
            'type' => 'text',
            'text' => ['body' => 'mesej-biasa-ujian'],
        ]],
    ],
];
$messageRaw = batch([['id' => '100000000002091', 'changes' => [$message]]]);
$firstMessage = $post($messageRaw, sign($messageRaw, $secret));
$secondMessage = $post($messageRaw, sign($messageRaw, $secret));
$foreignRaw = batch([[
    'id' => '100000000002091',
    'changes' => [[
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $otherPhone],
            'messages' => [[
                'from' => '60198765432',
                'id' => 'wamid.BUKAN00000000001',
                'timestamp' => '1710000302',
                'type' => 'text',
                'text' => ['body' => 'mesej-nombor-lain'],
            ]],
        ],
    ]],
]]);
$foreignHit = $post($foreignRaw, sign($foreignRaw, $secret));
$wordRaw = batch([[
    'id' => '100000000002091',
    'changes' => [[
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $livePhone],
            'messages' => [[
                'from' => '60198765432',
                'id' => 'wamid.PERKATAAN000001',
                'timestamp' => '1710000303',
                'type' => 'text',
                'text' => ['body' => 'PARTNER_REMOVED'],
            ]],
        ],
    ]],
]]);
$wordHit = $post($wordRaw, sign($wordRaw, $secret));
$lines = talks($stor, '60198765432');
$roles = array_map(static fn (array $row): string => (string) ($row['role'] ?? ''), $lines);
$check(
    'ordinary Cloud API messages stay on the legacy path',
    $firstMessage['status'] === 200
        && $firstMessage['body'] === '{"ok":true}'
        && $secondMessage['body'] === '{"ok":true}'
        && $foreignHit['body'] === '{"ok":true}'
        && $wordHit['status'] === 200
        && count($lines) === 2
        && $roles === ['user', 'user']
        && ($lines[0]['content'] ?? '') === 'mesej-biasa-ujian'
        && ($lines[1]['content'] ?? '') === 'PARTNER_REMOVED'
        && !str_contains((string) file_get_contents($stor . '/perbualan.json'), 'mesej-nombor-lain')
        && (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === 0
        && linkState($pdo, $lifeId)['token_state'] === 'verified'
);

$ambiguous = batch([[
    'id' => $lifeWaba,
    'changes' => [[
        'field' => 'account_update',
        'value' => ['event' => 'PARTNER_REMOVED'],
    ]],
]]);
$ambiguousHit = $post($ambiguous, sign($ambiguous, $secret));
$ambiguousRow = $pdo->query('SELECT business_id, connection_id FROM webhook_events WHERE event_kind = \'account_update\' ORDER BY id DESC LIMIT 1')->fetch();
$missingSignature = $post($ambiguous, '');
$badSignature = $post($ambiguous, 'sha256=' . str_repeat('ab', 32));
$eventsAfterReject = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$check(
    'invalid signatures and an ambiguous WABA do not revoke a connection',
    $ambiguousHit['status'] === 200
        && $ambiguousHit['body'] === '{"ok":true}'
        && is_array($ambiguousRow)
        && $ambiguousRow['business_id'] === null
        && $ambiguousRow['connection_id'] === null
        && $missingSignature['status'] === 403
        && $badSignature['status'] === 403
        && $eventsAfterReject === 1
        && linkState($pdo, $lifeId)['token_state'] === 'verified'
        && linkState($pdo, $siblingId)['token_state'] === 'verified'
        && linkState($pdo, $strangerId)['token_state'] === 'verified'
);

rename($configPath, $configPath . '.away');
$unconfigured = $post($ambiguous, sign($ambiguous, $secret));
rename($configPath . '.away', $configPath);
$check(
    'a lifecycle event without server config is not acknowledged',
    $unconfigured['status'] === 503
        && $unconfigured['body'] === '{"ok":false}'
        && (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === 1
        && !str_contains($unconfigured['body'], $secret)
);

$repoSpool = $root . '/os/bin/lifecycle-spool-test';
putenv('BESHARE_OS_LIFECYCLE_SPOOL=' . $repoSpool);
$pdo->exec('RENAME TABLE webhook_events TO webhook_events_away');
$blocked = LifecycleBridge::deliver($ambiguous, sign($ambiguous, $secret), $pdo, $seal, $secret);
$pdo->exec('RENAME TABLE webhook_events_away TO webhook_events');
putenv('BESHARE_OS_LIFECYCLE_SPOOL=' . $spool);
$check(
    'a spool inside the repository is refused',
    $blocked === 'failed' && !is_dir($repoSpool) && !is_file($repoSpool)
);

$specific = [
    'field' => 'account_update',
    'value' => [
        'event' => 'PARTNER_REMOVED',
        'phone_number' => '60128100101',
    ],
];
$mixedMessage = $message;
$mixedMessage['value']['messages'][0]['id'] = 'wamid.CAMPURAN0000001';
$mixedMessage['value']['messages'][0]['text']['body'] = 'mesej-campuran-ujian';
$mixed = batch([[
    'id' => $lifeWaba,
    'changes' => [$mixedMessage, $specific],
]]);
$pdo->exec('RENAME TABLE webhook_events TO webhook_events_away');
$failed = $post($mixed, sign($mixed, $secret));
$spoolFiles = is_dir($spool) ? glob($spool . '/*.bin') : [];
$spoolText = '';
foreach (is_array($spoolFiles) ? $spoolFiles : [] as $file) {
    $spoolText .= (string) file_get_contents($file);
}
$duringFailure = linkState($pdo, $lifeId);
$linesDuringFailure = talks($stor, '60198765432');
$check(
    'a verified lifecycle storage failure is retained without acknowledging it',
    $failed['status'] === 500
        && $failed['body'] === '{"ok":false}'
        && $duringFailure['token_state'] === 'verified'
        && $duringFailure['disconnected'] === 0
        && count($linesDuringFailure) === 2 // Foreign WABA cannot enter the legacy tenant store.
        && is_array($spoolFiles)
        && count($spoolFiles) === 1
        && !str_contains($spoolText, $secret)
        && !str_contains($spoolText, 'PARTNER_REMOVED')
        && !str_contains($spoolText, 'mesej-biasa-ujian')
        && !str_contains($spoolText, 'mesej-campuran-ujian')
        && !str_contains($failed['body'], $secret)
);
$pdo->exec('RENAME TABLE webhook_events_away TO webhook_events');
putenv('BESHARE_OS_LIFECYCLE_SPOOL=' . $spool);
$recovered = LifecycleBridge::recover($pdo, $seal, $secret);
$afterRecover = linkState($pdo, $lifeId);
$siblingAfter = linkState($pdo, $siblingId);
$strangerAfter = linkState($pdo, $strangerId);
$jobStatus = (string) $pdo->query('SELECT status FROM coexistence_sync_jobs WHERE id = ' . $jobId)->fetchColumn();
$auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action = \'rakan_diputuskan\' AND business_id = ' . $businessId)->fetchColumn();
$again = LifecycleBridge::recover($pdo, $seal, $secret);
$check(
    'interrupted lifecycle processing revokes only the matched connection once',
    ($recovered['recovered'] ?? 0) === 1
        && ($recovered['waiting'] ?? 1) === 0
        && ($again['recovered'] ?? 1) === 0
        && glob($spool . '/*.bin') === []
        && $afterRecover['token_state'] === 'revoked'
        && $afterRecover['disconnected'] === 1
        && $afterRecover['code'] === 'PARTNER_REMOVED'
        && $siblingAfter['token_state'] === 'verified'
        && $siblingAfter['disconnected'] === 0
        && $strangerAfter['token_state'] === 'verified'
        && $jobStatus === 'cancelled'
        && $auditCount === 1
);

$retry = $post($mixed, sign($mixed, $secret));
$retryLines = talks($stor, '60198765432');
$retryAudit = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action = \'rakan_diputuskan\' AND business_id = ' . $businessId)->fetchColumn();
$retryEvents = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events WHERE event_kind = \'message\'')->fetchColumn();
$check(
    'a mixed retry excludes foreign-WABA messages and does not revoke again',
    $retry['status'] === 200
        && $retry['body'] === '{"ok":true}'
        && count($retryLines) === 2
        && $retryAudit === 1
        && $retryEvents === 0
        && linkState($pdo, $siblingId)['token_state'] === 'verified'
);

$offboard = batch([[
    'id' => '100000000002081',
    'changes' => [[
        'field' => 'account_update',
        'value' => [
            'event' => 'ACCOUNT_OFFBOARDED',
            'phone_number' => '60128100181',
        ],
    ]],
]]);
$offboardHit = $post($offboard, sign($offboard, $secret));
$offboardAgain = $post($offboard, sign($offboard, $secret));
$offboardState = linkState($pdo, $offboardId);
$offboardAudit = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action = \'rakan_diputuskan\' AND entity_id = ' . $offboardId)->fetchColumn();
$unknown = batch([[
    'id' => '100000000009999',
    'changes' => [[
        'field' => 'account_update',
        'value' => ['event' => 'PARTNER_REMOVED', 'phone_number' => '60120000000'],
    ]],
]]);
$unknownHit = $post($unknown, sign($unknown, $secret));
$unknownRow = $pdo->query('SELECT business_id FROM webhook_events WHERE event_kind = \'account_update\' ORDER BY id DESC LIMIT 1')->fetch();
$check(
    'offboarding is idempotent and an unknown WABA stays unscoped',
    $offboardHit['status'] === 200
        && $offboardAgain['status'] === 200
        && $offboardState['token_state'] === 'revoked'
        && $offboardState['code'] === 'ACCOUNT_OFFBOARDED'
        && $offboardAudit === 1
        && $unknownHit['status'] === 200
        && is_array($unknownRow)
        && $unknownRow['business_id'] === null
        && linkState($pdo, $strangerId)['token_state'] === 'verified'
        && linkState($pdo, $siblingId)['disconnected'] === 0
);

$seenAfter = is_file($seenPath) ? hash_file('sha256', $seenPath) : '';
$check(
    'the default legacy storage files stay untouched',
    $seenBefore === $seenAfter && is_file($talkPath) === $talkBefore
);
$stopServer();

fwrite(STDOUT, $failures === 0 ? "PASS\n" : "FAIL {$failures}\n");
exit($failures === 0 ? 0 : 1);
