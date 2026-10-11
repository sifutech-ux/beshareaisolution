<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\Csrf;
use BesharOs\MetaCallException;
use BesharOs\MetaGraph;
use BesharOs\MetaTransport;
use BesharOs\PrivateConfig;
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

$root = dirname(__DIR__, 2);
$ids = (string) file_get_contents($root . '/whatsapp/sambungan/ids.php');
$tukar = (string) file_get_contents($root . '/whatsapp/sambungan/tukar.php');
$webhookFile = (string) file_get_contents($root . '/whatsapp/sambungan/webhook.php');
$dashboard = (string) file_get_contents($root . '/whatsapp/ruang.js');
$migrator = (string) file_get_contents($root . '/os/bin/migrate.php');
$check(
    'public meta ids are the configured app and login',
    str_contains($ids, "const BESHARE_META_APP_ID = '27767197276313052';")
        && str_contains($ids, "const BESHARE_META_CONFIG_ID = '1028295100269838';")
        && !str_contains($ids, 'app_secret')
);
$check('legacy code exchange no longer stores a token', !str_contains($tukar, 'curl_init') && !str_contains($tukar, 'access_token') && str_contains($tukar, '410'));
$check('webhook validates the hub signature before storage', str_contains($webhookFile, 'signatureMatches') && str_contains($webhookFile, 'WhatsAppWebhook'));
$check(
    'dashboard signup uses the session api',
    str_contains($dashboard, 'callApi("sambung"')
        && str_contains($dashboard, 'callApi("tukar"')
        && str_contains($dashboard, 'callApi("peristiwa"')
        && str_contains($dashboard, '? "peti" : "sesi"')
        && str_contains($dashboard, 'sessionInfoVersion: "3"')
        && str_contains($dashboard, 'response_type: "code"')
        && !str_contains($dashboard, 'sambungan/tukar.php')
        && !str_contains($dashboard, '27767197276313052')
        && !str_contains($dashboard, 'console.')
);
$check('inbox proposal is not executed by the migrator', is_file($root . '/os/proposals/0002_inbox.sql') && !str_contains($migrator, '0002_inbox'));

$seal = new TokenSeal(random_bytes(32), 1);
$round = $seal->seal('rahsia-ujian', 100);
$check('sealed token round trips', $seal->open($round['ciphertext'], $round['nonce'], $round['tag'], $round['version']) === 'rahsia-ujian');
$check('webhook signature rejects a mismatch', WhatsAppWebhook::signatureMatches('{"ok":1}', 'sha256=' . str_repeat('ab', 32), 'secret') === false);
$signed = hash_hmac('sha256', '{"ok":1}', 'secret');
$check('webhook signature accepts the hmac', WhatsAppWebhook::signatureMatches('{"ok":1}', 'sha256=' . $signed, 'secret') === true);

$outside = sys_get_temp_dir() . '/beshare-meta-config-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($outside, "<?php\nreturn ['verify_token' => 't', 'app_secret' => 's', 'token_key' => 'k'];\n");
chmod($outside, 0600);
$previousConfig = getenv('BESHARE_OS_WEBHOOK_CONFIG');
putenv('BESHARE_OS_WEBHOOK_CONFIG=' . $outside);
$loaded = PrivateConfig::load();
$inside = $root . '/os/var/not-a-secret.php';
file_put_contents($inside, "<?php\nreturn ['app_secret' => 'no'];\n");
putenv('BESHARE_OS_WEBHOOK_CONFIG=' . $inside);
$rejected = PrivateConfig::load();
putenv($previousConfig === false ? 'BESHARE_OS_WEBHOOK_CONFIG' : 'BESHARE_OS_WEBHOOK_CONFIG=' . $previousConfig);
unlink($outside);
unlink($inside);
$check('private config loads outside the repository', is_array($loaded) && ($loaded['app_secret'] ?? '') === 's');
$check('private config rejects a repository path', $rejected === null);

$pdo = lab();
if (!$pdo instanceof PDO) {
    fwrite(STDOUT, "FAIL lab database\n");
    exit(1);
}
$check('meta tests use the isolated lab', (string) $pdo->query('SELECT DATABASE()')->fetchColumn() === 'meta_lab');

