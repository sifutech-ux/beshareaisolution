<?php
declare(strict_types=1);

// Disposable MySQL fixture and injected transports only. Never load private config.
require_once __DIR__ . '/admin-fixture.php';
require_once __DIR__ . '/../src/CloudPhoneRegistration.php';
require_once __DIR__ . '/../src/AdminWhatsAppActivation.php';

use BesharOs\AdminWhatsAppActivation;
use BesharOs\CloudPhoneRegistration;
use BesharOs\MetaGraph;
use BesharOs\MetaTransport;

const ADMIN_WA_APP = '99000000009';

final class AdminWhatsAppFixtureTransport implements MetaTransport
{
    public array $calls = [];
    public array $phone;
    public array $debug;
    public bool $subscribed = true;
    public bool $memberPhone = true;
    public string $postOutcome = 'registered';
    public int $posts = 0;
    public ?string $sentPin = null;
    public ?Closure $afterRead = null;
    public ?Closure $onPost = null;

    public function __construct(private array $connection)
    {
        $this->phone = ['id' => $connection['phone_number_id'], 'display_phone_number' => $connection['display_phone'],
            'status' => 'PENDING', 'code_verification_status' => 'VERIFIED', 'is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'];
        $this->debug = ['data' => ['is_valid' => true, 'app_id' => ADMIN_WA_APP, 'expires_at' => 0,
            'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
            'granular_scopes' => [
                ['scope' => 'whatsapp_business_management', 'target_ids' => [$connection['waba_id']]],
                ['scope' => 'whatsapp_business_messaging', 'target_ids' => [$connection['waba_id']]],
            ]]];
    }

    public function call(string $method, string $url, array $headers, string $body): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.facebook.com') {
            throw new RuntimeException('Unexpected host');
        }
        $path = preg_replace('#^/v\d+\.\d+/#', '', (string) ($parts['path'] ?? ''));
        $this->calls[] = [$method, $path];
        if ($method === 'GET' && $path === 'debug_token') { return ['status' => 200, 'json' => $this->debug]; }
        if ($method === 'GET' && $path === $this->connection['waba_id']) {
            return ['status' => 200, 'json' => ['id' => $path, 'owner_business_info' => ['id' => '990000000001']]];
        }
        if ($method === 'GET' && $path === $this->connection['waba_id'] . '/phone_numbers') {
            return ['status' => 200, 'json' => ['data' => $this->memberPhone ? [$this->phone] : []]];
        }
        if ($method === 'GET' && $path === $this->connection['phone_number_id']) {
            return ['status' => 200, 'json' => $this->phone];
        }
        if ($method === 'GET' && $path === $this->connection['waba_id'] . '/subscribed_apps') {
            if ($this->afterRead !== null) { ($this->afterRead)(); }
            return ['status' => 200, 'json' => ['data' => $this->subscribed ? [['whatsapp_business_api_data' => ['id' => ADMIN_WA_APP]]] : []]];
        }
        if ($method === 'POST' && $path === $this->connection['phone_number_id'] . '/register') {
            $this->posts++;
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $this->sentPin = $payload['pin'] ?? null;
            if (($payload['messaging_product'] ?? '') !== 'whatsapp' || !is_string($this->sentPin)
                || preg_match('/\A\d{6}\z/', $this->sentPin) !== 1) { throw new RuntimeException('Invalid register payload'); }
            if ($this->onPost !== null) { ($this->onPost)(); }
            if ($this->postOutcome === 'unknown') { throw new RuntimeException('Synthetic transport timeout'); }
            if ($this->postOutcome === 'rejected') { return ['status' => 400, 'json' => ['error' => ['code' => 133005, 'message' => 'Synthetic secret response']]]; }
            if ($this->postOutcome === 'malformed') { return ['status' => 200, 'json' => ['success' => 'true']]; }
            $this->phone['status'] = 'CONNECTED';
            return ['status' => 200, 'json' => ['success' => true]];
        }
        throw new RuntimeException('Unexpected synthetic Meta request: ' . $method . ' ' . $path);
    }
}

