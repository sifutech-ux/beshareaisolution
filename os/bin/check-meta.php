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
        && str_contains($dashboard, 'fallback_redirect_uri')
        && str_contains($dashboard, 'function facebookSdkReady')
        && str_contains($dashboard, 'function signupLoginOptions')
        && str_contains($dashboard, 'function coexistenceLoginOptions')
        && str_contains($dashboard, 'data-act="connect"')
        && str_contains($dashboard, 'data-act="connect-app"')
        && str_contains($dashboard, 'Sambung WhatsApp Business App')
        && str_contains($dashboard, 'featureType: "whatsapp_business_app_onboarding"')
        && !str_contains($dashboard, 'featureType: ""')
        && !str_contains($dashboard, "featureType: ''")
        && str_contains($dashboard, 'function signupDiagnostic')
        && str_contains($dashboard, 'source.error_code')
        && str_contains($dashboard, 'source.error_id')
        && str_contains($dashboard, 'window.FB.login')
        && !str_contains($dashboard, 'callApi("sambung", {}).then')
        && !str_contains($dashboard, 'error_message')
        && !str_contains($dashboard, 'sambungan/tukar.php')
        && !str_contains($dashboard, '27767197276313052')
        && !str_contains($dashboard, 'console.')
);
$check('inbox proposal is not executed by the migrator', is_file($root . '/os/proposals/0002_inbox.sql') && !str_contains($migrator, '0002_inbox'));
$gatewaySrc = (string) file_get_contents($root . '/os/src/WhatsAppGateway.php');
$graphSrc = (string) file_get_contents($root . '/os/src/MetaGraph.php');
$bindAt = strpos($gatewaySrc, 'private function bind(');
$coexistAt = strpos($gatewaySrc, 'private function bindCoexistence(');
$eligibleAt = strpos($gatewaySrc, 'private function eligibleCoexistencePhones(');
$standardBind = is_int($bindAt) && is_int($coexistAt) ? substr($gatewaySrc, $bindAt, $coexistAt - $bindAt) : '';
$coexistBind = is_int($coexistAt) && is_int($eligibleAt) ? substr($gatewaySrc, $coexistAt, $eligibleAt - $coexistAt) : '';
$check(
    'coexistence bind skips webhook subscription, registration, and history sync',
    str_contains($standardBind, '$this->graph->subscribe(')
        && !str_contains($coexistBind, 'graph->subscribe')
        && !str_contains($coexistBind, 'subscribed_apps')
        && !str_contains($coexistBind, '/register')
        && !str_contains($coexistBind, 'smb_app')
        && str_contains($graphSrc, 'is_on_biz_app,platform_type')
        && str_contains($graphSrc, 'function smbAppData')
        && !str_contains($graphSrc, '/register')
        && !str_contains((string) file_get_contents($root . '/os/src/WhatsAppWebhook.php'), 'smbAppData')
        && !str_contains($webhookFile, 'smb_app_data')
);

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

$plant = 'EAA-webhook-rahsia-jangan-simpan';
$signSecret = 'tandatangan-ujian';
$unknownRaw = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => '100000000000909'],
                'messages' => [[
                    'from' => '15550001009',
                    'id' => 'wamid.UNKNOWNPHONE0001',
                    'timestamp' => (string) time(),
                    'type' => 'text',
                    'text' => ['body' => 'nombor belum ada'],
                ]],
            ],
        ]],
    ]],
], JSON_UNESCAPED_SLASHES);
$unknownHeader = 'sha256=' . hash_hmac('sha256', (string) $unknownRaw, $signSecret);
$tampered = (string) $unknownRaw . ' ';
$beforeUnknown = (string) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$tamperRejected = !WhatsAppWebhook::signatureMatches($tampered, $unknownHeader, $signSecret);
$signed = WhatsAppWebhook::signatureMatches((string) $unknownRaw, $unknownHeader, $signSecret);
if ($signed) {
    $webhook->ingest((string) $unknownRaw);
    $webhook->ingest((string) $unknownRaw);
}
$unknownRow = $pdo->query('SELECT business_id, connection_id FROM webhook_events WHERE dedupe_key LIKE \'%UNKNOWNPHONE0001\'')->fetch();
$afterUnknown = (string) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$check(
    'signed unknown phone is stored once without a tenant',
    $tamperRejected && $signed && is_array($unknownRow) && $unknownRow['business_id'] === null && $unknownRow['connection_id'] === null && (int) $afterUnknown === (int) $beforeUnknown + 1
);
$invalidRaw = '{"object":"page"}';
$invalidHeader = 'sha256=' . hash_hmac('sha256', $invalidRaw, $signSecret);
$invalidStatus = catchStatus(function () use ($webhook, $invalidRaw, $invalidHeader, $signSecret): void {
    if (!WhatsAppWebhook::signatureMatches($invalidRaw, $invalidHeader, $signSecret)) {
        throw new AuthException('Forbidden', 403);
    }
    $webhook->ingest($invalidRaw);
});
$afterInvalid = (string) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$check('signed invalid object is rejected without a row', $invalidStatus === 400 && $afterInvalid === $afterUnknown);

