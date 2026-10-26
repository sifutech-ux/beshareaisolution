<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

require_once __DIR__ . '/SqlScript.php';

/** Explicit additive release; an HTTP request only inspects readiness. */
final class AiProductionSchema
{
    public const VERSION = '0007';
    public const COLUMNS = ['audience_mode', 'audience_approved_by', 'audience_approved_at'];
    public const TABLE = 'ai_reply_dispatch_state';

    public function __construct(private PDO $pdo, private string $sql) {}

    public static function available(PDO $pdo): bool
    {
        try {
            $sql = file_get_contents(dirname(__DIR__) . '/migrations/0007_customer_ai.up.sql');
            return is_string($sql) && (new self($pdo, $sql))->inspect()['status'] === 'installed';
        } catch (\Throwable) {
            return false;
        }
    }

    public function inspect(): array
    {
        $schema = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $columns = $this->pdo->prepare('SELECT column_name,column_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=? AND table_name=?');
        foreach ([
            'users' => ['id', 'status'],
            'businesses' => ['id', 'owner_user_id', 'status'],
            'ai_reply_settings' => ['business_id', 'enabled', 'approved_by', 'approved_at', 'allowlist_json', 'quota_used', 'activation_at'],
            'ai_reply_jobs' => ['event_id', 'business_id', 'status', 'locked_until'],
            'webhook_events' => ['business_id', 'connection_id', 'status', 'event_kind'],
            'schema_migrations' => ['version', 'checksum'],
        ] as $table => $required) {
            $columns->execute([$schema, $table]);
            $names = array_keys($this->normalizeColumns($columns->fetchAll(PDO::FETCH_ASSOC)));
            if (array_diff($required, $names) !== []) {
                throw new AuthException('Skema asas AI belum lengkap. Tiada perubahan dibuat.', 409);
            }
        }
        $columns->execute([$schema, 'ai_reply_settings']);
        $settings = $this->normalizeColumns($columns->fetchAll(PDO::FETCH_ASSOC));
        $present = array_intersect(self::COLUMNS, array_keys($settings));
        $columns->execute([$schema, self::TABLE]);
        $dispatcher = $this->normalizeColumns($columns->fetchAll(PDO::FETCH_ASSOC));
        $record = $this->pdo->query("SELECT checksum FROM schema_migrations WHERE version='0007'")->fetchColumn();
        $checksum = hash('sha256', $this->sql);
        if ($record === false && $present === [] && $dispatcher === []) {
            return ['status' => 'ready', 'checksum' => $checksum, 'columns' => self::COLUMNS, 'table' => self::TABLE];
        }
        if ($record === false) {
            throw new AuthException('Pemasangan AI pelanggan separa dikesan. Semakan diperlukan; tiada data dipadam.', 409);
        }
        if (!is_string($record) || !hash_equals($checksum, $record)
            || count($present) !== 3 || array_diff(['name', 'last_business_id', 'updated_at'], array_keys($dispatcher)) !== []) {
            throw new AuthException('Skema AI pelanggan tidak sepadan dengan rekod pemasangan.', 409);
        }
        $expected = [
            'audience_mode' => ['varchar(16)', 'NO', 'test'],
            'audience_approved_by' => ['bigint unsigned', 'YES', null],
            'audience_approved_at' => ['datetime', 'YES', null],
        ];
        foreach ($expected as $column => $definition) {
            $actual = $settings[$column];
            // Ignore integer display widths used by older compatible MySQL servers.
            $type = preg_replace('/\b(bigint)\([0-9]+\)/', '$1', strtolower((string)$actual['column_type']));
            $default = $actual['column_default'] === "'test'" ? 'test' : $actual['column_default'];
            // MariaDB distinguishes SQL NULL (the string NULL here) from a
            // quoted literal 'NULL'; MySQL reports SQL NULL as a PHP null.
            if ($definition[2] === null && is_string($default) && strtoupper($default) === 'NULL') { $default = null; }
            if ($type !== $definition[0] || $actual['is_nullable'] !== $definition[1] || $default !== $definition[2]) {
                throw new AuthException('Takrif medan AI pelanggan memerlukan semakan.', 409);
            }
        }
        $constraints = $this->pdo->prepare("SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema=? AND table_name='ai_reply_settings'");
        $constraints->execute([$schema]);
        if (array_diff(['fk_ai_audience_approver', 'chk_ai_audience_mode', 'chk_ai_audience_approval'], $constraints->fetchAll(PDO::FETCH_COLUMN)) !== []) {
            throw new AuthException('Kawalan persetujuan AI pelanggan belum lengkap.', 409);
        }
        if ($this->pdo->query("SELECT name FROM ai_reply_dispatch_state WHERE name='all'")->fetchColumn() !== 'all') {
            throw new AuthException('Rekod giliran pemproses AI belum lengkap.', 409);
        }
        return ['status' => 'installed', 'checksum' => $checksum, 'columns' => self::COLUMNS, 'table' => self::TABLE];
    }

    public function apply(string $expectedChecksum): array
    {
        if ($this->pdo->inTransaction()) { throw new AuthException('Pemasangan memerlukan sambungan tanpa transaksi aktif.', 409); }
        if ($this->pdo->query("SELECT GET_LOCK('beshare:ai:schema:0007',0)")->fetchColumn() != 1) {
            throw new AuthException('Pemasangan lain sedang berjalan.', 409);
        }
        try {
            $state = $this->inspect();
            if (!hash_equals($state['checksum'], $expectedChecksum)) {
                throw new AuthException('Checksum tidak sepadan. Jalankan semakan pemasangan dahulu.', 409);
            }
            if ($state['status'] === 'installed') { return $state; }
            $statements = SqlScript::statements($this->sql);
            if (count($statements) !== 3
                || preg_match('/\AALTER\s+TABLE\s+ai_reply_settings\s+ADD\s+COLUMN\b/i', $statements[0]) !== 1
                || SqlScript::objectName($statements[1], 'table') !== self::TABLE
                || preg_match('/\AINSERT\s+INTO\s+ai_reply_dispatch_state\b/i', $statements[2]) !== 1) {
                throw new AuthException('Fail skema AI pelanggan tidak sah.', 500);
            }
            foreach ($statements as $statement) { $this->pdo->exec($statement); }
            $record = $this->pdo->prepare('INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,UTC_TIMESTAMP())');
            $record->execute([self::VERSION, 'customer_ai_replies', $state['checksum']]);
            return $this->inspect();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('beshare:ai:schema:0007')");
        }
    }

    private function normalizeColumns(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $result[(string)$row['column_name']] = $row;
        }
        return $result;
    }
}
