<?php
declare(strict_types=1);

use BesharOs\ActivationGate;
use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\CoexistenceSync;
use BesharOs\CoexistenceWorker;
use BesharOs\MetaGraph;
use BesharOs\MetaTransport;
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

    public bool $throwTransport = false;

    public function call(string $method, string $url, array $headers, string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->throwTransport) {
            throw new RuntimeException('timeout');
        }
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
    $command = 'env -u ' . ActivationGate::ENV;
    foreach ($env as $key => $value) {
        $command .= ' ' . $key . '=' . escapeshellarg($value);
    }
    $command .= ' php ' . escapeshellarg(__DIR__ . '/sync-coexistence.php');
    foreach ($args as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $command .= ' 2>&1';
    $output = [];
    exec($command, $output, $code);
    return [$code, implode("\n", $output)];
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
    $server->exec('DROP DATABASE IF EXISTS worker_lab');
    $server->exec('CREATE DATABASE worker_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE worker_lab');
    foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql'] as $name) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/' . $name);
        foreach (SqlScript::statements($sql) as $statement) {
            $server->exec($statement);
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=worker_lab;charset=utf8mb4', 'root', $password, [
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

/** @param array<string,mixed> $session */
function post(AuthHttp $http, array &$session, string $action, array $body, string $key): array
{
    return $http->handle('POST', $action, $session, $body, ['x-csrf-token' => (string) ($session['csrf'] ?? '')], '127.0.0.1', 'lab.test', $key);
}

function insertLink(PDO $pdo, TokenSeal $seal, int $businessId, string $waba, string $phone, string $display, string $deadline): int
{
    $sealed = $seal->seal('token-pekerja-bukan-live', 100);
    $insert = $pdo->prepare(
        'INSERT INTO whatsapp_connections (
            business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
            registration_state, messaging_state, sync_contacts, sync_history, token_kind,
            token_ciphertext, token_nonce, token_tag, token_key_version, refresh_supported,
            subscribed_at, created_at, updated_at, onboarded_at, sync_deadline_at
         ) VALUES (
            ?, ?, ?, ?, \'verified\', \'subscribed\', \'registered\', \'unverified\', \'pending\', \'pending\', \'system_user\',
            UNHEX(?), UNHEX(?), UNHEX(?), ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
            CASE WHEN ? = \'past\' THEN DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) ELSE DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) END
         )'
    );
    $insert->execute([
        $businessId, $waba, $phone, $display,
        bin2hex($sealed['ciphertext']), bin2hex($sealed['nonce']), bin2hex($sealed['tag']), $sealed['version'], $deadline,
    ]);
    return (int) $pdo->lastInsertId();
}

