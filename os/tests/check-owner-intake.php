<?php
declare(strict_types=1);

use BesharOs\OwnerIntakeBridge;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = getenv('BESHARE_OWNER_INTAKE_TEST_DSN');
if (!is_string($dsn) || preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]{2,5};dbname=beshare_owner_intake_test\z/D', $dsn) !== 1) {
    fwrite(STDERR, "Refused: dedicated loopback beshare_owner_intake_test is required.\n");
    exit(1);
}
$user = getenv('BESHARE_AI_TEST_USER');
$password = getenv('BESHARE_AI_TEST_PASSWORD');
if (!is_string($user) || $user === '' || !is_string($password)) {
    fwrite(STDERR, "Refused: disposable CI credentials are required.\n");
    exit(1);
}
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/SqlScript.php';
require_once __DIR__ . '/../src/OwnerIntakeBridge.php';

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'beshare_owner_intake_test'
    || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Refused: test database must be empty. No tables removed.');
}
$pdo->exec("SET time_zone = '+00:00'");
foreach (['0001_foundation', '0002_coexistence_sync', '0003_coexistence_worker'] as $migration) {
    foreach (SqlScript::statements((string) file_get_contents(__DIR__ . '/../migrations/' . $migration . '.up.sql')) as $sql) $pdo->exec($sql);
}

const INTAKE_WABA = '1104940391945821';
const INTAKE_PHONE = '1443799975474056';
const INTAKE_SECRET = 'synthetic-hmac-secret-used-only-in-fixtures';
$checks = 0;

function intakeCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    $checks++;
}

function intakeSql(PDO $pdo, string $sql, array $values = []): PDOStatement
{
    $query = $pdo->prepare($sql);
    $query->execute($values);
    return $query;
}

/** @return array{business:int,user:int} */
function intakeTenant(PDO $pdo, string $suffix): array
{
    $now = gmdate('Y-m-d H:i:s');
    intakeSql($pdo, 'INSERT INTO users (email,password_hash,name,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',
        ['intake-' . $suffix . '@fixture.invalid', 'synthetic-not-a-password', 'Fixture Owner', 'active', $now, $now]);
    $ownerId = (int) $pdo->lastInsertId();
    intakeSql($pdo, 'INSERT INTO businesses (public_id,name,status,owner_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?)',
        [str_pad($suffix, 26, '0', STR_PAD_LEFT), 'Fixture tenant', 'provisioning', $ownerId, $now, $now]);
    $businessId = (int) $pdo->lastInsertId();
    intakeSql($pdo, 'INSERT INTO business_users (business_id,user_id,role,created_at,updated_at) VALUES (?,?,?,?,?)',
        [$businessId, $ownerId, 'owner', $now, $now]);
    intakeSql($pdo, 'UPDATE businesses SET status=? WHERE id=?', ['active', $businessId]);
    return ['business' => $businessId, 'user' => $ownerId];
}

function intakeConnection(PDO $pdo, TokenSeal $seal, int $business, string $waba, string $phone): int
{
    $token = $seal->seal('synthetic-owner-access-token-not-a-real-credential', 1800);
    intakeSql($pdo, "INSERT INTO whatsapp_connections
        (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,registration_state,messaging_state,
         token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,subscribed_at,created_at,updated_at)
        VALUES (?,?,?,'+601154320018','verified','subscribed','registered','unverified','system_user',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
        [$business, $waba, $phone, $token['ciphertext'], $token['nonce'], $token['tag'], $token['version']]);
    return (int) $pdo->lastInsertId();
}

function intakeMessage(string $wamid, string $body = 'Synthetic owner question'): array
{
    return ['id' => $wamid, 'from' => '601100000222', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $body]];
}

function intakeEntry(string $waba, string $phone, array $messages, array $statuses = []): array
{
    return ['id' => $waba, 'changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => $phone], 'messages' => $messages, 'statuses' => $statuses,
        'contacts' => [['wa_id' => '601100000222', 'profile' => ['name' => 'Synthetic Contact']]],
    ]]]];
}