$appId = BESHARE_META_APP_ID;
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

$secret = 'test-app-secret-value';
$token = 'EAA-test-token-not-real';
$waba = '100000000000101';
$phone = '100000000000202';
$code = 'test-code-abcdefgh';
$direct = new ScriptedMeta();
$direct->script = [oauthResponse($token)];
$directGraph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $direct);
$exchanged = $directGraph->exchangeCode($code);
$check(
    'documented get exchange returns the business token',
    $exchanged === $token && isset($direct->calls[0]) && oauthExchangeIsDocumented($direct->calls[0], $appId, $secret, $code)
);
$failScript = new ScriptedMeta();
$failScript->script = [['status' => 400, 'json' => ['error' => ['code' => 100, 'message' => 'bad ' . $secret . ' ' . $code]]]];
$failGraph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $failScript);
$failedDirect = false;
try {
    $failGraph->exchangeCode($code);
} catch (MetaCallException $error) {
    $failedDirect = $error->metaCode === '100'
        && $error->httpStatus === 400
        && $error->getMessage() === 'meta'
        && credentialTextIsRedacted($error->getMessage(), $secret, $code);
}
$check(
    'failed oauth exchange hides graph error text',
    $failedDirect && isset($failScript->calls[0]) && oauthExchangeIsDocumented($failScript->calls[0], $appId, $secret, $code)
);
$redacted = false;
try {
    (new MetaGraph($appId, $secret, BESHARE_META_GRAPH, new class implements MetaTransport {
        public function call(string $method, string $url, array $headers, string $body): array
        {
            throw new RuntimeException($url);
        }
    }))->exchangeCode($code);
} catch (Throwable $error) {
    $redacted = $error instanceof MetaCallException
        && $error->getMessage() === 'meta'
        && credentialTextIsRedacted($error->getMessage(), $secret, $code);
}
$check('oauth transport errors redact the credential url', $redacted);
$host = 'lab.test';
$remote = '127.0.0.1';
$rates = new RateLimit(sys_get_temp_dir() . '/beshare-os-meta-rate-' . bin2hex(random_bytes(4)), 20, 900);

$script = new ScriptedMeta();
$graph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $script);
$gateway = new WhatsAppGateway($pdo, $graph, $seal, $appId);
$http = new AuthHttp($pdo, $rates, $gateway);

$owner = [];
$issued = $http->handle('GET', 'sesi', $owner, [], [], $remote, $host, 'owner-session');
$created = $http->handle('POST', 'daftar', $owner, [
    'name' => 'Pemilik Meta',
    'email' => 'meta@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Meta',
], ['x-csrf-token' => $issued['body']['csrf']], $remote, $host, 'owner-session');
$csrf = (string) $created['body']['csrf'];
$check('signup session starts signed out of meta', ($created['body']['connection']['linked'] ?? true) === false);

$anon = [];
$anonCsrf = $http->handle('GET', 'sesi', $anon, [], [], $remote, $host, 'anon')['body']['csrf'];
$denied = catchStatus(function () use ($http, &$anon, $anonCsrf, $remote, $host): void {
    $http->handle('POST', 'sambung', $anon, [], ['x-csrf-token' => $anonCsrf], $remote, $host, 'anon');
});
$check('anonymous signup is rejected', $denied === 401);
$missingCsrf = catchStatus(function () use ($http, &$owner, $remote, $host): void {
    $http->handle('POST', 'sambung', $owner, [], ['x-csrf-token' => 'salah'], $remote, $host, 'owner-session');
});
$check('signup without csrf is rejected', $missingCsrf === 403);

$http->handle('POST', 'ahli', $owner, [
    'name' => 'Staf Meta',
    'email' => 'staf.meta@kedai.test',
    'password' => 'staf-laluan-panjang',
    'role' => 'staff',
], ['x-csrf-token' => $csrf], $remote, $host, 'owner-session');
$csrf = (string) $owner['csrf'];
$staff = [];
$staffIssued = $http->handle('GET', 'sesi', $staff, [], [], $remote, $host, 'staff-session');
$staffLogin = $http->handle('POST', 'masuk', $staff, [
    'email' => 'staf.meta@kedai.test',
    'password' => 'staf-laluan-panjang',
], ['x-csrf-token' => $staffIssued['body']['csrf']], $remote, $host, 'staff-session');
$staffDenied = catchStatus(function () use ($http, &$staff, $staffLogin, $remote, $host): void {
    $http->handle('POST', 'sambung', $staff, [], ['x-csrf-token' => $staffLogin['body']['csrf']], $remote, $host, 'staff-session');
});
$check('staff cannot start embedded signup', $staffDenied === 403);

