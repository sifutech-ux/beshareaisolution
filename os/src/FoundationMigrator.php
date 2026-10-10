<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use PDOException;
use Throwable;

final class FoundationMigrator
{
    private PDO $pdo;
    private string $upPath;
    private string $checksum;

    public function __construct(private string $repoRoot)
    {
        $this->upPath = $this->repoRoot . '/os/migrations/0001_foundation.up.sql';
    }

    public function apply(string $exportPath, string $exportChecksum): int
    {
        $export = ExportGuard::realExport($exportPath, $this->repoRoot);
        ExportGuard::checksum($export, $exportChecksum);
        if (!is_file($this->upPath)) {
            fwrite(STDERR, "migration_file_missing\n");
            return 4;
        }
        $this->checksum = $this->hashMigration();
        $this->connect();
        if (!$this->acquireLock()) {
            fwrite(STDERR, "migration_lock_busy\n");
            return 3;
        }
        try {
            $this->enforceSession();
            return $this->run();
        } finally {
            $this->releaseLock();
        }
    }

    private function run(): int
    {
        $state = $this->inspect();
        if ($state === 'applied') {
            fwrite(STDOUT, "already_applied\n");
            return 0;
        }
        if ($state === 'checksum_mismatch') {
            fwrite(STDERR, "checksum_mismatch\n");
            return 5;
        }
        if ($state === 'foreign_object' || $state === 'drift' || $state === 'partial') {
            fwrite(STDERR, $state . "\n");
            return 5;
        }
        if ($state === 'complete_unrecorded') {
            fwrite(STDERR, "ddl_complete_version_unrecorded\n");
            return 7;
        }
        if ($state !== 'empty') {
            fwrite(STDERR, "preflight_rejected\n");
            return 4;
        }

        $statements = SqlScript::statements((string) file_get_contents($this->upPath));
        if ($this->hashMigration() !== $this->checksum || $this->inspect() !== 'empty') {
            fwrite(STDERR, "preflight_changed\n");
            return 4;
        }

        foreach ($statements as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $exception) {
                fwrite(STDERR, 'ddl_failed statement=' . ($index + 1) . ' sqlstate=' . $exception->getCode() . "\n");
                return 6;
            }
        }

