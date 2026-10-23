<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/SqlScript.php';
require_once __DIR__ . '/../src/KnowledgeSchema.php';

use BesharOs\AiReplyStore;
use BesharOs\AiReplyWorker;
use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\Csrf;
use BesharOs\KnowledgeCentre;
use BesharOs\KnowledgeSchema;
use BesharOs\RateLimit;
use BesharOs\SqlScript;
use BesharOs\TokenSeal;
use BesharOs\WhatsAppWebhook;

// Only this explicit loopback disposable schema is ever created or dropped.
// No private configuration, HTTP client, provider, browser, or production service is used.
const KNOWLEDGE_TEST_DATABASE = 'beshare_knowledge_test';
$knowledgeChecks = 0;
function knowledgeCheck(bool $condition, string $label): void
{
    global $knowledgeChecks;
    if (!$condition) { throw new RuntimeException($label); }
    $knowledgeChecks++;
}
function knowledgeDenied(callable $operation, int $status, string $label): void
{
    try { $operation(); }
    catch (AuthException $error) { knowledgeCheck($error->status() === $status, $label . ': denial status'); return; }
    throw new RuntimeException($label . ': operation was allowed');
}
function knowledgeInsert(PDO $pdo, string $table, array $values): void
{
    knowledgeCheck(in_array($table, ['users', 'businesses', 'business_users', 'whatsapp_connections'], true), 'Fixture table allowlist');
    $columns = array_keys($values);
    foreach ($columns as $column) { knowledgeCheck(preg_match('/\A[a-z_]+\z/', $column) === 1, 'Fixture column'); }
    $statement = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach (array_values($values) as $index => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        if (preg_match('/_(?:ciphertext|nonce|tag)\z/', $columns[$index]) === 1) { $type = PDO::PARAM_LOB; }
        $statement->bindValue($index + 1, $value, $type);
    }
    $statement->execute();
}
function knowledgeCopyMediaCipher(PDO $pdo, string $id, array $from): void
{
    $statement = $pdo->prepare('UPDATE knowledge_media SET body_ciphertext=?,body_nonce=?,body_tag=?,body_key_version=? WHERE id=?');
    foreach (['body_ciphertext', 'body_nonce', 'body_tag'] as $index => $field) {
        $statement->bindValue($index + 1, $from[$field], PDO::PARAM_LOB);
    }
    $statement->bindValue(4, (int) $from['body_key_version'], PDO::PARAM_INT);
    $statement->bindValue(5, $id);
    $statement->execute();
}