$started = $http->handle('POST', 'sambung', $owner, [], ['x-csrf-token' => $csrf], $remote, $host, 'owner-session');
$state = (string) ($started['body']['state'] ?? '');
$csrf = (string) $started['body']['csrf'];
$check('signup state is a server secret', preg_match('/\A[0-9a-f]{64}\z/', $state) === 1);
$wrongSession = catchStatus(function () use ($http, &$owner, $state, $csrf, $remote, $host): void {
    $http->handle('POST', 'peristiwa', $owner, [
        'state' => $state,
        'event' => 'FINISH',
        'waba_id' => '100000000000101',
        'phone_number_id' => '100000000000202',
    ], ['x-csrf-token' => $csrf], $remote, $host, 'other-session');
});
$check('signup state rejects a different session', $wrongSession === 403);

$other = [];
$otherIssued = $http->handle('GET', 'sesi', $other, [], [], $remote, $host, 'other-owner');
$otherCreated = $http->handle('POST', 'daftar', $other, [
    'name' => 'Pemilik Lain',
    'email' => 'lain.meta@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Lain Meta',
], ['x-csrf-token' => $otherIssued['body']['csrf']], $remote, $host, 'other-owner');
$cross = catchStatus(function () use ($http, &$other, $otherCreated, $state, $remote, $host): void {
    $http->handle('POST', 'tukar', $other, [
        'state' => $state,
        'code' => 'test-code-abcdefgh',
    ], ['x-csrf-token' => $otherCreated['body']['csrf']], $remote, $host, 'other-owner');
});
$check('cross tenant signup state is rejected', $cross === 403);
$check('cross tenant rejection does not call meta', $script->calls === []);

$script->script = [oauthResponse($token)];
$early = $http->handle('POST', 'tukar', $owner, [
    'state' => $state,
    'code' => $code,
], ['x-csrf-token' => $csrf], $remote, $host, 'owner-session');
$csrf = (string) $early['body']['csrf'];
$earlyJson = json_encode($early['body'], JSON_UNESCAPED_SLASHES);
$check('code exchange before the finish event stores no browser token', ($early['body']['connection']['token'] ?? '') === 'Tersimpan' && is_string($earlyJson) && !str_contains($earlyJson, $token) && !str_contains($earlyJson, $code) && !str_contains($earlyJson, $secret));
$check('oauth exchange uses the documented get query', count($script->calls) === 1 && oauthExchangeIsDocumented($script->calls[0], $appId, $secret, $code) && credentialTextIsRedacted((string) $earlyJson, $secret, $code));

