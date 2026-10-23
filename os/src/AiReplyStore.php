<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** Tenant-bound settings and durable conversation view for the owner's pilot. */
final class AiReplyStore
{
    private ?bool $installed = null;

    public function __construct(private PDO $pdo, private TokenSeal $seal, private array $config)
    {
    }

    public function view(array $actor): array
    {
        if (!$this->available($actor)) {
            return ['available' => false, 'enabled' => false, 'can_manage' => false];
        }
        $actor = $this->member($actor);
        $row = $this->settings((int) $actor['business_id']);
        $canManage = $actor['role'] === 'owner' && $actor['user_id'] === $actor['owner_user_id'];
        $connections = $this->pdo->prepare('SELECT id,display_phone,token_state,webhook_state,registration_state FROM whatsapp_connections WHERE business_id = ? AND disconnected_at IS NULL ORDER BY id');
        $connections->execute([$actor['business_id']]);
        $view = [
            'available' => true,
            'can_manage' => $canManage,
            'enabled' => $row !== null && (int) $row['enabled'] === 1,
            'deployment_ready' => $this->deploymentReady(),
            'revision' => $row === null ? 0 : (int) $row['knowledge_revision'],
            'connection_id' => $row === null ? 0 : (int) $row['connection_id'],
            'daily_limit' => $row === null ? 20 : (int) $row['daily_limit'],
            'quota_used' => $row !== null && $row['quota_date'] === gmdate('Y-m-d') ? (int) $row['quota_used'] : 0,
            'approved_at' => $row['approved_at'] ?? null,
            'connections' => $connections->fetchAll(),
        ];
        if ($canManage) {
            $view['knowledge'] = $row !== null && $row['knowledge_ciphertext'] !== null ? $this->open($row, 'knowledge') : '';
            $view['allowlist'] = $row === null ? [] : (json_decode((string) $row['allowlist_json'], true) ?: []);
        }
        return $view;
    }

