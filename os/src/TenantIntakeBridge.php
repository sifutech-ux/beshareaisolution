<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use Throwable;

require_once __DIR__ . '/AiReplyPolicy.php';

/** Signed message/status intake for already-bound customer assets, with no outbound calls. */
final class TenantIntakeBridge
{
    /**
     * The original provider bytes are authenticated before decoding or DB access.
     * New messages require an active local binding. Historical status receipts
     * retain their exact WABA/phone binding after suspension. Unknown assets,
     * history, echoes and lifecycle changes stay on their existing callback path.
     *
     * @return array{status:string,assets:array,message_assets:array,stored:int,replayed:int}
     */
    public static function deliver(string $raw, string $signature, array $config, ?PDO $pdo = null, ?TokenSeal $seal = null): array
    {
        if (!AiReplyPolicy::allBusinesses($config)) { return self::result('disabled'); }
        $secret = $config['app_secret'] ?? null;
        if (!is_string($secret) || !WhatsAppWebhook::signatureMatches($raw, $signature, $secret)
            || strlen($raw) > 1048576) {
            return self::result('rejected');
        }
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account'
                || !is_array($payload['entry'] ?? null) || !array_is_list($payload['entry'])) {
                return self::result('rejected');
            }
            $selected = [];
            $bindings = [];
            $keys = [];
            $messageAssets = [];
            foreach ($payload['entry'] as $entry) {
                if (!is_array($entry) || !is_array($entry['changes'] ?? null)) { continue; }
                $waba = self::id($entry['id'] ?? null);
                if ($waba === '') { continue; }
                foreach ($entry['changes'] as $change) {
                    if (!is_array($change) || ($change['field'] ?? '') !== 'messages') { continue; }
                    $value = $change['value'] ?? null;
                    if (!is_array($value) || !is_array($value['metadata'] ?? null)) { continue; }
                    $phone = self::id($value['metadata']['phone_number_id'] ?? null);
                    if ($phone === '') { continue; }
                    $pdo ??= Db::connect();
                    $binding = self::mapping($pdo, $waba, $phone);
                    if ($binding === null) { continue; }
                    $messages = $value['messages'] ?? [];
                    $statuses = $value['statuses'] ?? [];
                    if (!is_array($messages) || !array_is_list($messages)
                        || !is_array($statuses) || !array_is_list($statuses)) {
                        return self::result('failed');
                    }
                    if (!$binding['message_allowed']) { $messages = []; }
                    $asset = $waba . ':' . $phone;
                    foreach ($messages as $message) {
                        if (!is_array($message) || !self::wamid($message['id'] ?? null)
                            || !self::peer($message['from'] ?? null) || !self::stamp($message['timestamp'] ?? null)
                            || !is_string($message['type'] ?? null)
                            || ($message['type'] === 'text' && (!is_array($message['text'] ?? null)
                                || !is_string($message['text']['body'] ?? null)))) {
                            return self::result('failed');
                        }
                        $keys[$asset][] = self::key('m:' . $phone . ':' . $message['id']);
                    }
                    foreach ($statuses as $status) {
                        if (!is_array($status) || !self::wamid($status['id'] ?? null)
                            || !self::stamp($status['timestamp'] ?? null)
                            || !in_array($status['status'] ?? null, ['sent', 'delivered', 'read', 'failed', 'deleted'], true)
                            || (isset($status['recipient_id']) && !self::peer($status['recipient_id']))) {
                            return self::result('failed');
                        }
                        $keys[$asset][] = self::key('s:' . $phone . ':' . $status['id'] . ':' . $status['status'] . ':' . $status['timestamp']);
                    }
                    if ($messages === [] && $statuses === []) { continue; }
                    if ($messages !== []) { $messageAssets[$asset] = true; }
                    $contacts = [];
                    foreach (is_array($value['contacts'] ?? null) ? $value['contacts'] : [] as $contact) {
                        if (is_array($contact) && self::peer($contact['wa_id'] ?? null)
                            && is_array($contact['profile'] ?? null) && is_string($contact['profile']['name'] ?? null)) {
                            $contacts[] = ['wa_id' => $contact['wa_id'], 'profile' => ['name' => $contact['profile']['name']]];
                        }
                    }
                    $bindings[$asset] = ['id' => $binding['id'], 'business_id' => $binding['business_id']];
                    $selected[] = ['id' => $waba, 'changes' => [['field' => 'messages', 'value' => [
                        'metadata' => ['phone_number_id' => $phone], 'messages' => $messages,
                        'statuses' => $statuses, 'contacts' => $contacts,
                    ]]]];
                }
            }
            if ($selected === []) { return self::result('ignored'); }
            $seal ??= TokenSeal::tryFrom($config);
            if (!$seal instanceof TokenSeal || !$pdo instanceof PDO) { return self::result('failed'); }
            $slice = json_encode(['object' => 'whatsapp_business_account', 'entry' => $selected],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $result = (new WhatsAppWebhook($pdo, $seal))->ingest($slice, $bindings);
            $expected = array_sum(array_map('count', $keys));
            if ($result['stored'] + $result['replayed'] !== $expected) { return self::result('failed'); }
            foreach ($keys as $asset => $assetKeys) {
                $binding = $bindings[$asset];
                // A global duplicate key is not proof that the intended customer
                // received the record. Verify every dedupe key's exact local scope.
                foreach (array_chunk(array_values(array_unique($assetKeys)), 200) as $chunk) {
                    $query = $pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE business_id=? AND connection_id=? AND dedupe_key IN ('
                        . implode(',', array_fill(0, count($chunk), '?')) . ')');
                    $query->execute(array_merge([$binding['business_id'], $binding['id']], $chunk));
                    if ((int) $query->fetchColumn() !== count($chunk)) { return self::result('failed'); }
                }
            }
            return ['status' => 'stored', 'assets' => array_keys($bindings), 'message_assets' => array_keys($messageAssets),
                'stored' => $result['stored'], 'replayed' => $result['replayed']];
        } catch (Throwable) {
            // The caller returns retryable 503. No body, key, token or exception is logged.
            return self::result('failed');
        }
    }

    public static function handles(mixed $entry, mixed $change, array $result): bool
    {
        if (($result['status'] ?? '') !== 'stored' || !is_array($entry) || !is_array($change)
            || ($change['field'] ?? '') !== 'messages' || !is_array($change['value'] ?? null)
            || !is_array($change['value']['metadata'] ?? null)) {
            return false;
        }
        $asset = self::id($entry['id'] ?? null) . ':' . self::id($change['value']['metadata']['phone_number_id'] ?? null);
        $hasMessages = ($change['value']['messages'] ?? []) !== [];
        return in_array($asset, $result[$hasMessages ? 'message_assets' : 'assets'] ?? [], true);
    }

    private static function mapping(PDO $pdo, string $waba, string $phone): ?array
    {
        $query = $pdo->prepare("SELECT c.id,c.business_id,c.disconnected_at,c.token_state,
            b.status AS business_status,u.status AS owner_status,bu.role
            FROM whatsapp_connections c JOIN businesses b ON b.id=c.business_id
            JOIN users u ON u.id=b.owner_user_id
            LEFT JOIN business_users bu ON bu.business_id=b.id AND bu.user_id=b.owner_user_id
            WHERE c.waba_id=? AND c.phone_number_id=?");
        $query->execute([$waba, $phone]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) { return null; }
        $row = $rows[0];
        return ['id' => (int) $row['id'], 'business_id' => (int) $row['business_id'],
            'message_allowed' => $row['business_status'] === 'active' && $row['owner_status'] === 'active'
                && $row['role'] === 'owner' && $row['disconnected_at'] === null && $row['token_state'] === 'verified'];
    }

    private static function id(mixed $value): string
    {
        $id = is_string($value) || is_int($value) ? (string) $value : '';
        return preg_match('/\A[0-9]{5,32}\z/D', $id) === 1 ? $id : '';
    }

    private static function peer(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{6,20}\z/D', $value) === 1;
    }

    private static function wamid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._+\/=\-]{8,180}\z/D', $value) === 1;
    }

    private static function stamp(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{10,13}\z/D', (string) $value) === 1;
    }

    private static function key(string $value): string
    {
        return strlen($value) <= 191 ? $value : 'h:' . hash('sha256', $value);
    }

    private static function result(string $status): array
    {
        return ['status' => $status, 'assets' => [], 'message_assets' => [], 'stored' => 0, 'replayed' => 0];
    }
}