function markReady(PDO $pdo, int $connectionId, string $part): void
{
    global $seal, $secret;
    $statement = $pdo->prepare('SELECT business_id, waba_id, phone_number_id FROM whatsapp_connections WHERE id = ?');
    $statement->execute([$connectionId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('sambungan ujian tiada');
    }
    if (!CoexistenceSync::hasDeliveryEvidence($pdo, $connectionId, (int) $row['business_id'])) {
        $raw = envelope((string) $row['waba_id'], 'smb_message_echoes', [
            'metadata' => ['phone_number_id' => (string) $row['phone_number_id']],
            'message_echoes' => [[
                'from' => '60120000000',
                'to' => '60120000001',
                'id' => 'wamid.BUKTI.' . $connectionId,
                'timestamp' => '1710000000',
                'type' => 'text',
                'text' => ['body' => 'bukti-gema-' . $connectionId],
            ]],
        ]);
        signedIngest(new WhatsAppWebhook($pdo, $seal), $raw, $secret);
    }
    CoexistenceSync::record($pdo, $connectionId, $part);
}

/** @return array{status:int,json:array<string,mixed>} */
function accepted(string $requestId): array
{
    return ['status' => 200, 'json' => ['messaging_product' => 'whatsapp', 'request_id' => $requestId]];
}

/** @return array{status:string,attempt_count:int,max_attempts:int,last_error_code:string,request_id:?string} */
function jobState(PDO $pdo, int $jobId): array
{
    $statement = $pdo->prepare(
        'SELECT status, attempt_count, max_attempts, last_error_code, request_id FROM coexistence_sync_jobs WHERE id = ?'
    );
    $statement->execute([$jobId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return ['status' => '', 'attempt_count' => -1, 'max_attempts' => -1, 'last_error_code' => '', 'request_id' => null];
    }
    return [
        'status' => (string) $row['status'],
        'attempt_count' => (int) $row['attempt_count'],
        'max_attempts' => (int) $row['max_attempts'],
        'last_error_code' => (string) ($row['last_error_code'] ?? ''),
        'request_id' => is_string($row['request_id'] ?? null) ? (string) $row['request_id'] : null,
    ];
}

/** @param array<string,mixed> $value */
function envelope(string $waba, string $field, array $value): string
{
    $json = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => $waba,
            'changes' => [['field' => $field, 'value' => $value]],
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

$secretPlant = 'rahsia-pekerja-log';
$phrase = 'BADAN-MESEJ-PEKERJA';
[$disabledCode, $disabledOut] = runCli([
    'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
    'BESHARE_OS_DB_USER' => 'x',
    'BESHARE_OS_DB_PASSWORD' => $secretPlant,
]);
$check(
    'default worker stays disabled and does not connect',
    $disabledCode === 0 && str_contains($disabledOut, 'Penyegerakan langsung dimatikan') && !str_contains($disabledOut, $secretPlant)
);
foreach (['TRUE', '1', 'yes', 'false', ''] as $flag) {
    [$code, $out] = runCli([
        ActivationGate::ENV => $flag,
        'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
        'BESHARE_OS_DB_PASSWORD' => $secretPlant,
    ]);
    $label = $flag === '' ? 'empty' : $flag;
    $check(
        'malformed activation flag ' . $label . ' stays disabled',
        $code === 0 && str_contains($out, 'Penyegerakan langsung dimatikan') && !str_contains($out, $secretPlant)
    );
}
[$missingCode, $missingOut] = runCli([
    ActivationGate::ENV => 'true',
    'BESHARE_OS_WEBHOOK_CONFIG' => '/tmp/beshare-missing-worker-config.php',
    'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
    'BESHARE_OS_DB_PASSWORD' => $secretPlant,
]);
$check(
    'enabled worker without private config makes no call',
    $missingCode === 1 && str_contains($missingOut, 'Konfigurasi pelayan tidak lengkap') && !str_contains($missingOut, $secretPlant)
);
[$recordCode, $recordOut] = runCli([
    'BESHARE_OS_DSN' => 'mysql:host=127.0.0.1;port=1;dbname=none',
    'BESHARE_OS_DB_PASSWORD' => $secretPlant,
], ['record-callback', '--connection=1']);
$check(
    'readiness is not recorded without explicit confirmation',
    $recordCode === 1 && str_contains($recordOut, 'Rekod tidak ditulis') && !str_contains($recordOut, $secretPlant)
);
$check('activation accepts only the exact true flag', ActivationGate::enabled('true') && ActivationGate::enabled('TRUE') === false && ActivationGate::enabled(null) === false);

$pdo = lab();
if (!$pdo instanceof PDO) {
    fwrite(STDOUT, "FAIL lab database\n");
    exit(1);
}
$check('worker tests use an isolated lab', (string) $pdo->query('SELECT DATABASE()')->fetchColumn() === 'worker_lab');

$appId = BESHARE_META_APP_ID;
$secret = 'tandatangan-pekerja';
$token = 'EAA-pekerja-token-makmal';
$seal = new TokenSeal(random_bytes(32), 1);
$rates = new RateLimit(sys_get_temp_dir() . '/beshare-os-worker-rate-' . bin2hex(random_bytes(4)), 40, 900);
$httpGraph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, new ScriptedMeta());
$http = new AuthHttp($pdo, $rates, new WhatsAppGateway($pdo, $httpGraph, $seal, $appId));
$session = [];
$issued = $http->handle('GET', 'sesi', $session, [], [], '127.0.0.1', 'lab.test', 'worker-owner');
$session['csrf'] = $issued['body']['csrf'];
post($http, $session, 'daftar', [
    'name' => 'Pemilik Pekerja',
    'email' => 'pekerja.coex@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Pekerja',
], 'worker-owner');
$other = [];
$otherIssued = $http->handle('GET', 'sesi', $other, [], [], '127.0.0.1', 'lab.test', 'worker-other');
$other['csrf'] = $otherIssued['body']['csrf'];
post($http, $other, 'daftar', [
    'name' => 'Pemilik Lain',
    'email' => 'lain.pekerja@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Lain Pekerja',
], 'worker-other');
$businessId = (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Pekerja\'')->fetchColumn();
$otherBusiness = (int) $pdo->query('SELECT id FROM businesses WHERE name = \'Kedai Lain Pekerja\'')->fetchColumn();
$mainId = insertLink($pdo, $seal, $businessId, '100000000000911', '100000000000811', '+60 12 300 0811', 'future');
$authorized = post($http, $session, 'segera', ['sync_type' => 'smb_app_state_sync'], 'worker-owner');
$contactJob = (int) ($authorized['body']['sync']['job_id'] ?? 0);
$transport = new ScriptedMeta();
$graph = new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $transport);
$sync = new CoexistenceSync($pdo, $graph, $seal, true);
$blocked = $sync->dispatch($businessId, $contactJob, time());
$check(
    'dispatch waits for recorded callback and field readiness',
    ($blocked['calls_graph'] ?? true) === false && ($blocked['blocked'] ?? '') === 'callback' && $transport->calls === []
);
markReady($pdo, $mainId, 'callback');
$fieldsBlocked = $sync->dispatch($businessId, $contactJob, time());
$check('callback readiness alone does not dispatch', ($fieldsBlocked['blocked'] ?? '') === 'fields' && $transport->calls === []);
markReady($pdo, $mainId, 'fields');
$processor = new CoexistenceSync($pdo, $graph, $seal, true, static fn (): bool => false);
$processorBlocked = $processor->dispatch($businessId, $contactJob, time());
$check('local processor failure does not dispatch', ($processorBlocked['blocked'] ?? '') === 'processor' && $transport->calls === []);
$held = CoexistenceWorker::process($pdo, $graph, $seal, time(), false);
$check(
    'a disabled worker does not claim a due job',
    ($held['mode'] ?? '') === 'disabled' && ($held['sent'] ?? 1) === 0 && $transport->calls === []
);
$transport->script = [accepted('req-pekerja-kenalan')];
ob_start();
$ran = CoexistenceWorker::process($pdo, $graph, $seal, time(), true);
$noise = (string) ob_get_clean();
$contactCall = $transport->calls[0] ?? ['url' => '', 'body' => '', 'headers' => []];
$check(
    'enabled worker dispatches one mocked contact sync',
    ($ran['mode'] ?? '') === 'enabled'
        && ($ran['sent'] ?? 0) === 1
        && count($transport->calls) === 1
        && str_contains((string) $contactCall['url'], '/100000000000811/smb_app_data')
        && ($contactCall['body'] ?? '') === '{"messaging_product":"whatsapp","sync_type":"smb_app_state_sync"}'
        && ($contactCall['headers']['authorization'] ?? '') === 'Bearer token-pekerja-bukan-live'
        && !str_contains($noise, $token)
        && !str_contains($noise, $phrase)
);
$again = CoexistenceWorker::process($pdo, $graph, $seal, time(), true);
$check('a second worker run does not repeat the contact request', ($again['sent'] ?? 1) === 0 && count($transport->calls) === 1);
$historyAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$historyJob = (int) ($historyAuth['body']['sync']['job_id'] ?? 0);
$historyAgain = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$transport->script = [accepted('req-pekerja-sejarah')];
$historyRun = CoexistenceWorker::process($pdo, $graph, $seal, time(), true);
$historyCall = $transport->calls[1] ?? ['body' => ''];
$check(
    'enabled worker dispatches history once and keeps the same job',
    $historyJob > 0
        && (int) ($historyAgain['body']['sync']['job_id'] ?? 0) === $historyJob
        && ($historyRun['sent'] ?? 0) === 1
        && ($historyCall['body'] ?? '') === '{"messaging_product":"whatsapp","sync_type":"history"}'
        && count($transport->calls) === 2
);
$foreign = 0;
try {
    $sync->dispatch($otherBusiness, $contactJob, time());
} catch (AuthException $error) {
    $foreign = $error->status();
}
$check('another tenant cannot dispatch the job', $foreign === 404 && count($transport->calls) === 2);

$deadlineId = insertLink($pdo, $seal, $businessId, '100000000000921', '100000000000821', '+60 12 300 0821', 'future');
markReady($pdo, $deadlineId, 'callback');
markReady($pdo, $deadlineId, 'fields');
$deadlineAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$deadlineJob = (int) ($deadlineAuth['body']['sync']['job_id'] ?? 0);
$pdo->prepare('UPDATE whatsapp_connections SET sync_deadline_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE id = ?')->execute([$deadlineId]);
$deadlineCalls = count($transport->calls);
$deadlineResult = $sync->dispatch($businessId, $deadlineJob, time());
$deadlineState = jobState($pdo, $deadlineJob);
$deadlineRow = $pdo->query('SELECT sync_contacts, sync_history FROM whatsapp_connections WHERE id = ' . $deadlineId)->fetch();
$mainStill = jobState($pdo, $historyJob);
$check(
    'an expired 24 hour deadline expires the job without a graph call',
    $deadlineJob > 0
        && $deadlineJob !== $historyJob
        && ($deadlineResult['status'] ?? '') === 'expired'
        && ($deadlineResult['calls_graph'] ?? true) === false
        && count($transport->calls) === $deadlineCalls
        && $deadlineState['status'] === 'expired'
        && $deadlineState['last_error_code'] === 'expired'
        && is_array($deadlineRow)
        && $deadlineRow['sync_contacts'] === 'unavailable'
        && $deadlineRow['sync_history'] === 'unavailable'
        && $mainStill['status'] === 'in_progress'
);

$foreignWaba = insertLink($pdo, $seal, $businessId, 'NOTNUMERIC', '100000000000822', '+60 12 300 0822', 'future');
markReady($pdo, $foreignWaba, 'callback');
markReady($pdo, $foreignWaba, 'fields');
$foreignWabaAuth = post($http, $session, 'segera', ['sync_type' => 'smb_app_state_sync'], 'worker-owner');
$foreignWabaJob = (int) ($foreignWabaAuth['body']['sync']['job_id'] ?? 0);
$foreignWabaResult = $sync->dispatch($businessId, $foreignWabaJob, time());
$foreignWabaState = jobState($pdo, $foreignWabaJob);
$check(
    'a non numeric waba stays authorized and is not dispatched',
    $foreignWabaJob > 0
        && ($foreignWabaResult['blocked'] ?? '') === 'association'
        && ($foreignWabaResult['calls_graph'] ?? true) === false
        && count($transport->calls) === $deadlineCalls
        && $foreignWabaState['status'] === 'authorized'
        && $foreignWabaState['attempt_count'] === 0
);

$unsubscribedId = insertLink($pdo, $seal, $businessId, '100000000000923', '100000000000823', '+60 12 300 0823', 'future');
markReady($pdo, $unsubscribedId, 'callback');
markReady($pdo, $unsubscribedId, 'fields');
$unsubscribedAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$unsubscribedJob = (int) ($unsubscribedAuth['body']['sync']['job_id'] ?? 0);
$pdo->prepare('UPDATE whatsapp_connections SET webhook_state = \'not_subscribed\' WHERE id = ?')->execute([$unsubscribedId]);
$unsubscribedResult = $sync->dispatch($businessId, $unsubscribedJob, time());
$unsubscribedState = jobState($pdo, $unsubscribedJob);
$check(
    'a connection that is not webhook subscribed fails closed without a graph call',
    ($unsubscribedResult['status'] ?? '') === 'failed'
        && ($unsubscribedResult['calls_graph'] ?? true) === false
        && count($transport->calls) === $deadlineCalls
        && $unsubscribedState['status'] === 'failed'
        && $unsubscribedState['last_error_code'] === 'unavailable'
);

$leaseId = insertLink($pdo, $seal, $businessId, '100000000000924', '100000000000824', '+60 12 300 0824', 'future');
markReady($pdo, $leaseId, 'callback');
markReady($pdo, $leaseId, 'fields');
$leaseAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$leaseJob = (int) ($leaseAuth['body']['sync']['job_id'] ?? 0);
$pdo->prepare(
    'UPDATE coexistence_sync_jobs
     SET status = \'dispatching\', claim_token = ?, claimed_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 90 SECOND)
     WHERE id = ? AND status = \'authorized\''
)->execute(['0123456789abcdef0123456789abcdef', $leaseJob]);
$leaseResult = $sync->dispatch($businessId, $leaseJob, time());
$duringLease = CoexistenceWorker::process($pdo, $graph, $seal, time(), true);
$check(
    'an open worker lease is not dispatched by a second worker',
    $leaseJob > 0
        && ($leaseResult['status'] ?? '') === 'dispatching'
        && ($leaseResult['calls_graph'] ?? true) === false
        && ($duringLease['sent'] ?? 1) === 0
        && count($transport->calls) === $deadlineCalls
);
$pdo->prepare('UPDATE coexistence_sync_jobs SET claimed_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id = ?')->execute([$leaseJob]);
$recoveredRun = CoexistenceWorker::process($pdo, $graph, $seal, time(), true);
$recoveredState = jobState($pdo, $leaseJob);
$againAfterReview = $sync->dispatch($businessId, $leaseJob, time());
$check(
    'an interrupted dispatch moves to review and is not posted again',
    ($recoveredRun['recovered'] ?? 0) === 1
        && ($recoveredRun['sent'] ?? 1) === 0
        && count($transport->calls) === $deadlineCalls
        && $recoveredState['status'] === 'review'
        && $recoveredState['last_error_code'] === 'interrupted'
        && $recoveredState['request_id'] === null
        && ($againAfterReview['status'] ?? '') === 'review'
        && ($againAfterReview['calls_graph'] ?? true) === false
);

$timeoutId = insertLink($pdo, $seal, $businessId, '100000000000925', '100000000000825', '+60 12 300 0825', 'future');
markReady($pdo, $timeoutId, 'callback');
markReady($pdo, $timeoutId, 'fields');
$timeoutAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$timeoutJob = (int) ($timeoutAuth['body']['sync']['job_id'] ?? 0);
$timeoutTransport = new ScriptedMeta();
$timeoutTransport->throwTransport = true;
$timeoutSync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $timeoutTransport), $seal, true);
$timeoutResult = $timeoutSync->dispatch($businessId, $timeoutJob, time());
$timeoutRepeat = $timeoutSync->dispatch($businessId, $timeoutJob, time());
$timeoutState = jobState($pdo, $timeoutJob);
$webhook = new WhatsAppWebhook($pdo, $seal);
$progress = envelope('100000000000925', 'history', [
    'metadata' => ['phone_number_id' => '100000000000825'],
    'history' => [['metadata' => ['phase' => 2, 'chunk_order' => 1, 'progress' => 100], 'threads' => []]],
]);
signedIngest($webhook, $progress, $secret);
$timeoutAfter = jobState($pdo, $timeoutJob);
$timeoutConnection = $pdo->query('SELECT sync_history FROM whatsapp_connections WHERE id = ' . $timeoutId)->fetchColumn();
$check(
    'a timeout after a possible acceptance waits for review and a later webhook completes it',
    ($timeoutResult['status'] ?? '') === 'review'
        && ($timeoutResult['calls_graph'] ?? false) === true
        && count($timeoutTransport->calls) === 1
        && ($timeoutRepeat['calls_graph'] ?? true) === false
        && count($timeoutTransport->calls) === 1
        && $timeoutState['status'] === 'review'
        && $timeoutState['last_error_code'] === 'timeout'
        && $timeoutState['request_id'] === null
        && $timeoutAfter['status'] === 'completed'
        && $timeoutConnection === 'completed'
        && count($timeoutTransport->calls) === 1
);

