<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** Owner-reviewed knowledge and original images, encrypted and scoped to one tenant. */
final class KnowledgeCentre
{
    public const ID_PATTERN = '/\A[a-f0-9]{32}\z/';
    public const MEDIA_MAX_BYTES = 2097152;
    private const DOCUMENT_MAX_BYTES = 60000;
    private const MEDIA_MAX_COUNT = 40;
    private const MEDIA_TOTAL_BYTES = 83886080;
    private ?bool $installed = null;

    public function __construct(private PDO $pdo, private TokenSeal $seal)
    {
    }

    public function view(array $actor): array
    {
        $empty = ['available' => false, 'can_manage' => false, 'revision' => 0,
            'published_revision' => 0, 'published_at' => null, 'draft' => self::blank(), 'media' => []];
        if (!$this->available()) { return $empty; }
        $actor = $this->member($actor);
        $canManage = $actor['role'] === 'owner' && $actor['user_id'] === $actor['owner_user_id'] && $actor['status'] === 'active';
        $empty['available'] = true;
        $empty['can_manage'] = $canManage;
        if (!$canManage) { return $empty; }
        $row = $this->centre((int) $actor['business_id']);
        if ($row !== null) {
            $empty['revision'] = (int) $row['revision'];
            $empty['published_revision'] = (int) ($row['published_revision'] ?? 0);
            $empty['published_at'] = $row['published_at'];
            $empty['draft'] = $this->openDocument($row, 'draft', (int) $actor['business_id']);
        } else {
            $settings = $this->pdo->prepare('SELECT * FROM ai_reply_settings WHERE business_id=?');
            $settings->execute([$actor['business_id']]);
            $legacy = $settings->fetch();
            $empty['draft'] = $this->initialDocument(is_array($legacy) ? $legacy : null);
        }
        $media = $this->pdo->prepare('SELECT id,mime,size FROM knowledge_media WHERE business_id=? ORDER BY created_at DESC,id LIMIT 40');
        $media->execute([$actor['business_id']]);
        foreach ($media->fetchAll() as $item) { $empty['media'][] = $this->mediaRecord($item); }
        return $empty;
    }

    /** Imports are read-only but require the same current owner authorization as edits. */
    public function assertCanManage(array $actor): void
    {
        $this->owner($actor);
    }

    /** A stale tab must not apply its draft or file after another tab changes the active tenant. */
    public function assertRequestScope(array $actor, mixed $expectedPublicId): void
    {
        $actor = $this->member($actor);
        if (!is_string($expectedPublicId) || !hash_equals($actor['public_id'], $expectedPublicId)) {
            throw new AuthException('Perniagaan aktif telah berubah. Muat semula sebelum meneruskan.', 409);
        }
    }

    public function saveDraft(array $actor, array $input): array
    {
        $actor = $this->owner($actor);
        $expected = $this->expectedRevision($input);
        $document = $this->document($input['document'] ?? null);
        $businessId = (int) $actor['business_id'];
        $plain = $this->encodeDocument($businessId, $document);
        $sealed = $this->seal->seal($plain, self::DOCUMENT_MAX_BYTES + 128);
        $this->write($actor, function (?array $settings, ?array $row) use ($actor, $expected, $document, $plain, $sealed): void {
            if (($row === null ? 0 : (int) $row['revision']) !== $expected) {
                throw new AuthException('Draf telah berubah. Muat semula sebelum menyimpan.', 409);
            }
            $this->validateMediaReferences((int) $actor['business_id'], $document);
            $values = [$expected + 1, $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version'],
                hash('sha256', $plain), $actor['user_id'], $actor['business_id']];
            if ($row === null) {
                $this->execute('INSERT INTO knowledge_centres (revision,draft_ciphertext,draft_nonce,draft_tag,draft_key_version,draft_hash,updated_by,business_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', $values, [1, 2, 3]);
            } else {
                $this->execute('UPDATE knowledge_centres SET revision=?,draft_ciphertext=?,draft_nonce=?,draft_tag=?,draft_key_version=?,draft_hash=?,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE business_id=?', $values, [1, 2, 3]);
            }
            $this->audit($actor, 'pengetahuan_draf_disimpan', ['revision' => $expected + 1]);
        });
        return $this->view($actor);
    }

