<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use PDOException;

final class WhatsAppWebhook
{
    public function __construct(private PDO $pdo, private TokenSeal $seal)
    {
    }

    public static function signatureMatches(string $raw, string $header, string $secret): bool
    {
        if ($secret === '' || preg_match('/\Asha256=([0-9a-f]{64})\z/i', $header, $match) !== 1) {
            return false;
        }
        $expected = hash_hmac('sha256', $raw, $secret);
        return hash_equals($expected, strtolower($match[1]));
    }

    /** @return array{stored:int,replayed:int} */
    public function ingest(string $raw): array
    {
        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
            throw new AuthException('Payload webhook tidak sah.', 400);
        }
        $stored = 0;
        $replayed = 0;
        foreach ($this->events($payload) as $event) {
            $result = $this->store($event);
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
                                'wamid' => $wamid,
                                'status' => $name,
                                'timestamp' => $stamp,
                                'recipient_id' => $this->digits((string) ($status['recipient_id'] ?? '')),
                            ],
                        ];
                    }
                    continue;
                }
                $kind = $field === 'account_update' ? 'account_update' : 'other';
                $body = ['field' => $this->label($field, 64), 'waba_id' => $this->id((string) ($entry['id'] ?? ''))];
                $events[] = [
                    'kind' => $kind,
                    'key' => $this->key($kind . ':' . hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES) . $this->canonical($value))),
                    'phone' => '',
                    'body' => $body,
                ];
            }
        }
        return $events;
    }

    /** @param array{kind:string,key:string,phone:string,body:array<string,mixed>} $event */
    private function store(array $event): string
    {
        $json = json_encode($event['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return 'skipped';
        }
        $sealed = $this->seal->seal($json, 200000);
        $connection = $this->route($event);
        $businessId = $connection['business_id'] ?? null;
        $connectionId = $connection['id'] ?? null;
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO webhook_events (
                    business_id, connection_id, dedupe_key, event_kind, status, attempt_count, max_attempts,
                    payload_hash, payload_ciphertext, payload_nonce, payload_tag, payload_key_version, received_at
                 ) VALUES (?, ?, ?, ?, \'accepted\', 0, 5, ?, UNHEX(?), UNHEX(?), UNHEX(?), ?, UTC_TIMESTAMP())'
            );
            $insert->execute([
                $businessId,
                $connectionId,
                $event['key'],
                $event['kind'],
                hash('sha256', $json),
                bin2hex($sealed['ciphertext']),
                bin2hex($sealed['nonce']),
                bin2hex($sealed['tag']),
                $sealed['version'],
            ]);
        } catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'uq_webhook_dedupe') || str_contains($error->getMessage(), 'Duplicate')) {
                return 'replayed';
            }
            throw $error;
        }
        if ($event['kind'] === 'message' && is_int($businessId) && is_int($connectionId)) {
            $mark = $this->pdo->prepare(
                'UPDATE whatsapp_connections
                 SET messaging_state = \'receive_verified\', updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND business_id = ? AND token_state = \'verified\'
                   AND webhook_state = \'subscribed\' AND messaging_state = \'unverified\''
            );
            $mark->execute([$connectionId, $businessId]);
        }
        return 'stored';
    }

    /**
     * @param array{kind:string,key:string,phone:string,body:array<string,mixed>} $event
     * @return array{id:int,business_id:int}|null
     */
    private function route(array $event): ?array
    {
        if ($event['phone'] !== '') {
            return $this->connection($event['phone']);
        }
        $waba = $this->id($event['body']['waba_id'] ?? '');
        if ($waba === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, business_id FROM whatsapp_connections WHERE waba_id = ? AND disconnected_at IS NULL'
        );
        $statement->execute([$waba]);
        $rows = $statement->fetchAll();
        if (count($rows) !== 1 || !is_array($rows[0])) {
            return null;
        }
        return ['id' => (int) $rows[0]['id'], 'business_id' => (int) $rows[0]['business_id']];
    }

    /** @return array{id:int,business_id:int}|null */
    private function connection(string $phoneId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, business_id FROM whatsapp_connections WHERE phone_number_id = ? AND disconnected_at IS NULL LIMIT 1'
        );
        $statement->execute([$phoneId]);
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