function adminWhatsAppConnection(array $fixture, bool $consent = true): array
{
    static $sequence = 0;
    $sequence++;
    $pdo = $fixture['pdo'];
    $tenant = $fixture['tenantA'];
    $now = gmdate('Y-m-d H:i:s');
    $token = 'synthetic-admin-wa-token-' . $sequence;
    $sealed = $fixture['seal']->seal($token, 1800);
    $waba = (string) (990000000000000 + $sequence);
    $phone = (string) (991000000000000 + $sequence);
    $display = '+601199' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    adminSql($pdo, "INSERT INTO whatsapp_connections
        (business_id,waba_id,phone_number_id,display_phone,token_state,webhook_state,registration_state,messaging_state,
         token_kind,token_ciphertext,token_nonce,token_tag,token_key_version,subscribed_at,created_at,updated_at)
        VALUES (?,?,?,?,'verified','subscribed','not_registered','unverified','system_user',UNHEX(?),UNHEX(?),UNHEX(?),?,?,?,?)",
        [$tenant['business_id'], $waba, $phone, $display, bin2hex($sealed['ciphertext']), bin2hex($sealed['nonce']),
            bin2hex($sealed['tag']), $sealed['version'], $now, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    if ($consent) {
        adminSql($pdo, "INSERT INTO onboarding_sessions
            (business_id,user_id,correlation_hash,session_hash,status,finish_event,reported_waba_id,reported_phone_number_id,expires_at,consumed_at,created_at)
            VALUES (?,?,?,?,'completed','FINISH',?,?,?,?,?)",
            [$tenant['business_id'], $tenant['owner_id'], hash('sha256', 'consent-' . $sequence), hash('sha256', 'session-' . $sequence),
                $waba, $phone, gmdate('Y-m-d H:i:s', time() + 600), $now, $now]);
    }
    return ['id' => $id, 'business_id' => $tenant['business_id'], 'public_id' => $tenant['public_id'],
        'waba_id' => $waba, 'phone_number_id' => $phone, 'display_phone' => $display, 'token' => $token];
}

function adminWhatsAppService(array $fixture, array $connection): array
{
    $transport = new AdminWhatsAppFixtureTransport($connection);
    $graph = new MetaGraph(ADMIN_WA_APP, 'synthetic-app-secret', 'v24.0', $transport);
    $adapter = new CloudPhoneRegistration('v24.0', $transport);
    return [new AdminWhatsAppActivation($fixture['pdo'], $graph, $fixture['seal'], ADMIN_WA_APP, $adapter), $transport];
}

$fixture = adminFixture();
$pdo = $fixture['pdo'];
$admin = $fixture['admin'];

adminTest('tenant role and mismatched target cannot activate another customer', function () use ($fixture, $admin): void {
    $c = adminWhatsAppConnection($fixture);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    adminDenied(fn () => $service->activate(['user_id' => $fixture['users']['ownerA'], 'platform_role' => 'owner'], $c['public_id'], $c['id']), 403, 'Tenant owner is not a platform operator');
    adminDenied(fn () => $service->activate($admin, $fixture['tenantB']['public_id'], $c['id']), 404, 'Connection must belong to selected tenant');
    adminAssert($transport->calls === [], 'Denied actions make no provider calls');
});

adminTest('refresh pending number does not register or fabricate messaging proof', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    adminSql($pdo, "UPDATE whatsapp_connections SET last_error_code='subscribe_failed' WHERE id=?", [$c['id']]);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $result = $service->refresh($admin, $c['public_id'], $c['id']);
    adminAssert($result['status'] === 'refreshed' && $transport->posts === 0, 'Refresh uses read calls only');
    $row = adminSql($pdo, 'SELECT registration_state,messaging_state,last_error_code FROM whatsapp_connections WHERE id=?', [$c['id']])->fetch();
    adminAssert($row['registration_state'] === 'not_registered' && $row['messaging_state'] === 'unverified', 'Read result stays pending with no message proof');
    adminAssert($row['last_error_code'] === null, 'Observed subscription clears stale subscription error');
    adminAssert(adminSql($pdo, 'SELECT status FROM platform_whatsapp_operations WHERE connection_id=?', [$c['id']])->fetchColumn() === 'succeeded', 'Successful read is not stored as blocked');
});

adminTest('one registration stores encrypted PIN first and preserves customer ownership', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $transport->onPost = function () use ($pdo, $c, $fixture, $transport, $service, $admin): void {
        $op = adminSql($pdo, 'SELECT * FROM platform_whatsapp_operations WHERE connection_id=?', [$c['id']])->fetch();
        adminAssert($op['status'] === 'registering' && $op['registration_started_at'] !== null, 'Dispatch marker persisted before network write');
        $pin = $fixture['seal']->open($op['pin_ciphertext'], $op['pin_nonce'], $op['pin_tag'], (int) $op['pin_key_version']);
        adminAssert($pin === $transport->sentPin && $op['pin_ciphertext'] !== $pin, 'Stable PIN is encrypted before dispatch');
        $duplicate = $service->activate($admin, $c['public_id'], $c['id']);
        adminAssert($duplicate['status'] === 'registering' && $transport->posts === 1, 'Concurrent duplicate cannot register again');
    };
    $result = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($result['status'] === 'succeeded' && $transport->posts === 1, 'Registration succeeds once');
    $row = adminSql($pdo, 'SELECT business_id,waba_id,phone_number_id,registration_state,messaging_state FROM whatsapp_connections WHERE id=?', [$c['id']])->fetch();
    adminAssert((int) $row['business_id'] === $c['business_id'] && $row['waba_id'] === $c['waba_id'] && $row['phone_number_id'] === $c['phone_number_id'], 'Assets remain with customer');
    adminAssert($row['registration_state'] === 'registered' && $row['messaging_state'] === 'unverified', 'Register success does not create message proof');
    $safe = json_encode($result) . json_encode(adminSql($pdo, 'SELECT meta_json FROM audit_logs WHERE entity_type=? AND entity_id=?', ['whatsapp_connection', $c['id']])->fetchAll());
    adminAssert(!str_contains($safe, $c['token']) && !str_contains($safe, (string) $transport->sentPin), 'Responses and audit omit token and PIN');
    $again = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($again['status'] === 'succeeded' && $transport->posts === 1, 'Already registered reconciliation is idempotent');
});

