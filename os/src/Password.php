<?php
declare(strict_types=1);

namespace BesharOs;

final class Password
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 72;

    public static function assertAcceptable(string $password): void
    {
        $length = strlen($password);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new AuthException('Kata laluan mestilah 10 hingga 72 aksara.');
        }
    }

    public static function hash(string $password): string
    {
        self::assertAcceptable($password);
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algo);
        if (!is_string($hash) || $hash === '') {
            throw new AuthException('Kata laluan tidak dapat disimpan.', 500);
        }
        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function dummyHash(): string
    {
        static $hash = null;
        if (!is_string($hash)) {
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $hash = password_hash(bin2hex(random_bytes(16)), $algo);
        }
        return is_string($hash) ? $hash : '';
    }
}
