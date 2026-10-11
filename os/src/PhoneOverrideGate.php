<?php
declare(strict_types=1);

namespace BesharOs;

final class PhoneOverrideGate
{
    public const ENV = 'BESHARE_PHONE_OVERRIDE_ENABLED';

    public static function enabled(?string $value = null): bool
    {
        if ($value === null) {
            $raw = getenv(self::ENV);
            $value = is_string($raw) ? $raw : '';
        }
        return $value === 'true';
    }
}
