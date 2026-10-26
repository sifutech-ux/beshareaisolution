<?php
declare(strict_types=1);

// Destructive only inside the shared, explicitly marked loopback admin fixture.
// Run sequentially with other admin checks. No private configuration or network calls.
require_once __DIR__ . '/admin-fixture.php';
require_once __DIR__ . '/../src/AiProductionSchema.php';
require_once __DIR__ . '/../src/AiReplyDispatcher.php';
require_once __DIR__ . '/../src/TenantIntakeBridge.php';

use BesharOs\AiProductionSchema;
use BesharOs\AiReplyDispatcher;
use BesharOs\AiReplyWorker;
use BesharOs\AuthException;
use BesharOs\OwnerIntakeBridge;
use BesharOs\TenantIntakeBridge;
use BesharOs\WhatsAppWebhook;

const RUNTIME_SECRET = 'synthetic-customer-runtime-hmac-only';

function runtimeConnection(array $f, array $tenant, string $waba, string $phone, string $label): int
{
    $sealed = $f['seal']->seal('synthetic-runtime-token-' . $label, 2048);
    adminSql($f['pdo'], "INSERT INTO whatsapp_connections
        (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,registration_state,
         messaging_state,token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,created_at,updated_at)
        VALUES (?,?,?,'+601100000001','verified','subscribed','registered','unverified','system_user',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
        [$tenant['business_id'], $waba, $phone, $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version']]);
    return (int) $f['pdo']->lastInsertId();
}

function runtimeSettings(array $f, array $tenant, int $connection, string $label, string $mode, int $now): void
{
    $knowledge = 'Approved synthetic facts for business ' . $label . '. Tea costs RM5.';
    $sealed = $f['seal']->seal($knowledge, 8000);
    $values = [$tenant['business_id'], $connection, $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version'],
        hash('sha256', $knowledge), $tenant['owner_id'], gmdate('Y-m-d H:i:s', $now - 100),
        gmdate('Y-m-d H:i:s', $now - 100), $mode,
        $mode === 'all' ? $tenant['owner_id'] : null, $mode === 'all' ? gmdate('Y-m-d H:i:s', $now - 100) : null];
    adminSql($f['pdo'], "INSERT INTO ai_reply_settings
        (business_id,connection_id,enabled,knowledge_ciphertext,knowledge_nonce,knowledge_tag,knowledge_key_version,
         knowledge_revision,knowledge_hash,approved_by,approved_at,allowlist_json,daily_limit,activation_at,
         audience_mode,audience_approved_by,audience_approved_at,created_at,updated_at)
        VALUES (?,?,1,?,?,?,?,1,?,?,?,'[\"601100000111\"]',100,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE enabled=1,approved_by=VALUES(approved_by),approved_at=VALUES(approved_at),
         audience_mode=VALUES(audience_mode),audience_approved_by=VALUES(audience_approved_by),
         audience_approved_at=VALUES(audience_approved_at),activation_at=VALUES(activation_at)", $values);
}

function runtimeEntry(string $waba, string $phone, string $wamid, string $peer = '601199999111'): array
{
    global $now;
    return ['id' => $waba, 'changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => $phone],
        'messages' => [['id' => $wamid, 'from' => $peer, 'timestamp' => (string) ($now - 1),
            'type' => 'text', 'text' => ['body' => 'Synthetic customer asks for tea price']]],
    ]]]];
}

function runtimeRaw(array $entries): string
{
    return json_encode(['object' => 'whatsapp_business_account', 'entry' => $entries], JSON_THROW_ON_ERROR);
}

function runtimeSignature(string $raw): string
{
    return 'sha256=' . hash_hmac('sha256', $raw, RUNTIME_SECRET);
}

function runtimeBridge(array $f, array $config, array $entries): array
{
    $raw = runtimeRaw($entries);
    return TenantIntakeBridge::deliver($raw, runtimeSignature($raw), $config, $f['pdo'], $f['seal']);
}

function runtimeWorker(array $f, int $now, array &$calls, bool $rollout = true, ?callable $generate = null): AiReplyWorker
{
    $generator = $generate ?? function (string $knowledge, string $text, array $history) use (&$calls): array {
        $calls['generate'][] = ['knowledge' => $knowledge, 'text' => $text, 'history' => $history];
        return ['reply' => 'Synthetic approved reply.', 'handoff' => false];
    };
    $send = function (string $token, string $phone, string $peer, string $text, ?string $replyTo) use (&$calls): array {
        $calls['send'][] = compact('token', 'phone', 'peer', 'text', 'replyTo');
        return ['sent' => true, 'message_id' => 'wamid.runtime.out.' . count($calls['send']), 'ambiguous' => false, 'error' => null];
    };
    return new AiReplyWorker($f['pdo'], $f['seal'], $generator, $send, static fn (): int => $now, null, $rollout);
}

function runtimeCalls(array $calls): array { return [count($calls['generate']), count($calls['send'])]; }

final class CustomerRuntimeTrapPdo extends PDO
{
    public int $calls = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->calls++;
        throw new RuntimeException('Synthetic forbidden database access');
    }
}

$f = adminFixture();
$pdo = $f['pdo'];
$a = $f['tenantA'];
$b = $f['tenantB'];
$now = time();
$sql = (string) file_get_contents(__DIR__ . '/../migrations/0007_customer_ai.up.sql');
(new AiProductionSchema($pdo, $sql))->apply(hash('sha256', $sql));
$wabaA = '1104940391945821'; $phoneA = '1443799975474056';
$wabaB = '7100000000000002'; $phoneB = '8100000000000002';
$connectionA = runtimeConnection($f, $a, $wabaA, $phoneA, 'A');
$connectionB = runtimeConnection($f, $b, $wabaB, $phoneB, 'B');
$config = ['ai_customer_rollout' => 'all', 'app_secret' => RUNTIME_SECRET, 'owner_ai_enabled' => 'false'];
$calls = ['generate' => [], 'send' => []];

adminTest('Signed tenant intake preserves legacy owner routing and isolates mixed customer batches', function () use ($f, $pdo, $a, $b, $config, $wabaA, $phoneA, $wabaB, $phoneB, $connectionA, $connectionB): void {
    $entryA = runtimeEntry($wabaA, $phoneA, 'wamid.runtime.intake.A');
    $entryB = runtimeEntry($wabaB, $phoneB, 'wamid.runtime.intake.B');
    $raw = runtimeRaw([$entryA]);
    $trap = new CustomerRuntimeTrapPdo();
    $disabled = $config; unset($disabled['ai_customer_rollout']);
    adminAssert(TenantIntakeBridge::deliver($raw, runtimeSignature($raw), $disabled, $trap, $f['seal'])['status'] === 'disabled'
        && $trap->calls === 0, 'No customer rollout means no customer DB intake');
    adminAssert(TenantIntakeBridge::deliver($raw, 'sha256=' . str_repeat('0', 64), $config, $trap, $f['seal'])['status'] === 'rejected'
        && $trap->calls === 0, 'Forged original HMAC cannot reach DB');
    $changed = str_replace('Synthetic customer', 'Tampered customer', $raw);
    adminAssert(TenantIntakeBridge::deliver($changed, runtimeSignature($raw), $config, $trap, $f['seal'])['status'] === 'rejected'
        && $trap->calls === 0, 'Signature covers original bytes before slicing');
    $ownerConfig = $config + ['owner_intake_enabled' => 'true', 'owner_whatsapp_business_id' => (string) $a['business_id'],
        'owner_whatsapp_portfolio_id' => '1249591211577465', 'owner_whatsapp_waba_id' => $wabaA,
        'owner_whatsapp_phone_number_id' => $phoneA];
    $owner = OwnerIntakeBridge::deliver($raw, runtimeSignature($raw), $ownerConfig, $pdo, $f['seal']);
    adminAssert($owner['status'] === 'stored' && $owner['stored'] === 1, 'Original owner bridge still stores its exact slice');
    $unknown = runtimeEntry('7999999999999999', '8999999999999999', 'wamid.runtime.unknown');
    $wrongWaba = runtimeEntry($wabaB, $phoneA, 'wamid.runtime.wrongwaba');
    $history = ['id' => $wabaA, 'changes' => [['field' => 'history', 'value' => ['metadata' => ['phone_number_id' => $phoneA]]]]];
    $result = runtimeBridge($f, $config, [$entryA, $entryB, $unknown, $wrongWaba, $history]);
    adminAssert($result['status'] === 'stored' && $result['stored'] === 1 && $result['replayed'] === 1,
        'Shared owner callback is deduplicated while second customer is stored');
    adminAssert((int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === 2, 'Unknown, mismatched and history bodies are not copied');
    foreach ([[$a, $connectionA, $wabaA, $phoneA], [$b, $connectionB, $wabaB, $phoneB]] as [$tenant, $connection, $waba, $phone]) {
        $event = adminSql($pdo, 'SELECT * FROM webhook_events WHERE business_id=?', [$tenant['business_id']])->fetch();
        $body = json_decode($f['seal']->open($event['payload_ciphertext'], $event['payload_nonce'], $event['payload_tag'], (int) $event['payload_key_version']), true, 32, JSON_THROW_ON_ERROR);
        adminAssert((int) $event['connection_id'] === $connection && $body['waba_id'] === $waba && $body['phone_number_id'] === $phone,
            'Each accepted body belongs to exact active tenant binding');
    }
    adminAssert(TenantIntakeBridge::handles($entryB, $entryB['changes'][0], $result)
        && !TenantIntakeBridge::handles($unknown, $unknown['changes'][0], $result)
        && !TenantIntakeBridge::handles($history, $history['changes'][0], $result), 'Callback skips only successfully ingested message assets');
    $again = runtimeBridge($f, $config, [$entryA, $entryB]);
    adminAssert($again['stored'] === 0 && $again['replayed'] === 2, 'Provider retries cannot create duplicate jobs');
    $malformed = runtimeEntry($wabaB, $phoneB, 'wamid.runtime.partial');
    $malformed['changes'][0]['value']['messages'][] = ['id' => 'malformed-record'];
    adminAssert(runtimeBridge($f, $config, [$malformed])['status'] === 'failed'
        && (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === 2, 'Malformed selected records fail before partial storage');
    $mismatch = runtimeRaw([runtimeEntry($wabaA, $phoneA, 'wamid.runtime.bindingrace')]);
    adminDenied(fn () => (new WhatsAppWebhook($pdo, $f['seal']))->ingest($mismatch,
        [$wabaA . ':' . $phoneA => ['id' => $connectionB, 'business_id' => $b['business_id']]]), 409,
        'Changed binding is rejected under lock without another tenant metadata writes');
    adminAssert((int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn() === 2, 'Binding mismatch leaves queue unchanged');
    adminSql($pdo, "UPDATE businesses SET status='suspended' WHERE id=?", [$a['business_id']]);
    try {
        adminDenied(fn () => (new WhatsAppWebhook($pdo, $f['seal']))->ingest($mismatch,
            [$wabaA . ':' . $phoneA => ['id' => $connectionA, 'business_id' => $a['business_id']]]), 409,
            'Business suspension between selection and ingestion is rechecked under lock');
    } finally {
        adminSql($pdo, "UPDATE businesses SET status='active' WHERE id=?", [$a['business_id']]);
    }
    $poisonWamid = 'wamid.runtime.foreigndedupe';
    (new WhatsAppWebhook($pdo, $f['seal']))->ingest(runtimeRaw([runtimeEntry($wabaB, $phoneB, $poisonWamid)]));
    $poisonId = (int) adminSql($pdo, 'SELECT id FROM webhook_events WHERE dedupe_key=?', ['m:' . $phoneB . ':' . $poisonWamid])->fetchColumn();
    adminSql($pdo, 'UPDATE webhook_events SET dedupe_key=? WHERE id=?', ['m:' . $phoneA . ':' . $poisonWamid, $poisonId]);
    adminAssert(runtimeBridge($f, $config, [runtimeEntry($wabaA, $phoneA, $poisonWamid)])['status'] === 'failed',
        'Foreign global dedupe record cannot masquerade as successful customer intake');
    adminSql($pdo, 'DELETE FROM webhook_events WHERE id=?', [$poisonId]);
});

runtimeSettings($f, $a, $connectionA, 'A', 'all', $now);
runtimeSettings($f, $b, $connectionB, 'B', 'all', $now);
$worker = runtimeWorker($f, $now, $calls);

adminTest('All-customer replies use each consenting business own knowledge token phone and queue', function () use ($pdo, $a, $b, $worker, &$calls, $phoneA, $phoneB): void {
    $first = $worker->run($a['business_id']);
    adminAssert($first['sent'] === 1 && count($calls['send']) === 1
        && $calls['send'][0]['phone'] === $phoneA && $calls['send'][0]['token'] === 'synthetic-runtime-token-A'
        && str_contains($calls['generate'][0]['knowledge'], 'business A'), 'Business A uses only its own approved scope');
    adminAssert((int) adminSql($pdo, 'SELECT COUNT(*) FROM ai_reply_jobs WHERE business_id=?', [$b['business_id']])->fetchColumn() === 0,
        'Running A never claims B queue');
    $second = $worker->run($b['business_id']);
    adminAssert($second['sent'] === 1 && count($calls['send']) === 2
        && $calls['send'][1]['phone'] === $phoneB && $calls['send'][1]['token'] === 'synthetic-runtime-token-B'
        && str_contains($calls['generate'][1]['knowledge'], 'business B'), 'Business B replies to unlisted customer only with its own consent');
});

adminTest('Historical receipts survive suspension disabled owners and disconnected known assets', function () use ($f, $pdo, $a, $connectionA, $config, $wabaA, $phoneA, $now, &$calls): void {
    $before = runtimeCalls($calls);
    $worker = runtimeWorker($f, $now, $calls);
    foreach (['suspended' => 'delivered', 'disabled_owner' => 'read', 'disconnected' => 'failed'] as $scenario => $delivery) {
        if ($scenario === 'suspended') { adminSql($pdo, "UPDATE businesses SET status='suspended' WHERE id=?", [$a['business_id']]); }
        if ($scenario === 'disabled_owner') { adminSql($pdo, "UPDATE users SET status='disabled' WHERE id=?", [$a['owner_id']]); }
        if ($scenario === 'disconnected') { adminSql($pdo, 'UPDATE whatsapp_connections SET disconnected_at=UTC_TIMESTAMP() WHERE id=?', [$connectionA]); }
        try {
            $entry = ['id' => $wabaA, 'changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => $phoneA],
                'statuses' => [['id' => 'wamid.runtime.out.1', 'status' => $delivery,
                    'timestamp' => (string) $now, 'recipient_id' => '601199999111']],
            ]]]];
            $statusOnly = $entry;
            $blockedId = 'wamid.runtime.inactive.' . $scenario;
            $entry['changes'][0]['value']['messages'] = runtimeEntry($wabaA, $phoneA, $blockedId)['changes'][0]['value']['messages'];
            $result = runtimeBridge($f, $config, [$entry]);
            adminAssert($result['status'] === 'stored' && $result['stored'] === 1,
                $scenario . ' retains exact known receipt without permitting new messages');
            adminAssert(TenantIntakeBridge::handles($statusOnly, $statusOnly['changes'][0], $result)
                && !TenantIntakeBridge::handles($entry, $entry['changes'][0], $result),
                $scenario . ' does not falsely claim filtered new messages were handled');
            $processed = (new AiReplyDispatcher($pdo))->run([$worker, 'run'], 20, 10);
            adminAssert($processed['statuses'] === 1 && runtimeCalls($calls) === $before,
                $scenario . ' receipt processing calls no providers');
            adminAssert((int) adminSql($pdo, 'SELECT COUNT(*) FROM webhook_events WHERE dedupe_key=?', ['m:' . $phoneA . ':' . $blockedId])->fetchColumn() === 0,
                $scenario . ' new inbound message remains outside active tenant intake');
        } finally {
            adminSql($pdo, "UPDATE businesses SET status='active' WHERE id=?", [$a['business_id']]);
            adminSql($pdo, "UPDATE users SET status='active' WHERE id=?", [$a['owner_id']]);
            adminSql($pdo, 'UPDATE whatsapp_connections SET disconnected_at=NULL WHERE id=?', [$connectionA]);
        }
    }
    adminAssert(adminSql($pdo, "SELECT status FROM ai_messages WHERE business_id=? AND wamid='wamid.runtime.out.1' AND direction='out'", [$a['business_id']])->fetchColumn() === 'read',
        'Late failed receipt cannot regress an already read outgoing message');
    $pdo->exec("UPDATE ai_reply_dispatch_state SET last_business_id=0 WHERE name='all'");
});

adminTest('Legacy test scope and absent or stale all-customer consent never trigger providers', function () use ($f, $pdo, $a, $b, $connectionA, $config, $wabaA, $phoneA, $now, &$calls): void {
    foreach (['test', 'wrong_audience_owner', 'rollout_off', 'wrong_knowledge_owner', 'disabled'] as $scenario) {
        runtimeSettings($f, $a, $connectionA, 'A', $scenario === 'test' ? 'test' : 'all', $now);
        if ($scenario === 'wrong_audience_owner') { adminSql($pdo, 'UPDATE ai_reply_settings SET audience_approved_by=? WHERE business_id=?', [$b['owner_id'], $a['business_id']]); }
        if ($scenario === 'wrong_knowledge_owner') { adminSql($pdo, 'UPDATE ai_reply_settings SET approved_by=? WHERE business_id=?', [$b['owner_id'], $a['business_id']]); }
        if ($scenario === 'disabled') { adminSql($pdo, 'UPDATE ai_reply_settings SET enabled=0 WHERE business_id=?', [$a['business_id']]); }
        $wamid = 'wamid.runtime.guard.' . $scenario;
        runtimeBridge($f, $config, [runtimeEntry($wabaA, $phoneA, $wamid, '601199999' . (101 + strlen($scenario)))]);
        $before = runtimeCalls($calls);
        runtimeWorker($f, $now, $calls, $scenario !== 'rollout_off')->run($a['business_id']);
        adminAssert(runtimeCalls($calls) === $before, $scenario . ' blocks both providers');
        adminAssert((int) adminSql($pdo, "SELECT COUNT(*) FROM ai_messages WHERE business_id=? AND direction='in' AND wamid=?", [$a['business_id'], $wamid])->fetchColumn() === 1,
            $scenario . ' retains incoming message in encrypted inbox');
    }
    runtimeSettings($f, $a, $connectionA, 'A', 'all', $now);
    runtimeBridge($f, $config, [runtimeEntry($wabaA, $phoneA, 'wamid.runtime.consent.race', '601199999777')]);
    $before = runtimeCalls($calls);
    $generator = function () use ($pdo, $a, $b, &$calls): array {
        $calls['generate'][] = ['synthetic' => 'in-flight consent change'];
        adminSql($pdo, 'UPDATE ai_reply_settings SET audience_approved_by=? WHERE business_id=?', [$b['owner_id'], $a['business_id']]);
        return ['reply' => 'Generated before consent changed.', 'handoff' => false];
    };
    runtimeWorker($f, $now, $calls, true, $generator)->run($a['business_id']);
    adminAssert(count($calls['send']) === $before[1], 'Consent is rechecked after generation and before dispatch');
});

adminTest('Deadline, fair cron cursor and disabled archival are bounded and provider-free', function () use ($f, $pdo, $a, $b, $connectionA, $connectionB, $config, $wabaA, $phoneA, $wabaB, $phoneB, $now, &$calls): void {
    runtimeSettings($f, $a, $connectionA, 'A', 'all', $now);
    runtimeSettings($f, $b, $connectionB, 'B', 'all', $now);
    $pdo->exec('UPDATE ai_reply_settings SET enabled=0');
    $worker = runtimeWorker($f, $now, $calls);
    $worker->run($a['business_id']); $worker->run($b['business_id']);
    runtimeBridge($f, $config, [
        runtimeEntry($wabaA, $phoneA, 'wamid.runtime.round.A1'), runtimeEntry($wabaA, $phoneA, 'wamid.runtime.round.A2'),
        runtimeEntry($wabaB, $phoneB, 'wamid.runtime.round.B1'), runtimeEntry($wabaB, $phoneB, 'wamid.runtime.round.B2'),
    ]);
    $before = runtimeCalls($calls);
    $expired = $worker->run($a['business_id'], 10, microtime(true) - 1);
    adminAssert($expired['archived'] === 0 && runtimeCalls($calls) === $before, 'Expired budget begins no queue claim or provider call');
    $dispatcher = new AiReplyDispatcher($pdo);
    $snapshot = $dispatcher->inspect(2);
    adminAssert($snapshot['next_businesses'] === [$a['business_id'], $b['business_id']]
        && $snapshot['last_business_id'] === 0 && runtimeCalls($calls) === $before, 'Inspection is read-only and reports next active tenants');
    $seen = [];
    $process = function (int $business, int $limit, float $deadline) use ($worker, &$seen): array {
        $seen[] = $business; return $worker->run($business, $limit, $deadline);
    };
    $dispatcher->run($process, 1, 1);
    $dispatcher->run($process, 1, 1);
    $dispatcher->run($process, 1, 1);
    adminAssert($seen === [$a['business_id'], $b['business_id'], $a['business_id']],
        'Busy earlier tenant cannot starve later tenant across bounded cron runs');
    adminAssert(runtimeCalls($calls) === $before, 'Disabled tenants archive without generation or send');
    [$dsn, $user, $password] = adminTestConnection();
    $other = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->query("SELECT GET_LOCK('beshare_ai_dispatch_all',0)");
    $seenBefore = $seen;
    $busy = $dispatcher->run($process, 2, 1);
    $other->query("SELECT RELEASE_LOCK('beshare_ai_dispatch_all')");
    adminAssert($busy['mode'] === 'busy' && $seen === $seenBefore, 'Overlapping all-business cron performs no work');
    $worker->run($a['business_id']); $worker->run($b['business_id']);
    runtimeBridge($f, $config, [runtimeEntry($wabaA, $phoneA, 'wamid.runtime.failure.A'), runtimeEntry($wabaB, $phoneB, 'wamid.runtime.failure.B')]);
    $pdo->exec("UPDATE ai_reply_dispatch_state SET last_business_id=0 WHERE name='all'");
    $visited = [];
    $failed = $dispatcher->run(function (int $business, int $limit, float $deadline) use ($worker, $a, &$visited): array {
        $visited[] = $business;
        if ($business === $a['business_id']) { throw new RuntimeException('Synthetic tenant-specific failure'); }
        return $worker->run($business, $limit, $deadline);
    }, 2, 1);
    adminAssert($failed['failed_businesses'] === 1 && $visited === [$a['business_id'], $b['business_id']],
        'One tenant failure cannot block the next tenant');
    $worker->run($a['business_id']); $worker->run($b['business_id']);
    $job = adminSql($pdo, "SELECT event_id FROM ai_reply_jobs WHERE business_id=? AND status='sent' ORDER BY event_id LIMIT 1", [$a['business_id']])->fetchColumn();
    adminSql($pdo, "UPDATE ai_reply_jobs SET status='sending',locked_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),finished_at=NULL WHERE event_id=?", [$job]);
    runtimeBridge($f, $config, [runtimeEntry($wabaA, $phoneA, 'wamid.runtime.suspended.pending', '601199999555')]);
    adminSql($pdo, 'UPDATE ai_reply_settings SET enabled=1 WHERE business_id=?', [$a['business_id']]);
    adminSql($pdo, "UPDATE businesses SET status='suspended' WHERE id=?", [$a['business_id']]);
    try {
        $recovered = $dispatcher->run([$worker, 'run'], 2, 1);
        adminAssert($recovered['recovered'] === 1 && $recovered['archived'] === 1 && runtimeCalls($calls) === $before
            && adminSql($pdo, 'SELECT status FROM ai_reply_jobs WHERE event_id=?', [$job])->fetchColumn() === 'unknown',
            'Suspended business recovers uncertain send and archives queued message without providers');
    } finally {
        adminSql($pdo, "UPDATE businesses SET status='active' WHERE id=?", [$a['business_id']]);
        adminSql($pdo, 'UPDATE ai_reply_settings SET enabled=0 WHERE business_id=?', [$a['business_id']]);
    }
});

fwrite(STDOUT, 'PASS ' . $adminChecks . " customer AI runtime checks; synthetic DB and injected transports only.\n");