adminTest('ambiguous dispatch is never repeated and later reads can settle it', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $transport->postOutcome = 'unknown';
    $first = $service->activate($admin, $c['public_id'], $c['id']);
    $cipher = adminSql($pdo, 'SELECT HEX(pin_ciphertext) FROM platform_whatsapp_operations WHERE connection_id=?', [$c['id']])->fetchColumn();
    adminAssert($first['status'] === 'unknown' && $transport->posts === 1, 'Timeout is ambiguous');
    adminSql($pdo, "UPDATE platform_whatsapp_operations SET status='registering',updated_at=? WHERE connection_id=?", [gmdate('Y-m-d H:i:s', time() - 601), $c['id']]);
    $again = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($again['status'] === 'unknown' && $transport->posts === 1, 'Stale dispatch marker also prevents another POST');
    adminAssert(adminSql($pdo, 'SELECT HEX(pin_ciphertext) FROM platform_whatsapp_operations WHERE connection_id=?', [$c['id']])->fetchColumn() === $cipher, 'PIN remains unchanged across reconciliation');
    $transport->phone['status'] = 'CONNECTED';
    $settled = $service->refresh($admin, $c['public_id'], $c['id']);
    adminAssert($settled['status'] === 'succeeded' && $transport->posts === 1, 'Observed CONNECTED settles unknown outcome');
});

adminTest('Coexistence path never registers or infers webhook readiness', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    adminSql($pdo, "UPDATE whatsapp_connections SET sync_contacts='pending',sync_history='pending',webhook_state='not_subscribed',subscribed_at=NULL WHERE id=?", [$c['id']]);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $transport->phone['is_on_biz_app'] = true;
    $result = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($result['status'] === 'blocked' && $transport->posts === 0, 'Coexistence cannot enter new-number registration');
    adminAssert(adminSql($pdo, 'SELECT webhook_state FROM whatsapp_connections WHERE id=?', [$c['id']])->fetchColumn() === 'not_subscribed', 'Coexistence delivery proof is not inferred from an app list');
});

adminTest('imported connections can refresh but cannot be newly registered', function () use ($fixture, $admin): void {
    $c = adminWhatsAppConnection($fixture, false);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    unset($transport->debug['data']['granular_scopes']);
    $transport->phone['status'] = 'CONNECTED';
    adminAssert($service->refresh($admin, $c['public_id'], $c['id'])['status'] === 'succeeded', 'Read-only owner import can reconcile scoped token');
    $c = adminWhatsAppConnection($fixture, false);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $result = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($result['error_code'] === 'embedded_consent_missing' && $transport->posts === 0, 'Import without Embedded Signup cannot register');
});

adminTest('unknown phone type, permission, ownership and subscription fail closed', function () use ($fixture, $admin): void {
    foreach (['flags', 'scope', 'ownership', 'subscription', 'display', 'membership', 'on_premise', 'unknown_platform', 'missing_platform'] as $case) {
        $c = adminWhatsAppConnection($fixture);
        [$service, $transport] = adminWhatsAppService($fixture, $c);
        if ($case === 'flags') { unset($transport->phone['is_on_biz_app']); }
        if ($case === 'scope') { unset($transport->debug['data']['granular_scopes']); }
        if ($case === 'ownership') { $transport->phone['code_verification_status'] = 'NOT_VERIFIED'; }
        if ($case === 'subscription') { $transport->subscribed = false; }
        if ($case === 'display') { $transport->phone['display_phone_number'] = '+601199999999'; }
        if ($case === 'membership') { $transport->memberPhone = false; }
        if ($case === 'on_premise') { $transport->phone['platform_type'] = 'ON_PREMISE'; }
        if ($case === 'unknown_platform') { $transport->phone['platform_type'] = 'UNKNOWN'; }
        if ($case === 'missing_platform') { unset($transport->phone['platform_type']); }
        $result = $service->activate($admin, $c['public_id'], $c['id']);
        adminAssert($result['status'] === 'blocked' && $transport->posts === 0, 'Failed proof blocks registration: ' . $case);
    }
});