$accountRaw = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => $waba,
        'changes' => [[
            'field' => 'account_update',
            'value' => ['event' => 'PARTNER_ADDED', 'access_token' => $plant],
        ]],
    ]],
], JSON_UNESCAPED_SLASHES);
$webhook->ingest((string) $accountRaw);
$account = $pdo->query('SELECT business_id, dedupe_key, HEX(payload_ciphertext) AS payload_ciphertext, HEX(payload_nonce) AS payload_nonce, HEX(payload_tag) AS payload_tag, payload_key_version FROM webhook_events WHERE event_kind = \'account_update\' ORDER BY id DESC LIMIT 1')->fetch();
$accountPlain = '';
if (is_array($account)) {
    $accountPlain = $seal->open(hex2bin((string) $account['payload_ciphertext']) ?: '', hex2bin((string) $account['payload_nonce']) ?: '', hex2bin((string) $account['payload_tag']) ?: '', (int) $account['payload_key_version']);
}
$ownerBusinessId = (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Meta\'')->fetchColumn();
$check(
    'account update routes to the single waba tenant without the raw payload',
    is_array($account) && (int) $account['business_id'] === $ownerBusinessId && !str_contains((string) $account['dedupe_key'], $plant) && !str_contains($accountPlain, $plant) && str_contains($accountPlain, $waba)
);
$secondSeal = $seal->seal('token-kedua', 100);
$pdo->prepare(
    'INSERT INTO whatsapp_connections (
        business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
        registration_state, messaging_state, token_kind, token_ciphertext, token_nonce, token_tag,
        token_key_version, refresh_supported, created_at, updated_at
     ) VALUES (?, ?, ?, NULL, \'verified\', \'not_subscribed\', \'unknown\', \'unverified\', \'system_user\',
        UNHEX(?), UNHEX(?), UNHEX(?), 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([
    otherBusiness($pdo),
    $waba,
    '100000000000808',
    bin2hex($secondSeal['ciphertext']),
    bin2hex($secondSeal['nonce']),
    bin2hex($secondSeal['tag']),
]);
$ambiguousRaw = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => $waba,
        'changes' => [[
            'field' => 'account_update',
            'value' => ['event' => 'PARTNER_REMOVED', 'secret' => $plant],
        ]],
    ]],
], JSON_UNESCAPED_SLASHES);
$webhook->ingest((string) $ambiguousRaw);
$ambiguous = $pdo->query('SELECT business_id FROM webhook_events WHERE event_kind = \'account_update\' ORDER BY id DESC LIMIT 1')->fetch();
$stillVerified = (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE token_state = \'verified\' AND disconnected_at IS NULL AND waba_id = \'' . $waba . '\'')->fetchColumn();
$check('ambiguous waba update stays unscoped', is_array($ambiguous) && $ambiguous['business_id'] === null && $stillVerified === '2');

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

