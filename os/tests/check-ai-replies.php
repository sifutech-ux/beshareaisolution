<?php
declare(strict_types=1);

/**
 * New worker integration checks only. Refuses non-loopback/non-dedicated DBs,
 * requires an empty schema, and never loads private config or a live transport.
 *
 * BESHARE_AI_TEST_DSN=mysql:host=127.0.0.1;port=3306;dbname=beshare_ai_reply_test
 * BESHARE_AI_TEST_USER / BESHARE_AI_TEST_PASSWORD are disposable CI credentials.
 */
use BesharOs\Accounts;
use BesharOs\AiReplyWorker;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;
use BesharOs\WhatsAppWebhook;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dsn = getenv('BESHARE_AI_TEST_DSN');
if (!is_string($dsn) || preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]{2,5};dbname=beshare_ai_reply_test\z/', $dsn) !== 1) {
    fwrite(STDERR, "Refused: a dedicated loopback beshare_ai_reply_test schema is required.\n");
    exit(1);
}
$user = getenv('BESHARE_AI_TEST_USER');
$password = getenv('BESHARE_AI_TEST_PASSWORD');
if (!is_string($user) || $user === '' || !is_string($password)) {
    fwrite(STDERR, "Refused: disposable test credentials are required.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/SqlScript.php';
require_once dirname(__DIR__) . '/src/AiReplyWorker.php';

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
];
$pdo = new PDO($dsn, $user, $password, $pdoOptions);
$pdo->exec("SET time_zone = '+00:00'");
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'beshare_ai_reply_test'
    || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
    fwrite(STDERR, "Refused: test schema must be empty. No tables were removed.\n");
    exit(1);
}
foreach (['0001_foundation', '0002_coexistence_sync', '0003_coexistence_worker', '0004_ai_replies'] as $migration) {
    foreach (SqlScript::statements((string) file_get_contents(dirname(__DIR__) . '/migrations/' . $migration . '.up.sql')) as $sql) {
        $pdo->exec($sql);
    }
}

$seal = new TokenSeal(str_repeat('k', 32), 1);
$now = 1792764000;
$checks = 0;
$fixtureNumber = 0;
$messageNumber = 0;
$calls = ['generate' => 0, 'send' => 0, 'inputs' => []];

function check(string $label, bool $pass): void
{
    global $checks;
    if (!$pass) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    fwrite(STDOUT, 'OK ' . $label . "\n");
}

function sql(PDO $pdo, string $query, array $values = []): PDOStatement
{
    $statement = $pdo->prepare($query);
    $statement->execute($values);
    return $statement;
}

function row(PDO $pdo, string $query, array $values = []): array
{
    $result = sql($pdo, $query, $values)->fetch(PDO::FETCH_ASSOC);
    return is_array($result) ? $result : [];
}

function fixture(PDO $pdo, TokenSeal $seal, int $now): array
{
    global $fixtureNumber;
    $number = ++$fixtureNumber;
    $actor = Accounts::register($pdo, 'Owner Test', 'worker' . $number . '@example.test', 'Synthetic-only-passphrase!', 'Synthetic Shop ' . $number);
    $token = $seal->seal('synthetic-owner-token-' . $number, 2048);
    $date = gmdate('Y-m-d H:i:s', $now);
    $waba = (string) (6100000000000000 + $number);
    $phone = (string) (6200000000000000 + $number);
    sql($pdo,
        "INSERT INTO whatsapp_connections
         (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,registration_state,messaging_state,
          token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,subscribed_at,created_at,updated_at)
         VALUES (?,?,?,'+60100000111','verified','subscribed','registered','receive_verified',
                 'system_user',?,?,?,?,?,?,?)",
        [$actor['business_id'], $waba, $phone, $token['ciphertext'], $token['nonce'], $token['tag'], $token['version'], $date, $date, $date]
    );
    $connection = (int) $pdo->lastInsertId();
    $knowledge = 'Synthetic shop ' . $number . ' sells tea at RM5. Open 9am to 5pm.';
    $encrypted = $seal->seal($knowledge, 8000);
    sql($pdo,
        "INSERT INTO ai_reply_settings
         (business_id,connection_id,enabled,knowledge_ciphertext,knowledge_nonce,knowledge_tag,knowledge_key_version,
          knowledge_revision,knowledge_hash,approved_by,approved_at,allowlist_json,daily_limit,activation_at,created_at,updated_at)
         VALUES (?,?,1,?,?,?,?,1,?,?,?, ?,20,?,?,?)",
        [$actor['business_id'], $connection, $encrypted['ciphertext'], $encrypted['nonce'], $encrypted['tag'], $encrypted['version'],
         hash('sha256', $knowledge), $actor['user_id'], $date, '["60100000111"]', gmdate('Y-m-d H:i:s', $now - 100), $date, $date]
    );
    return ['business' => (int) $actor['business_id'], 'owner' => (int) $actor['user_id'],
        'connection' => $connection, 'waba' => $waba, 'phone' => $phone, 'knowledge' => $knowledge,
        'token' => 'synthetic-owner-token-' . $number];
}