$retryId = insertLink($pdo, $seal, $businessId, '100000000000926', '100000000000826', '+60 12 300 0826', 'future');
markReady($pdo, $retryId, 'callback');
markReady($pdo, $retryId, 'fields');
$retryAuth = post($http, $session, 'segera', ['sync_type' => 'smb_app_state_sync'], 'worker-owner');
$retryJob = (int) ($retryAuth['body']['sync']['job_id'] ?? 0);
$retryTransport = new ScriptedMeta();
$retrySync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $retryTransport), $seal, true);
$retryTransport->script = [
    ['status' => 500, 'json' => ['error' => ['code' => 131000]]],
    ['status' => 200, 'json' => ['messaging_product' => 'whatsapp', 'request_id' => 'req-pekerja-cuba']],
];
$retryNow = time();
$retryFirst = $retrySync->dispatch($businessId, $retryJob, $retryNow);
$retryWait = $retrySync->dispatch($businessId, $retryJob, $retryNow + 30);
$retrySecond = $retrySync->dispatch($businessId, $retryJob, $retryNow + 61);
$retryState = jobState($pdo, $retryJob);
$check(
    'a retryable graph error backs off and then posts once more',
    ($retryFirst['status'] ?? '') === 'authorized'
        && ($retryFirst['calls_graph'] ?? false) === true
        && ($retryWait['calls_graph'] ?? true) === false
        && ($retrySecond['status'] ?? '') === 'in_progress'
        && count($retryTransport->calls) === 2
        && $retryState['attempt_count'] === 1
        && $retryState['request_id'] === 'req-pekerja-cuba'
        && $retryState['status'] === 'in_progress'
);

