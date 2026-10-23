<?php
declare(strict_types=1);

namespace BesharOs;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class OwnerBindingException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

/** Imports an existing owner-controlled Cloud API number. Meta operations are GET-only. */
final class OwnerConnectionImporter
{
    private Closure $clock;
    private string $driver;

    public function __construct(
        private PDO $pdo,
        private MetaGraph $graph,
        private TokenSeal $seal,
        private string $appId,
        ?Closure $clock = null
    ) {
        if (preg_match('/\A[0-9]{5,20}\z/D', $appId) !== 1) {
            throw new OwnerBindingException('app_configuration_invalid');
        }
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'sqlite'], true)) {
            throw new OwnerBindingException('database_driver_unsupported');
        }
        $this->clock = $clock ?? static fn (): int => time();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * $actor IDs must identify an existing OS membership; all roles are reloaded from the DB.
     * $expected = portfolio_id, waba_id, phone_number_id, display_phone (international form),
     * and optional replace_connection_id (an explicitly reviewed same-tenant connection).
     * No access token, ciphertext, provider body or credential path is returned.
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $expected
     * @return array<string,mixed>
     */
    public function inspect(array $actor, string $token, array $expected): array
    {
        return $this->receipt($this->verify($actor, $token, $expected), 'preflight');
    }

    /**
     * Rechecks the preflight through GETs, then writes only the local connection and audit.
     * The fingerprint binds the intended mapping, token and verified Meta facts, not row IDs,
     * so retrying the identical approved command remains idempotent after a successful insert.
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $expected
     * @return array<string,mixed>
     */
    public function apply(array $actor, string $token, array $expected, string $expectedFingerprint): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint) !== 1) {
            throw new OwnerBindingException('preflight_fingerprint_required');
        }
        $proof = $this->verify($actor, $token, $expected);
        if (!hash_equals($proof['fingerprint'], $expectedFingerprint)) {
            throw new OwnerBindingException('preflight_changed');
        }
        if ($this->pdo->inTransaction()) {
            throw new OwnerBindingException('transaction_already_active');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockRows($proof['actor'], $proof['assets']);
            $owner = $this->owner($proof['actor']);
            if ($owner['user_id'] !== $proof['actor']['user_id'] || $owner['business_id'] !== $proof['actor']['business_id']) {
                throw new OwnerBindingException('owner_changed');
            }
            $expiry = $proof['token']['effective_expires_at'];
            if ($expiry > 0 && $expiry <= ($this->clock)()) {
                throw new OwnerBindingException('token_expired');
            }
            $existing = $this->existing($owner, $proof['assets'], $token, $proof['replace_id']);
            $replacement = $this->replacement($owner, $proof['assets'], $proof['replace_id'], $existing);
            if ($replacement !== $proof['replacement']) {
                throw new OwnerBindingException('replacement_changed');
            }
            if ($existing !== null) {
                // Preserve token, send/receive proof, callback flags and every existing value.
                $proof['existing'] = $existing;
                $this->pdo->commit();
                return $this->receipt($proof, 'already_bound');
            }
            $sealed = $this->seal->seal($token, 1800);
            $stamp = gmdate('Y-m-d H:i:s', ($this->clock)());
            if ($replacement !== null) {
                $disconnect = $this->pdo->prepare(
                    'UPDATE whatsapp_connections SET token_state = ?, token_ciphertext = NULL,
                     token_nonce = NULL, token_tag = NULL, token_key_version = NULL, token_expires_at = NULL,
                     refresh_supported = 0, messaging_state = ?, disconnected_at = ?, updated_at = ?
                     WHERE id = ? AND business_id = ? AND disconnected_at IS NULL'
                );
                $disconnect->execute(['cleared', 'unverified', $stamp, $stamp, $proof['replace_id'], $owner['business_id']]);
                if ($disconnect->rowCount() !== 1) throw new OwnerBindingException('replacement_changed');
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO whatsapp_connections (
                    business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
                    registration_state, messaging_state, token_kind, token_ciphertext, token_nonce,
                    token_tag, token_key_version, token_expires_at, refresh_supported, last_verified_at,
                    last_error_code, subscribed_at, disconnected_at, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NULL, ?, NULL, ?, ?)'
            );
            $values = [
                $owner['business_id'], $proof['assets']['waba_id'], $proof['assets']['phone_number_id'],
                '+' . $proof['assets']['display_phone'], 'verified',
                $proof['app_subscribed'] ? 'subscribed' : 'not_subscribed',
                $proof['registration_state'], 'unverified', $proof['token']['kind'],
                $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'], $sealed['version'],
                $expiry > 0 ? gmdate('Y-m-d H:i:s', $expiry) : null,
                $stamp, $proof['app_subscribed'] ? $stamp : null, $stamp, $stamp,
            ];
            foreach ($values as $index => $value) {
                $type = $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                if (in_array($index, [9, 10, 11], true)) $type = PDO::PARAM_LOB;
                $insert->bindValue($index + 1, $value, $type);
            }
            $insert->execute();
            $id = (int) $this->pdo->lastInsertId();
            $audit = $this->pdo->prepare(
                'INSERT INTO audit_logs (business_id, actor_user_id, actor_kind, action, entity_type, entity_id, meta_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $audit->execute([
                $owner['business_id'], $owner['user_id'], 'user', 'owner_whatsapp_bound', 'whatsapp_connection', $id,
                json_encode([
                    'source' => 'owner_existing_cloud_api',
                    'portfolio_id' => $proof['assets']['portfolio_id'],
                    'waba_id' => $proof['assets']['waba_id'],
                    'phone_number_id' => $proof['assets']['phone_number_id'],
                    'app_id' => $this->appId,
                    'meta_reads_only' => true,
                    'replaced_connection' => $replacement,
                    'replacement_disconnected_at' => $replacement === null ? null : $stamp,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $stamp,
            ]);
            $proof['existing'] = [
                'id' => $id, 'token_state' => 'verified',
                'webhook_state' => $proof['app_subscribed'] ? 'subscribed' : 'not_subscribed',
                'registration_state' => $proof['registration_state'], 'messaging_state' => 'unverified',
            ];
            $this->pdo->commit();
            return $this->receipt($proof, 'bound');
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof OwnerBindingException) throw $error;
            // Never expose SQL, secret material or provider exception details to CLI logs.
            throw new OwnerBindingException('binding_write_failed');
        }
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $expected @return array<string,mixed> */
    private function verify(array $actor, string $token, array $expected): array
    {
        $owner = $this->owner($actor);
        $assets = [];
        foreach (['portfolio_id', 'waba_id', 'phone_number_id'] as $key) {
            $value = $expected[$key] ?? null;
            if (!is_string($value) || preg_match('/\A[1-9][0-9]{4,31}\z/D', $value) !== 1) {
                throw new OwnerBindingException('expected_assets_invalid');
            }
            $assets[$key] = $value;
        }
        $assets['display_phone'] = self::normalizePhone($expected['display_phone'] ?? null);
        if (strlen($token) < 20 || strlen($token) > 1800 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new OwnerBindingException('token_format_invalid');
        }
        try {
            // These columns are already required by the current OS runtime (0001–0003).
            $this->pdo->query('SELECT sync_contacts, sync_history, callback_ready_at, fields_ready_at FROM whatsapp_connections WHERE 1 = 0');
        } catch (Throwable) {
            throw new OwnerBindingException('target_schema_unavailable');
        }
        $replaceId = $expected['replace_connection_id'] ?? null;
        if ($replaceId !== null && (!is_int($replaceId) || $replaceId < 1)) {
            throw new OwnerBindingException('replacement_id_invalid');
        }
        $existing = $this->existing($owner, $assets, $token, $replaceId);
        $replacement = $this->replacement($owner, $assets, $replaceId, $existing);
        $debug = $this->metaRead('token', fn (): array => $this->graph->debugToken($token));
        $tokenProof = $this->tokenProof($debug, $assets['waba_id']);
        $owned = $this->metaRead('ownership', fn (): array => $this->graph->ownerWabas($token, $assets['portfolio_id']));
        $this->oneAsset($owned, $assets['waba_id'], 'portfolio_waba_mismatch');
        $waba = $this->metaRead('waba', fn (): array => $this->graph->waba($token, $assets['waba_id']));
        $readWabaId = $waba['id'] ?? null;
        if ((!is_string($readWabaId) && !is_int($readWabaId)) || (string) $readWabaId !== $assets['waba_id']) {
            throw new OwnerBindingException('waba_mismatch');
        }
        $phones = $this->metaRead('phone', fn (): array => $this->graph->ownerPhones($token, $assets['waba_id']));
        $phone = $this->oneAsset($phones, $assets['phone_number_id'], 'phone_waba_mismatch');
        if (self::normalizePhone($phone['display_phone_number'] ?? null) !== $assets['display_phone']) {
            throw new OwnerBindingException('display_phone_mismatch');
        }
        $apps = $this->metaRead('subscription', fn (): array => $this->graph->ownerSubscribedApps($token, $assets['waba_id']));
        $subscribed = $this->hasApplication($apps);
        $phoneStatus = $phone['status'] ?? '';
        if (!is_string($phoneStatus)) throw new OwnerBindingException('phone_status_invalid');
        $registration = match (strtoupper($phoneStatus)) {
            'CONNECTED' => 'registered',
            'PENDING' => 'not_registered',
            'DELETED', 'BANNED', 'RESTRICTED' => 'failed',
            default => 'unknown',
        };
        $fingerprint = hash('sha256', (string) json_encode([
            'version' => 1,
            'business_id' => $owner['business_id'], 'owner_user_id' => $owner['user_id'],
            'app_id' => $this->appId, 'assets' => $assets,
            'replacement' => $replacement,
            'token_digest' => hash('sha256', $token), 'token_proof' => $tokenProof,
            'app_subscribed' => $subscribed, 'registration_state' => $registration,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return [
            'actor' => $owner, 'assets' => $assets, 'token' => $tokenProof,
            'app_subscribed' => $subscribed, 'registration_state' => $registration,
            'existing' => $existing, 'fingerprint' => $fingerprint,
            'replace_id' => $replaceId, 'replacement' => $replacement,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function owner(array $input): array
    {
        $userId = $input['user_id'] ?? null;
        $businessId = $input['business_id'] ?? null;
        if (!is_int($userId) || !is_int($businessId) || $userId < 1 || $businessId < 1) {
            throw new OwnerBindingException('owner_identity_required');
        }
        try {
            $actor = Membership::require($this->pdo, $userId, $businessId);
        } catch (Throwable) {
            throw new OwnerBindingException('owner_denied');
        }
        if ($actor['role'] !== 'owner' || $actor['status'] !== 'active' || $actor['owner_user_id'] !== $actor['user_id']) {
            throw new OwnerBindingException('owner_denied');
        }
        return $actor;
    }

    /** @param array<string,mixed> $debug @return array<string,mixed> */
    private function tokenProof(array $debug, string $wabaId): array
    {
        $data = $debug['data'] ?? null;
        $readAppId = is_array($data) ? ($data['app_id'] ?? null) : null;
        if (!is_array($data) || ($data['is_valid'] ?? false) !== true || (!is_string($readAppId) && !is_int($readAppId)) || (string) $readAppId !== $this->appId) {
            throw new OwnerBindingException('token_app_invalid');
        }
        $type = $data['type'] ?? '';
        if (!in_array($type, ['SYSTEM_USER', 'USER'], true)) {
            throw new OwnerBindingException('token_type_invalid');
        }
        $expires = $this->timestamp($data['expires_at'] ?? null);
        $dataExpires = $this->timestamp($data['data_access_expires_at'] ?? 0);
        if ($type === 'USER' && $expires === 0) throw new OwnerBindingException('user_token_expiry_required');
        $now = ($this->clock)();
        if (($expires > 0 && $expires <= $now) || ($dataExpires > 0 && $dataExpires <= $now)) {
            throw new OwnerBindingException('token_expired');
        }
        $scopes = $data['scopes'] ?? null;
        if (!is_array($scopes) || !array_is_list($scopes)) throw new OwnerBindingException('token_scopes_missing');
        foreach (['whatsapp_business_management', 'whatsapp_business_messaging'] as $scope) {
            if (!in_array($scope, $scopes, true)) throw new OwnerBindingException('token_scopes_missing');
            foreach (is_array($data['granular_scopes'] ?? null) ? $data['granular_scopes'] : [] as $grant) {
                if (!is_array($grant) || ($grant['scope'] ?? '') !== $scope || !array_key_exists('target_ids', $grant)) continue;
                $targets = $grant['target_ids'];
                if (!is_array($targets) || !array_is_list($targets)) {
                    throw new OwnerBindingException('token_waba_scope_mismatch');
                }
                $ids = [];
                foreach ($targets as $id) {
                    if ((!is_string($id) && !is_int($id)) || preg_match('/\A[0-9]{5,32}\z/D', (string) $id) !== 1) {
                        throw new OwnerBindingException('token_waba_scope_mismatch');
                    }
                    $ids[] = (string) $id;
                }
                if (!in_array($wabaId, $ids, true)) throw new OwnerBindingException('token_waba_scope_mismatch');
            }
        }
        // Owned-system-user tokens may omit granular target IDs. Ownership and exact phone
        // access are independently verified through the fixed read-only Graph edges above.
        $positive = array_values(array_filter([$expires, $dataExpires], static fn (int $value): bool => $value > 0));
        return [
            'type' => $type,
            'kind' => $type === 'SYSTEM_USER' ? 'system_user' : (($expires - $now) < 7200 ? 'user_short_lived' : 'user_long_lived'),
            'expires_at' => $expires,
            'data_access_expires_at' => $dataExpires,
            'effective_expires_at' => $positive === [] ? 0 : min($positive),
        ];
    }

    private function timestamp(mixed $value): int
    {
        if (is_int($value) && $value >= 0 && $value <= 253402300799) return $value;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $value) === 1 && (string) (int) $value === $value && (int) $value <= 253402300799) return (int) $value;
        throw new OwnerBindingException('token_expiry_unknown');
    }

    public static function normalizePhone(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A\+?[0-9 ()-]{8,40}\z/D', $value) !== 1) {
            throw new OwnerBindingException('display_phone_invalid');
        }
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
        if (preg_match('/\A[1-9][0-9]{7,14}\z/D', $digits) !== 1) throw new OwnerBindingException('display_phone_invalid');
        return $digits;
    }

    /** @param array<string,mixed> $result @return list<array<string,mixed>> */
    private function rows(array $result): array
    {
        $rows = $result['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || isset($result['paging']['next'])) {
            throw new OwnerBindingException('asset_list_incomplete');
        }
        foreach ($rows as $row) if (!is_array($row)) throw new OwnerBindingException('asset_list_invalid');
        return $rows;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function oneAsset(array $result, string $id, string $reason): array
    {
        $matches = [];
        foreach ($this->rows($result) as $row) {
            $rowId = $row['id'] ?? null;
            if ((!is_string($rowId) && !is_int($rowId)) || preg_match('/\A[0-9]{5,32}\z/D', (string) $rowId) !== 1) {
                throw new OwnerBindingException('asset_list_invalid');
            }
            if ((string) $rowId === $id) $matches[] = $row;
        }
        if (count($matches) !== 1) throw new OwnerBindingException($reason);
        return $matches[0];
    }

    /** @param array<string,mixed> $result */
    private function hasApplication(array $result): bool
    {
        $count = 0;
        foreach ($this->rows($result) as $row) {
            $data = $row['whatsapp_business_api_data'] ?? $row;
            $id = is_array($data) ? ($data['id'] ?? null) : null;
            if ((!is_string($id) && !is_int($id)) || preg_match('/\A[0-9]{5,32}\z/D', (string) $id) !== 1) {
                throw new OwnerBindingException('app_list_invalid');
            }
            if ((string) $id === $this->appId) $count++;
        }
        if ($count > 1) throw new OwnerBindingException('app_list_ambiguous');
        return $count === 1;
    }

    /** @return array<string,mixed> */
    private function metaRead(string $stage, Closure $read): array
    {
        try {
            return $read();
        } catch (Throwable) {
            throw new OwnerBindingException('meta_' . $stage . '_read_failed');
        }
    }

    /** @param array<string,mixed> $actor @param array<string,string> $assets @return array<string,mixed>|null */
    private function existing(array $actor, array $assets, string $token, ?int $replaceId): ?array
    {
        $other = $this->pdo->prepare('SELECT id FROM whatsapp_connections WHERE business_id = ? AND phone_number_id <> ? AND disconnected_at IS NULL');
        $other->execute([$actor['business_id'], $assets['phone_number_id']]);
        foreach ($other->fetchAll() as $row) {
            if ((int) $row['id'] !== $replaceId) throw new OwnerBindingException('business_has_other_active_phone');
        }
        $query = $this->pdo->prepare('SELECT * FROM whatsapp_connections WHERE phone_number_id = ?');
        $query->execute([$assets['phone_number_id']]);
        $row = $query->fetch();
        if (!is_array($row)) return null;
        if ((int) $row['business_id'] !== $actor['business_id'] || (string) $row['waba_id'] !== $assets['waba_id']) {
            throw new OwnerBindingException('phone_already_bound_elsewhere');
        }
        if ($row['disconnected_at'] !== null || $row['token_state'] !== 'verified') {
            throw new OwnerBindingException('existing_connection_requires_recovery');
        }
        if (self::normalizePhone($row['display_phone'] ?? null) !== $assets['display_phone']) {
            throw new OwnerBindingException('existing_display_mismatch');
        }
        try {
            $stored = $this->seal->open(
                (string) $row['token_ciphertext'], (string) $row['token_nonce'],
                (string) $row['token_tag'], (int) $row['token_key_version']
            );
        } catch (Throwable) {
            throw new OwnerBindingException('existing_token_unreadable');
        }
        if (!hash_equals($stored, $token)) throw new OwnerBindingException('existing_token_requires_rotation');
        return [
            'id' => (int) $row['id'], 'token_state' => (string) $row['token_state'],
            'webhook_state' => (string) $row['webhook_state'], 'registration_state' => (string) $row['registration_state'],
            'messaging_state' => (string) $row['messaging_state'],
        ];
    }

    /**
     * Keep only a safe, reviewable snapshot. A completed replacement is recognized solely
     * from this importer's audit and the old row remaining locally disconnected and cleared.
     * @param array<string,mixed> $actor @param array<string,string> $assets
     * @param array<string,mixed>|null $existing @return array<string,mixed>|null
     */
    private function replacement(array $actor, array $assets, ?int $replaceId, ?array $existing): ?array
    {
        if ($replaceId === null) return null;
        $query = $this->pdo->prepare('SELECT * FROM whatsapp_connections WHERE id = ? AND business_id = ?');
        $query->execute([$replaceId, $actor['business_id']]);
        $row = $query->fetch();
        if (!is_array($row) || (string) $row['phone_number_id'] === $assets['phone_number_id']) {
            throw new OwnerBindingException('replacement_not_same_tenant_other_phone');
        }
        $snapshot = [
            'id' => (int) $row['id'], 'waba_id' => (string) $row['waba_id'],
            'phone_number_id' => (string) $row['phone_number_id'], 'display_phone' => $row['display_phone'],
            'token_state' => (string) $row['token_state'], 'webhook_state' => (string) $row['webhook_state'],
            'registration_state' => (string) $row['registration_state'], 'messaging_state' => (string) $row['messaging_state'],
            'updated_at' => (string) $row['updated_at'],
        ];
        if ($row['disconnected_at'] === null) {
            if ($existing !== null) throw new OwnerBindingException('target_already_bound');
            return $snapshot;
        }
        if ($existing === null || $row['token_state'] !== 'cleared' || $row['messaging_state'] !== 'unverified'
            || $row['token_ciphertext'] !== null || $row['token_nonce'] !== null || $row['token_tag'] !== null || $row['token_key_version'] !== null) {
            throw new OwnerBindingException('replacement_not_active');
        }
        $audit = $this->pdo->prepare(
            'SELECT meta_json FROM audit_logs WHERE business_id = ? AND action = ? AND entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 20'
        );
        $audit->execute([$actor['business_id'], 'owner_whatsapp_bound', 'whatsapp_connection', $existing['id']]);
        foreach ($audit->fetchAll() as $entry) {
            $meta = json_decode((string) $entry['meta_json'], true);
            $before = is_array($meta) ? ($meta['replaced_connection'] ?? null) : null;
            if (!is_array($before) || ($before['id'] ?? null) !== $replaceId) continue;
            if (($meta['replacement_disconnected_at'] ?? null) !== $row['disconnected_at']
                || ($meta['phone_number_id'] ?? null) !== $assets['phone_number_id']
                || ($meta['waba_id'] ?? null) !== $assets['waba_id']
                || ($meta['app_id'] ?? null) !== $this->appId
                || ($before['phone_number_id'] ?? null) !== $snapshot['phone_number_id']
                || ($before['waba_id'] ?? null) !== $snapshot['waba_id']
                || ($before['display_phone'] ?? null) !== $snapshot['display_phone']) continue;
            // MySQL JSON normalizes object key order; restore the original snapshot order
            // so the reviewed fingerprint remains stable on an idempotent retry.
            $ordered = [];
            foreach (array_keys($snapshot) as $key) {
                if (!array_key_exists($key, $before)) throw new OwnerBindingException('replacement_audit_invalid');
                $ordered[$key] = $before[$key];
            }
            return $ordered;
        }
        throw new OwnerBindingException('replacement_not_active');
    }

    /** @param array<string,mixed> $actor @param array<string,string> $assets */
    private function lockRows(array $actor, array $assets): void
    {
        if ($this->driver !== 'mysql') return;
        foreach ([
            ['SELECT id FROM businesses WHERE id = ? FOR UPDATE', [$actor['business_id']]],
            ['SELECT id FROM users WHERE id = ? FOR UPDATE', [$actor['user_id']]],
            ['SELECT business_id FROM business_users WHERE business_id = ? AND user_id = ? FOR UPDATE', [$actor['business_id'], $actor['user_id']]],
            ['SELECT id FROM whatsapp_connections WHERE phone_number_id = ? OR business_id = ? FOR UPDATE', [$assets['phone_number_id'], $actor['business_id']]],
        ] as [$sql, $values]) {
            $query = $this->pdo->prepare($sql);
            $query->execute($values);
            $query->fetchAll();
        }
    }

    /** @param array<string,mixed> $proof @return array<string,mixed> */
    private function receipt(array $proof, string $status): array
    {
        $existing = $proof['existing'];
        return [
            'status' => $status,
            'fingerprint' => $proof['fingerprint'],
            'business_id' => $proof['actor']['business_id'], 'owner_user_id' => $proof['actor']['user_id'],
            'portfolio_id' => $proof['assets']['portfolio_id'], 'app_id' => $this->appId,
            'waba_id' => $proof['assets']['waba_id'], 'phone_number_id' => $proof['assets']['phone_number_id'],
            'display_phone' => '+' . $proof['assets']['display_phone'],
            'token_kind' => $proof['token']['kind'], 'token_expires_at' => $proof['token']['effective_expires_at'],
            'observed_app_subscribed' => $proof['app_subscribed'],
            'observed_registration_state' => $proof['registration_state'],
            'replace_connection' => $proof['replacement'],
            'connection_id' => $existing['id'] ?? null,
            'stored_messaging_state' => $existing['messaging_state'] ?? 'unverified',
            'stored_webhook_state' => $existing['webhook_state'] ?? null,
            'stored_registration_state' => $existing['registration_state'] ?? null,
            'change' => $status === 'bound' ? 'inserted' : ($existing === null ? 'would_insert' : 'none'),
            'meta_mutations' => false, 'message_sent' => false,
            'callback_readiness_verified_by_import' => false, 'auto_reply_enabled_by_import' => false,
        ];
    }
}
