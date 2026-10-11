<?php
declare(strict_types=1);

namespace BesharOs;

final class WebhookReadiness
{
    public const FIELDS = [
        'messages',
        'account_update',
        'history',
        'smb_app_state_sync',
        'smb_message_echoes',
    ];

    public static function ready(): bool
    {
        return self::covers(WhatsAppWebhook::fields());
    }

    /** @param list<string> $fields */
    public static function covers(array $fields): bool
    {
        foreach (self::FIELDS as $field) {
            if (!in_array($field, $fields, true)) {
                return false;
            }
        }
        return true;
    }
}