function event(PDO $pdo, TokenSeal $seal, array $f, int $timestamp, string $text = 'Berapa harga teh?', string $peer = '60100000111', string $type = 'text'): array
{
    global $messageNumber;
    $wamid = 'wamid.in.' . str_pad((string) ++$messageNumber, 12, '0', STR_PAD_LEFT);
    $message = ['id' => $wamid, 'from' => $peer, 'timestamp' => (string) $timestamp, 'type' => $type];
    if ($type === 'text') {
        $message['text'] = ['body' => $text];
    }
    $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => $f['waba'], 'changes' => [[
            'field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $f['phone']], 'messages' => [$message]],
        ]],
    ]]], JSON_THROW_ON_ERROR);
    // Follow the real endpoint's authenticate-before-ingest boundary using a synthetic secret.
    if (!WhatsAppWebhook::signatureMatches($raw, 'sha256=' . hash_hmac('sha256', $raw, 'synthetic-webhook-secret'), 'synthetic-webhook-secret')) {
        throw new RuntimeException('synthetic signature failed');
    }
    (new WhatsAppWebhook($pdo, $seal))->ingest($raw);
    $found = row($pdo, 'SELECT id FROM webhook_events WHERE business_id = ? AND connection_id = ? ORDER BY id DESC LIMIT 1', [$f['business'], $f['connection']]);
    return ['id' => (int) $found['id'], 'wamid' => $wamid, 'raw' => $raw];
}

function worker(PDO $pdo, TokenSeal $seal, int &$now, array &$calls, ?callable $generate = null, ?callable $send = null): AiReplyWorker
{
    $defaultGenerate = function (string $knowledge, string $inbound, array $history) use (&$calls): array {
        $calls['generate']++;
        $calls['inputs'][] = compact('knowledge', 'inbound', 'history');
        return ['reply' => 'Harga teh ialah RM5.', 'handoff' => false];
    };
    $defaultSend = function (string $token, string $phone, string $peer, string $text, ?string $replyTo) use (&$calls): array {
        $calls['send']++;
        return ['sent' => true, 'message_id' => 'wamid.out.' . str_pad((string) $calls['send'], 12, '0', STR_PAD_LEFT), 'ambiguous' => false, 'error' => null];
    };
    return new AiReplyWorker($pdo, $seal, $generate ?? $defaultGenerate, $send ?? $defaultSend, static function () use (&$now): int { return $now; });
}

function openBody(TokenSeal $seal, array $message): array
{
    return json_decode($seal->open($message['body_ciphertext'], $message['body_nonce'], $message['body_tag'], (int) $message['body_key_version']), true, 32, JSON_THROW_ON_ERROR);
}

function noProviders(array $before, array $after): bool
{
    return $before['generate'] === $after['generate'] && $before['send'] === $after['send'];
}

