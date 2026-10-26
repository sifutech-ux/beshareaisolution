<?php
declare(strict_types=1);

namespace BesharOs;

/** Shared eligibility and recipient rules; callers retain tenant and connection checks. */
final class AiReplyPolicy
{
    public static function allBusinesses(array $config): bool
    {
        return ($config['ai_customer_rollout'] ?? '') === 'all';
    }

    public static function serverReady(array $config): bool
    {
        $enabled = array_key_exists('ai_enabled', $config) ? $config['ai_enabled'] : ($config['owner_ai_enabled'] ?? '');
        return $enabled === 'true'
            && is_string($config['gemini_api_key'] ?? null) && trim($config['gemini_api_key']) !== ''
            && is_string($config['gemini_model'] ?? null) && trim($config['gemini_model']) !== '';
    }

    public static function businessAllowed(array $config, int $businessId): bool
    {
        if ($businessId < 1) { return false; }
        if (self::allBusinesses($config)) { return true; }
        $id = $config['owner_ai_business_id'] ?? '';
        return is_string($id) && preg_match('/\A[1-9]\d{0,18}\z/', $id) === 1 && $id === (string) $businessId;
    }

    public static function audienceMode(array $settings): string
    {
        // Rows read before migration 0007 retain their original test-only meaning.
        if (!array_key_exists('audience_mode', $settings)) { return 'test'; }
        return in_array($settings['audience_mode'], ['test', 'all'], true) ? $settings['audience_mode'] : '';
    }

    public static function customerConsent(array $settings, int $ownerId): bool
    {
        $mode = self::audienceMode($settings);
        if ($mode === 'test') { return true; }
        $approvedAt = $settings['audience_approved_at'] ?? null;
        return $mode === 'all' && $ownerId > 0
            && (string) ($settings['audience_approved_by'] ?? '') === (string) $ownerId
            && is_string($approvedAt) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $approvedAt) === 1
            && strtotime($approvedAt . ' UTC') > 0;
    }

    public static function recipientAllowed(array $settings, string $recipient, int $ownerId, bool $allAllowed): bool
    {
        if (preg_match('/\A[0-9]{6,20}\z/', $recipient) !== 1) { return false; }
        $mode = self::audienceMode($settings);
        if ($mode === 'all') { return $allAllowed && self::customerConsent($settings, $ownerId); }
        if ($mode !== 'test') { return false; }
        $allowlist = json_decode((string) ($settings['allowlist_json'] ?? ''), true, 8);
        if (!is_array($allowlist) || !array_is_list($allowlist) || count($allowlist) < 1 || count($allowlist) > 10) {
            return false;
        }
        foreach ($allowlist as $phone) {
            if (!is_string($phone) || preg_match('/\A[0-9]{6,20}\z/', $phone) !== 1) { return false; }
        }
        return in_array($recipient, $allowlist, true);
    }
}
