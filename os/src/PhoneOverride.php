<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/**
 * Phone-level callback override for one Coexistence connection.
 * The app callback is never written. A WABA subscription is never posted.
 * Meta success does not mark the connection subscribed.
 */
final class PhoneOverride
{
    public const OS_CALLBACK = 'https://beshareaisolution.com/whatsapp/sambungan/webhook.php';

    public const APP_CALLBACK = 'https://beshareaisolution.com/webhook/';

    /** @var list<string> */
    private const PROTECTED_DIGITS = ['01154320018', '601154320018'];

    /** @var list<string> */
    private const PROTECTED_IDS = ['1443799975474056'];

    public function __construct(
        private PDO $pdo,
        private MetaGraph $graph,
        private TokenSeal $seal,
        private string $appId,
        private bool $armed = false
    ) {
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{status:string,calls_graph:bool,webhook_state:string}
     */
    public function inspect(array $actor, int $connectionId): array
    {
        if (!$this->armed) {
            return ['status' => 'disabled', 'calls_graph' => false, 'webhook_state' => ''];
        }
        $connection = $this->connection($actor, $connectionId);
        $token = $this->openToken($connection);
        $this->readConfiguration($token, $connection);
        return ['status' => 'inspected', 'calls_graph' => true, 'webhook_state' => (string) $connection['webhook_state']];
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{status:string,calls_graph:bool,webhook_state:string}
     */
    public function apply(array $actor, int $connectionId, string $verifyToken): array
    {
        if (!$this->armed) {
            return ['status' => 'disabled', 'calls_graph' => false, 'webhook_state' => ''];
        }
        $connection = $this->connection($actor, $connectionId);
        $token = $this->openToken($connection);
        $config = $this->readConfiguration($token, $connection);
        $phoneUrl = $config['phone'];
        if ($phoneUrl === self::OS_CALLBACK) {
            $this->audit($actor, 'panggilan_disemak', (int) $connection['id'], 'already_set');
            return ['status' => 'already_set', 'calls_graph' => true, 'webhook_state' => (string) $connection['webhook_state']];
        }
        $this->graph->setPhoneOverride($token, (string) $connection['phone_number_id'], self::OS_CALLBACK, $verifyToken);
        $verified = $this->phoneConfiguration($token, (string) $connection['phone_number_id']);
        $status = $verified['phone'] === self::OS_CALLBACK
            && $verified['application'] === self::APP_CALLBACK
            && $verified['waba'] === ''
            ? 'configured'
            : 'unverified';
        $this->audit($actor, 'panggilan_ditetap', (int) $connection['id'], $status);
        return ['status' => $status, 'calls_graph' => true, 'webhook_state' => $this->webhookState((int) $connection['id'], $actor['business_id'])];
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{status:string,calls_graph:bool,webhook_state:string}
     */
    public function rollback(array $actor, int $connectionId): array
    {
        if (!$this->armed) {
            return ['status' => 'disabled', 'calls_graph' => false, 'webhook_state' => ''];
        }
        $connection = $this->connection($actor, $connectionId);
        $token = $this->openToken($connection);
        $config = $this->readConfiguration($token, $connection);
        if ($config['phone'] === '') {
            $this->audit($actor, 'panggilan_dibatalkan', (int) $connection['id'], 'already_clear');
            return ['status' => 'already_clear', 'calls_graph' => true, 'webhook_state' => (string) $connection['webhook_state']];
        }
        if ($config['phone'] !== self::OS_CALLBACK) {
            throw new AuthException('Panggilan sedia ada tidak sepadan.', 409);
        }
        $this->graph->clearPhoneOverride($token, (string) $connection['phone_number_id']);
        $verified = $this->phoneConfiguration($token, (string) $connection['phone_number_id']);
        $status = $verified['phone'] === '' && $verified['application'] === self::APP_CALLBACK && $verified['waba'] === ''
            ? 'cleared'
            : 'unverified';
        $this->audit($actor, 'panggilan_dibatalkan', (int) $connection['id'], $status);
        return ['status' => $status, 'calls_graph' => true, 'webhook_state' => $this->webhookState((int) $connection['id'], $actor['business_id'])];
    }

    /**
     * Subscription follows stored signed delivery, not a Graph success flag.
     *
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{status:string,calls_graph:bool}
     */
    public function noteSubscribed(array $actor, int $connectionId): array
    {
        $this->assertOwner($actor);
        $connection = $this->connection($actor, $connectionId);
        if (!CoexistenceSync::hasDeliveryEvidence($this->pdo, (int) $connection['id'], $actor['business_id'])) {
            throw new AuthException('Bukti webhook belum disahkan.', 409);
        }
        $statement = $this->pdo->prepare(
            'UPDATE whatsapp_connections
             SET webhook_state = \'subscribed\', subscribed_at = COALESCE(subscribed_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND business_id = ? AND disconnected_at IS NULL AND token_state = \'verified\''
        );
        $statement->execute([(int) $connection['id'], $actor['business_id']]);
        $this->audit($actor, 'langganan_disahkan', (int) $connection['id'], 'subscribed');
        return ['status' => 'subscribed', 'calls_graph' => false];
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor */
    private function assertOwner(array $actor): void
    {
        if ($actor['status'] !== 'active' || $actor['role'] !== 'owner' || (int) $actor['user_id'] !== (int) $actor['owner_user_id']) {
            throw new AuthException('Tindakan ditolak.', 403);
        }
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array<string,mixed>
     */
    private function connection(array $actor, int $connectionId): array
    {
        $this->assertOwner($actor);
        if ($connectionId < 1) {
            throw new AuthException('Sambungan tidak dijumpai.', 404);
        }
        $statement = $this->pdo->prepare(
            'SELECT id, business_id, waba_id, phone_number_id, display_phone, webhook_state, sync_deadline_at,
                    HEX(token_ciphertext) AS token_ciphertext, HEX(token_nonce) AS token_nonce,
                    HEX(token_tag) AS token_tag, token_key_version
             FROM whatsapp_connections
             WHERE id = ? AND business_id = ? AND disconnected_at IS NULL AND sync_deadline_at IS NOT NULL'
        );
        $statement->execute([$connectionId, $actor['business_id']]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new AuthException('Sambungan tidak dijumpai.', 404);
        }
        if ($this->protected((string) ($row['display_phone'] ?? ''), (string) $row['phone_number_id'])) {
            throw new AuthException('Nombor ini tidak boleh diubah.', 409);
        }
        if (preg_match('/\A\d{5,32}\z/', (string) $row['waba_id']) !== 1 || preg_match('/\A\d{5,32}\z/', (string) $row['phone_number_id']) !== 1) {
            throw new AuthException('Nombor ini tidak boleh diubah.', 409);
        }
        return $row;
    }

    private function protected(string $display, string $phoneId): bool
    {
        if (in_array($phoneId, self::PROTECTED_IDS, true)) {
            return true;
        }
        $digits = preg_replace('/\D/', '', $display);
        $digits = is_string($digits) ? $digits : '';
        $forms = [$digits];
        if (str_starts_with($digits, '60') && strlen($digits) > 2) {
            $forms[] = '0' . substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) > 1) {
            $forms[] = '60' . substr($digits, 1);
        }
        foreach ($forms as $form) {
            if (in_array($form, self::PROTECTED_DIGITS, true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $connection */
    private function openToken(array $connection): string
    {
        $cipher = hex2bin((string) ($connection['token_ciphertext'] ?? ''));
        $nonce = hex2bin((string) ($connection['token_nonce'] ?? ''));
        $tag = hex2bin((string) ($connection['token_tag'] ?? ''));
        if (!is_string($cipher) || !is_string($nonce) || !is_string($tag)) {
            throw new AuthException('Kelayakan Meta tidak dapat dibaca.', 409);
        }
        return $this->seal->open($cipher, $nonce, $tag, (int) $connection['token_key_version']);
    }

    /**
     * @param array<string,mixed> $connection
     * @return array{phone:string,waba:string,application:string}
     */
    private function readConfiguration(string $token, array $connection): array
    {
        $apps = $this->graph->subscribedApps($token, (string) $connection['waba_id']);
        $this->assertSubscribedApp($apps);
        return $this->phoneConfiguration($token, (string) $connection['phone_number_id']);
    }

    /** @param array<string,mixed> $apps */
    private function assertSubscribedApp(array $apps): void
    {
        $rows = is_array($apps['data'] ?? null) ? $apps['data'] : [];
        $matched = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $app = is_array($row['whatsapp_business_api_data'] ?? null) ? $row['whatsapp_business_api_data'] : [];
            if ((string) ($app['id'] ?? '') !== $this->appId) {
                continue;
            }
            $matched = true;
            $override = $row['override_callback_uri'] ?? '';
            if (is_string($override) && $override !== '') {
                throw new AuthException('Panggilan WABA sedia ada tidak sepadan.', 409);
            }
        }
        if (!$matched) {
            throw new AuthException('Aplikasi belum dilanggan pada WABA ini.', 409);
        }
    }

    /** @return array{phone:string,waba:string,application:string} */
    private function phoneConfiguration(string $token, string $phoneId): array
    {
        $json = $this->graph->webhookConfiguration($token, $phoneId);
        if ((string) ($json['id'] ?? '') !== $phoneId) {
            throw new AuthException('Konfigurasi panggilan tidak sepadan.', 409);
        }
        $config = is_array($json['webhook_configuration'] ?? null) ? $json['webhook_configuration'] : [];
        $phone = $this->url($config['phone_number'] ?? '');
        $waba = $this->url($config['whatsapp_business_account'] ?? '');
        $application = $this->url($config['application'] ?? '');
        if ($application !== self::APP_CALLBACK) {
            throw new AuthException('Panggilan aplikasi tidak sepadan.', 409);
        }
        if ($waba !== '') {
            throw new AuthException('Panggilan WABA sedia ada tidak sepadan.', 409);
        }
        if ($phone !== '' && $phone !== self::OS_CALLBACK) {
            throw new AuthException('Panggilan telefon sedia ada tidak sepadan.', 409);
        }
        return ['phone' => $phone, 'waba' => $waba, 'application' => $application];
    }

    private function url(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $trimmed = rtrim($value, '/');
        if ($trimmed === 'https://beshareaisolution.com/webhook') {
            return self::APP_CALLBACK;
        }
        if ($value === self::OS_CALLBACK) {
            return self::OS_CALLBACK;
        }
        return $value;
    }

    private function webhookState(int $connectionId, int $businessId): string
    {
        $statement = $this->pdo->prepare('SELECT webhook_state FROM whatsapp_connections WHERE id = ? AND business_id = ?');
        $statement->execute([$connectionId, $businessId]);
        $state = $statement->fetchColumn();
        return is_string($state) ? $state : '';
    }

    /** @param array{user_id:int,business_id:int} $actor */
    private function audit(array $actor, string $action, int $entityId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (business_id, actor_user_id, actor_kind, action, entity_type, entity_id, meta_json, created_at)
             VALUES (?, ?, \'user\', ?, \'whatsapp_connection\', ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([
            $actor['business_id'],
            $actor['user_id'],
            $action,
            $entityId,
            json_encode(['status' => $status], JSON_UNESCAPED_SLASHES),
        ]);
    }
}