$hardId = insertLink($pdo, $seal, $businessId, '100000000000927', '100000000000827', '+60 12 300 0827', 'future');
markReady($pdo, $hardId, 'callback');
markReady($pdo, $hardId, 'fields');
$hardAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$hardJob = (int) ($hardAuth['body']['sync']['job_id'] ?? 0);
$hardTransport = new ScriptedMeta();
$hardSync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $hardTransport), $seal, true);
$hardTransport->script = [['status' => 400, 'json' => ['error' => ['code' => 190, 'message' => $token . ' ' . $phrase]]]];
$hardResult = $hardSync->dispatch($businessId, $hardJob, time());
$hardRepeat = $hardSync->dispatch($businessId, $hardJob, time() + 120);
$hardState = jobState($pdo, $hardJob);
$hardConnection = $pdo->query('SELECT sync_history, last_error_code FROM whatsapp_connections WHERE id = ' . $hardId)->fetch();
$check(
    'a non retryable graph error fails once and keeps the response body out of storage',
    ($hardResult['status'] ?? '') === 'failed'
        && ($hardResult['calls_graph'] ?? false) === true
        && count($hardTransport->calls) === 1
        && ($hardRepeat['calls_graph'] ?? true) === false
        && $hardState['status'] === 'failed'
        && $hardState['attempt_count'] === $hardState['max_attempts']
        && $hardState['last_error_code'] === '190'
        && is_array($hardConnection)
        && $hardConnection['sync_history'] === 'failed'
        && $hardConnection['last_error_code'] === '190'
);