function intakeRaw(array $entries): string
{
    return json_encode(['object' => 'whatsapp_business_account', 'entry' => $entries], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function intakeSignature(string $raw): string
{
    return 'sha256=' . hash_hmac('sha256', $raw, INTAKE_SECRET);
}

function intakeCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
}

/** Throws if code reaches the DB; never creates a database or network connection. */
final class IntakeTrapPdo extends PDO
{
    public int $calls = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->calls++;
        throw new RuntimeException('Synthetic database outage');
    }
}

$seal = new TokenSeal(str_repeat('i', 32), 1);
$owner = intakeTenant($pdo, '1');
$other = intakeTenant($pdo, '2');
$config = ['owner_intake_enabled' => 'true', 'owner_whatsapp_business_id' => (string) $owner['business'],
    'owner_whatsapp_portfolio_id' => '1249591211577465', 'owner_whatsapp_waba_id' => INTAKE_WABA,
    'owner_whatsapp_phone_number_id' => INTAKE_PHONE, 'app_secret' => INTAKE_SECRET,
    'owner_ai_enabled' => 'false'];
$raw = intakeRaw([intakeEntry(INTAKE_WABA, INTAKE_PHONE, [intakeMessage('wamid.owner.first')])]);

// Disabled mode and unauthenticated bytes cannot reach decoding-dependent DB work.
$trap = new IntakeTrapPdo();
$disabled = $config;
unset($disabled['owner_intake_enabled']);
$result = OwnerIntakeBridge::deliver($raw, intakeSignature($raw), $disabled, $trap, $seal);
intakeCheck($result['status'] === 'disabled' && $trap->calls === 0, 'Intake defaults disabled with no DB work');
$result = OwnerIntakeBridge::deliver($raw, 'sha256=' . str_repeat('0', 64), $config, $trap, $seal);
intakeCheck($result['status'] === 'rejected' && $trap->calls === 0, 'Invalid original HMAC rejected before DB');
$changed = str_replace('Synthetic owner question', 'Modified question', $raw);
$result = OwnerIntakeBridge::deliver($changed, intakeSignature($raw), $config, $trap, $seal);
intakeCheck($result['status'] === 'rejected' && $trap->calls === 0, 'Tampered original bytes rejected');

// Real owner input must remain retryable until the exact local mapping exists.
$result = OwnerIntakeBridge::deliver($raw, intakeSignature($raw), $config, $pdo, $seal);
intakeCheck($result['status'] === 'failed' && $result['owner_slice'] && intakeCount($pdo) === 0, 'Missing binding never acknowledged or stored unscoped');
$connection = intakeConnection($pdo, $seal, $owner['business'], INTAKE_WABA, INTAKE_PHONE);
$foreignConnection = intakeConnection($pdo, $seal, $other['business'], '7000000000011', '8000000000011');
$result = OwnerIntakeBridge::deliver($raw, intakeSignature($raw), $config, $trap, $seal);
intakeCheck($result['status'] === 'failed' && $trap->calls === 1 && intakeCount($pdo) === 0, 'Database outage is a retryable owner failure');

