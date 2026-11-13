<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** Explicit, additive installation; never executes from an HTTP request. */
final class StripeBillingSchema
{
    public const VERSION = '0009';
    public const TABLES = ['os_billing','os_billing_events'];

    public function __construct(private PDO $pdo, private string $sql) {}

    public function inspect(): array
    {
        $schema = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $columns = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=?');
        foreach ([
            'businesses' => ['id','status','public_id'],
            'schema_migrations' => ['version','checksum'],
        ] as $table => $required) {
            $columns->execute([$schema,$table]);
            if (array_diff($required, $columns->fetchAll(PDO::FETCH_COLUMN)) !== []) {
                throw new AuthException('Skema akaun belum lengkap. Pemasangan pembayaran belum boleh diteruskan.', 409);
            }
        }
        $query = $this->pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_name IN (?,?)');
        $query->execute(array_merge([$schema], self::TABLES));
        $existing = $query->fetchAll(PDO::FETCH_COLUMN);
        $record = $this->pdo->query("SELECT checksum FROM schema_migrations WHERE version='0009'")->fetchColumn();
        $checksum = hash('sha256', $this->sql);
        if ($record !== false) {
            if (!hash_equals((string) $record, $checksum) || count($existing) !== count(self::TABLES)) {
                throw new AuthException('Skema pembayaran tidak sepadan dengan rekod pemasangan.', 409);
            }
            return ['status'=>'installed','checksum'=>$checksum,'tables'=>self::TABLES];
        }
        if ($existing !== []) { throw new AuthException('Pemasangan pembayaran separa dikesan. Tiada jadual dipadam atau diubah.', 409); }
        return ['status'=>'ready','checksum'=>$checksum,'tables'=>self::TABLES];
    }

    public function apply(string $checksum): array
    {
        if ($this->pdo->query("SELECT GET_LOCK('beshare:platform:schema:0009',0)")->fetchColumn() != 1) {
            throw new AuthException('Pemasangan lain sedang berjalan.', 409);
        }
        try {
            $state = $this->inspect();
            if (!hash_equals($state['checksum'], $checksum)) { throw new AuthException('Checksum tidak sepadan. Jalankan semakan pemasangan dahulu.', 409); }
            if ($state['status'] === 'installed') { return $state; }
            $statements = SqlScript::statements($this->sql);
            if (count($statements) !== count(self::TABLES)) { throw new AuthException('Fail skema pembayaran tidak sah.', 500); }
            foreach ($statements as $index => $statement) {
                if (SqlScript::objectName($statement, 'table') !== self::TABLES[$index]) { throw new AuthException('Fail skema pembayaran tidak sah.', 500); }
            }
            foreach ($statements as $statement) { $this->pdo->exec($statement); }
            $record = $this->pdo->prepare('INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,UTC_TIMESTAMP())');
            $record->execute([self::VERSION,'stripe_billing',$checksum]);
            return $this->inspect();
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('beshare:platform:schema:0009')"); }
    }
}