$tokenId = insertLink($pdo, $seal, $businessId, '100000000000928', '100000000000828', '+60 12 300 0828', 'future');
markReady($pdo, $tokenId, 'callback');
markReady($pdo, $tokenId, 'fields');
$tokenAuth = post($http, $session, 'segera', ['sync_type' => 'smb_app_state_sync'], 'worker-owner');
$tokenJob = (int) ($tokenAuth['body']['sync']['job_id'] ?? 0);
$pdo->prepare('UPDATE whatsapp_connections SET token_key_version = 2 WHERE id = ?')->execute([$tokenId]);
$tokenTransport = new ScriptedMeta();
$tokenSync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $tokenTransport), $seal, true);
$tokenResult = $tokenSync->dispatch($businessId, $tokenJob, time());
$tokenState = jobState($pdo, $tokenJob);
$check(
    'an unreadable connection token fails closed without a graph call',
    ($tokenResult['status'] ?? '') === 'failed'
        && ($tokenResult['calls_graph'] ?? true) === false
        && $tokenTransport->calls === []
        && $tokenState['status'] === 'failed'
        && $tokenState['last_error_code'] === 'token'
        && $tokenState['attempt_count'] === $tokenState['max_attempts']
);

$declineId = insertLink($pdo, $seal, $businessId, '100000000000929', '100000000000829', '+60 12 300 0829', 'future');
markReady($pdo, $declineId, 'callback');
markReady($pdo, $declineId, 'fields');
$declineAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$declineJob = (int) ($declineAuth['body']['sync']['job_id'] ?? 0);
$declineTransport = new ScriptedMeta();
$declineSync = new CoexistenceSync($pdo, new MetaGraph($appId, $secret, BESHARE_META_GRAPH, $declineTransport), $seal, true);
$declineTransport->script = [accepted('req-pekerja-tolak')];
$declineSent = $declineSync->dispatch($businessId, $declineJob, time());
$refusal = envelope('100000000000929', 'history', [
    'metadata' => ['phone_number_id' => '100000000000829'],
    'history' => [[
        'errors' => [[
            'code' => 2593109,
            'title' => 'History sync is turned off by the business from the WhatsApp Business App',
        ]],
    ]],
]);
signedIngest($webhook, $refusal, $secret);
$declinedState = jobState($pdo, $declineJob);
$declinedConnection = $pdo->query('SELECT sync_history, last_error_code FROM whatsapp_connections WHERE id = ' . $declineId)->fetch();
signedIngest($webhook, envelope('100000000000929', 'history', [
    'metadata' => ['phone_number_id' => '100000000000829'],
    'history' => [['metadata' => ['phase' => 2, 'chunk_order' => 2, 'progress' => 100], 'threads' => []]],
]), $secret);
$stillDeclined = jobState($pdo, $declineJob);
$stillConnection = $pdo->query('SELECT sync_history FROM whatsapp_connections WHERE id = ' . $declineId)->fetchColumn();
$check(
    'history refusal is declined and a later progress event does not overwrite it',
    ($declineSent['status'] ?? '') === 'in_progress'
        && count($declineTransport->calls) === 1
        && $declinedState['status'] === 'declined'
        && $declinedState['last_error_code'] === '2593109'
        && is_array($declinedConnection)
        && $declinedConnection['sync_history'] === 'declined'
        && $stillDeclined['status'] === 'declined'
        && $stillConnection === 'declined'
        && count($declineTransport->calls) === 1
);