$script->script = [
    debugResponse($appId, $waba),
    ['status' => 200, 'json' => ['id' => $waba, 'name' => 'Kedai Ujian']],
    ['status' => 200, 'json' => ['data' => [[
        'id' => $phone,
        'display_phone_number' => '+1 555 000 1001',
        'status' => 'CONNECTED',
    ]]]],
    ['status' => 200, 'json' => ['success' => true]],
];
$recorded = $http->handle('POST', 'peristiwa', $owner, [
    'state' => $state,
    'event' => 'FINISH',
    'waba_id' => $waba,
    'phone_number_id' => $phone,
], ['x-csrf-token' => $csrf], $remote, $host, 'owner-session');
$csrf = (string) $recorded['body']['csrf'];
$recordedJson = json_encode($recorded['body'], JSON_UNESCAPED_SLASHES);
$check('verified assets become a tenant connection', ($recorded['body']['connection']['token'] ?? '') === 'Disahkan' && ($recorded['body']['connection']['webhook'] ?? '') === 'Dilanggan' && ($recorded['body']['connection']['registration'] ?? '') === 'Didaftarkan' && ($recorded['body']['connection']['messaging'] ?? '') === 'Belum' && ($recorded['body']['connection']['phone'] ?? '') === '+1 555 000 1001');
$check('connection response hides the token', is_string($recordedJson) && !str_contains($recordedJson, $token) && !str_contains($recordedJson, $secret));
$cipher = (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE token_ciphertext IS NOT NULL')->fetchColumn();
$plain = (string) $pdo->query("SELECT COUNT(*) FROM whatsapp_connections WHERE HEX(token_ciphertext) LIKE '%" . strtoupper(bin2hex($token)) . "%'")->fetchColumn();
$auditLeak = (string) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE meta_json LIKE '%EAA-test-token-not-real%' OR meta_json LIKE '%test-app-secret-value%'")->fetchColumn();
$onboardingLeft = (string) $pdo->query('SELECT COUNT(*) FROM onboarding_sessions WHERE status = \'completed\' AND token_ciphertext IS NULL')->fetchColumn();
$check('stored token is ciphertext', $cipher === '1' && $plain === '0' && $auditLeak === '0' && $onboardingLeft === '1');
$again = catchStatus(function () use ($http, &$owner, $state, $csrf, $code, $remote, $host): void {
    $http->handle('POST', 'tukar', $owner, ['state' => $state, 'code' => $code], ['x-csrf-token' => $csrf], $remote, $host, 'owner-session');
});
$check('authorization code cannot be exchanged twice', $again === 409);

$badState = (string) $http->handle('POST', 'sambung', $owner, [], ['x-csrf-token' => $owner['csrf']], $remote, $host, 'owner-session')['body']['state'];
$script->calls = [];
$script->script = [['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => 'should-not-leak ' . $token]]]];
$failed = 0;
$failedMessage = '';
try {
    $http->handle('POST', 'tukar', $owner, [
        'state' => $badState,
        'code' => 'failed-code-abcdefgh',
    ], ['x-csrf-token' => $owner['csrf']], $remote, $host, 'owner-session');
} catch (AuthException $error) {
    $failed = $error->status();
    $failedMessage = $error->getMessage();
}
$failedRow = $pdo->query("SELECT status, token_ciphertext IS NULL AS empty_token FROM onboarding_sessions ORDER BY id DESC LIMIT 1")->fetch();
$check('failed oauth exchange stores nothing', $failed === 502 && $failedMessage === 'Pertukaran kod Meta gagal.' && credentialTextIsRedacted($failedMessage, $secret, 'failed-code-abcdefgh') && isset($script->calls[0]) && oauthExchangeIsDocumented($script->calls[0], $appId, $secret, 'failed-code-abcdefgh') && is_array($failedRow) && $failedRow['status'] === 'rejected' && (int) $failedRow['empty_token'] === 1);
$check('failed oauth does not subscribe the waba', count($script->calls) === 1);

$forgedStart = $http->handle('POST', 'sambung', $owner, [], ['x-csrf-token' => $owner['csrf']], $remote, $host, 'owner-session');
$forgedState = (string) $forgedStart['body']['state'];
$http->handle('POST', 'peristiwa', $owner, [
    'state' => $forgedState,
    'event' => 'FINISH',
    'waba_id' => '100000000000909',
    'phone_number_id' => $phone,
], ['x-csrf-token' => $owner['csrf']], $remote, $host, 'owner-session');
$script->calls = [];
$script->script = [
    oauthResponse($token),
    debugResponse($appId, $waba),
];
$forged = catchStatus(function () use ($http, &$owner, $forgedState, $remote, $host): void {
    $http->handle('POST', 'tukar', $owner, [
        'state' => $forgedState,
        'code' => 'forged-code-abcdefgh',
    ], ['x-csrf-token' => $owner['csrf']], $remote, $host, 'owner-session');
});
$connections = (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections')->fetchColumn();
$check('forged waba is not bound', $forged === 409 && $connections === '1');

$expiredStart = $http->handle('POST', 'sambung', $other, [], ['x-csrf-token' => $other['csrf']], $remote, $host, 'other-owner');
$expiredState = (string) $expiredStart['body']['state'];
$pdo->prepare('UPDATE onboarding_sessions SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE correlation_hash = ?')->execute([hash('sha256', $expiredState)]);
$stale = catchStatus(function () use ($http, &$other, $expiredState, $remote, $host): void {
    $http->handle('POST', 'tukar', $other, [
        'state' => $expiredState,
        'code' => 'stale-code-abcdefgh',
    ], ['x-csrf-token' => $other['csrf']], $remote, $host, 'other-owner');
});
$check('stale signup session is rejected', $stale === 409);

$samePhone = $http->handle('POST', 'sambung', $other, [], ['x-csrf-token' => $other['csrf']], $remote, $host, 'other-owner');
$sameState = (string) $samePhone['body']['state'];
$http->handle('POST', 'peristiwa', $other, [
    'state' => $sameState,
    'event' => 'FINISH',
    'waba_id' => $waba,
    'phone_number_id' => $phone,
], ['x-csrf-token' => $other['csrf']], $remote, $host, 'other-owner');
$script->script = [
    oauthResponse($token . '-lain'),
    debugResponse($appId, $waba),
    ['status' => 200, 'json' => ['id' => $waba, 'name' => 'Kedai Ujian']],
    ['status' => 200, 'json' => ['data' => [[
        'id' => $phone,
        'display_phone_number' => '+1 555 000 1001',
        'status' => 'CONNECTED',
    ]]]],
    ['status' => 200, 'json' => ['success' => true]],
];
$occupied = catchStatus(function () use ($http, &$other, $sameState, $remote, $host): void {
    $http->handle('POST', 'tukar', $other, [
        'state' => $sameState,
        'code' => 'occupied-code-abcdef',
    ], ['x-csrf-token' => $other['csrf']], $remote, $host, 'other-owner');
});
$ownerStill = (string) $pdo->query('SELECT business_id FROM whatsapp_connections WHERE phone_number_id = \'100000000000202\'')->fetchColumn();
$ownerBusiness = (string) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Meta\'')->fetchColumn();
$check('cross tenant phone binding is rejected', $occupied === 409 && $ownerStill === $ownerBusiness);

$webhook = new WhatsAppWebhook($pdo, $seal);
$raw = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => $waba,
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => $phone],
                'contacts' => [['wa_id' => '15550001001', 'profile' => ['name' => 'Pelanggan Ujian']]],
                'messages' => [[
                    'from' => '15550001001',
                    'id' => 'wamid.TESTMESSAGE0001',
                    'timestamp' => (string) time(),
                    'type' => 'text',
                    'text' => ['body' => 'Harga harini?'],
                ]],
            ],
        ]],
    ]],
], JSON_UNESCAPED_SLASHES);
$badHeader = catchStatus(function () use ($webhook): void {
    if (!WhatsAppWebhook::signatureMatches('{}', 'sha256=' . str_repeat('00', 32), 'secret')) {
        throw new AuthException('Forbidden', 403);
    }
    $webhook->ingest('{}');
});
$before = (string) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$check('invalid signature does not record an event', $badHeader === 403 && $before === '0');
$first = $webhook->ingest((string) $raw);
$second = $webhook->ingest((string) $raw);
$rows = (string) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$linked = (string) $pdo->query('SELECT COUNT(*) FROM webhook_events WHERE business_id IS NOT NULL')->fetchColumn();
$check('signed message is stored once', $first['stored'] === 1 && $second['replayed'] === 1 && $rows === '1' && $linked === '1');
$messaging = (string) $pdo->query('SELECT messaging_state FROM whatsapp_connections WHERE phone_number_id = \'100000000000202\'')->fetchColumn();
$check('inbound message marks receive verified', $messaging === 'receive_verified');

