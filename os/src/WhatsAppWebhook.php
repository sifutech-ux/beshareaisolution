<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use PDOException;

require_once __DIR__ . '/WhatsAppDeliveryError.php';

final class WhatsAppWebhook
{
    public function __construct(private PDO $pdo, private TokenSeal $seal)
    {
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return ['messages', 'account_update', 'history', 'smb_app_state_sync', 'smb_message_echoes'];
    }

    public static function signatureMatches(string $raw, string $header, string $secret): bool
    {
        if ($secret === '' || preg_match('/\Asha256=([0-9a-f]{64})\z/i', $header, $match) !== 1) {
            return false;
        }
        $expected = hash_hmac('sha256', $raw, $secret);
        return hash_equals($expected, strtolower($match[1]));
    }

    /**
     * Entry used by the app-callback bridge after the original body is verified.
     * The caller passes the lifecycle slice and a signature over that slice.
     * Unverified bodies are rejected before any write. Only PARTNER_REMOVED
     * and ACCOUNT_OFFBOARDED are accepted.
     *
     * @return array{stored:int,replayed:int}
     */
    public function ingestVerifiedLifecycle(string $raw, string $secret, string $header): array
    {
        if (!self::signatureMatches($raw, $header, $secret)) {
            throw new AuthException('Tandatangan tidak sah.', 403);
        }
        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account' || !is_array($payload['entry'] ?? null)) {
            throw new AuthException('Payload webhook tidak sah.', 400);
        }
        $seen = 0;
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || !is_array($entry['changes'] ?? null)) {
                throw new AuthException('Payload webhook tidak sah.', 400);
            }
            foreach ($entry['changes'] as $change) {
                if (!is_array($change) || (string) ($change['field'] ?? '') !== 'account_update' || !is_array($change['value'] ?? null)) {
                    throw new AuthException('Peristiwa ini tidak diteruskan.', 400);
                }
                $event = (string) ($change['value']['event'] ?? '');
                if (!in_array($event, ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED'], true)) {
                    throw new AuthException('Peristiwa ini tidak diteruskan.', 400);
                }
                $seen++;
            }
        }
        if ($seen < 1) {
            throw new AuthException('Payload webhook tidak sah.', 400);
        }
        return $this->ingest($raw);
    }

    /**
     * @param array<string,array{id:int,business_id:int}>|null $expectedBindings
     * @return array{stored:int,replayed:int}
     */
    public function ingest(string $raw, ?array $expectedBindings = null): array
    {
        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
            throw new AuthException('Payload webhook tidak sah.', 400);
        }
        $stored = 0;
        $replayed = 0;
        $events = $this->events($payload);
        if ($expectedBindings !== null) {
            foreach ($events as $event) { $this->expectedBinding($event, $expectedBindings); }
        }
        foreach ($events as $event) {
            $expected = $expectedBindings === null ? null : $this->expectedBinding($event, $expectedBindings);
            $result = $this->store($event, $expected);
            $stored += $result === 'stored' ? 1 : 0;
            $replayed += $result === 'replayed' ? 1 : 0;
        }
        return ['stored' => $stored, 'replayed' => $replayed];
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{kind:string,key:string,phone:string,body:array<string,mixed>}>
     */
    private function events(array $payload): array
    {
        $events = [];
        $entries = $payload['entry'] ?? [];
        if (!is_array($entries)) {
            return [];
        }
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_array($entry['changes'] ?? null)) {
                continue;
            }
            foreach ($entry['changes'] as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $field = (string) ($change['field'] ?? '');
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                if ($field === 'messages') {
                    $phone = $this->id($value['metadata']['phone_number_id'] ?? '');
                    if ($phone === '') {
                        continue;
                    }
                    $names = [];
                    foreach (is_array($value['contacts'] ?? null) ? $value['contacts'] : [] as $contact) {
                        if (!is_array($contact)) {
                            continue;
                        }
                        $wa = $this->digits((string) ($contact['wa_id'] ?? ''));
                        $profile = is_array($contact['profile'] ?? null) ? (string) ($contact['profile']['name'] ?? '') : '';
                        if ($wa !== '' && $profile !== '') {
                            $names[$wa] = $this->label($profile, 80);
                        }
                    }
                    foreach (is_array($value['messages'] ?? null) ? $value['messages'] : [] as $message) {
                        if (!is_array($message)) {
                            continue;
                        }
                        $wamid = $this->wamid((string) ($message['id'] ?? ''));
                        $from = $this->digits((string) ($message['from'] ?? ''));
                        if ($wamid === '' || $from === '') {
                            continue;
                        }
                        $events[] = [
                            'kind' => 'message',
                            'key' => $this->key('m:' . $phone . ':' . $wamid),
                            'phone' => $phone,
                            'body' => [
                                'phone_number_id' => $phone,
                                'waba_id' => $this->id($entry['id'] ?? ''),
                                'wamid' => $wamid,
                                'from' => $from,
                                'timestamp' => $this->stamp((string) ($message['timestamp'] ?? '')),
                                'type' => $this->label((string) ($message['type'] ?? ''), 32),
                                'text' => $this->textOf($message),
                                'name' => $names[$from] ?? '',
                            ],
                        ];
                    }
                    foreach (is_array($value['statuses'] ?? null) ? $value['statuses'] : [] as $status) {
                        if (!is_array($status)) {
                            continue;
                        }
                        $wamid = $this->wamid((string) ($status['id'] ?? ''));
                        $name = (string) ($status['status'] ?? '');
                        if ($wamid === '' || !in_array($name, ['sent', 'delivered', 'read', 'failed', 'deleted'], true)) {
                            continue;
                        }
                        $stamp = $this->stamp((string) ($status['timestamp'] ?? ''));
                        $events[] = [
                            'kind' => 'status',
                            'key' => $this->key('s:' . $phone . ':' . $wamid . ':' . $name . ':' . $stamp),
                            'phone' => $phone,
                            'body' => [
                                'phone_number_id' => $phone,
                                'waba_id' => $this->id($entry['id'] ?? ''),
                                'wamid' => $wamid,
                                'status' => $name,
                                'timestamp' => $stamp,
                                'recipient_id' => $this->digits((string) ($status['recipient_id'] ?? '')),
                            ] + ($name === 'failed' ? (WhatsAppDeliveryError::fromStatus($status['errors'] ?? null) ?? []) : []),
                        ];
                    }
                    continue;
                }
                if (!in_array($field, self::fields(), true) || $field === 'messages') {
                    $body = ['field' => $this->label($field, 64), 'waba_id' => $this->id((string) ($entry['id'] ?? ''))];
                    $events[] = [
                        'kind' => 'other',
                        'key' => $this->key('other:' . hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES) . $this->canonical($value))),
                        'phone' => '',
                        'body' => $body,
                    ];
                    continue;
                }
                $events[] = $this->coexistenceEvent($field, $entry, $value);
            }
        }
        return $events;
    }

    /** @param array{kind:string,key:string,phone:string,body:array<string,mixed>} $event */
    private function store(array $event, ?array $expectedBinding = null): string
    {
        $json = json_encode($event['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return 'skipped';
        }
        $wide = in_array($event['kind'], ['history', 'smb_app_state_sync', 'smb_message_echoes', 'account_update'], true);
        $sealed = $this->sealBody($event['body'], $wide);
        $json = $sealed['json'];
        $connection = $expectedBinding ?? $this->route($event);
        // An unscoped event must not consume a known tenant's dedupe key.
        $dedupeKey = $event['key'];
        if ($connection === null && in_array($event['kind'], ['message', 'status'], true)) {
            $dedupeKey = $this->key('unscoped:' . ($event['body']['waba_id'] ?? '') . ':' . $dedupeKey);
        }
        $businessId = $connection['business_id'] ?? null;
        $connectionId = $connection['id'] ?? null;
        $this->pdo->beginTransaction();
        try {
            if ($expectedBinding !== null) {
                // Match the worker lock order: current business/owner before its
                // connection. A reassigned or disconnected asset cannot receive
                // a previously selected customer's event or messaging metadata.
                $message = $event['kind'] === 'message';
                $owner = $this->pdo->prepare($message ? "SELECT b.id FROM businesses b
                    JOIN users u ON u.id=b.owner_user_id AND u.status='active'
                    JOIN business_users bu ON bu.business_id=b.id AND bu.user_id=b.owner_user_id AND bu.role='owner'
                    WHERE b.id=? AND b.status='active' FOR UPDATE" : 'SELECT id FROM businesses WHERE id=? FOR UPDATE');
                $owner->execute([$businessId]);
                if ($owner->fetchColumn() === false) {
                    throw new AuthException('Perniagaan penerima telah berubah.', 409);
                }
                $bound = $this->pdo->prepare('SELECT id FROM whatsapp_connections
                    WHERE id=? AND business_id=? AND waba_id=? AND phone_number_id=?'
                    . ($message ? " AND disconnected_at IS NULL AND token_state='verified'" : '') . ' FOR UPDATE');
                $bound->execute([$connectionId, $businessId, $event['body']['waba_id'] ?? '', $event['phone']]);
                if ($bound->fetchColumn() === false) {
                    throw new AuthException('Sambungan penerima telah berubah.', 409);
                }
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO webhook_events (
                    business_id, connection_id, dedupe_key, event_kind, status, attempt_count, max_attempts,
                    payload_hash, payload_ciphertext, payload_nonce, payload_tag, payload_key_version, received_at
                 ) VALUES (?, ?, ?, ?, \'accepted\', 0, 5, ?, UNHEX(?), UNHEX(?), UNHEX(?), ?, UTC_TIMESTAMP())'
            );
            $insert->execute([
                $businessId,
                $connectionId,
                $dedupeKey,
                $event['kind'],
                hash('sha256', $json),
                bin2hex($sealed['sealed']['ciphertext']),
                bin2hex($sealed['sealed']['nonce']),
                bin2hex($sealed['sealed']['tag']),
                $sealed['sealed']['version'],
            ]);
            if ($event['kind'] === 'message' && is_int($businessId) && is_int($connectionId)) {
                $mark = $this->pdo->prepare(
                    'UPDATE whatsapp_connections
                     SET messaging_state = \'receive_verified\', updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND business_id = ? AND token_state = \'verified\'
                       AND webhook_state = \'subscribed\' AND messaging_state = \'unverified\''
                );
                $mark->execute([$connectionId, $businessId]);
            }
            $this->observe($event, $connection);
            $this->pdo->commit();
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (str_contains($error->getMessage(), 'uq_webhook_dedupe') || str_contains($error->getMessage(), 'Duplicate')) {
                return 'replayed';
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return 'stored';
    }

    private function expectedBinding(array $event, array $bindings): array
    {
        $key = ($event['body']['waba_id'] ?? '') . ':' . $event['phone'];
        $binding = $bindings[$key] ?? null;
        if (!in_array($event['kind'], ['message', 'status'], true) || !is_array($binding)
            || !is_int($binding['id'] ?? null) || $binding['id'] < 1
            || !is_int($binding['business_id'] ?? null) || $binding['business_id'] < 1) {
            throw new AuthException('Skop penerima webhook tidak sah.', 409);
        }
        return ['id' => $binding['id'], 'business_id' => $binding['business_id']];
    }

    /**
     * @param array{kind:string,key:string,phone:string,body:array<string,mixed>} $event
     * @return array{id:int,business_id:int}|null
     */
    private function route(array $event): ?array
    {
        if ($event['phone'] !== '') {
            return $this->connection($event['phone'], $this->id($event['body']['waba_id'] ?? ''));
        }
        $waba = $this->id($event['body']['waba_id'] ?? '');
        if ($waba === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, business_id, display_phone FROM whatsapp_connections WHERE waba_id = ? AND disconnected_at IS NULL'
        );
        $statement->execute([$waba]);
        $rows = $statement->fetchAll();
        $wanted = $this->digitsOnly((string) ($event['body']['phone_number'] ?? ''));
        if ($wanted !== '') {
            $matches = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($this->digitsOnly((string) ($row['display_phone'] ?? '')) === $wanted) {
                    $matches[] = $row;
                }
            }
            if (count($matches) !== 1) {
                return null;
            }
            return ['id' => (int) $matches[0]['id'], 'business_id' => (int) $matches[0]['business_id']];
        }
        if (count($rows) === 1 && is_array($rows[0])) {
            return ['id' => (int) $rows[0]['id'], 'business_id' => (int) $rows[0]['business_id']];
        }
        return null;
    }

    /** @return array{id:int,business_id:int}|null */
    private function connection(string $phoneId, string $wabaId): ?array
    {
        if ($wabaId === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, business_id FROM whatsapp_connections WHERE phone_number_id = ? AND waba_id = ? AND disconnected_at IS NULL LIMIT 1'
        );
        $statement->execute([$phoneId, $wabaId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return ['id' => (int) $row['id'], 'business_id' => (int) $row['business_id']];
    }

    /** @param array<string,mixed> $message */
    private function textOf(array $message): string
    {
        $type = (string) ($message['type'] ?? '');
        if ($type === 'text' && is_array($message['text'] ?? null)) {
            return $this->label((string) ($message['text']['body'] ?? ''), 4000);
        }
        return match ($type) {
            'image' => 'Imej diterima.',
            'audio' => 'Audio diterima.',
            'video' => 'Video diterima.',
            'document' => 'Dokumen diterima.',
            'location' => 'Lokasi diterima.',
            'contacts' => 'Kenalan diterima.',
            'interactive', 'button' => 'Butang diterima.',
            'sticker' => 'Stiker diterima.',
            'reaction' => 'Reaksi diterima.',
            default => 'Mesej diterima.',
        };
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $value
     * @return array{kind:string,key:string,phone:string,body:array<string,mixed>}
     */
    private function coexistenceEvent(string $field, array $entry, array $value): array
    {
        $phone = $this->id(is_array($value['metadata'] ?? null) ? ($value['metadata']['phone_number_id'] ?? '') : '');
        $signal = $this->historySignal($value);
        $info = is_array($value['disconnection_info'] ?? null) ? $value['disconnection_info'] : [];
        $reason = $this->token((string) ($info['reason'] ?? ''));
        $initiated = $this->token((string) ($info['initiated_by'] ?? ''));
        $body = [
            'field' => $field,
            'waba_id' => $this->id((string) ($entry['id'] ?? '')),
            'phone_number_id' => $phone,
            'phone_number' => $this->digitsOnly((string) ($value['phone_number'] ?? '')),
            'event' => $this->token((string) ($value['event'] ?? '')),
            'reason' => in_array($reason, ['ACCOUNT_DISCONNECTED', 'BUSINESS_DOWNGRADE', 'CHANGE_NUMBER', 'COMPANION_INACTIVITY', 'PRIMARY_INACTIVITY', 'USER_RE_REGISTERED'], true) ? $reason : '',
            'initiated_by' => in_array($initiated, ['USER', 'SYSTEM'], true) ? $initiated : '',
            'phase' => $signal['phase'],
            'chunk_order' => $signal['chunk'],
            'progress' => $signal['progress'],
            'error_code' => $signal['error'],
            'value' => $this->scrub($value),
        ];
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            'kind' => $field,
            'key' => $this->key($field . ':' . hash('sha256', is_string($encoded) ? $encoded : $field)),
            'phone' => $phone,
            'body' => $body,
        ];
    }

    /** @param array<string,mixed> $value @return array{phase:?int,chunk:?int,progress:?int,error:string} */
    private function historySignal(array $value): array
    {
        $phase = null;
        $chunk = null;
        $progress = null;
        $error = '';
        foreach (is_array($value['history'] ?? null) ? $value['history'] : [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $meta = is_array($block['metadata'] ?? null) ? $block['metadata'] : [];
            if (is_numeric($meta['phase'] ?? null)) {
                $phase = (int) $meta['phase'];
            }
            if (is_numeric($meta['chunk_order'] ?? null)) {
                $chunk = (int) $meta['chunk_order'];
            }
            if (is_numeric($meta['progress'] ?? null)) {
                $progress = (int) $meta['progress'];
            }
            foreach (is_array($block['errors'] ?? null) ? $block['errors'] : [] as $item) {
                if (is_array($item) && (string) ($item['code'] ?? '') === '2593109') {
                    $error = '2593109';
                }
            }
        }
        return ['phase' => $phase, 'chunk' => $chunk, 'progress' => $progress, 'error' => $error];
    }

    /** @param array<string,mixed> $body @return array{json:string,sealed:array{ciphertext:string,nonce:string,tag:string,version:int}} */
    private function sealBody(array $body, bool $wide): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new AuthException('Rekod tidak dapat disimpan.', 500);
        }
        try {
            return ['json' => $json, 'sealed' => $this->seal->seal($json, $wide ? 1048576 : 200000)];
        } catch (AuthException $error) {
            if (!$wide) {
                throw $error;
            }
            $short = [
                'field' => $body['field'] ?? '',
                'waba_id' => $body['waba_id'] ?? '',
                'phone_number_id' => $body['phone_number_id'] ?? '',
                'event' => $body['event'] ?? '',
                'error_code' => $body['error_code'] ?? '',
                'progress' => $body['progress'] ?? null,
                'truncated' => true,
            ];
            $json = json_encode($short, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                throw $error;
            }
            return ['json' => $json, 'sealed' => $this->seal->seal($json, 200000)];
        }
    }

    /** @param array{kind:string,key:string,phone:string,body:array<string,mixed>} $event @param array{id:int,business_id:int}|null $connection */
    private function observe(array $event, ?array $connection): void
    {
        if ($connection === null) {
            return;
        }
        $name = (string) ($event['body']['event'] ?? '');
        if ($event['kind'] === 'account_update' && in_array($name, ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED'], true)) {
            $this->disconnect($connection, $event['body']);
            return;
        }
        if ($event['kind'] === 'history') {
            $this->observeHistory($connection, $event['body']);
            return;
        }
        if ($event['kind'] === 'smb_app_state_sync') {
            $this->advance($connection, 'sync_contacts');
        }
    }

    /** @param array{id:int,business_id:int} $connection @param array<string,mixed> $body */
    private function observeHistory(array $connection, array $body): void
    {
        if ((string) ($body['error_code'] ?? '') === '2593109') {
            $this->setSync($connection, 'sync_history', 'declined', '2593109');
            $this->finishJob($connection, 'history', 'declined', '2593109');
            return;
        }
        if (($body['progress'] ?? null) === 100) {
            $this->setSync($connection, 'sync_history', 'completed', null);
            $this->finishJob($connection, 'history', 'completed', null);
            return;
        }
        $this->setSync($connection, 'sync_history', 'in_progress', null);
    }

    /** @param array{id:int,business_id:int} $connection */
    private function advance(array $connection, string $column): void
    {
        if ($column !== 'sync_contacts' && $column !== 'sync_history') {
            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE whatsapp_connections
             SET ' . $column . ' = \'in_progress\', updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND business_id = ? AND disconnected_at IS NULL AND ' . $column . ' = \'pending\''
        );
        $statement->execute([$connection['id'], $connection['business_id']]);
    }

    /** @param array{id:int,business_id:int} $connection */
    private function setSync(array $connection, string $column, string $status, ?string $code): void
    {
        if ($column !== 'sync_history') {
            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE whatsapp_connections
             SET sync_history = ?, last_error_code = COALESCE(?, last_error_code), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND business_id = ? AND disconnected_at IS NULL
               AND sync_history IN (\'pending\', \'in_progress\', \'failed\')'
        );
        $statement->execute([$status, $code, $connection['id'], $connection['business_id']]);
    }

    /** @param array{id:int,business_id:int} $connection */
    private function finishJob(array $connection, string $syncType, string $status, ?string $code): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE coexistence_sync_jobs
             SET status = ?, last_error_code = ?, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE connection_id = ? AND business_id = ? AND sync_type = ? AND status IN (\'authorized\', \'dispatching\', \'in_progress\', \'review\')'
        );
        $statement->execute([$status, $code, $connection['id'], $connection['business_id'], $syncType]);
    }

    /** @param array{id:int,business_id:int} $connection @param array<string,mixed> $body */
    private function disconnect(array $connection, array $body): void
    {
        $reason = (string) ($body['reason'] ?? '');
        $code = $reason !== '' ? $reason : ((string) ($body['event'] ?? '') === 'ACCOUNT_OFFBOARDED' ? 'ACCOUNT_OFFBOARDED' : 'PARTNER_REMOVED');
        $statement = $this->pdo->prepare(
            'UPDATE whatsapp_connections
             SET token_state = \'revoked\', messaging_state = \'unverified\',
                 token_ciphertext = NULL, token_nonce = NULL, token_tag = NULL, token_key_version = NULL,
                 disconnected_at = UTC_TIMESTAMP(), last_error_code = ?,
                 sync_contacts = IF(sync_contacts IN (\'pending\', \'in_progress\'), \'unavailable\', sync_contacts),
                 sync_history = IF(sync_history IN (\'pending\', \'in_progress\'), \'unavailable\', sync_history),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND business_id = ? AND token_state = \'verified\' AND disconnected_at IS NULL'
        );
        $statement->execute([$code, $connection['id'], $connection['business_id']]);
        if ($statement->rowCount() !== 1) {
            return;
        }
        $jobs = $this->pdo->prepare(
            'UPDATE coexistence_sync_jobs
             SET status = \'cancelled\', last_error_code = \'PARTNER_REMOVED\', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE connection_id = ? AND business_id = ? AND status IN (\'authorized\', \'dispatching\', \'in_progress\', \'review\')'
        );
        $jobs->execute([$connection['id'], $connection['business_id']]);
        $audit = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (business_id, actor_user_id, actor_kind, action, entity_type, entity_id, meta_json, created_at)
             VALUES (?, NULL, \'system\', \'rakan_diputuskan\', \'whatsapp_connection\', ?, ?, UTC_TIMESTAMP())'
        );
        $audit->execute([
            $connection['business_id'],
            $connection['id'],
            json_encode([
                'event' => (string) ($body['event'] ?? ''),
                'reason' => $reason,
                'initiated_by' => (string) ($body['initiated_by'] ?? ''),
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function scrub(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return null;
        }
        if (is_string($value)) {
            return $this->label($value, 4000);
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $name = is_int($key) ? $key : (is_string($key) ? $key : '');
            if (!is_int($name) && in_array(strtolower($name), ['access_token', 'token', 'authorization', 'client_secret', 'app_secret', 'password', 'secret', 'url', 'href', 'input_token'], true)) {
                continue;
            }
            $clean[$name] = $this->scrub($item, $depth + 1);
        }
        return $clean;
    }

    private function digitsOnly(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        $digits = is_string($digits) ? $digits : '';
        return preg_match('/\A\d{6,20}\z/', $digits) === 1 ? $digits : '';
    }

    private function token(string $value): string
    {
        return preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $value) === 1 ? $value : '';
    }

    /** @param array<string,mixed> $value */
    private function canonical(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }

    private function key(string $key): string
    {
        if (strlen($key) <= 191) {
            return $key;
        }
        return 'h:' . hash('sha256', $key);
    }

    private function id(mixed $value): string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        return preg_match('/\A\d{5,32}\z/', $text) === 1 ? $text : '';
    }

    private function digits(string $value): string
    {
        return preg_match('/\A\d{6,20}\z/', $value) === 1 ? $value : '';
    }

    private function wamid(string $value): string
    {
        return preg_match('/\A[A-Za-z0-9._+\/=-]{8,180}\z/', $value) === 1 ? $value : '';
    }

    private function stamp(string $value): string
    {
        return preg_match('/\A\d{10,13}\z/', $value) === 1 ? $value : '';
    }

    private function label(string $value, int $max): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        $clean = trim(is_string($clean) ? $clean : '');
        if (strlen($clean) > $max) {
            $clean = substr($clean, 0, $max);
        }
        return $clean;
    }
}