    public function save(array $actor, array $input): array
    {
        $actor = $this->owner($actor);
        $connectionId = $input['connection_id'] ?? null;
        $revision = $input['expected_revision'] ?? null;
        $limit = $input['daily_limit'] ?? null;
        $enabled = $input['enabled'] ?? null;
        $knowledge = $input['knowledge'] ?? null;
        if (!is_int($connectionId) || $connectionId < 1 || !is_int($revision) || $revision < 0
            || !is_int($limit) || $limit < 1 || $limit > 1000 || !is_bool($enabled)
            || !is_string($knowledge) || trim($knowledge) === '' || strlen($knowledge) > 8000
            || preg_match('//u', $knowledge) !== 1 || ($input['approved'] ?? false) !== true) {
            throw new AuthException('Sahkan fakta perniagaan, nombor ujian dan had balasan sebelum menyimpan.', 400);
        }
        $knowledge = trim($knowledge);
        $allowlist = $this->allowlist($input['allowlist'] ?? null);
        if ($enabled && !$this->deploymentReady()) {
            throw new AuthException('Pengaktifan AI pada pelayan belum tersedia. Fakta boleh disimpan dengan AI dimatikan.', 409);
        }
        $sealed = $this->seal->seal($knowledge, 8000);
        $this->pdo->beginTransaction();
        try {
            $old = $this->settings((int) $actor['business_id'], true);
            $this->lockOwner($actor);
            $connection = $this->pdo->prepare('SELECT id,display_phone,token_state,webhook_state,registration_state,token_expires_at FROM whatsapp_connections WHERE business_id = ? AND id = ? AND disconnected_at IS NULL FOR UPDATE');
            $connection->execute([$actor['business_id'], $connectionId]);
            $target = $connection->fetch();
            if (!is_array($target)) {
                throw new AuthException('Sambungan tidak dijumpai dalam perniagaan ini.', 404);
            }
            if (in_array(preg_replace('/\D/', '', (string) $target['display_phone']), $allowlist, true)) {
                throw new AuthException('Nombor ujian mestilah nombor lain daripada nombor perniagaan.', 400);
            }
            if ($enabled && ($target['token_state'] !== 'verified' || $target['webhook_state'] !== 'subscribed'
                || $target['registration_state'] !== 'registered'
                || ($target['token_expires_at'] !== null && strtotime($target['token_expires_at'] . ' UTC') <= time()))) {
                throw new AuthException('Sambungan belum bersedia untuk balasan AI.', 409);
            }
            if (($old === null ? 0 : (int) $old['knowledge_revision']) !== $revision) {
                throw new AuthException('Tetapan telah berubah. Muat semula sebelum menyimpan.', 409);
            }
            $now = gmdate('Y-m-d H:i:s');
            $values = [
                $connectionId, $enabled ? 1 : 0, bin2hex($sealed['ciphertext']), bin2hex($sealed['nonce']),
                bin2hex($sealed['tag']), $sealed['version'], $revision + 1, hash('sha256', $knowledge),
                $actor['user_id'], $now, json_encode($allowlist, JSON_THROW_ON_ERROR), $limit,
                $enabled ? $now : null, $now,
            ];
            if ($old === null) {
                $statement = $this->pdo->prepare('INSERT INTO ai_reply_settings (connection_id,enabled,knowledge_ciphertext,knowledge_nonce,knowledge_tag,knowledge_key_version,knowledge_revision,knowledge_hash,approved_by,approved_at,allowlist_json,daily_limit,activation_at,updated_at,business_id,created_at) VALUES (?,?,UNHEX(?),UNHEX(?),UNHEX(?),?,?,?,?,?,?,?,?,?,?,?)');
                $statement->execute(array_merge($values, [$actor['business_id'], $now]));
            } else {
                // Quota reservations survive edits and off/on toggles during the same day.
                $statement = $this->pdo->prepare('UPDATE ai_reply_settings SET connection_id=?,enabled=?,knowledge_ciphertext=UNHEX(?),knowledge_nonce=UNHEX(?),knowledge_tag=UNHEX(?),knowledge_key_version=?,knowledge_revision=?,knowledge_hash=?,approved_by=?,approved_at=?,allowlist_json=?,daily_limit=?,activation_at=?,updated_at=? WHERE business_id=?');
                $statement->execute(array_merge($values, [$actor['business_id']]));
            }
            $audit = $this->pdo->prepare("INSERT INTO audit_logs (business_id,actor_user_id,actor_kind,action,entity_type,entity_id,meta_json,created_at) VALUES (?,?,'user','ai_tetapan_disimpan','whatsapp_connection',?,?,UTC_TIMESTAMP())");
            $audit->execute([$actor['business_id'], $actor['user_id'], $connectionId,
                json_encode(['revision' => $revision + 1, 'enabled' => $enabled, 'recipients' => count($allowlist)], JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        return $this->view($actor);
    }

    public function stop(array $actor): array
    {
        $actor = $this->owner($actor);
        $this->pdo->beginTransaction();
        try {
            $this->settings((int) $actor['business_id'], true);
            $this->lockOwner($actor);
            $statement = $this->pdo->prepare('UPDATE ai_reply_settings SET enabled=0,knowledge_revision=knowledge_revision+1,activation_at=NULL,updated_at=UTC_TIMESTAMP() WHERE business_id=?');
            $statement->execute([$actor['business_id']]);
            Accounts::audit($this->pdo, $actor['business_id'], $actor['user_id'], 'ai_dihentikan', 'business', $actor['business_id']);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        return $this->view($actor);
    }

    public function setMode(array $actor, string $conversationId, string $mode): void
    {
        if (!$this->available($actor)) { throw new AuthException('Kawalan AI belum tersedia.', 409); }
        $actor = $this->member($actor);
        if ($actor['status'] !== 'active' || !in_array($mode, ['ai', 'human'], true)
            || preg_match('/\Aai-([1-9]\d{0,18})\z/', $conversationId, $match) !== 1) {
            throw new AuthException('Mod perbualan tidak sah.', 400);
        }
        $this->pdo->beginTransaction();
        try {
            // Same lock order as settings updates and worker rechecks: settings, conversation.
            $settings = $this->settings((int) $actor['business_id'], true);
            $statement = $this->pdo->prepare('SELECT id,connection_id,last_inbound_at FROM ai_conversations WHERE id=? AND business_id=? FOR UPDATE');
            $statement->execute([(int) $match[1], $actor['business_id']]);
            $conversation = $statement->fetch();
            if (!is_array($conversation)) { throw new AuthException('Perbualan tidak dijumpai.', 404); }
            if ($mode === 'ai' && (!$this->deploymentReady() || $settings === null || !(bool) $settings['enabled']
                || (int) $settings['connection_id'] !== (int) $conversation['connection_id'])) {
                throw new AuthException('Aktifkan tetapan AI untuk sambungan ini dahulu.', 409);
            }
            $update = $this->pdo->prepare('UPDATE ai_conversations SET mode=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND business_id=?');
            $update->execute([$mode, $conversation['id'], $actor['business_id']]);
            Accounts::audit($this->pdo, $actor['business_id'], $actor['user_id'], $mode === 'ai' ? 'ai_disambung' : 'ai_ambil_alih', 'ai_conversation', (int) $conversation['id']);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** Merge unprocessed signed webhook events with permanent, encrypted message history. */
    public function inbox(array $actor, array $legacyInbox): array
    {
        if (!$this->available($actor)) { return $legacyInbox; }
        $actor = $this->member($actor);
        $query = $this->pdo->prepare('SELECT c.*,w.phone_number_id,w.disconnected_at,w.token_state,w.webhook_state,w.registration_state,w.token_expires_at FROM ai_conversations c INNER JOIN whatsapp_connections w ON w.business_id=c.business_id AND w.id=c.connection_id WHERE c.business_id=? ORDER BY c.updated_at DESC,c.id DESC LIMIT 100');
        $query->execute([$actor['business_id']]);
        $rows = $query->fetchAll();
        if ($rows === []) { return $legacyInbox; }
        $settings = $this->settings((int) $actor['business_id']);
        $allowlist = json_decode((string) ($settings['allowlist_json'] ?? '[]'), true, 8);
        if (!is_array($allowlist) || !array_is_list($allowlist) || count($allowlist) > 10) { $allowlist = []; }
        $conversations = [];
        $contacts = [];
        $aliases = [];
        foreach ($rows as $row) {
            $id = 'ai-' . $row['id'];
            $aliases['wa-' . $row['phone_number_id'] . '-' . $row['recipient']] = $id;
            $allowlisted = in_array($row['recipient'], $allowlist, true);
            $enabled = $allowlisted && $this->deploymentReady() && $settings !== null && (int) $settings['enabled'] === 1
                && (int) $settings['connection_id'] === (int) $row['connection_id'] && $actor['status'] === 'active'
                && $row['disconnected_at'] === null && $row['token_state'] === 'verified'
                && $row['webhook_state'] === 'subscribed' && $row['registration_state'] === 'registered'
                && ($row['token_expires_at'] === null || strtotime($row['token_expires_at'] . ' UTC') > time() + 30);
            $last = $row['last_inbound_at'] === null ? 0 : (int) strtotime($row['last_inbound_at'] . ' UTC');
            $conversations[$id] = [
                'id' => $id, 'name' => '+' . $row['recipient'], 'phone' => '+' . $row['recipient'],
                'mode' => $row['mode'] === 'human' ? 'human_takeover' : ($enabled ? 'ai_active' : 'ai_paused'),
                'window' => $last > 0 && $last <= time() && time() - $last < 86400 ? 'open' : 'closed',
                'messages' => [], 'tag' => 'Ujian pemilik', 'assignee' => '', 'unread' => false,
                'note' => $allowlisted
                    ? 'Sejarah disimpan pada pelayan. Kawalan ambil alih terpakai kepada balasan AI.'
                    : 'Nombor ini belum dimasukkan dalam senarai nombor ujian. Semak Pengetahuan.',
                'can_control' => true,
            ];
        }
        $ids = array_column($rows, 'id');
        $messages = $this->pdo->prepare('SELECT * FROM ai_messages WHERE business_id=? AND conversation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT 2000');
        $messages->execute(array_merge([$actor['business_id']], $ids));
        foreach (array_reverse($messages->fetchAll()) as $row) {
            $id = 'ai-' . $row['conversation_id'];
            try {
                $body = json_decode($this->open($row, 'body'), true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $conversations[$id]['note'] = 'Sebahagian sejarah tidak dapat dibaca. Semakan pelayan diperlukan.';
                continue;
            }
            if (!is_array($body)) { continue; }
            if ($row['direction'] === 'in' && is_string($body['name'] ?? null) && trim($body['name']) !== '') {
                $conversations[$id]['name'] = $body['name'];
            }
            $conversations[$id]['messages'][] = [
                'from' => $row['direction'] === 'in' ? 'customer' : 'ai',
                'text' => is_string($body['text'] ?? null) ? $body['text'] : '[Mesej bukan teks]',
                'wamid' => $row['wamid'] ?? '', 'status' => $row['status'], 'at' => $row['message_at'],
            ];
        }
        foreach ($legacyInbox['conversations'] ?? [] as $row) {
            $id = $aliases[$row['id']] ?? null;
            if ($id === null) { $conversations[$row['id']] = $row; continue; }
            $seen = array_column($conversations[$id]['messages'], 'wamid');
            foreach ($row['messages'] ?? [] as $message) {
                if (!in_array($message['wamid'] ?? '', $seen, true)) {
                    $conversations[$id]['messages'][] = $message;
                }
            }
        }
        foreach ($conversations as $id => $row) {
            $contacts[] = ['id' => 'contact-' . $id, 'conversationId' => $id, 'name' => $row['name'],
                'phone' => $row['phone'] ?? '', 'tag' => $row['tag'] ?? '', 'assignee' => '', 'priority' => 'Biasa', 'note' => $row['note'] ?? ''];
        }
        return ['conversations' => array_values($conversations), 'contacts' => $contacts];
    }

    private function available(array $actor): bool
    {
        $id = $this->config['owner_ai_business_id'] ?? '';
        if (!is_string($id) || preg_match('/\A[1-9]\d{0,18}\z/', $id) !== 1 || (int) $id !== (int) ($actor['business_id'] ?? 0)) {
            return false;
        }
        if ($this->installed === null) {
            $this->installed = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ai_reply_settings','ai_conversations','ai_messages','ai_reply_jobs')")->fetchColumn() === 4;
            if ($this->installed) {
                $record = $this->pdo->query("SELECT checksum FROM schema_migrations WHERE version='0004'")->fetchColumn();
                $expected = hash_file('sha256', dirname(__DIR__) . '/migrations/0004_ai_replies.up.sql');
                $this->installed = is_string($record) && is_string($expected) && hash_equals($expected, $record);
            }
        }
        return $this->installed;
    }

    private function deploymentReady(): bool
    {
        return ($this->config['owner_ai_enabled'] ?? '') === 'true'
            && is_string($this->config['gemini_api_key'] ?? null) && $this->config['gemini_api_key'] !== ''
            && is_string($this->config['gemini_model'] ?? null) && $this->config['gemini_model'] !== '';
    }

    private function member(array $actor): array
    {
        return Membership::require($this->pdo, (int) ($actor['user_id'] ?? 0), (int) ($actor['business_id'] ?? 0));
    }

    private function owner(array $actor): array
    {
        if (!$this->available($actor)) { throw new AuthException('Tetapan AI belum tersedia.', 409); }
        $actor = $this->member($actor);
        if ($actor['role'] !== 'owner' || $actor['user_id'] !== $actor['owner_user_id'] || $actor['status'] !== 'active') {
            throw new AuthException('Hanya pemilik perniagaan aktif boleh mengubah tetapan AI.', 403);
        }
        return $actor;
    }

    private function lockOwner(array $actor): void
    {
        $statement = $this->pdo->prepare("SELECT b.id FROM businesses b INNER JOIN business_users bu ON bu.business_id=b.id AND bu.user_id=b.owner_user_id INNER JOIN users u ON u.id=bu.user_id WHERE b.id=? AND b.owner_user_id=? AND b.status='active' AND bu.role='owner' AND u.status='active' FOR UPDATE");
        $statement->execute([$actor['business_id'], $actor['user_id']]);
        if (!$statement->fetch()) { throw new AuthException('Pemilikan perniagaan telah berubah.', 403); }
    }

    private function settings(int $businessId, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ai_reply_settings WHERE business_id=?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$businessId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function open(array $row, string $prefix): string
    {
        return $this->seal->open((string) $row[$prefix . '_ciphertext'], (string) $row[$prefix . '_nonce'],
            (string) $row[$prefix . '_tag'], (int) $row[$prefix . '_key_version']);
    }

    private function allowlist(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 10) {
            throw new AuthException('Tetapkan satu hingga sepuluh nombor ujian.', 400);
        }
        $result = [];
        foreach ($value as $number) {
            if (!is_string($number) || preg_match('/\A\+?[1-9]\d{6,14}\z/', $number) !== 1) {
                throw new AuthException('Gunakan nombor antarabangsa, contohnya 60 diikuti nombor telefon.', 400);
            }
            $result[] = ltrim($number, '+');
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }
}