$statusRaw = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => $phone],
                'statuses' => [[
                    'id' => 'wamid.TESTMESSAGE0001',
                    'status' => 'delivered',
                    'timestamp' => (string) time(),
                    'recipient_id' => '15550001001',
                ]],
            ],
        ]],
    ]],
], JSON_UNESCAPED_SLASHES);
$webhook->ingest((string) $statusRaw);
$foreignStatus = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => '100000000000303'],
                'statuses' => [[
                    'id' => 'wamid.TESTMESSAGE0001',
                    'status' => 'failed',
                    'timestamp' => (string) time(),
                    'recipient_id' => '15550001001',
                ]],
            ],
        ]],
    ]],
], JSON_UNESCAPED_SLASHES);
$webhook->ingest((string) $foreignStatus);
$inbox = $http->handle('GET', 'peti', $owner, [], [], $remote, $host, 'owner-session');
$otherInbox = $http->handle('GET', 'peti', $other, [], [], $remote, $host, 'other-owner');
$conversation = $inbox['body']['conversations'][0] ?? [];
$message = is_array($conversation) && isset($conversation['messages'][0]) ? $conversation['messages'][0] : [];
$check('inbox reads the verified webhook for its tenant', ($message['text'] ?? '') === 'Harga harini?' && ($message['status'] ?? '') === 'delivered' && ($message['wamid'] ?? '') === 'wamid.TESTMESSAGE0001');
$check('another tenant cannot read the webhook', ($otherInbox['body']['conversations'] ?? null) === []);

