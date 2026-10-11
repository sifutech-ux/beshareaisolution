<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

final class CoexistenceArchive
{
    public function __construct(private PDO $pdo, private TokenSeal $seal)
    {
    }

    public function drain(int $limit = 20): int
    {
        $limit = max(1, min($limit, 50));
        $statement = $this->pdo->prepare(
            'SELECT id, business_id, connection_id, event_kind,
                    HEX(payload_ciphertext) AS payload_ciphertext, HEX(payload_nonce) AS payload_nonce,
                    HEX(payload_tag) AS payload_tag, payload_key_version
             FROM webhook_events
             WHERE status = \'accepted\' AND result_code IS NULL
               AND event_kind IN (\'history\', \'smb_app_state_sync\', \'smb_message_echoes\')
             ORDER BY id ASC
             LIMIT ' . $limit
        );
        $statement->execute();
        $count = 0;
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->archiveRow($row);
            $count++;
        }
        return $count;
    }

    /** @param array<string,mixed> $row */
    private function archiveRow(array $row): void
    {
        $plain = '';
        try {
            $cipher = hex2bin((string) $row['payload_ciphertext']);
            $nonce = hex2bin((string) $row['payload_nonce']);
            $tag = hex2bin((string) $row['payload_tag']);
            if (is_string($cipher) && is_string($nonce) && is_string($tag)) {
                $plain = $this->seal->open($cipher, $nonce, $tag, (int) $row['payload_key_version']);
            }
        } catch (AuthException) {
            $plain = '';
        }
        $body = json_decode($plain, true);
        $businessId = $row['business_id'] === null ? 0 : (int) $row['business_id'];
        $connectionId = $row['connection_id'] === null ? 0 : (int) $row['connection_id'];
        $this->pdo->beginTransaction();
        try {
            if ($businessId > 0 && $connectionId > 0 && is_array($body)) {
                $value = is_array($body['value'] ?? null) ? $body['value'] : [];
                if ((string) $row['event_kind'] === 'smb_app_state_sync') {
                    $this->contacts($businessId, $connectionId, $value);
                } else {
                    $phase = is_numeric($body['phase'] ?? null) ? (int) $body['phase'] : null;
                    $chunk = is_numeric($body['chunk_order'] ?? null) ? (int) $body['chunk_order'] : null;
                    $source = (string) $row['event_kind'] === 'smb_message_echoes' ? 'echo' : 'history';
                    $this->messages($businessId, $connectionId, $value, $source, $phase, $chunk);
                }
            }
            $done = $this->pdo->prepare(
                'UPDATE webhook_events SET result_code = \'archived\' WHERE id = ? AND status = \'accepted\' AND result_code IS NULL'
            );
            $done->execute([(int) $row['id']]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $value */
    private function contacts(int $businessId, int $connectionId, array $value): void
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO coexistence_contacts (
                business_id, connection_id, phone_number, full_name, first_name, sync_action, changed_at, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        foreach (is_array($value['state_sync'] ?? null) ? $value['state_sync'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $contact = is_array($item['contact'] ?? null) ? $item['contact'] : [];
            $phone = $this->digits((string) ($contact['phone_number'] ?? ''));
            $action = (string) ($item['action'] ?? '');
            $stamp = $this->stamp((string) (is_array($item['metadata'] ?? null) ? ($item['metadata']['timestamp'] ?? '') : ''));
            if ($phone === '' || !in_array($action, ['add', 'remove'], true) || $stamp === '') {
                continue;
            }
            $insert->execute([
                $businessId,
                $connectionId,
                $phone,
                $this->name($contact['full_name'] ?? '', 120),
                $this->name($contact['first_name'] ?? '', 80),
                $action,
                $stamp,
            ]);
        }
    }

    /** @param array<string,mixed> $value */
    private function messages(int $businessId, int $connectionId, array $value, string $source, ?int $phase, ?int $chunk): void
    {
        $lists = [];
        if ($source === 'echo') {
            $lists[] = ['peer' => '', 'messages' => is_array($value['message_echoes'] ?? null) ? $value['message_echoes'] : []];
        }
        foreach (is_array($value['history'] ?? null) ? $value['history'] : [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            foreach (is_array($block['threads'] ?? null) ? $block['threads'] : [] as $thread) {
                if (!is_array($thread)) {
                    continue;
                }
                $lists[] = [
                    'peer' => $this->digits((string) ($thread['id'] ?? '')),
                    'messages' => is_array($thread['messages'] ?? null) ? $thread['messages'] : [],
                ];
            }
        }
        if (is_array($value['messages'] ?? null)) {
            $lists[] = ['peer' => '', 'messages' => $value['messages'], 'source' => 'media'];
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO coexistence_messages (
                business_id, connection_id, source_kind, wamid, peer_phone, from_phone, to_phone, message_timestamp,
                message_type, body_text, media_id, media_sha256, history_status, history_phase, chunk_order, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                media_id = IF(media_id IS NULL, VALUES(media_id), media_id),
                media_sha256 = IF(media_sha256 IS NULL, VALUES(media_sha256), media_sha256),
                body_text = IF(body_text IS NULL OR body_text = \'\', VALUES(body_text), body_text)'
        );
        foreach ($lists as $list) {
            foreach ($list['messages'] as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $wamid = $this->wamid((string) ($message['id'] ?? ''));
                $type = $this->name($message['type'] ?? '', 32);
                if ($wamid === '' || $type === null) {
                    continue;
                }
                $media = $this->media($message, $type);
                $context = is_array($message['history_context'] ?? null) ? $message['history_context'] : [];
                $insert->execute([
                    $businessId,
                    $connectionId,
                    is_string($list['source'] ?? null) ? $list['source'] : $source,
                    $wamid,
                    $list['peer'] !== '' ? $list['peer'] : null,
                    $this->nullableDigits((string) ($message['from'] ?? '')),
                    $this->nullableDigits((string) ($message['to'] ?? '')),
                    $this->nullableStamp((string) ($message['timestamp'] ?? '')),
                    $type,
                    $type === 'text' && is_array($message['text'] ?? null) ? $this->name($message['text']['body'] ?? '', 4000) : null,
                    $media['id'],
                    $media['sha256'],
                    $this->name($context['status'] ?? '', 16),
                    $phase,
                    $chunk,
                ]);
            }
        }
    }

    /** @param array<string,mixed> $message @return array{id:?string,sha256:?string} */
    private function media(array $message, string $type): array
    {
        $block = $message[$type] ?? null;
        if ($type === 'media_placeholder' || !is_array($block)) {
            return ['id' => null, 'sha256' => null];
        }
        $id = (string) ($block['id'] ?? '');
        $sha = strtolower((string) ($block['sha256'] ?? ''));
        return [
            'id' => preg_match('/\A\d{6,64}\z/', $id) === 1 ? $id : null,
            'sha256' => preg_match('/\A[0-9a-f]{64}\z/', $sha) === 1 ? $sha : null,
        ];
    }

    private function digits(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        $digits = is_string($digits) ? $digits : '';
        return preg_match('/\A\d{6,20}\z/', $digits) === 1 ? $digits : '';
    }

    private function nullableDigits(string $value): ?string
    {
        $digits = $this->digits($value);
        return $digits === '' ? null : $digits;
    }

    private function stamp(string $value): string
    {
        return preg_match('/\A\d{10,13}\z/', $value) === 1 ? $value : '';
    }

    private function nullableStamp(string $value): ?string
    {
        $stamp = $this->stamp($value);
        return $stamp === '' ? null : $stamp;
    }

    private function wamid(string $value): string
    {
        return preg_match('/\A[A-Za-z0-9._+\/=-]{8,180}\z/', $value) === 1 ? $value : '';
    }

    private function name(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        $clean = trim(is_string($clean) ? $clean : '');
        if ($clean === '') {
            return null;
        }
        if (strlen($clean) > $max) {
            $clean = substr($clean, 0, $max);
        }
        return $clean;
    }
}
