<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** Installs only the four additive AI tables. Never alters or drops existing data. */
final class AiReplySchema
{
    public const VERSION = '0004';
    public const TABLES = ['ai_reply_settings', 'ai_conversations', 'ai_messages', 'ai_reply_jobs'];

    public function __construct(private PDO $pdo, private string $sql)
    {
    }

    public function inspect(): array
    {
        $schema = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $columns = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
        foreach ([
            'whatsapp_connections' => ['business_id', 'id', 'sync_contacts', 'fields_ready_at'],
            'webhook_events' => ['business_id', 'connection_id', 'payload_ciphertext', 'payload_purged_at'],
            'coexistence_sync_jobs' => ['claim_token', 'claimed_until'],
            'schema_migrations' => ['version', 'checksum'],
        ] as $table => $required) {
            $columns->execute([$schema, $table]);
            if (array_diff($required, $columns->fetchAll(PDO::FETCH_COLUMN)) !== []) {
                throw new AuthException('Skema asas 0001–0003 belum lengkap. Tiada perubahan dibuat.', 409);
            }
        }
        $statement = $this->pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name IN (?,?,?,?)');
        $statement->execute(array_merge([$schema], self::TABLES));
        $existing = $statement->fetchAll(PDO::FETCH_COLUMN);
        $version = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
        $version->execute([self::VERSION]);
        $recorded = $version->fetchColumn();
        $checksum = hash('sha256', $this->sql);
        if ($recorded !== false) {
            if (!hash_equals((string) $recorded, $checksum) || count($existing) !== 4) {
                throw new AuthException('Skema AI tidak sepadan dengan rekod pemasangan. Semakan diperlukan.', 409);
            }
            return ['status' => 'installed', 'checksum' => $checksum, 'tables' => self::TABLES];
        }
        if ($existing !== []) {
            throw new AuthException('Pemasangan AI separa dikesan. Tiada jadual dipadam atau diubah.', 409);
        }
        return ['status' => 'ready', 'checksum' => $checksum, 'tables' => self::TABLES];
    }

    public function apply(string $expectedChecksum): array
    {
        if ($this->pdo->query("SELECT GET_LOCK('beshare:ai:schema:0004',0)")->fetchColumn() != 1) {
            throw new AuthException('Pemasangan lain sedang berjalan.', 409);
        }
        try {
            $state = $this->inspect();
            if (!hash_equals($state['checksum'], $expectedChecksum)) {
                throw new AuthException('Semakan awal telah berubah. Jalankan semakan semula.', 409);
            }
            if ($state['status'] === 'installed') {
                return $state;
            }
            $statements = SqlScript::statements($this->sql);
            if (count($statements) !== count(self::TABLES)) {
                throw new AuthException('Fail skema AI tidak sah.', 500);
            }
            foreach ($statements as $index => $statement) {
                if (SqlScript::objectName($statement, 'table') !== self::TABLES[$index]) {
                    throw new AuthException('Fail skema AI tidak sah.', 500);
                }
            }
            // MySQL DDL commits implicitly. A partial failure is detected on the next preflight;
            // no automatic DROP or foundation replay is attempted.
            foreach ($statements as $statement) {
                $this->pdo->exec($statement);
            }
            $record = $this->pdo->prepare('INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,UTC_TIMESTAMP())');
            $record->execute([self::VERSION, 'owner_ai_replies', $state['checksum']]);
            return $this->inspect();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('beshare:ai:schema:0004')");
        }
    }
}
