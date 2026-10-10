<?php
declare(strict_types=1);

namespace BesharOs;

final class SqlMode
{
    public static function hasStrict(string $mode): bool
    {
        return in_array('STRICT_TRANS_TABLES', self::tokens($mode), true);
    }

    public static function withStrict(string $mode): string
    {
        if (self::hasStrict($mode)) {
            return $mode;
        }
        $mode = trim($mode);
        if ($mode === '') {
            return 'STRICT_TRANS_TABLES';
        }
        return $mode . ',STRICT_TRANS_TABLES';
    }

    public static function isSafeLiteral(string $mode): bool
    {
        return preg_match('/\A[A-Z0-9_,]*\z/', $mode) === 1;
    }

    /** @return list<string> */
    private static function tokens(string $mode): array
    {
        $tokens = [];
        foreach (explode(',', strtoupper($mode)) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }
}