$dropId = insertLink($pdo, $seal, $businessId, '100000000000930', '100000000000830', '+60 12 300 0830', 'future');
markReady($pdo, $dropId, 'callback');
markReady($pdo, $dropId, 'fields');
$dropAuth = post($http, $session, 'segera', ['sync_type' => 'history'], 'worker-owner');
$dropJob = (int) ($dropAuth['body']['sync']['job_id'] ?? 0);
$pdo->prepare(
    'UPDATE coexistence_sync_jobs
     SET status = \'dispatching\', claim_token = ?, claimed_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 90 SECOND)
     WHERE id = ?'
)->execute(['fedcba9876543210fedcba9876543210', $dropJob]);
$otherId = insertLink($pdo, $seal, $otherBusiness, '100000000000931', '100000000000831', '+60 12 300 0831', 'future');
$removed = envelope('100000000000930', 'account_update', [
    'event' => 'PARTNER_REMOVED',
    'phone_number' => '+60 12 300 0830',
    'disconnection_info' => ['reason' => 'ACCOUNT_DISCONNECTED', 'initiated_by' => 'USER'],
]);
ob_start();
signedIngest($webhook, $removed, $secret);
$dropNoise = (string) ob_get_clean();
$dropState = jobState($pdo, $dropJob);
$dropRow = $pdo->query(
    'SELECT token_state, messaging_state, disconnected_at IS NOT NULL AS disconnected, token_ciphertext IS NULL AS token_cleared, sync_history
     FROM whatsapp_connections WHERE id = ' . $dropId
)->fetch();
$otherRow = $pdo->query('SELECT token_state, disconnected_at IS NOT NULL AS disconnected FROM whatsapp_connections WHERE id = ' . $otherId)->fetch();
$dropAfter = $sync->dispatch($businessId, $dropJob, time());
$check(
    'partner removal cancels an active job and leaves the other tenant connected',
    $dropState['status'] === 'cancelled'
        && is_array($dropRow)
        && $dropRow['token_state'] === 'revoked'
        && $dropRow['messaging_state'] === 'unverified'
        && (int) $dropRow['disconnected'] === 1
        && (int) $dropRow['token_cleared'] === 1
        && $dropRow['sync_history'] === 'unavailable'
        && is_array($otherRow)
        && $otherRow['token_state'] === 'verified'
        && (int) $otherRow['disconnected'] === 0
        && ($dropAfter['status'] ?? '') === 'cancelled'
        && ($dropAfter['calls_graph'] ?? true) === false
        && count($transport->calls) === $deadlineCalls
        && !str_contains($dropNoise, $token)
        && !str_contains($dropNoise, $phrase)
        && !str_contains($dropNoise, 'token-pekerja-bukan-live')
);