        if ($this->hashMigration() !== $this->checksum) {
            fwrite(STDERR, "ddl_complete_version_unrecorded\n");
            return 7;
        }
        $after = CatalogState::classify(
            $this->names($this->rows('SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'')),
            $this->names($this->rows('SELECT TRIGGER_NAME AS name FROM information_schema.triggers WHERE trigger_schema = DATABASE()')),
            $this->names($this->rows('SELECT ROUTINE_NAME AS name FROM information_schema.routines WHERE routine_schema = DATABASE()')),
            $this->names($this->rows('SELECT EVENT_NAME AS name FROM information_schema.events WHERE event_schema = DATABASE()')),
            $this->names($this->rows('SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'VIEW\'')),
            [],
            $this->checksum
        );
        if ($after !== 'complete_unrecorded') {
            fwrite(STDERR, "ddl_complete_version_unrecorded\n");
            return 7;
        }
        return $this->recordVersion();
    }

    private function recordVersion(): int
    {
        try {
            $this->pdo->beginTransaction();
            $insert = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
            );
            $insert->execute([Contract::VERSION, Contract::NAME, $this->checksum]);
            $read = $this->pdo->prepare('SELECT version, checksum FROM schema_migrations WHERE version = ?');
            $read->execute([Contract::VERSION]);
            $row = $read->fetch();
            $count = $this->pdo->query('SELECT COUNT(*) AS total FROM schema_migrations');
            $total = $count === false ? null : $count->fetch();
            if (
                !is_array($row)
                || (string) $row['version'] !== Contract::VERSION
                || !hash_equals($this->checksum, (string) $row['checksum'])
                || !is_array($total)
                || (int) $total['total'] !== 1
            ) {
                $this->pdo->rollBack();
                fwrite(STDERR, "ddl_complete_version_unrecorded\n");
                return 7;
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            fwrite(STDERR, "ddl_complete_version_unrecorded\n");
            return 7;
        }
        fwrite(STDOUT, "applied\n");
        return 0;
    }

    private function inspect(): string
    {
        $tables = $this->names($this->rows(
            'SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''
        ));
        $triggers = $this->names($this->rows(
            'SELECT TRIGGER_NAME AS name FROM information_schema.triggers WHERE trigger_schema = DATABASE()'
        ));
        $routines = $this->names($this->rows(
            'SELECT ROUTINE_NAME AS name FROM information_schema.routines WHERE routine_schema = DATABASE()'
        ));
        $events = $this->names($this->rows(
            'SELECT EVENT_NAME AS name FROM information_schema.events WHERE event_schema = DATABASE()'
        ));
        $views = $this->names($this->rows(
            'SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'VIEW\''
        ));
        $versions = [];
        if (in_array('schema_migrations', $tables, true)) {
            $versions = $this->rows('SELECT version, checksum FROM schema_migrations ORDER BY version');
        }
        return CatalogState::classify($tables, $triggers, $routines, $events, $views, $versions, $this->checksum);
    }

    private function connect(): void
    {
        $dsn = getenv('BESHARE_OS_DSN');
        $user = getenv('BESHARE_OS_DB_USER');
        $password = getenv('BESHARE_OS_DB_PASSWORD');
        if (!is_string($dsn) || !is_string($user) || !is_string($password) || $dsn === '' || $user === '' || $password === '') {
            throw new \RuntimeException('database_env_missing');
        }
        if (str_contains($dsn, 'password=')) {
            throw new \RuntimeException('dsn_must_not_carry_password');
        }
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $current = $this->pdo->query('SELECT DATABASE() AS name');
        $row = $current === false ? false : $current->fetch();
        if (!is_array($row) || (string) $row['name'] !== Contract::DATABASE) {
            throw new \RuntimeException('database_name_rejected');
        }
    }

    private function enforceSession(): void
    {
        $before = $this->modeRow();
        $session = SqlMode::withStrict($before['s']);
        if (!SqlMode::isSafeLiteral($session)) {
            throw new \RuntimeException('sql_mode_unexpected');
        }
        $this->pdo->exec("SET SESSION sql_mode = '" . $session . "'");
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");
        $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $after = $this->modeRow();
        if ($after['g'] !== $before['g'] || !SqlMode::hasStrict($after['s']) || $after['z'] !== '+00:00') {
            throw new \RuntimeException('session_guard_failed');
        }
    }

    /** @return array{g:string,s:string,z:string} */
    private function modeRow(): array
    {
        $query = $this->pdo->query('SELECT @@GLOBAL.sql_mode AS g, @@SESSION.sql_mode AS s, @@session.time_zone AS z');
        $row = $query === false ? false : $query->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('session_guard_failed');
        }
        return [
            'g' => (string) $row['g'],
            's' => (string) $row['s'],
            'z' => (string) $row['z'],
        ];
    }

    private function acquireLock(): bool
    {
        $query = $this->pdo->query("SELECT GET_LOCK('" . Contract::LOCK . "', 0) AS locked");
        $row = $query === false ? false : $query->fetch();
        return is_array($row) && (int) $row['locked'] === 1;
    }

    private function releaseLock(): void
    {
        try {
            $this->pdo->query("SELECT RELEASE_LOCK('" . Contract::LOCK . "')");
        } catch (Throwable $exception) {
            fwrite(STDERR, "lock_release_failed\n");
        }
    }

    private function hashMigration(): string
    {
        $hash = hash_file('sha256', $this->upPath);
        if ($hash === false) {
            throw new \RuntimeException('migration_file_missing');
        }
        return $hash;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $query = $this->pdo->query($sql);
        if ($query === false) {
            throw new \RuntimeException('inventory_failed');
        }
        $rows = $query->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }
}