$cancel = [];
$cancelIssued = $http->handle('GET', 'sesi', $cancel, [], [], $remote, $host, 'cancel-session');
$cancelCreated = $http->handle('POST', 'daftar', $cancel, [
    'name' => 'Pemilik Batal',
    'email' => 'batal.meta@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Batal',
], ['x-csrf-token' => $cancelIssued['body']['csrf']], $remote, $host, 'cancel-session');
$cancelStarted = $http->handle('POST', 'sambung', $cancel, [], ['x-csrf-token' => $cancelCreated['body']['csrf']], $remote, $host, 'cancel-session');
$cancelState = (string) ($cancelStarted['body']['state'] ?? '');
$planted = 'Garis panduan rahsia ujian 991';
$callsBefore = count($script->calls);
$cancelled = $http->handle('POST', 'peristiwa', $cancel, [
    'state' => $cancelState,
    'event' => 'CANCEL',
    'current_step' => 'PHONE_NUMBER_SETUP',
    'error_code' => 524126,
    'error_id' => 'LEGACY_SHOULD_LOSE',
    'session_id' => 'f34b51dab5e0498',
    'error_message' => $planted,
    'waba_id' => $waba,
    'phone_number_id' => '100000000000707',
], ['x-csrf-token' => $cancelStarted['body']['csrf']], $remote, $host, 'cancel-session');
$cancelJson = json_encode($cancelled['body'], JSON_UNESCAPED_UNICODE);
$cancelRow = $pdo->query('SELECT status, finish_event, current_step, meta_error_code, token_ciphertext IS NULL AS token_cleared FROM onboarding_sessions WHERE business_id = (SELECT id FROM businesses WHERE name = \'Kedai Batal\')')->fetch();
$cancelAudit = (string) $pdo->query('SELECT meta_json FROM audit_logs WHERE action = \'sambungan_dibatalkan\' ORDER BY id DESC LIMIT 1')->fetchColumn();
$cancelLinks = (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE business_id = (SELECT id FROM businesses WHERE name = \'Kedai Batal\')')->fetchColumn();
$check(
    'cancel with error code stays abandoned and stores only safe diagnostics',
    is_array($cancelRow)
        && $cancelRow['status'] === 'abandoned'
        && $cancelRow['finish_event'] === 'CANCEL'
        && $cancelRow['current_step'] === 'PHONE_NUMBER_SETUP'
        && $cancelRow['meta_error_code'] === '524126'
        && (string) $cancelRow['token_cleared'] === '1'
        && $cancelLinks === '0'
        && ($cancelled['body']['connection']['linked'] ?? true) === false
        && ($cancelled['body']['connection']['note'] ?? '') === 'Aliran Meta dibatalkan. Kod ralat 524126.'
        && is_string($cancelJson)
        && !str_contains($cancelJson, $planted)
        && !str_contains($cancelJson, 'f34b51dab5e0498')
        && str_contains(str_replace(' ', '', $cancelAudit), '"error_code":"524126"')
        && str_contains(str_replace(' ', '', $cancelAudit), '"session_id":"f34b51dab5e0498"')
        && str_contains(str_replace(' ', '', $cancelAudit), '"current_step":"PHONE_NUMBER_SETUP"')
        && !str_contains($cancelAudit, $planted)
        && !str_contains($cancelAudit, 'LEGACY_SHOULD_LOSE')
        && count($script->calls) === $callsBefore
);
$callsAtExchange = $script->calls;
$script->script = [oauthResponse('EAA-cancel-must-not-bind')];
$cancelExchange = catchStatus(function () use ($http, &$cancel, $cancelState, $cancelled, $remote, $host): void {
    $http->handle('POST', 'tukar', $cancel, [
        'state' => $cancelState,
        'code' => 'cancel-code-abcdefgh',
    ], ['x-csrf-token' => $cancelled['body']['csrf']], $remote, $host, 'cancel-session');
});
$cancelLinksAfter = (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE business_id = (SELECT id FROM businesses WHERE name = \'Kedai Batal\')')->fetchColumn();
$check('cancel blocks a later code exchange', $cancelExchange === 409 && $cancelLinksAfter === '0' && $script->calls === $callsAtExchange);

$legacyStarted = $http->handle('POST', 'sambung', $cancel, [], ['x-csrf-token' => $cancelled['body']['csrf']], $remote, $host, 'cancel-session');
$legacy = $http->handle('POST', 'peristiwa', $cancel, [
    'state' => (string) ($legacyStarted['body']['state'] ?? ''),
    'event' => 'CANCEL',
    'error_id' => 'ERR_LEGACY',
    'current_step' => 'PERMISSIONS',
], ['x-csrf-token' => $legacyStarted['body']['csrf']], $remote, $host, 'cancel-session');
$legacyCode = (string) $pdo->query('SELECT meta_error_code FROM onboarding_sessions WHERE finish_event = \'CANCEL\' AND current_step = \'PERMISSIONS\'')->fetchColumn();
$check('legacy error id is kept when error code is absent', $legacyCode === 'ERR_LEGACY' && ($legacy['body']['connection']['linked'] ?? true) === false);

$plainStarted = $http->handle('POST', 'sambung', $cancel, [], ['x-csrf-token' => $legacy['body']['csrf']], $remote, $host, 'cancel-session');
$plain = $http->handle('POST', 'peristiwa', $cancel, [
    'state' => (string) ($plainStarted['body']['state'] ?? ''),
    'event' => 'CANCEL',
    'current_step' => 'BUSINESS_ACCOUNT_SELECTION',
    'error_code' => 'kod tidak sah!',
], ['x-csrf-token' => $plainStarted['body']['csrf']], $remote, $host, 'cancel-session');
$plainRow = $pdo->query('SELECT meta_error_code, current_step FROM onboarding_sessions WHERE current_step = \'BUSINESS_ACCOUNT_SELECTION\'')->fetch();
$check(
    'plain cancel keeps the step and drops an unsafe code',
    is_array($plainRow) && $plainRow['meta_error_code'] === null && $plainRow['current_step'] === 'BUSINESS_ACCOUNT_SELECTION' && ($plain['body']['connection']['note'] ?? '') === 'Aliran Meta dibatalkan.'
);

$app = [];
$appIssued = $http->handle('GET', 'sesi', $app, [], [], $remote, $host, 'app-owner');
$appCreated = $http->handle('POST', 'daftar', $app, [
    'name' => 'Pemilik Aplikasi',
    'email' => 'aplikasi.meta@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Aplikasi',
], ['x-csrf-token' => $appIssued['body']['csrf']], $remote, $host, 'app-owner');
$appCsrf = (string) $appCreated['body']['csrf'];
$http->handle('POST', 'ahli', $app, [
    'name' => 'Staf Aplikasi',
    'email' => 'staf.aplikasi@kedai.test',
    'password' => 'staf-laluan-panjang',
    'role' => 'staff',
], ['x-csrf-token' => $appCsrf], $remote, $host, 'app-owner');
$appStaff = [];
$appStaffIssued = $http->handle('GET', 'sesi', $appStaff, [], [], $remote, $host, 'app-staff');
$appStaffLogin = $http->handle('POST', 'masuk', $appStaff, [
    'email' => 'staf.aplikasi@kedai.test',
    'password' => 'staf-laluan-panjang',
], ['x-csrf-token' => $appStaffIssued['body']['csrf']], $remote, $host, 'app-staff');
$appStaffDenied = catchStatus(function () use ($http, &$appStaff, $appStaffLogin, $remote, $host): void {
    $http->handle('POST', 'sambung', $appStaff, [], ['x-csrf-token' => $appStaffLogin['body']['csrf']], $remote, $host, 'app-staff');
});
$check('staff cannot start coexistence onboarding', $appStaffDenied === 403);

$appWaba = '100000000000611';
$appPhone = '100000000000612';
$appOtherPhone = '100000000000613';
$appToken = 'EAA-coexistence-token-not-real';
$beforeAppLinks = (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections')->fetchColumn();
$script->calls = [];
$script->script = [oauthResponse($appToken)];
$appStarted = $http->handle('POST', 'sambung', $app, [], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$appState = (string) ($appStarted['body']['state'] ?? '');
$appHeld = $http->handle('POST', 'tukar', $app, [
    'state' => $appState,
    'code' => 'app-code-abcdefghij',
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$heldRow = latestOnboarding($pdo, 'Kedai Aplikasi');
$script->script = [
    debugResponse($appId, $appWaba),
    ['status' => 200, 'json' => ['id' => $appWaba, 'name' => 'Kedai Aplikasi']],
    ['status' => 200, 'json' => ['data' => [
        coexistPhone($appPhone, true, 'CLOUD_API', '+60 12 300 0612'),
        coexistPhone($appOtherPhone, false, 'CLOUD_API', '+60 13 300 0613'),
    ]]],
    ['status' => 200, 'json' => ['success' => true]],
];
$appFinished = $http->handle('POST', 'peristiwa', $app, [
    'state' => $appState,
    'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
    'waba_id' => $appWaba,
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$appJson = json_encode($appFinished['body'], JSON_UNESCAPED_SLASHES);
$appTargets = callTargets($script->calls);
$appRow = $pdo->query('SELECT business_id, waba_id, phone_number_id, webhook_state, registration_state, messaging_state, subscribed_at IS NULL AS unsubscribed, HEX(token_ciphertext) AS token_hex FROM whatsapp_connections WHERE phone_number_id = \'100000000000612\'')->fetch();
$appBusiness = (string) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Aplikasi\'')->fetchColumn();
$check(
    'waba-only coexistence finish binds the single flagged phone without subscribing',
    is_array($heldRow)
        && $heldRow['status'] === 'token_held'
        && ($appHeld['body']['connection']['linked'] ?? true) === false
        && is_array($appRow)
        && (string) $appRow['business_id'] === $appBusiness
        && $appRow['waba_id'] === $appWaba
        && $appRow['phone_number_id'] === $appPhone
        && $appRow['webhook_state'] === 'not_subscribed'
        && $appRow['registration_state'] === 'registered'
        && $appRow['messaging_state'] === 'unverified'
        && (string) $appRow['unsubscribed'] === '1'
        && ($appFinished['body']['connection']['token'] ?? '') === 'Disahkan'
        && ($appFinished['body']['connection']['webhook'] ?? '') === 'Belum dilanggan'
        && ($appFinished['body']['connection']['phone'] ?? '') === '+60 12 300 0612'
        && ($appFinished['body']['connection']['messaging'] ?? '') === 'Belum'
        && is_string($appJson)
        && !str_contains($appJson, $appToken)
        && !str_contains($appJson, 'app-code-abcdefghij')
        && !str_contains(strtoupper((string) $appRow['token_hex']), strtoupper(bin2hex($appToken)))
        && coexistenceCallsAreSafe($appTargets)
        && str_contains($appTargets, '/phone_numbers?')
        && str_contains($appTargets, 'is_on_biz_app')
        && str_contains($appTargets, 'platform_type')
        && !str_contains($appTargets, 'subscribed_apps')
);
$check('coexistence does not bind the unflagged extra phone', (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE phone_number_id = \'100000000000613\'')->fetchColumn() === '0');

$earlyWaba = '100000000000621';
$earlyPhone = '100000000000622';
$earlyToken = 'EAA-coexistence-early-not-real';
$script->calls = [];
$script->script = [];
$earlyStarted = $http->handle('POST', 'sambung', $app, [], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$earlyState = (string) ($earlyStarted['body']['state'] ?? '');
$earlyEvent = $http->handle('POST', 'peristiwa', $app, [
    'state' => $earlyState,
    'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
    'waba_id' => $earlyWaba,
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$earlyCalls = $script->calls;
$earlyPending = latestOnboarding($pdo, 'Kedai Aplikasi');
$script->script = [
    oauthResponse($earlyToken),
    debugResponse($appId, $earlyWaba),
    ['status' => 200, 'json' => ['id' => $earlyWaba, 'name' => 'Kedai Aplikasi']],
    ['status' => 200, 'json' => ['data' => [
        coexistPhone($earlyPhone, true, 'CLOUD_API', '+60 12 300 0622'),
    ]]],
];
$earlyDone = $http->handle('POST', 'tukar', $app, [
    'state' => $earlyState,
    'code' => 'early-code-abcdefgh',
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$earlyRow = $pdo->query('SELECT webhook_state, phone_number_id FROM whatsapp_connections WHERE phone_number_id = \'100000000000622\'')->fetch();
$check(
    'coexistence event before the code stays pending and then binds',
    $earlyCalls === []
        && is_array($earlyPending)
        && $earlyPending['status'] === 'pending'
        && $earlyPending['finish_event'] === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'
        && $earlyPending['reported_waba_id'] === $earlyWaba
        && $earlyPending['reported_phone_number_id'] === null
        && is_array($earlyRow)
        && $earlyRow['webhook_state'] === 'not_subscribed'
        && ($earlyDone['body']['connection']['phone'] ?? '') === '+60 12 300 0622'
        && coexistenceCallsAreSafe(callTargets($script->calls))
);

$ambiguous = runCoexistence($http, $pdo, $script, $app, $remote, $host, 'app-owner', $appId, '100000000000631', 'EAA-ambiguous-token-not-real', 'ambiguous-code-abcdef', [
    coexistPhone('100000000000632', true, 'CLOUD_API', '+60 12 300 0632'),
    coexistPhone('100000000000633', true, 'CLOUD_API', '+60 12 300 0633'),
], '100000000000631');
$check(
    'two flagged phones stay incomplete and unbound',
    $ambiguous['status'] === 'incomplete'
        && $ambiguous['code'] === 'coexistence_ambiguous'
        && $ambiguous['links'] === 0
        && $ambiguous['safe']
);

$invalidPlatform = runCoexistence($http, $pdo, $script, $app, $remote, $host, 'app-owner', $appId, '100000000000641', 'EAA-platform-token-not-real', 'platform-code-abcdef', [
    coexistPhone('100000000000642', true, 'CONSUMER', '+60 12 300 0642'),
], '100000000000641');
$check(
    'a single phone with the wrong platform stays incomplete',
    $invalidPlatform['status'] === 'incomplete'
        && $invalidPlatform['code'] === 'coexistence_absent'
        && $invalidPlatform['links'] === 0
        && $invalidPlatform['safe']
);

$invalidFlag = runCoexistence($http, $pdo, $script, $app, $remote, $host, 'app-owner', $appId, '100000000000651', 'EAA-flag-token-not-real', 'flag-code-abcdefghij', [
    [
        'id' => '100000000000652',
        'display_phone_number' => '+60 12 300 0652',
        'status' => 'CONNECTED',
        'is_on_biz_app' => 1,
        'platform_type' => 'CLOUD_API',
    ],
], '100000000000651');
$check(
    'a non-boolean coexistence flag does not make the only phone eligible',
    $invalidFlag['status'] === 'incomplete' && $invalidFlag['code'] === 'coexistence_absent' && $invalidFlag['links'] === 0 && $invalidFlag['safe']
);

$mismatch = runCoexistence($http, $pdo, $script, $app, $remote, $host, 'app-owner', $appId, '100000000000661', 'EAA-mismatch-token-not-real', 'mismatch-code-abcdef', [
    coexistPhone('100000000000662', true, 'CLOUD_API', '+60 12 300 0662'),
    coexistPhone('100000000000663', false, 'CLOUD_API', '+60 13 300 0663'),
], '100000000000661', '100000000000663');
$check(
    'a reported phone that is not the flagged phone stays unbound',
    $mismatch['status'] === 'incomplete'
        && $mismatch['code'] === 'coexistence_mismatch'
        && $mismatch['links'] === 0
        && (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE phone_number_id = \'100000000000662\'')->fetchColumn() === '0'
        && $mismatch['safe']
);

$missingPhones = runCoexistence($http, $pdo, $script, $app, $remote, $host, 'app-owner', $appId, '100000000000671', 'EAA-empty-token-not-real', 'empty-code-abcdefgh', [], '100000000000671');
$check(
    'an empty phone list stays incomplete',
    $missingPhones['status'] === 'incomplete' && $missingPhones['code'] === 'coexistence_absent' && $missingPhones['links'] === 0 && $missingPhones['safe']
);

$script->calls = [];
$script->script = [oauthResponse('EAA-missing-waba-not-real')];
$missingStarted = $http->handle('POST', 'sambung', $app, [], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$missingState = (string) ($missingStarted['body']['state'] ?? '');
$http->handle('POST', 'tukar', $app, [
    'state' => $missingState,
    'code' => 'missing-waba-code-ab',
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$callsAfterMissingExchange = count($script->calls);
$missingEvent = $http->handle('POST', 'peristiwa', $app, [
    'state' => $missingState,
    'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$missingRow = latestOnboarding($pdo, 'Kedai Aplikasi');
$check(
    'coexistence without a waba stays incomplete and does not list phones',
    is_array($missingRow)
        && $missingRow['status'] === 'incomplete'
        && $missingRow['meta_error_code'] === 'coexistence_waba'
        && (string) $missingRow['token_cleared'] === '1'
        && count($script->calls) === $callsAfterMissingExchange
        && coexistenceCallsAreSafe(callTargets($script->calls))
);

$foreign = runCoexistence($http, $pdo, $script, $app, $remote, $host, 'app-owner', $appId, '100000000000681', 'EAA-foreign-waba-not-real', 'foreign-code-abcdef', [
    coexistPhone('100000000000682', true, 'CLOUD_API', '+60 12 300 0682'),
], '100000000000681', null, '100000000000699');
$check(
    'a waba outside the authorized token stays incomplete',
    $foreign['status'] === 'incomplete' && $foreign['code'] === 'waba' && $foreign['links'] === 0 && $foreign['safe'] && !str_contains($foreign['targets'], 'subscribed_apps')
);

$edge = [];
$edgeIssued = $http->handle('GET', 'sesi', $edge, [], [], $remote, $host, 'edge-owner');
$edgeCreated = $http->handle('POST', 'daftar', $edge, [
    'name' => 'Pemilik Sempadan',
    'email' => 'sempadan.meta@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Sempadan',
], ['x-csrf-token' => $edgeIssued['body']['csrf']], $remote, $host, 'edge-owner');
$script->calls = [];
$script->script = [oauthResponse('EAA-edge-should-not-exchange')];
$edgeCross = catchStatus(function () use ($http, &$edge, $edgeCreated, $appState, $appWaba, $remote, $host): void {
    $http->handle('POST', 'peristiwa', $edge, [
        'state' => $appState,
        'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
        'waba_id' => $appWaba,
    ], ['x-csrf-token' => $edgeCreated['body']['csrf']], $remote, $host, 'edge-owner');
});
$edgeExchange = catchStatus(function () use ($http, &$edge, $appState, $remote, $host): void {
    $http->handle('POST', 'tukar', $edge, [
        'state' => $appState,
        'code' => 'edge-code-abcdefghij',
    ], ['x-csrf-token' => $edge['csrf']], $remote, $host, 'edge-owner');
});
$edgeLinks = (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE business_id = (SELECT id FROM businesses WHERE name = \'Kedai Sempadan\')')->fetchColumn();
$check('another tenant cannot use a coexistence session', $edgeCross === 403 && $edgeExchange === 403 && $edgeLinks === '0' && $script->calls === []);

$cancelApp = $http->handle('POST', 'sambung', $app, [], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$cancelAppState = (string) ($cancelApp['body']['state'] ?? '');
$script->calls = [];
$script->script = [oauthResponse('EAA-app-cancel-not-real')];
$cancelAppEvent = $http->handle('POST', 'peristiwa', $app, [
    'state' => $cancelAppState,
    'event' => 'CANCEL',
    'current_step' => 'WHATSAPP_BUSINESS_APP_ONBOARDING',
    'error_code' => '524126',
    'error_message' => 'do-not-store-app-cancel',
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$cancelAppRow = latestOnboarding($pdo, 'Kedai Aplikasi');
$cancelAppExchange = catchStatus(function () use ($http, &$app, $cancelAppState, $remote, $host): void {
    $http->handle('POST', 'tukar', $app, [
        'state' => $cancelAppState,
        'code' => 'app-cancel-code-abcd',
    ], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
});
$check(
    'coexistence cancel stays abandoned and does not bind',
    is_array($cancelAppRow)
        && $cancelAppRow['status'] === 'abandoned'
        && $cancelAppRow['meta_error_code'] === '524126'
        && (string) $cancelAppRow['token_cleared'] === '1'
        && $cancelAppExchange === 409
        && $script->calls === []
        && !str_contains((string) json_encode($cancelAppEvent['body']), 'do-not-store-app-cancel')
);

$errorApp = $http->handle('POST', 'sambung', $app, [], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$errorAppEvent = $http->handle('POST', 'peristiwa', $app, [
    'state' => (string) ($errorApp['body']['state'] ?? ''),
    'event' => 'ERROR',
    'error_code' => 'APP_ERROR',
    'waba_id' => '100000000000691',
    'phone_number_id' => '100000000000692',
], ['x-csrf-token' => $app['csrf']], $remote, $host, 'app-owner');
$errorAppRow = latestOnboarding($pdo, 'Kedai Aplikasi');
$check(
    'coexistence error is rejected without a connection',
    is_array($errorAppRow)
        && $errorAppRow['status'] === 'rejected'
        && $errorAppRow['finish_event'] === 'ERROR'
        && (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections WHERE phone_number_id = \'100000000000692\'')->fetchColumn() === '0'
);
$check(
    'coexistence added connections only for flagged phones',
    (string) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections')->fetchColumn() === (string) ((int) $beforeAppLinks + 2)
);

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

/** @param array<string,mixed> $phone */
function coexistPhone(string $id, bool $onApp, string $platform, string $display): array
{
    return [
        'id' => $id,
        'display_phone_number' => $display,
        'status' => 'CONNECTED',
        'is_on_biz_app' => $onApp,
        'platform_type' => $platform,
    ];
}

/** @param list<array{method:string,url:string,headers:array<string,string>,body:string}> $calls */
function callTargets(array $calls): string
{
    $lines = [];
    foreach ($calls as $call) {
        $path = parse_url($call['url'], PHP_URL_PATH);
        $query = parse_url($call['url'], PHP_URL_QUERY);
        $lines[] = $call['method'] . ' ' . (is_string($path) ? $path : '') . (is_string($query) ? '?' . $query : '');
    }
    return implode("\n", $lines);
}

function coexistenceCallsAreSafe(string $targets): bool
{
    return !str_contains($targets, 'subscribed_apps')
        && !str_contains($targets, 'smb_app_data')
        && !str_contains($targets, '/register');
}

/** @return array<string,mixed>|false */
function latestOnboarding(PDO $pdo, string $business): array|false
{
    $statement = $pdo->prepare(
        'SELECT status, finish_event, meta_error_code, reported_waba_id, reported_phone_number_id,
                token_ciphertext IS NULL AS token_cleared
         FROM onboarding_sessions
         WHERE business_id = (SELECT id FROM businesses WHERE name = ?)
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute([$business]);
    $row = $statement->fetch();
    return is_array($row) ? $row : false;
}

/**
 * @param array<string,mixed> $session
 * @param list<array<string,mixed>> $phones
 * @return array{status:string,code:string,links:int,note:string,safe:bool,targets:string}
 */
function runCoexistence(AuthHttp $http, PDO $pdo, ScriptedMeta $script, array &$session, string $remote, string $host, string $key, string $appId, string $waba, string $token, string $code, array $phones, string $reportedWaba, ?string $reportedPhone = null, ?string $authorizedWaba = null): array
{
    $before = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections')->fetchColumn();
    $script->calls = [];
    $script->script = [oauthResponse($token)];
    $started = $http->handle('POST', 'sambung', $session, [], ['x-csrf-token' => (string) $session['csrf']], $remote, $host, $key);
    $state = (string) ($started['body']['state'] ?? '');
    $http->handle('POST', 'tukar', $session, [
        'state' => $state,
        'code' => $code,
    ], ['x-csrf-token' => (string) $session['csrf']], $remote, $host, $key);
    $script->script = [
        debugResponse($appId, $authorizedWaba ?? $waba),
        ['status' => 200, 'json' => ['id' => $waba, 'name' => 'Kedai Aplikasi']],
        ['status' => 200, 'json' => ['data' => $phones]],
        ['status' => 200, 'json' => ['success' => true]],
    ];
    $body = [
        'state' => $state,
        'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
        'waba_id' => $reportedWaba,
    ];
    if ($reportedPhone !== null) {
        $body['phone_number_id'] = $reportedPhone;
    }
    $finished = $http->handle('POST', 'peristiwa', $session, $body, ['x-csrf-token' => (string) $session['csrf']], $remote, $host, $key);
    $row = latestOnboarding($pdo, 'Kedai Aplikasi');
    $payload = json_encode($finished['body'], JSON_UNESCAPED_SLASHES);
    $after = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_connections')->fetchColumn();
    return [
        'status' => is_array($row) ? (string) $row['status'] : '',
        'code' => is_array($row) ? (string) ($row['meta_error_code'] ?? '') : '',
        'links' => $after - $before,
        'note' => (string) ($finished['body']['connection']['note'] ?? ''),
        'safe' => is_string($payload) && !str_contains($payload, $token) && !str_contains($payload, $code),
        'targets' => callTargets($script->calls),
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
    $server->exec('DROP DATABASE IF EXISTS meta_lab');
    $server->exec('CREATE DATABASE meta_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE meta_lab');
    foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql'] as $name) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/' . $name);
        foreach (SqlScript::statements($sql) as $statement) {
            $server->exec($statement);
        }
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