$expiredSeal = $seal->seal('token-tamat', 100);
$pdo->prepare(
    'INSERT INTO whatsapp_connections (
        business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
        registration_state, messaging_state, token_kind, token_ciphertext, token_nonce, token_tag,
        token_key_version, token_expires_at, refresh_supported, last_verified_at, created_at, updated_at
     ) VALUES (?, ?, ?, NULL, \'verified\', \'not_subscribed\', \'unknown\', \'unverified\', \'user_short_lived\',
        UNHEX(?), UNHEX(?), UNHEX(?), 1, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([
    otherBusiness($pdo),
    '100000000000404',
    '100000000000505',
    bin2hex($expiredSeal['ciphertext']),
    bin2hex($expiredSeal['nonce']),
    bin2hex($expiredSeal['tag']),
]);
$view = $http->handle('GET', 'sesi', $other, [], [], $remote, $host, 'other-owner');
$cleared = (string) $pdo->query('SELECT token_ciphertext IS NULL FROM whatsapp_connections WHERE phone_number_id = \'100000000000505\'')->fetchColumn();
$check('expired credentials are cleared', ($view['body']['connection']['token'] ?? '') === 'Tamat' && $cleared === '1');

$removed = $pdo->prepare('DELETE FROM business_users WHERE user_id = (SELECT id FROM users WHERE email = ?)');
$removed->execute(['staf.meta@kedai.test']);
$staleStaff = catchStatus(function () use ($http, &$staff, $remote, $host): void {
    $http->handle('POST', 'sambung', $staff, [], ['x-csrf-token' => (string) ($staff['csrf'] ?? '')], $remote, $host, 'staff-session');
});
$check('removed member cannot start signup', $staleStaff === 401);

exit($failures === 0 ? 0 : 1);

/** @param array{method:string,url:string,headers:array<string,string>,body:string} $call */
function oauthExchangeIsDocumented(array $call, string $appId, string $secret, string $code): bool
{
    if ($call['method'] !== 'GET' || $call['body'] !== '') {
        return false;
    }
    $parts = parse_url($call['url']);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.facebook.com') {
        return false;
    }
    if (($parts['path'] ?? '') !== '/v26.0/oauth/access_token') {
        return false;
    }
    parse_str((string) ($parts['query'] ?? ''), $query);
    return ($query['client_id'] ?? '') === $appId
        && ($query['client_secret'] ?? '') === $secret
        && ($query['code'] ?? '') === $code
        && !array_key_exists('redirect_uri', $query)
        && count($query) === 3;
}

function credentialTextIsRedacted(string $text, string $secret, string $code): bool
{
    return !str_contains($text, $secret)
        && !str_contains($text, $code)
        && !str_contains($text, 'client_secret=')
        && !str_contains($text, 'graph.facebook.com');
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

function otherBusiness(PDO $pdo): int
{
    return (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Lain Meta\'')->fetchColumn();
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
    $server->exec('DROP DATABASE IF EXISTS meta_lab');
    $server->exec('CREATE DATABASE meta_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE meta_lab');
    $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/0001_foundation.up.sql');
    foreach (SqlScript::statements($sql) as $statement) {
        $server->exec($statement);
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=meta_lab;charset=utf8mb4', 'root', $password, [
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
