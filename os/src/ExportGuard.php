<?php
declare(strict_types=1);

namespace BesharOs;

final class ExportGuard
{
    public static function realExport(string $path, string $repoRoot): string
    {
        if ($path === '' || !is_file($path)) {
            throw new \InvalidArgumentException('export_missing');
        }
        $real = realpath($path);
        $root = realpath($repoRoot);
        if ($real === false || $root === false || !is_file($real)) {
            throw new \InvalidArgumentException('export_missing');
        }
        if (filesize($real) === 0) {
            throw new \InvalidArgumentException('export_empty');
        }
        $prefix = rtrim($root, '/') . '/';
        if ($real === $root || str_starts_with($real, $prefix)) {
            throw new \InvalidArgumentException('export_inside_repository');
        }
        if (preg_match('#(?:^|/)public_html(?:/|$)#', $real) === 1) {
            throw new \InvalidArgumentException('export_inside_public_html');
        }
        return $real;
    }

    public static function checksum(string $realPath, string $expected): string
    {
        $expected = strtolower($expected);
        if (preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1) {
            throw new \InvalidArgumentException('export_checksum_invalid');
        }
        $actual = hash_file('sha256', $realPath);
        if ($actual === false || !hash_equals($expected, $actual)) {
            throw new \InvalidArgumentException('export_checksum_mismatch');
        }
        return $actual;
    }
}