$auditBlob = json_encode($pdo->query('SELECT action, meta_json FROM audit_logs')->fetchAll());
$check(
    'audit records omit tokens contacts and message bodies',
    is_string($auditBlob)
        && !str_contains($auditBlob, $token)
        && !str_contains($auditBlob, $phrase)
        && !str_contains($auditBlob, 'token-pekerja-bukan-live')
        && !str_contains($auditBlob, $secretPlant)
        && str_contains($auditBlob, 'penyegerakan_dihantar')
        && str_contains($auditBlob, 'penyegerakan_semak')
);

$workerSource = (string) file_get_contents(__DIR__ . '/sync-coexistence.php');
$migrator = (string) file_get_contents(__DIR__ . '/migrate.php');
$signup = (string) file_get_contents(dirname(__DIR__, 2) . '/whatsapp/ruang.js');
$gateAt = strpos($workerSource, 'ActivationGate::enabled()');
$transportAt = strpos($workerSource, 'new StreamMetaTransport()');
$standardAt = strpos($signup, 'function signupLoginOptions');
$coexistenceAt = strpos($signup, 'function coexistenceLoginOptions');
$featureAt = strpos($signup, 'featureType: "whatsapp_business_app_onboarding"');
$check(
    'the live transport is constructed only after the activation gate',
    $gateAt !== false && $transportAt !== false && $gateAt < $transportAt && !str_contains($workerSource, 'smbAppData')
);
$check('the foundation migrator does not apply the worker migration', !str_contains($migrator, '0003_coexistence') && !str_contains($migrator, '0002_coexistence'));
$check(
    'the standard signup path still omits the coexistence feature type',
    $standardAt !== false
        && $coexistenceAt !== false
        && $featureAt !== false
        && $standardAt < $coexistenceAt
        && $featureAt > $coexistenceAt
        && substr_count($signup, 'featureType') === 1
);

fwrite(STDOUT, $failures === 0 ? "PASS\n" : "FAIL {$failures}\n");
exit($failures === 0 ? 0 : 1);