// Mixed batches cannot leak DHerbs, other clients, or lifecycle/history into owner intake.
$status = ['id' => 'wamid.owner.delivery', 'status' => 'delivered', 'timestamp' => (string) time(), 'recipient_id' => '601100000222'];
$ownerEntry = intakeEntry(INTAKE_WABA, INTAKE_PHONE, [intakeMessage('wamid.owner.mixed', 'Only this owner body belongs in OS')], [$status]);
$ownerEntry['changes'][] = ['field' => 'account_update', 'value' => ['event' => 'PARTNER_REMOVED']];
$ownerEntry['changes'][] = ['field' => 'history', 'value' => ['metadata' => ['phone_number_id' => INTAKE_PHONE], 'secret_fixture' => 'history must stay out']];
$mixed = intakeRaw([$ownerEntry,
    intakeEntry(INTAKE_WABA, '1207159062490441', [intakeMessage('wamid.dherbs.ignore', 'DHerbs body must stay out')]),
    intakeEntry('7000000000011', '8000000000011', [intakeMessage('wamid.client.ignore', 'Other client body must stay out')]),
    intakeEntry('7000000000099', INTAKE_PHONE, [intakeMessage('wamid.wrongwaba.ignore', 'Wrong WABA body must stay out')]),
]);
$result = OwnerIntakeBridge::deliver($mixed, intakeSignature($mixed), $config, $pdo, $seal);
intakeCheck($result['status'] === 'stored' && $result['owner_slice'] && $result['stored'] === 2 && $result['replayed'] === 0, 'Only exact owner message and status are ingested');
intakeCheck(intakeCount($pdo) === 2, 'Mixed foreign and lifecycle events remain outside owner slice');
$events = $pdo->query('SELECT * FROM webhook_events ORDER BY id')->fetchAll();
foreach ($events as $event) {
    $body = json_decode($seal->open($event['payload_ciphertext'], $event['payload_nonce'], $event['payload_tag'], (int) $event['payload_key_version']), true, 32, JSON_THROW_ON_ERROR);
    intakeCheck((int) $event['business_id'] === $owner['business'] && (int) $event['connection_id'] === $connection
        && $body['waba_id'] === INTAKE_WABA && $body['phone_number_id'] === INTAKE_PHONE, 'Every stored event belongs to configured internal tenant and asset');
    intakeCheck(!str_contains(json_encode($body, JSON_THROW_ON_ERROR), 'must stay out'), 'Foreign message bodies are not forwarded');
}
intakeCheck(intakeSql($pdo, 'SELECT disconnected_at FROM whatsapp_connections WHERE id=?', [$connection])->fetchColumn() === null, 'Lifecycle change was left for the existing lifecycle handler');
intakeCheck(($config['owner_ai_enabled'] ?? '') === 'false' && intakeCount($pdo) === 2, 'Intake works while AI is disabled');
$again = OwnerIntakeBridge::deliver($mixed, intakeSignature($mixed), $config, $pdo, $seal);
intakeCheck($again['status'] === 'stored' && $again['stored'] === 0 && $again['replayed'] === 2 && intakeCount($pdo) === 2, 'Provider retry is deduplicated');

// All nonowner batches avoid even opening or querying the owner database.
$trap = new IntakeTrapPdo();
$foreignRaw = intakeRaw([intakeEntry('7000000000011', '8000000000011', [intakeMessage('wamid.foreign.only')])]);
$result = OwnerIntakeBridge::deliver($foreignRaw, intakeSignature($foreignRaw), $config, $trap, $seal);
intakeCheck($result['status'] === 'ignored' && !$result['owner_slice'] && $trap->calls === 0, 'Other client batch stays on its existing path');
$foreignRaw = intakeRaw([intakeEntry(INTAKE_WABA, '1207159062490441', [intakeMessage('wamid.dherbs.only')])]);
$result = OwnerIntakeBridge::deliver($foreignRaw, intakeSignature($foreignRaw), $config, $trap, $seal);
intakeCheck($result['status'] === 'ignored' && $trap->calls === 0, 'DHerbs never routes into owner intake');
$wrongConfig = $config;
$wrongConfig['owner_whatsapp_phone_number_id'] = '1207159062490441';
$result = OwnerIntakeBridge::deliver($foreignRaw, intakeSignature($foreignRaw), $wrongConfig, $trap, $seal);
intakeCheck($result['status'] === 'failed' && $trap->calls === 0, 'Private config cannot repurpose owner bridge for DHerbs');
$wrongConfig = $config;
$wrongConfig['owner_whatsapp_portfolio_id'] = '1249591211577466';
$result = OwnerIntakeBridge::deliver($raw, intakeSignature($raw), $wrongConfig, $trap, $seal);
intakeCheck($result['status'] === 'failed' && $trap->calls === 0, 'Portfolio configuration is exact');

