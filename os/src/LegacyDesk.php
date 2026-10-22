<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** The legacy JSON store belongs to exactly one explicitly configured business. */
final class LegacyDesk
{
    public static function authorize(PDO $pdo, array $session, array $config, string $method, array $body, array $actions): array
    {
        $actor = Membership::require($pdo, (int) ($session['user_id'] ?? 0), (int) ($session['business_id'] ?? 0));
        $business = $config['legacy_business_id'] ?? '';
        if (!is_string($business) || preg_match('/\A[1-9][0-9]*\z/', $business) !== 1) {
            throw new AuthException('Unavailable', 503);
        }
        if ((string) $actor['business_id'] !== $business || $actor['status'] !== 'active'
            || !in_array($actor['role'], ['owner', 'admin'], true)) {
            throw new AuthException('Forbidden', 403);
        }
        // Never accept a client-selected tenant/phone/store, even from an admin.
        foreach (['business_id', 'tenant_id', 'phone_number_id', 'waba_id'] as $key) {
            if (array_key_exists($key, $body)) {
                throw new AuthException('Forbidden', 403);
            }
        }
        if ($method === 'POST') {
            $csrf = $body['csrf'] ?? '';
            Csrf::check($session, is_string($csrf) ? $csrf : '');
            $action = $body['tindakan'] ?? '';
            if (!is_string($action) || !in_array($action, $actions, true)) {
                throw new AuthException('Forbidden', 403);
            }
        } elseif ($method !== 'GET') {
            throw new AuthException('Method not allowed', 405);
        }
        return $actor;
    }
}