// Happy path verifies both pre-call durability boundaries, then queue purge and history retention.
$f = fixture($pdo, $seal, $now);
$e = event($pdo, $seal, $f, $now - 1);
$happy = worker($pdo, $seal, $now, $calls,
    function (string $knowledge, string $inbound, array $history) use ($pdo, $seal, $f, $e, &$calls): array {
        $calls['generate']++;
        $job = row($pdo, 'SELECT * FROM ai_reply_jobs WHERE event_id = ?', [$e['id']]);
        $archive = row($pdo, "SELECT * FROM ai_messages WHERE source_event_id = ? AND direction = 'in'", [$e['id']]);
        check('generation reservation committed before provider', !$pdo->inTransaction() && $job['status'] === 'generating' && (int) $job['generation_attempts'] === 1);
        check('incoming encrypted archive exists before generation', $archive !== [] && openBody($seal, $archive)['text'] === $inbound && $archive['body_ciphertext'] !== $inbound);
        check('generation uses only approved tenant knowledge', $knowledge === $f['knowledge'] && $history === []);
        return ['reply' => 'Harga teh ialah RM5.', 'handoff' => false];
    },
    function (string $token, string $phone, string $peer, string $text, ?string $replyTo) use ($pdo, $f, $e, &$calls): array {
        $calls['send']++;
        $job = row($pdo, 'SELECT * FROM ai_reply_jobs WHERE event_id = ?', [$e['id']]);
        check('send attempt persisted and control locks held before transport', $pdo->inTransaction() && $job['status'] === 'sending' && (int) $job['send_attempts'] === 1 && (int) $job['reply_message_id'] > 0);
        check('sender receives exact tenant token phone peer and reply target', $token === $f['token'] && $phone === $f['phone'] && $peer === '60100000111' && $replyTo === $e['wamid']);
        return ['sent' => true, 'message_id' => 'wamid.out.happy0001', 'ambiguous' => false, 'error' => null];
    }
);
$result = $happy->run($f['business']);
$queued = row($pdo, 'SELECT * FROM webhook_events WHERE id = ?', [$e['id']]);
$incoming = row($pdo, "SELECT * FROM ai_messages WHERE source_event_id = ? AND direction = 'in'", [$e['id']]);
$outgoing = row($pdo, "SELECT * FROM ai_messages WHERE source_event_id = ? AND direction = 'out'", [$e['id']]);
check('successful send terminal with purged queue', $result['sent'] === 1 && $queued['result_code'] === 'reply_sent' && $queued['payload_ciphertext'] === null && $queued['payload_purged_at'] !== null);
check('confirmed send upgrades existing receive proof', row($pdo, 'SELECT messaging_state FROM whatsapp_connections WHERE id = ?', [$f['connection']])['messaging_state'] === 'send_and_receive_verified');
check('inbound and outbound remain decryptable after queue purge', openBody($seal, $incoming)['wamid'] === $e['wamid'] && openBody($seal, $outgoing)['reply_to'] === $e['wamid'] && openBody($seal, $outgoing)['text'] === 'Harga teh ialah RM5.');
check('one durable quota reservation', (int) row($pdo, 'SELECT quota_used FROM ai_reply_settings WHERE business_id = ?', [$f['business']])['quota_used'] === 1);
$before = $calls;
(new WhatsAppWebhook($pdo, $seal))->ingest($e['raw']);
$happy->run($f['business']);
check('webhook replay never regenerates or resends', noProviders($before, $calls));

// Delivery events update history; no Gemini or sending, and lower states do not regress read.
foreach (['read', 'delivered', 'sent'] as $delivery) {
    $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => $f['waba'], 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $f['phone']], 'statuses' => [[
                'id' => 'wamid.out.happy0001', 'status' => $delivery, 'timestamp' => (string) $now, 'recipient_id' => '60100000111',
            ]],
        ]]],
    ]]], JSON_THROW_ON_ERROR);
    (new WhatsAppWebhook($pdo, $seal))->ingest($raw);
    $happy->run($f['business']);
}
check('delivery-only statuses never invoke providers or regress read', noProviders($before, $calls) && row($pdo, 'SELECT status FROM ai_messages WHERE id = ?', [$outgoing['id']])['status'] === 'read');
foreach (['history', 'smb_message_echoes'] as $field) {
    (new WhatsAppWebhook($pdo, $seal))->ingest(json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => $f['waba'], 'changes' => [['field' => $field, 'value' => ['metadata' => ['phone_number_id' => $f['phone']]]]],
    ]]], JSON_THROW_ON_ERROR));
}
$happy->run($f['business']);
check('history and echoes are not AI input', noProviders($before, $calls));