// Matching Meta assets in the wrong SaaS tenant are never accepted.
$wrongTenant = $config;
$wrongTenant['owner_whatsapp_business_id'] = (string) $other['business'];
$before = intakeCount($pdo);
$result = OwnerIntakeBridge::deliver($raw, intakeSignature($raw), $wrongTenant, $pdo, $seal);
intakeCheck($result['status'] === 'failed' && intakeCount($pdo) === $before, 'Configured tenant cannot consume another tenant phone');
intakeSql($pdo, 'UPDATE businesses SET status=? WHERE id=?', ['suspended', $owner['business']]);
$result = OwnerIntakeBridge::deliver($raw, intakeSignature($raw), $config, $pdo, $seal);
intakeCheck($result['status'] === 'failed' && intakeCount($pdo) === $before, 'Suspended owner business is retryable');
intakeSql($pdo, 'UPDATE businesses SET status=? WHERE id=?', ['active', $owner['business']]);
intakeSql($pdo, 'UPDATE whatsapp_connections SET disconnected_at=UTC_TIMESTAMP() WHERE id=?', [$connection]);
$result = OwnerIntakeBridge::deliver($raw, intakeSignature($raw), $config, $pdo, $seal);
intakeCheck($result['status'] === 'failed' && intakeCount($pdo) === $before, 'Disconnected phone is not acknowledged');
intakeSql($pdo, 'UPDATE whatsapp_connections SET disconnected_at=NULL WHERE id=?', [$connection]);

// Invalid selected records fail before partially persisting a batch.
$malformedEntry = intakeEntry(INTAKE_WABA, INTAKE_PHONE, [intakeMessage('wamid.owner.goodbutbatchinvalid'), ['id' => 'missing-from']]);
$malformed = intakeRaw([$malformedEntry]);
$result = OwnerIntakeBridge::deliver($malformed, intakeSignature($malformed), $config, $pdo, $seal);
intakeCheck($result['status'] === 'failed' && intakeCount($pdo) === $before, 'Malformed selected batch cannot silently drop or partially store events');

// A preexisting dedupe key outside this tenant cannot masquerade as an owner replay.
$poisonedWamid = 'wamid.owner.preexistingforeign';
$sealed = $seal->seal('{"synthetic":"unrelated existing record"}', 1000);
intakeSql($pdo, "INSERT INTO webhook_events (business_id,connection_id,dedupe_key,event_kind,status,attempt_count,max_attempts,payload_hash,payload_ciphertext,payload_nonce,payload_tag,payload_key_version,received_at)
 VALUES (?,?,?,'message','accepted',0,5,?,?,?,?,?,UTC_TIMESTAMP())",
    [$other['business'], $foreignConnection, 'm:' . INTAKE_PHONE . ':' . $poisonedWamid, hash('sha256', 'synthetic'), $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version']]);
$poisoned = intakeRaw([intakeEntry(INTAKE_WABA, INTAKE_PHONE, [intakeMessage($poisonedWamid)])]);
$result = OwnerIntakeBridge::deliver($poisoned, intakeSignature($poisoned), $config, $pdo, $seal);
intakeCheck($result['status'] === 'failed', 'Foreign dedupe replay is not acknowledged as owner storage');

// Storage failure returns failed, which the app callback maps to HTTP 503 for retry.
$pdo->exec("CREATE TRIGGER owner_intake_fixture_failure BEFORE INSERT ON webhook_events FOR EACH ROW BEGIN IF NEW.dedupe_key LIKE '%wamid.owner.storagefailure%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_owner_intake_failure'; END IF; END");
$failure = intakeRaw([intakeEntry(INTAKE_WABA, INTAKE_PHONE, [intakeMessage('wamid.owner.storagefailure')])]);
$before = intakeCount($pdo);
$result = OwnerIntakeBridge::deliver($failure, intakeSignature($failure), $config, $pdo, $seal);
intakeCheck($result['status'] === 'failed' && $result['owner_slice'] && intakeCount($pdo) === $before, 'Storage error is retryable without false acknowledgement');
$pdo->exec('DROP TRIGGER owner_intake_fixture_failure');
$result = OwnerIntakeBridge::deliver($failure, intakeSignature($failure), $config, $pdo, $seal);
intakeCheck($result['status'] === 'stored' && $result['stored'] === 1 && intakeCount($pdo) === $before + 1, 'Retry after storage recovery stores exactly once');
intakeCheck(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), INTAKE_SECRET), 'Bridge returns no secret or message body');

fwrite(STDOUT, 'owner intake: ' . $checks . " focused assertions passed (MySQL, synthetic signed payloads, no provider calls)\n");
