<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-fixture.php';

use BesharOs\AiProductionSchema;
use BesharOs\AiReplyPolicy;
use BesharOs\AiReplyStore;
use BesharOs\AuthHttp;
use BesharOs\Csrf;
use BesharOs\KnowledgeCentre;
use BesharOs\Membership;
use BesharOs\PlatformAdminStore;
use BesharOs\RateLimit;

// Reuses the explicitly guarded, loopback-only synthetic fixture. Run sequentially
// with the other admin-fixture checks. No private configuration or provider is read.
function productionSettingsConnection(array $fixture, array $tenant, string $suffix): int
{
    $token = $fixture['seal']->seal('synthetic-production-settings-token-' . $suffix, 2048);
    adminSql($fixture['pdo'], "INSERT INTO whatsapp_connections
        (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,registration_state,
         messaging_state,token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,subscribed_at,created_at,updated_at)
        VALUES (?,?,?,?,'verified','subscribed','registered','unverified','system_user',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
        [$tenant['business_id'], '7100000000000' . $suffix, '8100000000000' . $suffix, '+60110000000' . $suffix,
            $token['ciphertext'], $token['nonce'], $token['tag'], $token['version']]);
    return (int) $fixture['pdo']->lastInsertId();
}

function productionSettingsHttp(AuthHttp $http, string $method, array $session, array $body, array $headers): array
{
    return $http->handle($method, 'aiconfig', $session, $body, $headers, '127.0.0.1', 'production-settings.invalid');
}

$fixture = adminFixture();
$pdo = $fixture['pdo'];
$seal = $fixture['seal'];
$a = $fixture['tenantA'];
$b = $fixture['tenantB'];
$users = $fixture['users'];
$admin = $fixture['admin'];
$connectionA = productionSettingsConnection($fixture, $a, '1');
$connectionB = productionSettingsConnection($fixture, $b, '2');
$pilot = ['owner_ai_business_id' => (string) $a['business_id'], 'owner_ai_enabled' => 'true',
    'gemini_api_key' => 'synthetic-production-settings-key', 'gemini_model' => 'synthetic-fixture-model'];
$rollout = $pilot + ['ai_customer_rollout' => 'all'];
$initial = ['connection_id' => $connectionA, 'expected_revision' => 0,
    'knowledge' => 'Synthetic approved business facts A.', 'approved' => true,
    'allowlist' => ['601200000001'], 'daily_limit' => 20, 'enabled' => true];

adminTest('Production rollout requires the exact switch and installed schema while preserving the existing pilot', function () use ($pdo, $seal, $a, $b, $pilot, $rollout, $initial, $connectionB): void {
    $store = new AiReplyStore($pdo, $seal, $pilot);
    $saved = $store->save($a['member'], $initial);
    adminAssert($saved['enabled'] && $saved['audience_mode'] === 'test' && $saved['controls_configured'], 'Existing pilot stays enabled in test scope');
    $outside = $store->view($b['member']);
    adminAssert(!$outside['available'] && !$outside['can_manage'] && $outside['connections'][0]['id'] == $connectionB,
        'Nonpilot owner gets their own connection for independent first publication');
    adminAssert(count($outside['connections']) === 1 && $outside['revision'] === 0 && $outside['connection_id'] === 0,
        'Publication view exposes only the current tenant connection and revision');
    $early = new AiReplyStore($pdo, $seal, $rollout);
    adminAssert(!$early->view($b['member'])['available'] && !$early->view($a['member'])['production_available'], 'Flag alone cannot bypass missing production schema');
    adminDenied(fn () => $early->save($b['member'], array_replace($initial, ['connection_id' => $connectionB, 'audience_mode' => 'all'])), 409, 'Missing schema blocks production settings');
    adminAssert(!AiReplyPolicy::allBusinesses(['ai_customer_rollout' => true])
        && !AiReplyPolicy::allBusinesses(['ai_customer_rollout' => 'true']), 'Rollout requires exact all value');
    adminAssert(AiReplyPolicy::serverReady($pilot)
        && !AiReplyPolicy::serverReady($pilot + ['ai_enabled' => 'false'])
        && AiReplyPolicy::serverReady(array_replace($pilot, ['owner_ai_enabled' => 'false', 'ai_enabled' => 'true'])),
        'Explicit master switch overrides the legacy key without truthy coercion');
    adminDenied(fn () => $store->view(['user_id' => $a['owner_id'], 'business_id' => $b['business_id']]), 401, 'Connection publication metadata is membership scoped');
});

$before = adminSql($pdo, 'SELECT * FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetch();
$sql = (string) file_get_contents(__DIR__ . '/../migrations/0007_customer_ai.up.sql');
$schema = new AiProductionSchema($pdo, $sql);
$schema->apply(hash('sha256', $sql));
$after = adminSql($pdo, 'SELECT * FROM ai_reply_settings WHERE business_id=?', [$a['business_id']])->fetch();
adminAssert(array_intersect_key($after, $before) === $before && $after['audience_mode'] === 'test'
    && $after['audience_approved_by'] === null && $after['audience_approved_at'] === null,
    'Additive migration preserves every prior setting and grants no all-customer approval');
$store = new AiReplyStore($pdo, $seal, $rollout);
$platform = new PlatformAdminStore($pdo, $rollout, true);
$centre = new KnowledgeCentre($pdo, $seal);

adminTest('All businesses can configure AI but a knowledge publication does not approve live replies', function () use ($pdo, $store, $platform, $centre, $a, $b, $admin, $connectionB): void {
    $empty = $store->view($b['member']);
    adminAssert($empty['available'] && $empty['can_manage'] && $empty['production_available']
        && $empty['audience_mode'] === 'all' && !$empty['enabled'] && !$empty['controls_configured'], 'New tenant has live-audience option without automatic enablement');
    $document = $centre->view($b['member'])['draft'];
    $document['facts'] = 'Synthetic approved business facts B.';
    $centre->saveDraft($b['member'], ['expected_revision' => 0, 'document' => $document]);
    $centre->publish($b['member'], ['expected_revision' => 1, 'connection_id' => $connectionB, 'approved' => true]);
    $published = $store->view($b['member']);
    adminAssert($published['revision'] === 1 && $published['connection_id'] === $connectionB
        && !$published['enabled'] && !$published['controls_configured'] && !$published['audience_approved'], 'Publication stores facts and connection without audience approval');
    adminAssert(!$platform->details($admin, $b['public_id'])['ai']['can_enable'], 'Platform owner cannot enable a publication-only row');
    adminDenied(fn () => $platform->setAi($admin, $b['public_id'], true, $published['revision']), 409, 'Admin cannot manufacture recipient scope');
    $legacy = $store->view($a['member']);
    adminAssert($legacy['audience_mode'] === 'test' && $legacy['controls_configured'] && $legacy['enabled'], 'Existing selected test scope is not silently broadened');
});

$inputB = static function (array $patch = []) use ($store, $b, $connectionB): array {
    $view = $store->view($b['member']);
    return array_replace(['connection_id' => $connectionB, 'expected_revision' => $view['revision'],
        'knowledge' => $view['knowledge'], 'approved' => true, 'allowlist' => [], 'audience_mode' => 'all',
        'daily_limit' => 30, 'enabled' => true], $patch);
};

adminTest('Only current owner approval can open all-customer replies and input identities cannot spoof it', function () use ($pdo, $store, $a, $b, $users, $inputB, $connectionA): void {
    foreach ([['approved' => false], ['audience_mode' => '*'], ['audience_mode' => true], ['audience_mode' => null],
        ['audience_mode' => 'test'], ['allowlist' => ['*']], ['daily_limit' => 0], ['daily_limit' => 1001]] as $patch) {
        adminDenied(fn () => $store->save($b['member'], $inputB($patch)), 400, 'Invalid scope, consent, recipients or quota rejected');
    }
    adminDenied(fn () => $store->save($b['member'], $inputB(['connection_id' => $connectionA])), 404, 'Production owner cannot bind another tenant number');
    $spoofedStaff = ['user_id' => $users['staff'], 'business_id' => $a['business_id'], 'role' => 'owner', 'owner_user_id' => $users['staff'], 'status' => 'active'];
    adminDenied(fn () => $store->save($spoofedStaff, $inputB()), 403, 'Forged current owner fields do not promote a staff member');
    $saved = $store->save($b['member'], $inputB(['audience_approved_by' => $a['owner_id'], 'audience_approved_at' => '1999-01-01 00:00:00']));
    $row = adminSql($pdo, 'SELECT * FROM ai_reply_settings WHERE business_id=?', [$b['business_id']])->fetch();
    adminAssert($saved['enabled'] && $saved['audience_mode'] === 'all' && $saved['audience_approved']
        && $saved['controls_configured'] && $saved['allowlist'] === [], 'Current owner can approve all-customer replies without a test number');
    adminAssert((int) $row['audience_approved_by'] === $b['owner_id'] && $row['audience_approved_at'] !== '1999-01-01 00:00:00', 'Audience approval uses stored membership and server time');
    adminAssert(AiReplyPolicy::recipientAllowed($row, '601299999999', $b['owner_id'], true)
        && !AiReplyPolicy::recipientAllowed($row, '601299999999', $b['owner_id'], false), 'Live scope accepts new customers only with rollout available');
    adminAssert(!AiReplyPolicy::recipientAllowed($row, '601299999999', $a['owner_id'], true)
        && !AiReplyPolicy::recipientAllowed(array_replace($row, ['audience_mode' => 'unknown']), '601299999999', $b['owner_id'], true),
        'Former/foreign owner and unknown audience are denied');
});

adminTest('Production saves preserve per-tenant usage and old clients cannot change recipient scope', function () use ($pdo, $store, $b, $inputB): void {
    adminSql($pdo, 'UPDATE ai_reply_settings SET quota_date=UTC_DATE(),quota_used=17 WHERE business_id=?', [$b['business_id']]);
    $before = adminSql($pdo, 'SELECT audience_approved_by,audience_approved_at FROM ai_reply_settings WHERE business_id=?', [$b['business_id']])->fetch();
    $legacyInput = $inputB(['daily_limit' => 25]);
    unset($legacyInput['audience_mode']);
    $saved = $store->save($b['member'], $legacyInput);
    $after = adminSql($pdo, 'SELECT audience_approved_by,audience_approved_at FROM ai_reply_settings WHERE business_id=?', [$b['business_id']])->fetch();
    adminAssert($saved['audience_mode'] === 'all' && $saved['quota_used'] === 17 && $before === $after,
        'Old client keeps existing scope, approval and quota');
    $revision = $saved['revision'];
    adminDenied(fn () => $store->save($b['member'], $inputB(['expected_revision' => $revision - 1])), 409, 'Stale edit cannot change scope');
    $saved = $store->save($b['member'], $inputB(['audience_mode' => 'test', 'allowlist' => ['601211111111']]));
    adminAssert($saved['audience_mode'] === 'test' && !$saved['audience_approved'] && $saved['quota_used'] === 17, 'Owner can narrow audience while retaining quota');
    $row = adminSql($pdo, 'SELECT * FROM ai_reply_settings WHERE business_id=?', [$b['business_id']])->fetch();
    adminAssert($row['audience_approved_by'] === null && $row['audience_approved_at'] === null
        && !AiReplyPolicy::recipientAllowed($row, '601299999999', $b['owner_id'], true), 'Test scope clears broader approval and excludes unlisted sender');
    $store->save($b['member'], $inputB());
});

$rateDirectory = sys_get_temp_dir() . '/beshare-production-settings-' . bin2hex(random_bytes(8));
$http = new AuthHttp($pdo, new RateLimit($rateDirectory), null, $store, $centre);
$session = ['user_id' => $b['owner_id'], 'business_id' => $b['business_id']];
$csrf = Csrf::issue($session);
$headers = ['origin' => 'https://production-settings.invalid', 'x-csrf-token' => $csrf];

adminTest('HTTP production controls enforce POST, session, CSRF, origin and tenant ownership', function () use ($pdo, $http, $session, $headers, $csrf, $inputB, $a, $b, $users, $connectionA): void {
    adminDenied(fn () => productionSettingsHttp($http, 'GET', $session, $inputB(), $headers), 405, 'Scope mutation requires POST');
    adminDenied(fn () => productionSettingsHttp($http, 'POST', $session, $inputB(), ['origin' => $headers['origin']]), 403, 'Scope mutation requires CSRF');
    adminDenied(fn () => productionSettingsHttp($http, 'POST', $session, $inputB(), array_replace($headers, ['origin' => 'https://other.invalid'])), 403, 'Cross-origin live enablement denied');
    adminDenied(fn () => productionSettingsHttp($http, 'POST', ['csrf' => $csrf], $inputB() + ['user_id' => $b['owner_id'], 'business_id' => $b['business_id']], $headers), 401, 'Body cannot supply authentication');
    $staffSession = ['user_id' => $users['staff'], 'business_id' => $a['business_id'], 'csrf' => $csrf];
    adminDenied(fn () => productionSettingsHttp($http, 'POST', $staffSession, $inputB() + ['user_id' => $b['owner_id'], 'business_id' => $b['business_id'], 'role' => 'owner'], $headers), 403, 'Body cannot promote staff or select another tenant');
    adminDenied(fn () => productionSettingsHttp($http, 'POST', $session, $inputB(['connection_id' => $connectionA]), $headers), 404, 'HTTP scope cannot target another tenant connection');
    $response = productionSettingsHttp($http, 'POST', $session, $inputB() + ['business_id' => $a['business_id'], 'user_id' => $a['owner_id'], 'audience_approved_by' => $a['owner_id']], $headers);
    adminAssert($response['body']['ok'] && $response['body']['business']['public_id'] === $b['public_id']
        && $response['body']['ai']['audience_mode'] === 'all', 'Authorized production save remains in session business');
    $json = json_encode($response, JSON_THROW_ON_ERROR);
    foreach (['synthetic-production-settings-key', 'token_ciphertext', 'knowledge_ciphertext', 'allowlist_json'] as $private) {
        adminAssert(!str_contains($json, $private), 'Public response excludes ' . $private);
    }
    adminAssert((int) adminSql($pdo, 'SELECT audience_approved_by FROM ai_reply_settings WHERE business_id=?', [$b['business_id']])->fetchColumn() === $b['owner_id'], 'HTTP approval actor comes from session');
});

adminTest('Platform enablement and inbox status follow the saved audience without changing it', function () use ($pdo, $store, $platform, $admin, $a, $b, $connectionB): void {
    $stopped = $store->stop($b['member']);
    $detail = $platform->details($admin, $b['public_id'])['ai'];
    adminAssert($detail['available'] && $detail['production_available'] && $detail['audience_mode'] === 'all'
        && $detail['audience_approved'] && $detail['can_enable'], 'Platform sees eligible customer-owned production scope');
    $platform->setAi($admin, $b['public_id'], true, $stopped['revision']);
    adminSql($pdo, "INSERT INTO ai_conversations (business_id,connection_id,recipient,mode,last_inbound_at,created_at,updated_at)
        VALUES (?,?,'601299999999','ai',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$b['business_id'], $connectionB]);
    $conversationId = (int) $pdo->lastInsertId();
    $inbox = $store->inbox($b['member'], []);
    adminAssert($inbox['conversations'][0]['mode'] === 'ai_active' && $inbox['conversations'][0]['tag'] === 'Pelanggan', 'Unlisted customer is active under approved all audience');
    adminDenied(fn () => $store->setMode($a['member'], 'ai-' . $conversationId, 'human'), 404, 'Conversation controls remain tenant scoped');
    $store->setMode($b['member'], 'ai-' . $conversationId, 'human');
    adminAssert($store->inbox($b['member'], [])['conversations'][0]['mode'] === 'human_takeover', 'Human takeover still wins over live audience');
    $store->setMode($b['member'], 'ai-' . $conversationId, 'ai');
});

adminTest('Ownership transfer and knowledge republication cannot inherit all-customer consent', function () use ($pdo, $seal, $rollout, $store, $platform, $centre, $admin, $b, $users, $connectionB): void {
    $newOwnerId = $users['staff'];
    adminSql($pdo, "INSERT INTO business_users (business_id,user_id,role,created_at,updated_at) VALUES (?,?,'staff',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$b['business_id'], $newOwnerId]);
    $pdo->beginTransaction();
    adminSql($pdo, "UPDATE businesses SET status='provisioning' WHERE id=?", [$b['business_id']]);
    adminSql($pdo, "UPDATE business_users SET role='admin' WHERE business_id=? AND user_id=?", [$b['business_id'], $b['owner_id']]);
    adminSql($pdo, "UPDATE business_users SET role='owner' WHERE business_id=? AND user_id=?", [$b['business_id'], $newOwnerId]);
    adminSql($pdo, 'UPDATE businesses SET owner_user_id=? WHERE id=?', [$newOwnerId, $b['business_id']]);
    adminSql($pdo, "UPDATE businesses SET status='active' WHERE id=?", [$b['business_id']]);
    $pdo->commit();
    $newOwner = Membership::require($pdo, $newOwnerId, $b['business_id']);
    $current = new AiReplyStore($pdo, $seal, $rollout);
    adminAssert(!$current->view($newOwner)['audience_approved'] && !$current->view($newOwner)['controls_configured'], 'New owner does not inherit former-owner audience approval');
    adminAssert($current->inbox($newOwner, [])['conversations'][0]['mode'] === 'ai_paused', 'Old consent cannot show active inbox after owner transfer');
    $document = $centre->view($newOwner)['draft'];
    $centre->saveDraft($newOwner, ['expected_revision' => 1, 'document' => $document]);
    $centre->publish($newOwner, ['expected_revision' => 2, 'approved' => true]);
    $view = $current->view($newOwner);
    adminAssert(!$platform->details($admin, $b['public_id'])['ai']['can_enable'], 'Republishing knowledge is not new audience approval');
    adminDenied(fn () => $platform->setAi($admin, $b['public_id'], true, $view['revision']), 409, 'Platform cannot reuse former-owner audience consent');
    $payload = ['connection_id' => $connectionB, 'expected_revision' => $view['revision'], 'knowledge' => $view['knowledge'],
        'approved' => true, 'allowlist' => [], 'daily_limit' => 25, 'enabled' => true];
    adminDenied(fn () => $current->save($newOwner, $payload), 409, 'Old client omission cannot grant new all-customer consent');
    $saved = $current->save($newOwner, $payload + ['audience_mode' => 'all']);
    adminAssert($saved['audience_approved'] && $saved['enabled'] && $saved['quota_used'] === 17, 'Explicit new-owner approval restores live scope without quota reset');
    adminDenied(fn () => $store->save($b['member'], $payload + ['audience_mode' => 'all']), 403, 'Former owner cannot save using a stale actor');
});

adminTest('Global shutdown and a bad rollout ledger fail closed after production enablement', function () use ($pdo, $seal, $rollout, $pilot, $a, $b, $users, $sql): void {
    $newOwner = Membership::require($pdo, $users['staff'], $b['business_id']);
    $off = new AiReplyStore($pdo, $seal, $rollout + ['ai_enabled' => 'false']);
    $view = $off->view($newOwner);
    adminAssert($view['available'] && !$view['deployment_ready'], 'Master off keeps configuration available but blocks runtime readiness');
    adminDenied(fn () => $off->save($newOwner, ['connection_id' => $view['connection_id'], 'expected_revision' => $view['revision'],
        'knowledge' => $view['knowledge'], 'approved' => true, 'allowlist' => [], 'audience_mode' => 'all', 'daily_limit' => 25, 'enabled' => true]), 409, 'Master shutdown blocks activation');
    adminAssert(!(new AiReplyStore($pdo, $seal, $pilot))->view($newOwner)['available'], 'Removing rollout flag restores original per-business eligibility');
    adminSql($pdo, "UPDATE schema_migrations SET checksum=? WHERE version='0007'", [str_repeat('0', 64)]);
    try {
        adminAssert(!(new AiReplyStore($pdo, $seal, $rollout))->view($newOwner)['available'], 'Bad schema ledger cannot expose production controls');
    } finally {
        adminSql($pdo, "UPDATE schema_migrations SET checksum=? WHERE version='0007'", [hash('sha256', $sql)]);
    }
});

foreach (glob($rateDirectory . '/*.json') ?: [] as $file) { unlink($file); }
rmdir($rateDirectory);
fwrite(STDOUT, 'PASS AI production settings: ' . $adminChecks . " assertions; synthetic MySQL only; no provider calls.\n");