// Gates act on original signed timestamp and owner settings, while preserving incoming history.
foreach (['disabled', 'not_allowlisted', 'expired_window', 'before_activation', 'future_timestamp', 'unsupported', 'human_request'] as $scenario) {
    $case = fixture($pdo, $seal, $now);
    $stamp = match ($scenario) {
        'expired_window' => $now - 90000,
        'before_activation' => $now - 200,
        'future_timestamp' => $now + 1,
        default => $now - 1,
    };
    if ($scenario === 'disabled') {
        sql($pdo, 'UPDATE ai_reply_settings SET enabled = 0 WHERE business_id = ?', [$case['business']]);
    }
    $event = event($pdo, $seal, $case, $stamp, $scenario === 'human_request' ? 'Nak bercakap dengan admin' : 'Harga?',
        $scenario === 'not_allowlisted' ? '60100000999' : '60100000111', $scenario === 'unsupported' ? 'image' : 'text');
    $before = $calls;
    worker($pdo, $seal, $now, $calls)->run($case['business']);
    check($scenario . ' blocks both providers and retains incoming archive', noProviders($before, $calls)
        && (int) sql($pdo, "SELECT COUNT(*) FROM ai_messages WHERE source_event_id = ? AND direction = 'in'", [$event['id']])->fetchColumn() === 1
        && row($pdo, 'SELECT status FROM webhook_events WHERE id = ?', [$event['id']])['status'] === 'processed');
}