adminTest('first registration accepts recognized unassigned platform with complete proof', function () use ($fixture, $admin): void {
    $c = adminWhatsAppConnection($fixture);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $transport->phone['platform_type'] = 'NOT_APPLICABLE';
    $result = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($result['status'] === 'succeeded' && $transport->posts === 1, 'Verified pending new number may have no platform before registration');
});

adminTest('registration requires current and recent completed signup consent', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    adminSql($pdo, 'UPDATE onboarding_sessions SET consumed_at=? WHERE reported_phone_number_id=?', [gmdate('Y-m-d H:i:s', time() - 15 * 86400), $c['phone_number_id']]);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $result = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($result['error_code'] === 'embedded_consent_expired' && $transport->posts === 0, 'Stale onboarding never triggers registration');
    $c = adminWhatsAppConnection($fixture);
    adminSql($pdo, 'UPDATE onboarding_sessions SET finish_event=NULL WHERE reported_phone_number_id=?', [$c['phone_number_id']]);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    adminAssert($service->activate($admin, $c['public_id'], $c['id'])['status'] === 'succeeded', 'Verified signup fallback remains supported');
});

adminTest('revoked operator and suspended tenant are rechecked after Meta reads', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $transport->afterRead = fn () => adminSql($pdo, "UPDATE platform_admins SET status='revoked' WHERE user_id=?", [$admin['user_id']]);
    adminDenied(fn () => $service->activate($admin, $c['public_id'], $c['id']), 403, 'Revoked operator cannot dispatch');
    adminAssert($transport->posts === 0, 'Revocation stops external write');
    adminSql($pdo, "UPDATE platform_admins SET status='active' WHERE user_id=?", [$admin['user_id']]);
    $c = adminWhatsAppConnection($fixture);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $transport->afterRead = fn () => adminSql($pdo, "INSERT INTO platform_business_access (business_id,status,updated_by,created_at,updated_at) VALUES (?,'suspended',?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='suspended'", [$c['business_id'], $admin['user_id']]);
    adminDenied(fn () => $service->activate($admin, $c['public_id'], $c['id']), 403, 'Platform suspension cannot be bypassed');
    adminAssert($transport->posts === 0, 'Suspension stops external write');
    adminSql($pdo, "UPDATE platform_business_access SET status='active' WHERE business_id=?", [$c['business_id']]);
});

adminTest('changing the connection during preflight stops registration', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $transport->afterRead = fn () => adminSql($pdo, 'UPDATE whatsapp_connections SET phone_number_id=? WHERE id=?', ['995000000000001', $c['id']]);
    $result = $service->activate($admin, $c['public_id'], $c['id']);
    adminAssert($result['error_code'] === 'connection_changed' && $transport->posts === 0, 'Stale asset preflight cannot write');
});

adminTest('explicit Meta rejection and malformed success never become active', function () use ($fixture, $admin, $pdo): void {
    foreach (['rejected', 'malformed'] as $mode) {
        $c = adminWhatsAppConnection($fixture);
        [$service, $transport] = adminWhatsAppService($fixture, $c);
        $transport->postOutcome = $mode;
        $result = $service->activate($admin, $c['public_id'], $c['id']);
        adminAssert($result['status'] === ($mode === 'rejected' ? 'blocked' : 'unknown'), 'Only unambiguous success counts: ' . $mode);
        adminAssert(adminSql($pdo, 'SELECT registration_state FROM whatsapp_connections WHERE id=?', [$c['id']])->fetchColumn() === 'not_registered', 'Failed register does not mark registered');
        $service->activate($admin, $c['public_id'], $c['id']);
        adminAssert($transport->posts === 1, 'No duplicate POST after attempted registration');
        adminAssert(!str_contains(json_encode($result), 'Synthetic secret response'), 'Provider error text is never exposed');
    }
});

adminTest('established message proof is not overwritten by conflicting Meta status', function () use ($fixture, $admin, $pdo): void {
    $c = adminWhatsAppConnection($fixture);
    adminSql($pdo, "UPDATE whatsapp_connections SET registration_state='registered',messaging_state='send_and_receive_verified' WHERE id=?", [$c['id']]);
    [$service, $transport] = adminWhatsAppService($fixture, $c);
    $result = $service->refresh($admin, $c['public_id'], $c['id']);
    adminAssert($result['error_code'] === 'evidence_conflict' && $transport->posts === 0, 'Contradictory proof is surfaced');
    adminAssert(adminSql($pdo, 'SELECT messaging_state FROM whatsapp_connections WHERE id=?', [$c['id']])->fetchColumn() === 'send_and_receive_verified', 'Prior actual messaging proof is retained');
});

fwrite(STDOUT, "Admin WhatsApp activation tests completed with synthetic Meta transport only.\n");
