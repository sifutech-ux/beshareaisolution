<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** Two additive tables only; never replays, alters or drops existing application tables. */
final class KnowledgeSchema
{
    public const VERSION = '0005';
    public const TABLES = ['knowledge_centres', 'knowledge_media'];

    public function __construct(private PDO $pdo, private string $sql)
    {
    }

    public function inspect(): array
    {
        $schema = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $columns = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=?');
        foreach ([
            'businesses' => ['id', 'owner_user_id', 'status'],
            'users' => ['id', 'status'],
            'ai_reply_settings' => ['business_id', 'knowledge_revision', 'knowledge_hash', 'activation_at', 'quota_used'],
            'schema_migrations' => ['version', 'checksum'],
        ] as $table => $required) {
            $columns->execute([$schema, $table]);
            if (array_diff($required, $columns->fetchAll(PDO::FETCH_COLUMN)) !== []) {
                throw new AuthException('Skema asas hingga 0004 belum lengkap. Tiada perubahan dibuat.', 409);
            }
        }
        $previous = $this->pdo->query("SELECT checksum FROM schema_migrations WHERE version='0004'")->fetchColumn();
        $expectedPrevious = hash_file('sha256', dirname(__DIR__) . '/migrations/0004_ai_replies.up.sql');
        if (!is_string($previous) || !is_string($expectedPrevious) || !hash_equals($previous, $expectedPrevious)) {
            throw new AuthException('Rekod skema AI 0004 tidak sepadan. Semakan diperlukan.', 409);
        }
        $statement = $this->pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_name IN (?,?)');
        $statement->execute(array_merge([$schema], self::TABLES));
        $existing = $statement->fetchAll(PDO::FETCH_COLUMN);
        $version = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
        $version->execute([self::VERSION]);
        $recorded = $version->fetchColumn();
        $checksum = hash('sha256', $this->sql);
        if ($recorded !== false) {
            if (!hash_equals((string) $recorded, $checksum) || count($existing) !== count(self::TABLES)) {
                throw new AuthException('Skema Pusat Pengetahuan tidak sepadan dengan rekod pemasangan.', 409);
            }
            return ['status' => 'installed', 'checksum' => $checksum, 'tables' => self::TABLES];
        }
        if ($existing !== []) {
            throw new AuthException('Pemasangan Pusat Pengetahuan separa dikesan. Tiada jadual dipadam atau diubah.', 409);
        }
        return ['status' => 'ready', 'checksum' => $checksum, 'tables' => self::TABLES];
    }

    public function apply(string $expectedChecksum): array
    {
        if ($this->pdo->query("SELECT GET_LOCK('beshare:knowledge:schema:0005',0)")->fetchColumn() != 1) {
            throw new AuthException('Pemasangan lain sedang berjalan.', 409);
        }
        try {
            $state = $this->inspect();
            if (!hash_equals($state['checksum'], $expectedChecksum)) {
                throw new AuthException('Semakan awal telah berubah. Jalankan semakan semula.', 409);
            }
            if ($state['status'] === 'installed') { return $state; }
            $statements = SqlScript::statements($this->sql);
            if (count($statements) !== count(self::TABLES)) { throw new AuthException('Fail skema pengetahuan tidak sah.', 500); }
            foreach ($statements as $index => $statement) {
                if (SqlScript::objectName($statement, 'table') !== self::TABLES[$index]) {
                    throw new AuthException('Fail skema pengetahuan tidak sah.', 500);
                }
            }
            // DDL commits implicitly in MySQL. A partial install stops the next inspection.
            foreach ($statements as $statement) { $this->pdo->exec($statement); }
            $record = $this->pdo->prepare('INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,UTC_TIMESTAMP())');
            $record->execute([self::VERSION, 'knowledge_centre', $state['checksum']]);
            return $this->inspect();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('beshare:knowledge:schema:0005')");
        }
    }
}