// Recheck mutable controls after the generation call and before any sending claim.
foreach (['disable', 'knowledge_changed', 'human_pause', 'token_revoked', 'window_elapsed'] as $scenario) {
    $case = fixture($pdo, $seal, $now);
    $event = event($pdo, $seal, $case, $now - 1);
    $originalNow = $now;
    $beforeSend = $calls['send'];
    $generator = function (string $knowledge, string $inbound, array $history) use ($pdo, $case, $scenario, &$calls, &$now): array {
        $calls['generate']++;
        if ($scenario === 'disable') {
            sql($pdo, 'UPDATE ai_reply_settings SET enabled = 0 WHERE business_id = ?', [$case['business']]);
        } elseif ($scenario === 'knowledge_changed') {
            sql($pdo, 'UPDATE ai_reply_settings SET knowledge_revision = knowledge_revision + 1 WHERE business_id = ?', [$case['business']]);
        } elseif ($scenario === 'human_pause') {
            sql($pdo, "UPDATE ai_conversations SET mode = 'human' WHERE business_id = ?", [$case['business']]);
        } elseif ($scenario === 'token_revoked') {
            sql($pdo, "UPDATE whatsapp_connections SET token_state = 'revoked', token_ciphertext = NULL,
                token_nonce = NULL, token_tag = NULL, token_key_version = NULL, messaging_state = 'unverified'
                WHERE id = ?", [$case['connection']]);
        } elseif ($scenario === 'window_elapsed') {
            $now += 86400;
        }
        return ['reply' => 'Generated answer.', 'handoff' => false];
    };
    worker($pdo, $seal, $now, $calls, $generator)->run($case['business']);
    check($scenario . ' during generation prevents send', $calls['send'] === $beforeSend
        && row($pdo, 'SELECT status FROM ai_reply_jobs WHERE event_id = ?', [$event['id']])['status'] === 'handoff');
    $now = $originalNow;
}

// Generation exceptions, handoff and malformed output terminate without automatic retry.
foreach (['exception', 'handoff', 'invalid_reply'] as $scenario) {
    $case = fixture($pdo, $seal, $now);
    $event = event($pdo, $seal, $case, $now - 1);
    $beforeSend = $calls['send'];
    $generator = function () use ($scenario, &$calls): array {
        $calls['generate']++;
        if ($scenario === 'exception') {
            throw new RuntimeException('Synthetic secret: must never be logged');
        }
        return $scenario === 'handoff' ? ['reply' => '', 'handoff' => true] : ['reply' => '', 'handoff' => false];
    };
    $w = worker($pdo, $seal, $now, $calls, $generator);
    $w->run($case['business']);
    $after = $calls;
    $w->run($case['business']);
    check('generation ' . $scenario . ' has no retry or send', noProviders($after, $calls) && $calls['send'] === $beforeSend
        && row($pdo, 'SELECT status FROM ai_reply_jobs WHERE event_id = ?', [$event['id']])['status'] === 'handoff');
}

// Daily quota is reserved once before the first generation, not measured after sending.
$case = fixture($pdo, $seal, $now);
sql($pdo, 'UPDATE ai_reply_settings SET daily_limit = 1 WHERE business_id = ?', [$case['business']]);
event($pdo, $seal, $case, $now - 2);
event($pdo, $seal, $case, $now - 1);
$before = $calls;
worker($pdo, $seal, $now, $calls)->run($case['business']);
check('daily quota bounds provider work before calls', $calls['generate'] === $before['generate'] + 1 && $calls['send'] === $before['send'] + 1
    && (int) row($pdo, 'SELECT quota_used FROM ai_reply_settings WHERE business_id = ?', [$case['business']])['quota_used'] === 1);

// Definite rejection is terminal; timeout/exception/malformed success remains unknown.
foreach (['ambiguous', 'exception', 'malformed_success', 'rejected'] as $scenario) {
    $case = fixture($pdo, $seal, $now);
    $event = event($pdo, $seal, $case, $now - 1);
    $sender = function () use ($scenario, &$calls): array {
        $calls['send']++;
        if ($scenario === 'exception') {
            throw new RuntimeException('Synthetic transport interruption');
        }
        return match ($scenario) {
            'rejected' => ['sent' => false, 'ambiguous' => false, 'error' => 'rejected'],
            'malformed_success' => ['sent' => true, 'ambiguous' => false, 'message_id' => null],
            default => ['sent' => false, 'ambiguous' => true, 'error' => 'timeout'],
        };
    };
    $w = worker($pdo, $seal, $now, $calls, null, $sender);
    $w->run($case['business']);
    $after = $calls;
    $w->run($case['business']);
    $job = row($pdo, 'SELECT * FROM ai_reply_jobs WHERE event_id = ?', [$event['id']]);
    $reply = row($pdo, "SELECT * FROM ai_messages WHERE source_event_id = ? AND direction = 'out'", [$event['id']]);
    check('send ' . $scenario . ' never repeats and preserves draft for human', noProviders($after, $calls)
        && $job['status'] === ($scenario === 'rejected' ? 'failed' : 'unknown')
        && (int) $job['send_attempts'] === 1 && openBody($seal, $reply)['text'] !== ''
        && row($pdo, 'SELECT mode FROM ai_conversations WHERE id = ?', [$job['conversation_id']])['mode'] === 'human');
}

// Reproduce the durable state left by process death at either external-call boundary.
foreach (['generating', 'sending'] as $phase) {
    $case = fixture($pdo, $seal, $now);
    $event = event($pdo, $seal, $case, $now - 1);
    $queued = row($pdo, 'SELECT * FROM webhook_events WHERE id = ?', [$event['id']]);
    $plain = $seal->open($queued['payload_ciphertext'], $queued['payload_nonce'], $queued['payload_tag'], (int) $queued['payload_key_version']);
    $archive = $seal->seal($plain, 200000);
    $date = gmdate('Y-m-d H:i:s', $now - 200);
    sql($pdo, "INSERT INTO ai_conversations (business_id,connection_id,recipient,mode,last_inbound_at,created_at,updated_at)
        VALUES (?,?,'60100000111','ai',?,?,?)", [$case['business'], $case['connection'], $date, $date, $date]);
    $conversation = (int) $pdo->lastInsertId();
    sql($pdo, "INSERT INTO ai_messages (business_id,connection_id,conversation_id,source_event_id,direction,wamid,
        body_ciphertext,body_nonce,body_tag,body_key_version,message_at,status,created_at,updated_at)
        VALUES (?,?,?,?,'in',?,?,?,?,?,?,'received',?,?)",
        [$case['business'], $case['connection'], $conversation, $event['id'], $event['wamid'],
         $archive['ciphertext'], $archive['nonce'], $archive['tag'], $archive['version'], $date, $date, $date]);
    $replyId = null;
    if ($phase === 'sending') {
        $reply = $seal->seal(json_encode(['text' => 'Persisted reply.', 'reply_to' => $event['wamid']], JSON_THROW_ON_ERROR), 200000);
        sql($pdo, "INSERT INTO ai_messages (business_id,connection_id,conversation_id,source_event_id,direction,
            body_ciphertext,body_nonce,body_tag,body_key_version,message_at,status,created_at,updated_at)
            VALUES (?,?,?,?,'out',?,?,?,?,?,'sending',?,?)",
            [$case['business'], $case['connection'], $conversation, $event['id'], $reply['ciphertext'], $reply['nonce'], $reply['tag'], $reply['version'], $date, $date, $date]);
        $replyId = (int) $pdo->lastInsertId();
    }
    sql($pdo, 'INSERT INTO ai_reply_jobs (event_id,business_id,connection_id,conversation_id,knowledge_revision,status,
        reply_message_id,generation_attempts,send_attempts,locked_until,claimed_at,created_at,updated_at)
        VALUES (?,?,?,?,1,?,?,1,?,?,?,?,?)',
        [$event['id'], $case['business'], $case['connection'], $conversation, $phase, $replyId,
         $phase === 'sending' ? 1 : 0, $date, $date, $date, $date]);
    sql($pdo, "UPDATE webhook_events SET status = 'processing', locked_until = ? WHERE id = ?", [$date, $event['id']]);
    $before = $calls;
    $w = worker($pdo, $seal, $now, $calls);
    $result = $w->run($case['business']);
    $w->run($case['business']);
    check('interrupted ' . $phase . ' recovers once without provider retry', $result['recovered'] === 1
        && noProviders($before, $calls) && row($pdo, 'SELECT status FROM ai_reply_jobs WHERE event_id = ?', [$event['id']])['status'] === ($phase === 'sending' ? 'unknown' : 'handoff')
        && row($pdo, 'SELECT payload_ciphertext FROM webhook_events WHERE id = ?', [$event['id']])['payload_ciphertext'] === null);
}

