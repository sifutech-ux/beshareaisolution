<?php
declare(strict_types=1);

namespace BesharOs;

final class Csrf
{
    /** @param array<string,mixed> $session */
    public static function issue(array &$session): string
    {
        $current = $session['csrf'] ?? '';
        if (!is_string($current) || strlen($current) !== 64) {
            $session['csrf'] = bin2hex(random_bytes(32));
        }
        return $session['csrf'];
    }

    /** @param array<string,mixed> $session */
    public static function rotate(array &$session): string
    {
        $session['csrf'] = bin2hex(random_bytes(32));
        return $session['csrf'];
    }

    /** @param array<string,mixed> $session */
    public static function check(array $session, string $token): void
    {
        $expected = $session['csrf'] ?? '';
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
            throw new AuthException('Permintaan ditolak.', 403);
        }
    }
}
