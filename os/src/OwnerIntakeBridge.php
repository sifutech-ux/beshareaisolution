<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use Throwable;

/** Signed intake for the existing owner's Cloud API assets; no outbound provider calls. */
final class OwnerIntakeBridge
{
    private const PORTFOLIO = '1249591211577465';
    private const WABA = '1104940391945821';
    private const PHONE = '1443799975474056';

    /**
     * The original bytes are authenticated before decoding, slicing, connecting or writing.
     * A successful result covers only the configured owner slice. The app callback remains
     * responsible for its other legacy and lifecycle paths.
     * @param array<string,mixed> $config
     * @return array{status:string,owner_slice:bool,stored:int,replayed:int}
     */
    public static function deliver(string $raw, string $signature, array $config, ?PDO $pdo = null, ?TokenSeal $seal = null): array
    {
        if (($config['owner_intake_enabled'] ?? '') !== 'true') return self::result('disabled');
        $secret = $config['app_secret'] ?? null;
        if (!is_string($secret) || !WhatsAppWebhook::signatureMatches($raw, $signature, $secret)) {
            return self::result('rejected');
        }
        if (strlen($raw) > 1048576) return self::result('rejected');
        $business = $config['owner_whatsapp_business_id'] ?? null;
        if (!is_string($business) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $business) !== 1 || (string) (int) $business !== $business
            || ($config['owner_whatsapp_portfolio_id'] ?? null) !== self::PORTFOLIO
            || ($config['owner_whatsapp_waba_id'] ?? null) !== self::WABA
            || ($config['owner_whatsapp_phone_number_id'] ?? null) !== self::PHONE) {
            return self::result('failed');
        }
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account'
                || !is_array($payload['entry'] ?? null) || !array_is_list($payload['entry'])) {
                return self::result('rejected');
            }
            $changes = [];
            $keys = [];
            foreach ($payload['entry'] as $entry) {
                if (!is_array($entry) || self::id($entry['id'] ?? null) !== self::WABA) continue;
                if (!is_array($entry['changes'] ?? null) || !array_is_list($entry['changes'])) return self::result('failed', true);
                foreach ($entry['changes'] as $change) {
                    if (!is_array($change) || ($change['field'] ?? '') !== 'messages') continue;
                    $value = $change['value'] ?? null;
                    if (!is_array($value)) return self::result('failed', true);
                    $metadata = $value['metadata'] ?? null;
                    if (!is_array($metadata) || self::id($metadata['phone_number_id'] ?? null) !== self::PHONE) continue;
                    $messages = $value['messages'] ?? [];
                    $statuses = $value['statuses'] ?? [];
                    if (!is_array($messages) || !array_is_list($messages) || !is_array($statuses) || !array_is_list($statuses)) return self::result('failed', true);
                    foreach ($messages as $message) {
                        if (!is_array($message) || !self::wamid($message['id'] ?? null)
                            || !is_string($message['from'] ?? null) || preg_match('/\A[0-9]{6,20}\z/D', $message['from']) !== 1
                            || !self::stamp($message['timestamp'] ?? null) || !is_string($message['type'] ?? null)
                            || ($message['type'] === 'text' && (!is_array($message['text'] ?? null) || !is_string($message['text']['body'] ?? null)))) {
                            return self::result('failed', true);
                        }
                        $keys[] = self::key('m:' . self::PHONE . ':' . $message['id']);
                    }
                    foreach ($statuses as $status) {
                        if (!is_array($status) || !self::wamid($status['id'] ?? null) || !self::stamp($status['timestamp'] ?? null)
                            || !in_array($status['status'] ?? null, ['sent', 'delivered', 'read', 'failed', 'deleted'], true)
                            || (isset($status['recipient_id']) && (!is_string($status['recipient_id']) || preg_match('/\A[0-9]{6,20}\z/D', $status['recipient_id']) !== 1))) {
                            return self::result('failed', true);
                        }
                        $keys[] = self::key('s:' . self::PHONE . ':' . $status['id'] . ':' . $status['status'] . ':' . $status['timestamp']);
                    }
                    if ($messages === [] && $statuses === []) continue;
                    $contacts = [];
                    foreach (is_array($value['contacts'] ?? null) ? $value['contacts'] : [] as $contact) {
                        if (is_array($contact) && is_string($contact['wa_id'] ?? null)
                            && is_array($contact['profile'] ?? null) && is_string($contact['profile']['name'] ?? null)) {
                            $contacts[] = ['wa_id' => $contact['wa_id'], 'profile' => ['name' => $contact['profile']['name']]];
                        }
                    }
                    $changes[] = ['field' => 'messages', 'value' => [
                        'metadata' => ['phone_number_id' => self::PHONE],
                        'messages' => $messages, 'statuses' => $statuses, 'contacts' => $contacts,
                    ]];
                }
            }
            if ($changes === []) return self::result('ignored');
            $slice = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => self::WABA, 'changes' => $changes]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $seal ??= TokenSeal::tryFrom($config);
            if (!$seal instanceof TokenSeal) return self::result('failed', true);
            $pdo ??= Db::connect();
            $connectionId = self::mapping($pdo, (int) $business);
            if ($connectionId === null) return self::result('failed', true);
            $result = (new WhatsAppWebhook($pdo, $seal))->ingest($slice);
            if (($result['stored'] + $result['replayed']) !== count($keys) || self::mapping($pdo, (int) $business) !== $connectionId) {
                return self::result('failed', true);
            }
            // A retry is acknowledged only if each dedupe key belongs to this exact tenant
            // and connection. Existing unrelated/unscoped rows cannot count as owner intake.
            foreach (array_chunk(array_values(array_unique($keys)), 200) as $chunk) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE business_id=? AND connection_id=? AND dedupe_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $check->execute(array_merge([(int) $business, $connectionId], $chunk));
                if ((int) $check->fetchColumn() !== count($chunk)) return self::result('failed', true);
            }
            return ['status' => 'stored', 'owner_slice' => true, 'stored' => $result['stored'], 'replayed' => $result['replayed']];
        } catch (Throwable) {
            // No raw body, provider data, token, secret, path or exception text is logged.
            return self::result('failed', true);
        }
    }

    private static function mapping(PDO $pdo, int $businessId): ?int
    {
        $query = $pdo->prepare("SELECT c.id FROM whatsapp_connections c
            INNER JOIN businesses b ON b.id=c.business_id AND b.status='active'
            INNER JOIN users u ON u.id=b.owner_user_id AND u.status='active'
            INNER JOIN business_users bu ON bu.business_id=b.id AND bu.user_id=b.owner_user_id AND bu.role='owner'
            WHERE c.business_id=? AND c.waba_id=? AND c.phone_number_id=?
            AND c.disconnected_at IS NULL AND c.token_state='verified'");
        $query->execute([$businessId, self::WABA, self::PHONE]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    private static function id(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private static function wamid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._+\/=\-]{8,180}\z/D', $value) === 1;
    }

    private static function stamp(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{10,13}\z/D', (string) $value) === 1;
    }

    private static function key(string $key): string
    {
        return strlen($key) <= 191 ? $key : 'h:' . hash('sha256', $key);
    }

    /** @return array{status:string,owner_slice:bool,stored:int,replayed:int} */
    private static function result(string $status, bool $owner = false): array
    {
        return ['status' => $status, 'owner_slice' => $owner, 'stored' => 0, 'replayed' => 0];
    }
}