// A competing process cannot reserve quota or call providers for the same business.
$case = fixture($pdo, $seal, $now);
event($pdo, $seal, $case, $now - 1);
$otherPdo = new PDO($dsn, $user, $password, $pdoOptions);
$lockName = 'beshare_ai_reply_' . $case['business'];
sql($pdo, 'SELECT GET_LOCK(?, 0)', [$lockName]);
$before = $calls;
$result = worker($otherPdo, $seal, $now, $calls)->run($case['business']);
sql($pdo, 'SELECT RELEASE_LOCK(?)', [$lockName]);
check('concurrent worker refuses the held tenant lease', $result['mode'] === 'busy' && noProviders($before, $calls)
    && (int) row($pdo, 'SELECT quota_used FROM ai_reply_settings WHERE business_id = ?', [$case['business']])['quota_used'] === 0);

// A mismatch in the sealed event scope is quarantined with its ciphertext intact.
$case = fixture($pdo, $seal, $now);
$event = event($pdo, $seal, $case, $now - 1);
$queued = row($pdo, 'SELECT * FROM webhook_events WHERE id = ?', [$event['id']]);
$body = json_decode($seal->open($queued['payload_ciphertext'], $queued['payload_nonce'], $queued['payload_tag'], (int) $queued['payload_key_version']), true, 32, JSON_THROW_ON_ERROR);
$body['waba_id'] = '6999999999999999';
$plain = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$changed = $seal->seal($plain, 200000);
sql($pdo, 'UPDATE webhook_events SET payload_hash=?,payload_ciphertext=?,payload_nonce=?,payload_tag=?,payload_key_version=? WHERE id=?',
    [hash('sha256', $plain), $changed['ciphertext'], $changed['nonce'], $changed['tag'], $changed['version'], $event['id']]);
$before = $calls;
worker($pdo, $seal, $now, $calls)->run($case['business']);
$quarantined = row($pdo, 'SELECT status,payload_ciphertext FROM webhook_events WHERE id=?', [$event['id']]);
check('mismatched tenant binding never enters inbox or providers', noProviders($before, $calls)
    && $quarantined['status'] === 'failed' && $quarantined['payload_ciphertext'] !== null
    && (int) sql($pdo, 'SELECT COUNT(*) FROM ai_messages WHERE source_event_id=?', [$event['id']])->fetchColumn() === 0);

fwrite(STDOUT, 'PASS ' . $checks . " owner AI worker checks; synthetic data and injected transports only.\n");
