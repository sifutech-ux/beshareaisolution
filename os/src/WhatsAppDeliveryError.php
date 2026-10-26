<?php
declare(strict_types=1);

namespace BesharOs;

/** Only bounded numeric provider diagnostics may leave the transport boundary. */
final class WhatsAppDeliveryError
{
    /** @return array{error_code:int,error_subcode:?int}|null */
    public static function fromGraph(mixed $error): ?array
    {
        if (!is_array($error)) { return null; }
        return self::fromFields(['error_code' => $error['code'] ?? null,
            'error_subcode' => $error['error_subcode'] ?? null]);
    }

    /** Retain the first valid diagnostic from a small, bounded provider error list. */
    public static function fromStatus(mixed $errors): ?array
    {
        if (!is_array($errors) || !array_is_list($errors)) { return null; }
        foreach (array_slice($errors, 0, 3) as $error) {
            $diagnostic = self::fromGraph($error);
            if ($diagnostic !== null) { return $diagnostic; }
        }
        return null;
    }

    /** @return array{error_code:int,error_subcode:?int}|null */
    public static function fromFields(array $fields): ?array
    {
        $code = self::number($fields['error_code'] ?? null, false);
        if ($code === null) { return null; }
        return ['error_code' => $code, 'error_subcode' => self::number($fields['error_subcode'] ?? null, true)];
    }

    /** Fits the existing 64-character job field; never accepts a provider description. */
    public static function reason(array $fields, string $fallback): string
    {
        $diagnostic = self::fromFields($fields);
        if ($diagnostic === null) { return $fallback === 'send_rejected' ? 'send_rejected' : 'delivery_failed'; }
        return 'meta_error_' . $diagnostic['error_code']
            . ($diagnostic['error_subcode'] === null ? '' : '_sub_' . $diagnostic['error_subcode']);
    }

    /** @return array{failure_reason:string,error_code:?int,error_subcode:?int} */
    public static function inbox(mixed $reason): array
    {
        $diagnostic = null;
        if (is_string($reason) && strlen($reason) <= 64
            && preg_match('/\Ameta_error_([1-9][0-9]{0,9})(?:_sub_(0|[1-9][0-9]{0,9}))?\z/', $reason, $match) === 1) {
            $diagnostic = self::fromFields(['error_code' => $match[1], 'error_subcode' => $match[2] ?? null]);
        }
        return ['failure_reason' => $diagnostic !== null ? 'meta_error'
                : ($reason === 'send_rejected' ? 'send_rejected' : 'delivery_failed'),
            'error_code' => $diagnostic['error_code'] ?? null,
            'error_subcode' => $diagnostic['error_subcode'] ?? null];
    }

    private static function number(mixed $value, bool $zeroAllowed): ?int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < ($zeroAllowed ? 0 : 1) || $value > 2147483647) { return null; }
        return $value;
    }
}