    public function publish(array $actor, array $input): array
    {
        $actor = $this->owner($actor);
        $expected = $this->expectedRevision($input);
        if (($input['approved'] ?? false) !== true) {
            throw new AuthException('Sahkan kandungan sebelum menerbitkan pengetahuan.', 400);
        }
        $this->write($actor, function (?array $settings, ?array $row) use ($actor, $input, $expected): void {
            if ($row === null || (int) $row['revision'] !== $expected) {
                throw new AuthException('Draf telah berubah. Simpan dan muat semula sebelum menerbitkan.', 409);
            }
            $connectionId = null;
            if ($settings === null) {
                $connectionId = $input['connection_id'] ?? null;
                if (!is_int($connectionId) || $connectionId < 1) {
                    throw new AuthException('Pilih sambungan WhatsApp untuk penerbitan pertama.', 400);
                }
                $connection = $this->pdo->prepare('SELECT id FROM whatsapp_connections WHERE business_id=? AND id=? AND disconnected_at IS NULL FOR UPDATE');
                $connection->execute([$actor['business_id'], $connectionId]);
                if (!$connection->fetch()) { throw new AuthException('Sambungan tidak dijumpai dalam perniagaan ini.', 404); }
            }
            $document = $this->openDocument($row, 'draft', (int) $actor['business_id']);
            if ($document['payment']['enabled'] && ($input['payment_confirmed'] ?? false) !== true) {
                throw new AuthException('Sahkan butiran bank dan gambar QR sebelum menerbitkan pembayaran.', 400);
            }
            $compiled = $this->compile($document);
            $this->validateMediaReferences((int) $actor['business_id'], $document);
            $nextRevision = $settings === null ? 1 : (int) $settings['knowledge_revision'] + 1;
            if ($nextRevision < 1 || !is_int($nextRevision)) { throw new AuthException('Revisi pengetahuan tidak sah.', 409); }
            $sealed = $this->seal->seal($compiled, 8000);
            // The settings row is locked first. This update never enables AI or resets activation/quota.
            $values = [$sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version'], $nextRevision,
                hash('sha256', $compiled), $actor['user_id'], $actor['business_id']];
            if ($settings === null) {
                $this->execute("INSERT INTO ai_reply_settings (knowledge_ciphertext,knowledge_nonce,knowledge_tag,knowledge_key_version,knowledge_revision,knowledge_hash,approved_by,business_id,connection_id,enabled,allowlist_json,daily_limit,quota_used,activation_at,approved_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,'[]',20,0,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    array_merge($values, [$connectionId]), [0, 1, 2]);
            } else {
                $this->execute('UPDATE ai_reply_settings SET knowledge_ciphertext=?,knowledge_nonce=?,knowledge_tag=?,knowledge_key_version=?,knowledge_revision=?,knowledge_hash=?,approved_by=?,approved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE business_id=?',
                    $values, [0, 1, 2]);
            }
            $update = $this->pdo->prepare('UPDATE knowledge_centres SET published_revision=revision,published_ciphertext=draft_ciphertext,published_nonce=draft_nonce,published_tag=draft_tag,published_key_version=draft_key_version,published_hash=draft_hash,compiled_hash=?,knowledge_revision=?,published_by=?,published_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE business_id=?');
            $update->execute([hash('sha256', $compiled), $nextRevision, $actor['user_id'], $actor['business_id']]);
            $this->audit($actor, 'pengetahuan_diterbitkan', ['revision' => $expected, 'knowledge_revision' => $nextRevision]);
        });
        return $this->view($actor);
    }

    /** The worker holds its existing settings lock while reading the active snapshot. */
    public function runtime(int $businessId): array
    {
        if ($businessId < 1 || !$this->available()) { return []; }
        $statement = $this->pdo->prepare('SELECT * FROM knowledge_centres WHERE business_id=?' . ($this->pdo->inTransaction() ? ' FOR SHARE' : ''));
        $statement->execute([$businessId]);
        $row = $statement->fetch();
        if (!is_array($row) || $row['published_revision'] === null) { return []; }
        $document = $this->openDocument($row, 'published', $businessId);
        if (!is_string($row['compiled_hash']) || !hash_equals($row['compiled_hash'], hash('sha256', $this->compile($document)))) {
            throw new AuthException('Pengetahuan diterbitkan tidak dapat disahkan.', 500);
        }
        $document['_published_revision'] = (int) $row['published_revision'];
        $document['_knowledge_revision'] = (int) $row['knowledge_revision'];
        $document['_compiled_hash'] = $row['compiled_hash'];
        return $document;
    }

    /** Called under the settings lock, so the legacy controls cannot overwrite a publication. */
    public function assertLegacyKnowledge(int $businessId, string $knowledge): void
    {
        $active = $this->runtime($businessId);
        if ($active !== [] && !hash_equals($active['_compiled_hash'], hash('sha256', $knowledge))) {
            throw new AuthException('Ubah fakta melalui Pusat Pengetahuan, kemudian terbitkan draf tersebut.', 409);
        }
    }

    public function uploadMedia(array $actor, string $bytes): array
    {
        $actor = $this->owner($actor);
        $mime = $this->imageMime($bytes);
        $id = bin2hex(random_bytes(16));
        $businessId = (int) $actor['business_id'];
        $sealed = $this->seal->seal($this->mediaPrefix($businessId, $id) . $bytes, self::MEDIA_MAX_BYTES + 128);
        $this->write($actor, function (?array $settings, ?array $row) use ($actor, $bytes, $mime, $id, $sealed): void {
            if ($row === null) { $this->createEmpty($actor, $settings); }
            // Every upload for this tenant holds the centre/business locks, including the first.
            $quota = $this->pdo->prepare('SELECT COUNT(*) AS count,COALESCE(SUM(size),0) AS bytes FROM knowledge_media WHERE business_id=?');
            $quota->execute([$actor['business_id']]);
            $used = $quota->fetch();
            if ((int) $used['count'] >= self::MEDIA_MAX_COUNT || (int) $used['bytes'] + strlen($bytes) > self::MEDIA_TOTAL_BYTES) {
                throw new AuthException('Had pustaka ialah 40 gambar dan 80 MiB bagi setiap perniagaan.', 409);
            }
            $this->execute('INSERT INTO knowledge_media (id,business_id,mime,size,sha256,body_ciphertext,body_nonce,body_tag,body_key_version,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
                [$id, $actor['business_id'], $mime, strlen($bytes), hash('sha256', $bytes), $sealed['ciphertext'],
                    $sealed['nonce'], $sealed['tag'], $sealed['version'], $actor['user_id']], [5, 6, 7]);
            $this->audit($actor, 'pengetahuan_gambar_disimpan', ['media_id' => $id, 'bytes' => strlen($bytes)]);
        });
        return $this->mediaRecord(['id' => $id, 'mime' => $mime, 'size' => strlen($bytes)]);
    }

    /** Preview permission comes from the current session membership, never a request tenant ID. */
    public function previewMedia(array $actor, string $mediaId): array
    {
        $actor = $this->member($actor);
        if ($actor['status'] !== 'active') { throw new AuthException('Perniagaan tidak aktif.', 403); }
        return $this->mediaForWorker((int) $actor['business_id'], $mediaId);
    }

    /** The worker separately re-resolves this opaque ID from the current published document. */
    public function mediaForWorker(int $businessId, string $mediaId): array
    {
        if ($businessId < 1 || preg_match(self::ID_PATTERN, $mediaId) !== 1 || !$this->available()) {
            throw new AuthException('Gambar tidak dijumpai.', 404);
        }
        $statement = $this->pdo->prepare('SELECT * FROM knowledge_media WHERE business_id=? AND id=?' . ($this->pdo->inTransaction() ? ' FOR SHARE' : ''));
        $statement->execute([$businessId, $mediaId]);
        $row = $statement->fetch();
        if (!is_array($row)) { throw new AuthException('Gambar tidak dijumpai.', 404); }
        $plain = $this->seal->open($row['body_ciphertext'], $row['body_nonce'], $row['body_tag'], (int) $row['body_key_version']);
        $prefix = $this->mediaPrefix($businessId, $mediaId);
        if (!str_starts_with($plain, $prefix)) { throw new AuthException('Gambar tidak dapat disahkan.', 500); }
        $bytes = substr($plain, strlen($prefix));
        if (strlen($bytes) !== (int) $row['size'] || !hash_equals((string) $row['sha256'], hash('sha256', $bytes))
            || !in_array($row['mime'], ['image/png', 'image/jpeg'], true) || strlen($bytes) > self::MEDIA_MAX_BYTES) {
            throw new AuthException('Gambar tidak dapat disahkan.', 500);
        }
        return ['bytes' => $bytes, 'mime' => $row['mime']];
    }

    private function available(): bool
    {
        if ($this->installed !== null) { return $this->installed; }
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('knowledge_centres','knowledge_media')")->fetchColumn();
        $record = $this->pdo->query("SELECT checksum FROM schema_migrations WHERE version='0005'")->fetchColumn();
        if ($count === 0 && $record === false) { return $this->installed = false; }
        $expected = hash_file('sha256', dirname(__DIR__) . '/migrations/0005_knowledge_centre.up.sql');
        if ($count !== 2 || !is_string($record) || !is_string($expected) || !hash_equals($expected, $record)) {
            throw new AuthException('Pemasangan Pusat Pengetahuan belum lengkap. Semakan pelayan diperlukan.', 503);
        }
        return $this->installed = true;
    }

    private function owner(array $actor): array
    {
        $actor = $this->member($actor);
        if ($actor['role'] !== 'owner' || $actor['user_id'] !== $actor['owner_user_id'] || $actor['status'] !== 'active') {
            throw new AuthException('Hanya pemilik perniagaan aktif boleh mengurus pengetahuan.', 403);
        }
        if (!$this->available()) { throw new AuthException('Pusat Pengetahuan belum tersedia.', 409); }
        return $actor;
    }

    private function member(array $actor): array
    {
        return Membership::require($this->pdo, (int) ($actor['user_id'] ?? 0), (int) ($actor['business_id'] ?? 0));
    }

    private function write(array $actor, callable $operation): void
    {
        $this->pdo->beginTransaction();
        try {
            // Same first lock as the reply worker and AI controls. Publication is one transaction.
            $settings = $this->pdo->prepare('SELECT * FROM ai_reply_settings WHERE business_id=? FOR UPDATE');
            $settings->execute([$actor['business_id']]);
            $lockedSettings = $settings->fetch();
            $owner = $this->pdo->prepare("SELECT b.id FROM businesses b INNER JOIN business_users bu ON bu.business_id=b.id AND bu.user_id=b.owner_user_id INNER JOIN users u ON u.id=bu.user_id WHERE b.id=? AND b.owner_user_id=? AND b.status='active' AND bu.role='owner' AND u.status='active' FOR UPDATE");
            $owner->execute([$actor['business_id'], $actor['user_id']]);
            if (!$owner->fetch()) { throw new AuthException('Pemilikan perniagaan telah berubah.', 403); }
            $row = $this->centre((int) $actor['business_id'], true);
            $operation(is_array($lockedSettings) ? $lockedSettings : null, $row);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function centre(int $businessId, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM knowledge_centres WHERE business_id=?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$businessId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function createEmpty(array $actor, ?array $settings): void
    {
        $plain = $this->encodeDocument((int) $actor['business_id'], $this->initialDocument($settings));
        $sealed = $this->seal->seal($plain, self::DOCUMENT_MAX_BYTES + 128);
        $this->execute('INSERT INTO knowledge_centres (business_id,revision,draft_ciphertext,draft_nonce,draft_tag,draft_key_version,draft_hash,updated_by,created_at,updated_at) VALUES (?,0,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            [$actor['business_id'], $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version'], hash('sha256', $plain), $actor['user_id']], [1, 2, 3]);
    }

    /** Existing approved text becomes an editable first draft, never an implicit publication. */
    private function initialDocument(?array $settings): array
    {
        $document = self::blank();
        if ($settings !== null && $settings['knowledge_ciphertext'] !== null) {
            $facts = $this->seal->open($settings['knowledge_ciphertext'], $settings['knowledge_nonce'],
                $settings['knowledge_tag'], (int) $settings['knowledge_key_version']);
            if (strlen($facts) > 8000 || preg_match('//u', $facts) !== 1
                || !hash_equals((string) $settings['knowledge_hash'], hash('sha256', $facts))) {
                throw new AuthException('Fakta sedia ada tidak dapat disahkan.', 500);
            }
            $document['facts'] = $facts;
        }
        return $document;
    }

    private function expectedRevision(array $input): int
    {
        $revision = $input['expected_revision'] ?? null;
        if (!is_int($revision) || $revision < 0 || $revision >= PHP_INT_MAX) {
            throw new AuthException('Revisi draf tidak sah. Muat semula halaman.', 400);
        }
        return $revision;
    }

    private static function blank(): array
    {
        return ['facts' => '', 'sources' => [], 'assets' => [], 'payment' => ['enabled' => false,
            'bank_name' => '', 'account_name' => '', 'account_number' => '', 'instructions' => '', 'qr_media_id' => '']];
    }

    private function document(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['facts', 'sources', 'assets', 'payment']) !== []) {
            throw new AuthException('Dokumen pengetahuan tidak sah.', 400);
        }
        $out = self::blank();
        $out['facts'] = $this->field($value, 'facts', 8000);
        $sources = $value['sources'] ?? [];
        $assets = $value['assets'] ?? [];
        if (!is_array($sources) || !array_is_list($sources) || count($sources) > 8
            || !is_array($assets) || !array_is_list($assets) || count($assets) > 20) {
            throw new AuthException('Had draf ialah 8 sumber dan 20 item gambar.', 400);
        }
        $ids = [];
        foreach ($sources as $source) {
            if (!is_array($source) || array_diff(array_keys($source), ['id', 'kind', 'url', 'title', 'text', 'enabled']) !== []) {
                throw new AuthException('Sumber pengetahuan tidak sah.', 400);
            }
            $id = $this->itemId($source, 'id');
            if (isset($ids[$id])) { throw new AuthException('ID item draf mesti unik.', 400); }
            $ids[$id] = true;
            $kind = $source['kind'] ?? '';
            if (!is_string($kind) || !in_array($kind, ['website', 'landing', 'facebook', 'ads'], true)) {
                throw new AuthException('Jenis sumber tidak sah.', 400);
            }
            $url = $this->field($source, 'url', 2048, true);
            if ($url !== '') { $this->sourceUrl($url); }
            $out['sources'][] = ['id' => $id, 'kind' => $kind, 'url' => $url,
                'title' => $this->field($source, 'title', 160, true), 'text' => $this->field($source, 'text', 8000),
                'enabled' => $this->flag($source, 'enabled')];
        }
        foreach ($assets as $asset) {
            if (!is_array($asset) || array_diff(array_keys($asset), ['id', 'category', 'title', 'facts', 'caption', 'when_to_send', 'media_id', 'enabled']) !== []) {
                throw new AuthException('Item gambar tidak sah.', 400);
            }
            $id = $this->itemId($asset, 'id');
            if (isset($ids[$id])) { throw new AuthException('ID item draf mesti unik.', 400); }
            $ids[$id] = true;
            $category = $asset['category'] ?? '';
            if (!is_string($category) || !in_array($category, ['product', 'combo', 'reference'], true)) {
                throw new AuthException('Kategori gambar tidak sah.', 400);
            }
            $out['assets'][] = ['id' => $id, 'category' => $category, 'title' => $this->field($asset, 'title', 160, true),
                'facts' => $this->field($asset, 'facts', 2000), 'caption' => $this->field($asset, 'caption', 1000),
                'when_to_send' => $this->field($asset, 'when_to_send', 400), 'media_id' => $this->itemId($asset, 'media_id', true),
                'enabled' => $this->flag($asset, 'enabled')];
        }
        $payment = $value['payment'] ?? [];
        if (!is_array($payment) || array_diff(array_keys($payment), ['enabled', 'bank_name', 'account_name', 'account_number', 'instructions', 'qr_media_id']) !== []) {
            throw new AuthException('Butiran pembayaran tidak sah.', 400);
        }
        $out['payment'] = ['enabled' => $this->flag($payment, 'enabled'),
            'bank_name' => $this->field($payment, 'bank_name', 100, true),
            'account_name' => $this->field($payment, 'account_name', 160, true),
            'account_number' => $this->field($payment, 'account_number', 64, true),
            'instructions' => $this->field($payment, 'instructions', 400),
            'qr_media_id' => $this->itemId($payment, 'qr_media_id', true)];
        if (strlen(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::DOCUMENT_MAX_BYTES) {
            throw new AuthException('Keseluruhan draf melebihi had 60,000 bait. Ringkaskan kandungan sumber.', 400);
        }
        return $out;
    }

    private function field(array $input, string $key, int $max, bool $singleLine = false): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1
            || ($singleLine && preg_match('/[\r\n\t]/', $value) === 1)) {
            throw new AuthException('Medan pengetahuan tidak sah atau melebihi had: ' . $key . '.', 400);
        }
        return trim($value);
    }

    private function flag(array $input, string $key): bool
    {
        $value = $input[$key] ?? false;
        if (!is_bool($value)) { throw new AuthException('Pilihan pengetahuan tidak sah.', 400); }
        return $value;
    }

    private function itemId(array $input, string $key, bool $optional = false): string
    {
        $id = $input[$key] ?? '';
        if (!is_string($id) || (!($optional && $id === '') && preg_match(self::ID_PATTERN, $id) !== 1)) {
            throw new AuthException('ID item pengetahuan tidak sah.', 400);
        }
        return $id;
    }

    private function sourceUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/', $host) !== 1
            || preg_match('/\.(?:localhost|local|internal|home|onion|invalid|test|example)\z/', $host) === 1
            || preg_match('/[\s\\\\]/', $url) === 1) {
            throw new AuthException('Gunakan pautan HTTPS awam tanpa kata laluan atau alamat IP.', 400);
        }
    }

    private function compile(array $document): string
    {
        $parts = [];
        if ($document['facts'] !== '') { $parts[] = $document['facts']; }
        foreach ($document['sources'] as $source) {
            if (!$source['enabled']) { continue; }
            if ($source['url'] === '' || $source['title'] === '' || $source['text'] === '') {
                throw new AuthException('Lengkapkan tajuk, pautan dan teks setiap sumber yang diaktifkan.', 400);
            }
            $parts[] = 'Sumber disahkan: ' . $source['title'] . "\nPautan: " . $source['url'] . "\n" . $source['text'];
        }
        foreach ($document['assets'] as $asset) {
            if (!$asset['enabled']) { continue; }
            if ($asset['title'] === '' || $asset['media_id'] === '' || $asset['when_to_send'] === '') {
                throw new AuthException('Lengkapkan tajuk, gambar dan kegunaan setiap item yang diaktifkan.', 400);
            }
            $parts[] = 'Gambar diluluskan: ' . $asset['title'] . ($asset['facts'] === '' ? '' : "\n" . $asset['facts'])
                . "\nBila sesuai dihantar: " . $asset['when_to_send'];
        }
        if ($document['payment']['enabled']) {
            foreach (['bank_name', 'account_name', 'account_number'] as $field) {
                if ($document['payment'][$field] === '') { throw new AuthException('Lengkapkan nama bank, nama akaun dan nombor akaun pembayaran.', 400); }
            }
            $parts[] = 'Maklumat pembayaran yang diluluskan tersedia. Gunakan butiran pembayaran berstruktur apabila pelanggan meminta cara membayar.';
        }
        $compiled = trim(implode("\n\n", $parts));
        if ($compiled === '' || strlen($compiled) > 8000) {
            throw new AuthException('Fakta yang diterbitkan mesti berisi dan tidak melebihi 8,000 bait. Ringkaskan fakta atau nyahaktifkan sumber.', 400);
        }
        return $compiled;
    }

    private function validateMediaReferences(int $businessId, array $document): void
    {
        $ids = [];
        foreach ($document['assets'] as $asset) { if ($asset['media_id'] !== '') { $ids[] = $asset['media_id']; } }
        if ($document['payment']['qr_media_id'] !== '') { $ids[] = $document['payment']['qr_media_id']; }
        $ids = array_values(array_unique($ids));
        if ($ids === []) { return; }
        $statement = $this->pdo->prepare('SELECT id FROM knowledge_media WHERE business_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR SHARE');
        $statement->execute(array_merge([$businessId], $ids));
        if (array_diff($ids, $statement->fetchAll(PDO::FETCH_COLUMN)) !== []) {
            throw new AuthException('Gambar mesti dimuat naik dalam perniagaan ini dahulu.', 400);
        }
    }

    private function encodeDocument(int $businessId, array $document): string
    {
        return json_encode(['business_id' => $businessId, 'document' => $document], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function openDocument(array $row, string $prefix, int $businessId): array
    {
        try {
            $plain = $this->seal->open($row[$prefix . '_ciphertext'], $row[$prefix . '_nonce'], $row[$prefix . '_tag'], (int) $row[$prefix . '_key_version']);
            if (strlen($plain) > self::DOCUMENT_MAX_BYTES + 128 || !hash_equals((string) $row[$prefix . '_hash'], hash('sha256', $plain))) {
                throw new \RuntimeException();
            }
            $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded['business_id'] ?? null) !== $businessId) { throw new \RuntimeException(); }
            return $this->document($decoded['document'] ?? null);
        } catch (\Throwable) {
            throw new AuthException('Dokumen pengetahuan tidak dapat dibaca atau disahkan.', 500);
        }
    }

    private function imageMime(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::MEDIA_MAX_BYTES) { throw new AuthException('Muat naik gambar JPEG atau PNG sehingga 2 MiB.', 400); }
        if (!function_exists('getimagesizefromstring') || !class_exists(\finfo::class)) {
            throw new AuthException('Pengesahan gambar belum tersedia pada pelayan.', 503);
        }
        $image = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_array($image) || !in_array($image[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || !in_array($mime, ['image/jpeg', 'image/png'], true) || ($image['mime'] ?? null) !== $mime
            || $image[0] < 1 || $image[1] < 1 || $image[0] > 8192 || $image[1] > 8192 || $image[0] * $image[1] > 16777216) {
            throw new AuthException('Fail mestilah gambar JPEG atau PNG sah dengan saiz piksel yang munasabah.', 400);
        }
        return $mime;
    }

    private function mediaPrefix(int $businessId, string $id): string
    {
        return "BESHARE_KNOWLEDGE_MEDIA\n" . $businessId . "\n" . $id . "\n";
    }

    private function mediaRecord(array $row): array
    {
        return ['id' => $row['id'], 'mime' => $row['mime'], 'size' => (int) $row['size'],
            'preview_url' => '/whatsapp/akaun/knowledge-media.php?id=' . rawurlencode($row['id'])];
    }

    private function execute(string $sql, array $values, array $binaryIndexes): void
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($values as $index => $value) {
            $type = in_array($index, $binaryIndexes, true) ? PDO::PARAM_LOB : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();
    }

    private function audit(array $actor, string $action, array $meta): void
    {
        $statement = $this->pdo->prepare("INSERT INTO audit_logs (business_id,actor_user_id,actor_kind,action,entity_type,entity_id,meta_json,created_at) VALUES (?,?,'user',?,'business',?,?,UTC_TIMESTAMP())");
        $statement->execute([$actor['business_id'], $actor['user_id'], $action, $actor['business_id'], json_encode($meta, JSON_THROW_ON_ERROR)]);
    }
}