$dsn = getenv('BESHARE_KNOWLEDGE_TEST_DSN');
$user = getenv('BESHARE_AI_TEST_USER');
$password = getenv('BESHARE_AI_TEST_PASSWORD');
if (!is_string($dsn) || preg_match('/\Amysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=beshare_knowledge_test(?:;charset=utf8mb4)?\z/', $dsn, $match) !== 1
    || (int) $match[1] < 1 || (int) $match[1] > 65535 || !is_string($user) || $user === '' || !is_string($password)) {
    throw new RuntimeException('Explicit loopback beshare_knowledge_test DSN and synthetic test credentials required');
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
$admin = new PDO('mysql:host=127.0.0.1;port=' . $match[1] . ';charset=utf8mb4', $user, $password, $options);
$admin->exec('DROP DATABASE IF EXISTS ' . KNOWLEDGE_TEST_DATABASE);
$admin->exec('CREATE DATABASE ' . KNOWLEDGE_TEST_DATABASE . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = null;
$rateDirectory = null;
try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->exec("SET time_zone='+00:00'");
    knowledgeCheck($pdo->query('SELECT DATABASE()')->fetchColumn() === KNOWLEDGE_TEST_DATABASE, 'Disposable schema identity');
    $sql = file_get_contents(__DIR__ . '/../migrations/0005_knowledge_centre.up.sql');
    knowledgeCheck(is_string($sql), 'Additive knowledge migration exists');
    $schema = new KnowledgeSchema($pdo, $sql);
    knowledgeDenied(fn () => $schema->inspect(), 409, 'Missing foundation fails before DDL');
    foreach (['0001_foundation.up.sql', '0002_coexistence_sync.up.sql', '0003_coexistence_worker.up.sql', '0004_ai_replies.up.sql'] as $filename) {
        $foundation = file_get_contents(__DIR__ . '/../migrations/' . $filename);
        knowledgeCheck(is_string($foundation), 'Foundation fixture migration exists');
        foreach (SqlScript::statements($foundation) as $statement) { $pdo->exec($statement); }
        $record = $pdo->prepare('INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,UTC_TIMESTAMP())');
        $record->execute([substr($filename, 0, 4), $filename, hash('sha256', $foundation)]);
    }
    $seal = new TokenSeal(str_repeat('K', 32), 1);
    $now = gmdate('Y-m-d H:i:s');
    foreach ([1, 2, 3] as $id) {
        knowledgeInsert($pdo, 'users', ['id' => $id, 'email' => 'knowledge-fixture-' . $id . '@example.invalid',
            'password_hash' => 'unused-synthetic-hash', 'name' => 'Knowledge fixture ' . $id, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now]);
    }
    foreach ([1 => 1, 2 => 3, 3 => 1] as $businessId => $ownerId) {
        knowledgeInsert($pdo, 'businesses', ['id' => $businessId, 'public_id' => str_pad((string) $businessId, 26, '0', STR_PAD_LEFT),
            'name' => 'Knowledge fixture ' . $businessId, 'status' => 'provisioning', 'owner_user_id' => $ownerId,
            'created_at' => $now, 'updated_at' => $now]);
        knowledgeInsert($pdo, 'business_users', ['business_id' => $businessId, 'user_id' => $ownerId, 'role' => 'owner',
            'created_at' => $now, 'updated_at' => $now]);
        $activate = $pdo->prepare("UPDATE businesses SET status='active' WHERE id=?");
        $activate->execute([$businessId]);
    }
    knowledgeInsert($pdo, 'business_users', ['business_id' => 1, 'user_id' => 2, 'role' => 'staff', 'created_at' => $now, 'updated_at' => $now]);
    $token = $seal->seal('synthetic-knowledge-fixture-token', 1800);
    foreach ([1, 2] as $businessId) {
        knowledgeInsert($pdo, 'whatsapp_connections', ['id' => $businessId, 'business_id' => $businessId,
            'waba_id' => '91000000000000' . $businessId, 'phone_number_id' => '92000000000000' . $businessId,
            'display_phone' => '+60110000000' . $businessId, 'token_state' => 'verified', 'webhook_state' => 'subscribed',
            'registration_state' => 'registered', 'messaging_state' => 'unverified', 'token_kind' => 'system_user',
            'token_ciphertext' => $token['ciphertext'], 'token_nonce' => $token['nonce'], 'token_tag' => $token['tag'],
            'token_key_version' => $token['version'], 'subscribed_at' => $now, 'callback_ready_at' => $now,
            'fields_ready_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    }
    $owner = ['user_id' => 1, 'business_id' => 1];
    $staff = ['user_id' => 2, 'business_id' => 1];
    $otherOwner = ['user_id' => 3, 'business_id' => 2];
    $config = ['owner_ai_business_id' => '1', 'owner_ai_enabled' => 'true',
        'gemini_api_key' => 'synthetic-unused-key', 'gemini_model' => 'synthetic-unused-model'];
    $store = new AiReplyStore($pdo, $seal, $config);
    $initial = ['connection_id' => 1, 'expected_revision' => 0, 'daily_limit' => 20, 'enabled' => true,
        'knowledge' => 'EXISTING_APPROVED_FACTS: We help customers during office hours.', 'approved' => true, 'allowlist' => ['601200000001']];
    $store->save($owner, $initial);
    (new AiReplyStore($pdo, $seal, array_replace($config, ['owner_ai_business_id' => '2'])))->save($otherOwner,
        array_replace($initial, ['connection_id' => 2, 'enabled' => false, 'knowledge' => 'Other tenant private facts.']));
    $pdo->exec("UPDATE ai_reply_settings SET quota_date=UTC_DATE(),quota_used=17,activation_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR WHERE business_id=1");
    $before = $pdo->query('SELECT * FROM ai_reply_settings WHERE business_id=1')->fetch();
    $unavailable = new KnowledgeCentre($pdo, $seal);
    knowledgeCheck($unavailable->runtime(1) === [] && !$unavailable->view($owner)['available'], 'Old worker remains text-only before additive schema');
    $ready = $schema->inspect();
    knowledgeCheck($ready['status'] === 'ready', 'Knowledge schema is ready after 0004');
    knowledgeDenied(fn () => $schema->apply(str_repeat('0', 64)), 409, 'Wrong expected checksum cannot install');
    $installed = $schema->apply($ready['checksum']);
    knowledgeCheck($installed['status'] === 'installed' && $schema->apply($ready['checksum']) === $installed, 'Additive schema apply is idempotent');
    $centre = new KnowledgeCentre($pdo, $seal);
    $view = $centre->view($owner);
    knowledgeCheck($view['draft']['facts'] === $initial['knowledge'] && $view['revision'] === 0
        && $centre->runtime(1) === [], 'Existing facts seed the first draft without publication');
    knowledgeCheck(!$centre->view($staff)['can_manage'] && $centre->view($staff)['draft']['facts'] === '', 'Staff cannot read draft facts or bank details');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j7j8AAAAASUVORK5CYII=', true);
    knowledgeCheck(is_string($png), 'Original synthetic PNG fixture');
    knowledgeDenied(fn () => $centre->uploadMedia($staff + ['role' => 'owner', 'owner_user_id' => 2], $png), 403, 'Forged role cannot upload');
    knowledgeDenied(fn () => $centre->uploadMedia($owner, '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 400, 'SVG is rejected');
    knowledgeDenied(fn () => $centre->uploadMedia($owner, str_repeat('x', KnowledgeCentre::MEDIA_MAX_BYTES + 1)), 400, 'Oversized upload is rejected');
    $image = $centre->uploadMedia($owner, $png);
    $otherImage = $centre->uploadMedia($otherOwner, $png);
    knowledgeCheck(preg_match(KnowledgeCentre::ID_PATTERN, $image['id']) === 1 && $image['mime'] === 'image/png'
        && $image['preview_url'] === '/whatsapp/akaun/knowledge-media.php?id=' . $image['id'], 'Opaque image with authenticated preview URL');
    knowledgeCheck($centre->view($owner)['draft']['facts'] === $initial['knowledge'], 'First upload preserves the seeded facts');
    knowledgeCheck($centre->previewMedia($staff, $image['id'])['bytes'] === $png, 'Tenant member sees the exact original bytes');
    knowledgeDenied(fn () => $centre->previewMedia($otherOwner, $image['id']), 404, 'Another tenant cannot preview by guessed ID');
    knowledgeDenied(fn () => $centre->mediaForWorker(1, $otherImage['id']), 404, 'Worker media lookup is tenant scoped');
    $mediaQuery = $pdo->prepare('SELECT * FROM knowledge_media WHERE id=?');
    $mediaQuery->execute([$image['id']]); $storedImage = $mediaQuery->fetch();
    $mediaQuery->execute([$otherImage['id']]); $storedOtherImage = $mediaQuery->fetch();
    knowledgeCheck(!str_contains($storedImage['body_ciphertext'], "\x89PNG") && $storedImage['body_ciphertext'] !== $png, 'Original image is encrypted at rest');
    knowledgeCopyMediaCipher($pdo, $image['id'], $storedOtherImage);
    knowledgeDenied(fn () => $centre->mediaForWorker(1, $image['id']), 500, 'Ciphertext moved across tenant or media ID is rejected');
    knowledgeCopyMediaCipher($pdo, $image['id'], $storedImage);

    $document = ['facts' => 'PUBLISHED_PRIVATE_FACTS: Our product is RM35.',
        'sources' => [['id' => str_repeat('a', 32), 'kind' => 'website', 'url' => 'https://www.example.com/catalog',
            'title' => 'Approved product page', 'text' => 'Delivery takes three working days.', 'enabled' => true]],
        'assets' => [['id' => str_repeat('b', 32), 'category' => 'product', 'title' => 'Product one', 'facts' => 'One box contains 10 units.',
            'caption' => 'Our original product photo.', 'when_to_send' => 'When a customer asks for the product photo.',
            'media_id' => $image['id'], 'enabled' => true]],
        'payment' => ['enabled' => true, 'bank_name' => 'SYNTHETIC_PRIVATE_BANK', 'account_name' => 'Owner fixture',
            'account_number' => '000123456789', 'instructions' => 'Use the order number as reference.', 'qr_media_id' => $image['id']]];
    $saved = $centre->saveDraft($owner, ['expected_revision' => 0, 'document' => $document]);
    knowledgeCheck($saved['revision'] === 1 && $saved['published_revision'] === 0 && $centre->runtime(1) === [], 'Saved draft is not provider knowledge');
    knowledgeCheck($store->view($owner)['knowledge'] === $initial['knowledge'], 'Saving draft preserves active AI facts');
    knowledgeDenied(fn () => $centre->saveDraft($owner, ['expected_revision' => 0, 'document' => $document]), 409, 'Stale draft cannot overwrite');
    knowledgeDenied(fn () => $centre->saveDraft($otherOwner, ['expected_revision' => 0, 'document' => $document]), 400, 'Another tenant cannot reference owner images, including QR');
    knowledgeDenied(fn () => $centre->publish($owner, ['expected_revision' => 1, 'approved' => false, 'payment_confirmed' => true]), 400, 'Publication needs explicit approval');
    knowledgeDenied(fn () => $centre->publish($owner, ['expected_revision' => 1, 'approved' => true, 'payment_confirmed' => false]), 400, 'Payment publication needs explicit confirmation');
    $published = $centre->publish($owner, ['expected_revision' => 1, 'approved' => true, 'payment_confirmed' => true]);
    $after = $pdo->query('SELECT * FROM ai_reply_settings WHERE business_id=1')->fetch();
    foreach (['connection_id', 'enabled', 'activation_at', 'allowlist_json', 'daily_limit', 'quota_date', 'quota_used'] as $field) {
        knowledgeCheck($after[$field] === $before[$field], 'Publication preserves ' . $field);
    }
    $active = $centre->runtime(1);
    knowledgeCheck($published['published_revision'] === 1 && $active['facts'] === $document['facts']
        && $active['payment']['account_number'] === '000123456789', 'Publication activates the reviewed snapshot and preserves leading zeroes');
    $compiled = $store->view($owner)['knowledge'];
    knowledgeCheck((int) $after['knowledge_revision'] === (int) $before['knowledge_revision'] + 1
        && hash_equals($active['_compiled_hash'], hash('sha256', $compiled)), 'Publication and job revision change atomically');
    knowledgeCheck(str_contains($compiled, $document['facts']) && str_contains($compiled, 'Delivery takes three working days.')
        && !str_contains($compiled, '000123456789') && !str_contains($compiled, 'SYNTHETIC_PRIVATE_BANK'), 'Compiled knowledge contains reviewed facts without raw bank coordinates');
    $encrypted = $pdo->query('SELECT draft_ciphertext,published_ciphertext FROM knowledge_centres WHERE business_id=1')->fetch();
    knowledgeCheck(!str_contains($encrypted['draft_ciphertext'], 'PUBLISHED_PRIVATE_FACTS')
        && !str_contains($encrypted['published_ciphertext'], '000123456789'), 'Draft and published documents are encrypted');
    $longDraft = array_replace($document, ['facts' => str_repeat('x', 7999)]);
    $centre->saveDraft($owner, ['expected_revision' => 1, 'document' => $longDraft]);
    knowledgeDenied(fn () => $centre->publish($owner, ['expected_revision' => 2, 'approved' => true, 'payment_confirmed' => true]), 400, 'Compiled facts exceeding 8000 bytes cannot publish');
    knowledgeCheck($centre->runtime(1)['facts'] === $document['facts'] && $store->view($owner)['revision'] === (int) $after['knowledge_revision'],
        'Failed publication leaves the old snapshot and job revision unchanged');
    $next = array_replace($document, ['facts' => 'NEW_DRAFT_ONLY: The price is RM40.']);
    $centre->saveDraft($owner, ['expected_revision' => 2, 'document' => $next]);
    knowledgeCheck($centre->runtime(1)['facts'] === $document['facts'], 'Editing the next draft cannot affect approved image replies');
    $centre->publish($owner, ['expected_revision' => 3, 'approved' => true, 'payment_confirmed' => true]);
    $live = $store->view($owner);
    knowledgeDenied(fn () => $store->save($owner, array_replace($initial, ['expected_revision' => $live['revision'], 'knowledge' => 'Bypass the knowledge centre.'])), 409,
        'Legacy AI controls cannot replace published knowledge');
    $controls = $store->save($owner, array_replace($initial, ['expected_revision' => $live['revision'], 'knowledge' => $live['knowledge'], 'daily_limit' => 25]));
    knowledgeCheck($controls['daily_limit'] === 25 && $centre->runtime(1)['facts'] === $next['facts'], 'Controls edit keeps the published catalog despite a newer settings revision');
    $store->stop($owner);
    $centre->publish($owner, ['expected_revision' => 3, 'approved' => true, 'payment_confirmed' => true]);
    $off = $pdo->query('SELECT enabled,activation_at,quota_used FROM ai_reply_settings WHERE business_id=1')->fetch();
    knowledgeCheck((int) $off['enabled'] === 0 && $off['activation_at'] === null && (int) $off['quota_used'] === 17,
        'Publishing never enables a stopped AI or resets quota');

    $rateDirectory = sys_get_temp_dir() . '/knowledge-rates-' . bin2hex(random_bytes(8));
    $http = new AuthHttp($pdo, new RateLimit($rateDirectory), null, $store, $centre);
    $session = ['user_id' => 1, 'business_id' => 1];
    $csrf = Csrf::issue($session);
    $headers = ['x-csrf-token' => $csrf, 'origin' => 'https://knowledge.example.invalid'];
    $response = $http->handle('GET', 'sesi', $session, [], [], '127.0.0.1', 'knowledge.example.invalid');
    knowledgeCheck($response['body']['knowledge_centre']['draft']['payment']['account_number'] === '000123456789'
        && !str_contains(json_encode($response['body'], JSON_THROW_ON_ERROR), 'body_ciphertext'), 'Owner payload contains editable fields without sealed storage fields');
    $staffSession = ['user_id' => 2, 'business_id' => 1];
    $staffCsrf = Csrf::issue($staffSession);
    $staffResponse = $http->handle('GET', 'sesi', $staffSession, [], [], '127.0.0.1', 'knowledge.example.invalid');
    knowledgeCheck(!array_key_exists('knowledge_centre', $staffResponse['body']), 'Non-owner AuthHttp payload excludes the centre');
    knowledgeDenied(function () use ($http, &$staffSession, $staffCsrf, $document): void {
        $http->handle('POST', 'knowsave', $staffSession, ['expected_revision' => 3, 'document' => $document, 'role_id' => 'owner',
            'expected_business_public_id' => str_pad('1', 26, '0', STR_PAD_LEFT)],
            ['x-csrf-token' => $staffCsrf], '127.0.0.1', 'knowledge.example.invalid');
    }, 403, 'HTTP draft save uses session owner authority');
    knowledgeDenied(function () use ($http, &$session, $document): void {
        $http->handle('POST', 'knowsave', $session, ['expected_revision' => 3, 'document' => $document], [], '127.0.0.1', 'knowledge.example.invalid');
    }, 403, 'HTTP draft save requires CSRF');
    knowledgeDenied(function () use ($http, &$session, $csrf): void {
        $http->handle('POST', 'knowpublish', $session, ['expected_revision' => 3, 'approved' => true, 'payment_confirmed' => true],
            ['x-csrf-token' => $csrf, 'origin' => 'https://other.example.invalid'], '127.0.0.1', 'knowledge.example.invalid');
    }, 403, 'HTTP publication rejects another origin');
    $sameOwnerOtherTenant = ['user_id' => 1, 'business_id' => 3];
    $otherDraft = ['facts' => 'OTHER_TAB_TENANT_FACTS', 'sources' => [], 'assets' => [], 'payment' => []];
    for ($revision = 0; $revision < 3; $revision++) {
        $centre->saveDraft($sameOwnerOtherTenant, ['expected_revision' => $revision, 'document' => $otherDraft]);
    }
    $changedTenantSession = ['user_id' => 1, 'business_id' => 3];
    $changedTenantCsrf = Csrf::issue($changedTenantSession);
    foreach (['knowsave', 'knowpublish', 'knowimport'] as $action) {
        knowledgeDenied(function () use ($http, &$changedTenantSession, $changedTenantCsrf, $action, $next): void {
            $http->handle('POST', $action, $changedTenantSession, ['expected_revision' => 3, 'document' => $next,
                'approved' => true, 'payment_confirmed' => true, 'url' => 'http://127.0.0.1/',
                'expected_business_public_id' => str_pad('1', 26, '0', STR_PAD_LEFT)],
                ['x-csrf-token' => $changedTenantCsrf], '127.0.0.1', 'knowledge.example.invalid');
        }, 409, 'Stale tab cannot ' . $action . ' after the same owner switches business');
    }
    knowledgeDenied(fn () => $centre->assertRequestScope($sameOwnerOtherTenant, str_pad('1', 26, '0', STR_PAD_LEFT)), 409,
        'Multipart upload shares the fresh tenant conflict guard');
    knowledgeDenied(function () use ($http, &$session, $headers, $next): void {
        $http->handle('POST', 'knowsave', $session, ['expected_revision' => 3, 'document' => $next],
            $headers, '127.0.0.1', 'knowledge.example.invalid');
    }, 409, 'Knowledge HTTP mutations require an explicit expected tenant scope');
    knowledgeCheck($centre->view($sameOwnerOtherTenant)['revision'] === 3
        && $centre->view($sameOwnerOtherTenant)['draft']['facts'] === 'OTHER_TAB_TENANT_FACTS',
        'Matching draft revisions never override the explicit tenant conflict guard');
    $response = $http->handle('POST', 'knowsave', $session, ['expected_revision' => 3, 'document' => $next, 'business_id' => 2,
        'expected_business_public_id' => str_pad('1', 26, '0', STR_PAD_LEFT)],
        $headers, '127.0.0.1', 'knowledge.example.invalid');
    knowledgeCheck($response['body']['knowledge_centre']['revision'] === 4 && isset($response['body']['ai'], $response['body']['conversations'], $response['body']['csrf'])
        && $centre->view($otherOwner)['revision'] === 0, 'Successful HTTP save returns full payload and ignores request tenant IDs');

    // New worker integration only: approved catalog -> one original image -> encrypted history.
    $settings = $store->view($owner);
    $store->save($owner, array_replace($initial, ['expected_revision' => $settings['revision'], 'knowledge' => $settings['knowledge'], 'daily_limit' => 25]));
    $workerNow = time() + 2;
    $makeEvent = static function (int $number, string $text, ?int $timestamp = null) use ($pdo, $seal, $workerNow): array {
        $wamid = 'wamid.knowledge.in.' . str_pad((string) $number, 12, '0', STR_PAD_LEFT);
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => '910000000000001', 'changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => '920000000000001'],
                'messages' => [['id' => $wamid, 'from' => '601200000001', 'timestamp' => (string) ($timestamp ?? $workerNow - 1),
                    'type' => 'text', 'text' => ['body' => $text]]],
            ]]],
        ]]], JSON_THROW_ON_ERROR);
        knowledgeCheck(WhatsAppWebhook::signatureMatches($raw, 'sha256=' . hash_hmac('sha256', $raw, 'synthetic-knowledge-webhook-secret'),
            'synthetic-knowledge-webhook-secret'), 'Synthetic webhook authentication precedes ingestion');
        (new WhatsAppWebhook($pdo, $seal))->ingest($raw);
        $query = $pdo->query('SELECT id FROM webhook_events WHERE business_id=1 ORDER BY id DESC LIMIT 1');
        return ['id' => (int) $query->fetchColumn(), 'wamid' => $wamid, 'raw' => $raw];
    };
    $calls = ['generate' => 0, 'send' => 0];
    $firstEvent = $makeEvent(1, 'Boleh lihat gambar produk?');
    $imageWorker = new AiReplyWorker($pdo, $seal,
        function (string $knowledge, string $inbound, array $history, array $catalog) use ($centre, $owner, $next, $document, &$calls): array {
            $calls['generate']++;
            $json = json_encode($catalog, JSON_THROW_ON_ERROR);
            knowledgeCheck(!str_contains($json, '000123456789') && !str_contains($json, 'SYNTHETIC_PRIVATE_BANK')
                && !str_contains($json, 'bytes') && !str_contains($json, 'media_id'), 'Provider catalog excludes bank coordinates and image storage identifiers');
            knowledgeCheck($catalog['assets'][0]['id'] === $document['assets'][0]['id'] && $history === [], 'Provider gets the approved asset choice only');
            $unpublished = $next;
            $unpublished['assets'][0]['caption'] = 'UNPUBLISHED_CAPTION_EDIT';
            $centre->saveDraft($owner, ['expected_revision' => 4, 'document' => $unpublished]);
            return ['reply' => 'Ini gambar produk.', 'handoff' => false, 'asset_id' => $document['assets'][0]['id'], 'payment' => 'none'];
        },
        function (string $token, string $phone, string $recipient, string $text, ?string $replyTo, ?array $media) use ($pdo, $png, $firstEvent, $document, &$calls): array {
            $calls['send']++;
            $job = $pdo->query('SELECT * FROM ai_reply_jobs WHERE event_id=' . $firstEvent['id'])->fetch();
            knowledgeCheck($pdo->inTransaction() && $job['status'] === 'sending' && (int) $job['send_attempts'] === 1,
                'Image send begins only after a durable single send claim with locks held');
            knowledgeCheck($media === ['bytes' => $png, 'mime' => 'image/png'] && $text === $document['assets'][0]['caption']
                && $replyTo === $firstEvent['wamid'] && $recipient === '601200000001' && $phone === '920000000000001'
                && $token === 'synthetic-knowledge-fixture-token', 'Transport receives the exact approved original and caption, ignoring concurrent draft edits');
            return ['sent' => true, 'message_id' => 'wamid.knowledge.out.000001', 'ambiguous' => false, 'error' => null];
        }, static fn (): int => $workerNow, $centre);
    $result = $imageWorker->run(1);
    knowledgeCheck($result['sent'] === 1 && $calls === ['generate' => 1, 'send' => 1], 'One inbound produces one image message');
    (new WhatsAppWebhook($pdo, $seal))->ingest($firstEvent['raw']);
    $imageWorker->run(1);
    knowledgeCheck($calls === ['generate' => 1, 'send' => 1], 'Replayed image inbound is never regenerated or resent');
    $archive = $pdo->query("SELECT * FROM ai_messages WHERE source_event_id=" . $firstEvent['id'] . " AND direction='out'")->fetch();
    $body = json_decode($seal->open($archive['body_ciphertext'], $archive['body_nonce'], $archive['body_tag'], (int) $archive['body_key_version']), true, 16, JSON_THROW_ON_ERROR);
    knowledgeCheck($body['type'] === 'image' && $body['media_id'] === $image['id'] && $body['asset_id'] === $document['assets'][0]['id']
        && !str_contains($archive['body_ciphertext'], $image['id']), 'Encrypted outgoing archive stores a tenant image reference');
    $inbox = $store->inbox($owner, ['conversations' => [], 'contacts' => []]);
    $imageMessages = array_values(array_filter($inbox['conversations'][0]['messages'], static fn (array $message): bool => ($message['type'] ?? '') === 'image'));
    knowledgeCheck(count($imageMessages) === 1 && $imageMessages[0]['media_url'] === $image['preview_url'], 'Inbox uses the authenticated image preview');
    $purged = $pdo->query('SELECT payload_ciphertext,payload_purged_at FROM webhook_events WHERE id=' . $firstEvent['id'])->fetch();
    knowledgeCheck($purged['payload_ciphertext'] === null && $purged['payload_purged_at'] !== null, 'Image history survives queue payload purge');

    $secondEvent = $makeEvent(2, 'Hantar gambar produk lagi.');
    $staleWorker = new AiReplyWorker($pdo, $seal,
        function (string $knowledge, string $inbound, array $history, array $catalog) use ($centre, $owner, $document, &$calls): array {
            $calls['generate']++;
            $centre->publish($owner, ['expected_revision' => 5, 'approved' => true, 'payment_confirmed' => true]);
            return ['reply' => 'Gambar produk.', 'handoff' => false, 'asset_id' => $document['assets'][0]['id'], 'payment' => 'none'];
        }, function () use (&$calls): array {
            $calls['send']++;
            return ['sent' => true, 'message_id' => 'wamid.unexpected.stale', 'ambiguous' => false, 'error' => null];
        }, static fn (): int => $workerNow, $centre);
    $result = $staleWorker->run(1);
    $staleJob = $pdo->query('SELECT status,send_attempts FROM ai_reply_jobs WHERE event_id=' . $secondEvent['id'])->fetch();
    knowledgeCheck($result['handoff'] === 1 && $calls === ['generate' => 2, 'send' => 1] && $staleJob['status'] === 'handoff'
        && (int) $staleJob['send_attempts'] === 0, 'Publishing while generation runs prevents the stale image send');
    $conversationId = (int) $pdo->query('SELECT id FROM ai_conversations WHERE business_id=1 LIMIT 1')->fetchColumn();
    $store->setMode($owner, 'ai-' . $conversationId, 'ai');
    $thirdEvent = $makeEvent(3, 'Boleh saya dapatkan QR untuk bayar?');
    $paymentWorker = new AiReplyWorker($pdo, $seal,
        function (string $knowledge, string $inbound, array $history, array $catalog) use (&$calls): array {
            $calls['generate']++;
            knowledgeCheck(in_array('qr', $catalog['payment_options'], true), 'Published QR permits a payment choice');
            return ['reply' => 'Invented bank number 9999999999', 'handoff' => false, 'asset_id' => 'none', 'payment' => 'qr'];
        }, function (string $token, string $phone, string $recipient, string $text, ?string $replyTo, ?array $media) use ($png, &$calls): array {
            $calls['send']++;
            knowledgeCheck($media === ['bytes' => $png, 'mime' => 'image/png'] && str_contains($text, '000123456789')
                && !str_contains($text, '9999999999'), 'Payment reply reproduces the approved account and QR, discarding model-authored bank coordinates');
            return ['sent' => true, 'message_id' => 'wamid.knowledge.out.000003', 'ambiguous' => false, 'error' => null];
        }, static fn (): int => $workerNow, $centre);
    $result = $paymentWorker->run(1);
    knowledgeCheck($result['sent'] === 1 && $calls === ['generate' => 3, 'send' => 2], 'QR and bank caption use one outbound message');

    $makeEvent(4, 'Terima kasih, saya akan membuat bayaran.');
    $historyWorker = new AiReplyWorker($pdo, $seal,
        function (string $knowledge, string $inbound, array $history, array $catalog) use (&$calls): array {
            $calls['generate']++;
            $historyText = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            knowledgeCheck(str_contains($historyText, 'Maklumat pembayaran yang diluluskan telah dihantar.')
                && !str_contains($historyText, '000123456789') && !str_contains($historyText, 'SYNTHETIC_PRIVATE_BANK'),
                'The next generation receives a neutral payment history marker without bank coordinates');
            return ['reply' => '', 'handoff' => true, 'asset_id' => 'none', 'payment' => 'none'];
        }, function () use (&$calls): array { $calls['send']++; return ['sent' => false, 'ambiguous' => false]; },
        static fn (): int => $workerNow, $centre);
    $result = $historyWorker->run(1);
    knowledgeCheck($result['handoff'] === 1 && $calls === ['generate' => 4, 'send' => 2], 'Privacy history check makes no additional send');
    $store->setMode($owner, 'ai-' . $conversationId, 'ai');

    $replaceKnowledge = static function (array $sealed, string $hash) use ($pdo): void {
        $statement = $pdo->prepare('UPDATE ai_reply_settings SET knowledge_ciphertext=?,knowledge_nonce=?,knowledge_tag=?,knowledge_key_version=?,knowledge_hash=? WHERE business_id=1');
        foreach (['ciphertext', 'nonce', 'tag'] as $index => $key) { $statement->bindValue($index + 1, $sealed[$key], PDO::PARAM_LOB); }
        $statement->bindValue(4, $sealed['version'], PDO::PARAM_INT);
        $statement->bindValue(5, $hash);
        $statement->execute();
    };
    $validSettings = $pdo->query('SELECT * FROM ai_reply_settings WHERE business_id=1')->fetch();
    $differentText = 'UNPUBLISHED_SETTINGS_DRIFT: This content was never published in the centre.';
    $replaceKnowledge($seal->seal($differentText, 8000), hash('sha256', $differentText));
    $mismatchEvent = $makeEvent(5, 'Saya hendak lihat gambar yang diluluskan.');
    $blockedWorker = new AiReplyWorker($pdo, $seal,
        function () use ($document, &$calls): array {
            $calls['generate']++;
            return ['reply' => 'Unexpected generation.', 'handoff' => false, 'asset_id' => $document['assets'][0]['id'], 'payment' => 'none'];
        }, function () use (&$calls): array { $calls['send']++; return ['sent' => false, 'ambiguous' => false]; },
        static fn (): int => $workerNow, $centre);
    $result = $blockedWorker->run(1);
    $mismatchJob = $pdo->query('SELECT status,generation_attempts,send_attempts,last_error FROM ai_reply_jobs WHERE event_id=' . $mismatchEvent['id'])->fetch();
    knowledgeCheck($result['handoff'] === 1 && $calls === ['generate' => 4, 'send' => 2]
        && (int) $mismatchJob['generation_attempts'] === 0 && (int) $mismatchJob['send_attempts'] === 0
        && $mismatchJob['last_error'] === 'knowledge_or_history_unreadable',
        'Validly sealed settings with a different published hash hand off before either provider call');
    $replaceKnowledge(['ciphertext' => $validSettings['knowledge_ciphertext'], 'nonce' => $validSettings['knowledge_nonce'],
        'tag' => $validSettings['knowledge_tag'], 'version' => (int) $validSettings['knowledge_key_version']], $validSettings['knowledge_hash']);
    $store->setMode($owner, 'ai-' . $conversationId, 'ai');
    $activation = $pdo->prepare('UPDATE ai_reply_settings SET activation_at=? WHERE business_id=1');
    $activation->execute([gmdate('Y-m-d H:i:s', $workerNow - 86500)]);
    $windowEvent = $makeEvent(6, 'Hantar gambar produk.', $workerNow - 86400 + 45);
    $result = $blockedWorker->run(1);
    $windowJob = $pdo->query('SELECT generation_attempts,send_attempts,last_error FROM ai_reply_jobs WHERE event_id=' . $windowEvent['id'])->fetch();
    knowledgeCheck($result['skipped'] === 1 && $calls === ['generate' => 4, 'send' => 2]
        && (int) $windowJob['generation_attempts'] === 0 && (int) $windowJob['send_attempts'] === 0 && $windowJob['last_error'] === 'expired_window',
        'A 45-second remaining message window cannot start the bounded image path');
    $expiry = $pdo->prepare('UPDATE whatsapp_connections SET token_expires_at=? WHERE id=1');
    $expiry->execute([gmdate('Y-m-d H:i:s', $workerNow + 45)]);
    $tokenEvent = $makeEvent(7, 'Hantar gambar produk.');
    $result = $blockedWorker->run(1);
    $tokenJob = $pdo->query('SELECT generation_attempts,send_attempts,last_error FROM ai_reply_jobs WHERE event_id=' . $tokenEvent['id'])->fetch();
    knowledgeCheck($result['skipped'] === 1 && $calls === ['generate' => 4, 'send' => 2]
        && (int) $tokenJob['generation_attempts'] === 0 && (int) $tokenJob['send_attempts'] === 0 && $tokenJob['last_error'] === 'inactive_connection',
        'A token expiring in 45 seconds cannot start the bounded image path');
    $pdo->exec('UPDATE whatsapp_connections SET token_expires_at=NULL WHERE id=1');
    $activation->execute([$validSettings['activation_at']]);

    // A tenant's first publication can initialize controls, but only disabled and tenant bound.
    $pdo->exec('DELETE FROM ai_reply_settings WHERE business_id=2');
    $firstDocument = $document;
    $firstDocument['assets'][0]['media_id'] = $otherImage['id'];
    $firstDocument['payment']['qr_media_id'] = $otherImage['id'];
    $centre->saveDraft($otherOwner, ['expected_revision' => 0, 'document' => $firstDocument]);
    $firstPublish = ['expected_revision' => 1, 'approved' => true, 'payment_confirmed' => true];
    knowledgeDenied(fn () => $centre->publish($otherOwner, $firstPublish), 400, 'First publication requires an explicit connection');
    knowledgeDenied(fn () => $centre->publish($otherOwner, $firstPublish + ['connection_id' => 1]), 404, 'First publication cannot initialize a foreign connection');
    knowledgeCheck((int) $pdo->query('SELECT COUNT(*) FROM ai_reply_settings WHERE business_id=2')->fetchColumn() === 0,
        'Rejected first publication creates no settings');
    $centre->publish($otherOwner, $firstPublish + ['connection_id' => 2]);
    $firstSettings = $pdo->query('SELECT * FROM ai_reply_settings WHERE business_id=2')->fetch();
    knowledgeCheck((int) $firstSettings['connection_id'] === 2 && (int) $firstSettings['enabled'] === 0
        && $firstSettings['allowlist_json'] === '[]' && (int) $firstSettings['quota_used'] === 0
        && $firstSettings['activation_at'] === null && (int) $firstSettings['daily_limit'] === 20
        && (int) $firstSettings['knowledge_revision'] === 1 && $centre->runtime(2)['facts'] === $firstDocument['facts'],
        'First publication atomically initializes disabled controls with no recipient authorization');

    foreach (range(2, 40) as $unused) { $centre->uploadMedia($owner, $png); }
    knowledgeDenied(fn () => $centre->uploadMedia($owner, $png), 409, 'Tenant image count cannot exceed 40');
    knowledgeCheck(count($centre->view($owner)['media']) === 40 && count($centre->view($otherOwner)['media']) === 1,
        'Upload quota is serialized and scoped to one tenant');
    $audit = $pdo->query("SELECT meta_json FROM audit_logs WHERE action LIKE 'pengetahuan_%'")->fetchAll(PDO::FETCH_COLUMN);
    knowledgeCheck(!str_contains(implode('\n', $audit), '000123456789') && !str_contains(implode('\n', $audit), 'PUBLISHED_PRIVATE_FACTS'),
        'Audit logs contain revisions and IDs without source or banking content');
    $pdo->exec("UPDATE schema_migrations SET checksum='" . str_repeat('0', 64) . "' WHERE version='0005'");
    knowledgeDenied(fn () => (new KnowledgeCentre($pdo, $seal))->runtime(1), 503, 'Checksum drift blocks catalog use');
    fwrite(STDOUT, 'Knowledge centre synthetic checks passed: ' . $knowledgeChecks . "\n");
} finally {
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS ' . KNOWLEDGE_TEST_DATABASE);
    if (is_string($rateDirectory) && is_dir($rateDirectory)) {
        foreach (glob($rateDirectory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($rateDirectory);
    }
}
