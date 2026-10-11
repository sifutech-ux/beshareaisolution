<?php
declare(strict_types=1);

namespace BesharOs;

final class ActivationGate
{
    public const ENV = 'BESHARE_COEXISTENCE_SYNC_ENABLED';

    public static function enabled(?string $value = null): bool
    {
        if ($value === null) {
            $raw = getenv(self::ENV);
            $value = is_string($raw) ? $raw : '';
        }
        return $value === 'true';
    }
}
