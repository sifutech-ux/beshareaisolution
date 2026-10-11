<?php
declare(strict_types=1);

namespace BesharOs;

final class BackupProof
{
    /**
     * Checksum identity and database identity are separate gates.
     * A matching SHA-256 proves the export bytes. It does not prove that those
     * bytes are a backup of Contract::DATABASE.
     *
     * Auditable proof before DDL is a manifest taken from the same session that
     * already observed SELECT DATABASE(), plus a structure dump whose header
     * names that database, the server version, and an empty inventory.
     * This class reads that evidence. It does not execute the dump.
     *
     * @return array<string,string>
     */
    public static function read(string $path, string $repoRoot): array
    {
        $real = ExportGuard::realExport($path, $repoRoot, 'backup_proof');
        $text = file_get_contents($real);
        if ($text === false) {
            throw new \InvalidArgumentException('backup_proof_missing');
        }
        $allowed = [
            'database',
            'server_version',
            'export_sha256',
            'tables',
            'triggers',
            'routines',
            'events',
            'views',
            'schema_migrations_rows',
        ];
        $values = [];
        foreach (preg_split("/\n/", str_replace("\r\n", "\n", $text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/\A([a-z0-9_]+)=(.*)\z/', $line, $matches) !== 1) {
                throw new \InvalidArgumentException('backup_proof_invalid');
            }
            $key = $matches[1];
            if (!in_array($key, $allowed, true) || isset($values[$key]) || trim($matches[2]) === '') {
                throw new \InvalidArgumentException('backup_proof_invalid');
            }
            $values[$key] = trim($matches[2]);
        }
        foreach ($allowed as $key) {
            if (!isset($values[$key])) {
                throw new \InvalidArgumentException('backup_proof_invalid');
            }
        }
        $values['export_sha256'] = strtolower($values['export_sha256']);
        return $values;
    }

    /** @param array<string,string> $manifest */
    public static function verify(string $dump, array $manifest, string $dumpSha256): void
    {
        if (($manifest['database'] ?? '') !== Contract::DATABASE) {
            throw new \InvalidArgumentException('backup_database_rejected');
        }
        $bound = strtolower($manifest['export_sha256'] ?? '');
        $dumpSha256 = strtolower($dumpSha256);
        if (preg_match('/\A[a-f0-9]{64}\z/', $bound) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $dumpSha256) !== 1
            || !hash_equals($bound, $dumpSha256)) {
            throw new \InvalidArgumentException('backup_checksum_unbound');
        }
        foreach (['tables', 'triggers', 'routines', 'events', 'views', 'schema_migrations_rows'] as $key) {
            if (($manifest[$key] ?? '') !== '0') {
                throw new \InvalidArgumentException('backup_inventory_not_empty');
            }
        }
        $headers = self::headerNames($dump);
        if ($headers === []) {
            throw new \InvalidArgumentException('backup_header_missing');
        }
        foreach ($headers as $header) {
            if ($header !== Contract::DATABASE) {
                throw new \InvalidArgumentException('backup_other_database');
            }
        }
        $version = $manifest['server_version'] ?? '';
        if (!str_contains($version, 'MariaDB')
            || preg_match('/^--.*Server version[:\s]+' . preg_quote($version, '/') . '\s*$/mi', $dump) !== 1) {
            throw new \InvalidArgumentException('backup_server_unbound');
        }
        if (self::namesAnotherDatabase($dump, Contract::DATABASE)) {
            throw new \InvalidArgumentException('backup_other_database');
        }
        if (self::containsObjectsOrRows($dump)) {
            throw new \InvalidArgumentException('backup_not_empty_structure');
        }
    }

    /** @return list<string> */
    private static function headerNames(string $dump): array
    {
        $found = [];
        foreach (preg_split("/\n/", str_replace("\r\n", "\n", $dump)) ?: [] as $line) {
            if (preg_match('/^\s*--.*\bDatabase:\s*`?([A-Za-z0-9_]+)`?\s*$/i', $line, $matches) === 1) {
                $found[] = $matches[1];
            }
        }
        return $found;
    }

    private static function namesAnotherDatabase(string $dump, string $database): bool
    {
        foreach (self::codeLines($dump) as $line) {
            if (preg_match('/\A(?:USE|CREATE\s+DATABASE)\b/i', $line) !== 1) {
                continue;
            }
            $token = self::lastIdentifier($line);
            if ($token === null || $token !== $database) {
                return true;
            }
        }
        return false;
    }

    private static function containsObjectsOrRows(string $dump): bool
    {
        foreach (self::codeLines($dump) as $line) {
            if (preg_match('/\A(?:USE|CREATE\s+DATABASE|SET|START|COMMIT)\b/i', $line) === 1) {
                continue;
            }
            if (preg_match('/\A(?:CREATE|INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER)\b/i', $line) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function codeLines(string $dump): array
    {
        $dump = preg_replace('/\/\*(?!!).*?\*\//s', ' ', $dump) ?? $dump;
        $dump = preg_replace('/\/\*!?\d*\s*/', ' ', $dump) ?? $dump;
        $dump = str_replace('*/', ' ', $dump);
        $lines = [];
        foreach (preg_split("/\n/", str_replace("\r\n", "\n", $dump)) ?: [] as $line) {
            if (preg_match('/^\s*(?:--|#)/', $line) === 1) {
                continue;
            }
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private static function lastIdentifier(string $line): ?string
    {
        if (preg_match_all('/`([A-Za-z0-9_]+)`/', $line, $quoted) > 0) {
            $names = $quoted[1];
            return $names[count($names) - 1];
        }
        if (preg_match('/([A-Za-z0-9_]+)\s*;?\s*$/', $line, $matches) === 1) {
            $token = $matches[1];
            if (in_array(strtoupper($token), ['EXISTS', 'NOT', 'IF', 'DATABASE'], true)) {
                return null;
            }
            return $token;
        }
        return null;
    }
}
