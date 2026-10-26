<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** A safe reason, never a provider response or credential. */
final class AdminWhatsAppActivationBlocked extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

/** Platform operations on one existing customer connection; no customer impersonation. */
final class AdminWhatsAppActivation
{
    private const CLAIM_SECONDS = 300;

    public function __construct(
        private PDO $pdo,
        private MetaGraph $graph,
        private TokenSeal $seal,
        private string $appId,
        private CloudPhoneRegistration $registration
    ) {
        if (preg_match('/\A[1-9]\d{4,31}\z/', $appId) !== 1) {
            throw new AuthException('Konfigurasi Meta tidak sah.', 503);
        }
    }

    public function refresh(array $admin, string $businessPublicId, int $connectionId): array
    {
        return $this->perform($admin, $businessPublicId, $connectionId, false);
    }

    public function activate(array $admin, string $businessPublicId, int $connectionId): array
    {
        return $this->perform($admin, $businessPublicId, $connectionId, true);
    }

    private function perform(array $admin, string $publicId, int $connectionId, bool $activate): array
    {
        $admin = PlatformAccess::requireAdmin($this->pdo, (int) ($admin['user_id'] ?? 0));
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1 || $connectionId < 1) {
            throw new AuthException('Sasaran sambungan tidak sah.', 400);
        }
        $claim = $this->claim($admin, $publicId, $connectionId);
        if (isset($claim['result'])) { return $claim['result']; }
        $connection = $claim['connection'];
        $operationId = $claim['operation_id'];
        $attempted = $claim['registration_started_at'] !== null;
        $token = null;
        $pin = null;
        try {
            $token = $this->seal->open((string) $connection['token_ciphertext'], (string) $connection['token_nonce'],
                (string) $connection['token_tag'], (int) $connection['token_key_version']);
            $proof = $this->inspect($token, $connection);
            $this->snapshot($admin, $connection, $operationId, $proof);
            if ($proof['registration_state'] === 'registered') {
                return $this->finish($admin, $connection, $operationId, 'succeeded', null, 'registered');
            }
            // A dispatched request (including a process crash) is never submitted twice.
            if ($attempted) {
                return $this->finish($admin, $connection, $operationId, 'unknown', 'registration_unconfirmed', $proof['registration_state']);
            }
            if (!$activate) {
                $result = $this->finish($admin, $connection, $operationId, 'succeeded', 'registration_pending', $proof['registration_state']);
                $result['status'] = 'refreshed';
                $result['message'] = 'Status Meta telah dikemas kini. Kesediaan nombor dan bukti mesej dipaparkan berasingan.';
                return $result;
            }
            if ($proof['coexistence'] || ($proof['phone']['is_on_biz_app'] ?? null) !== false) { $this->block('coexistence_or_unknown'); }
            // A number awaiting its first registration can have no hosting platform yet.
            // Both recognized values still require PENDING, verified ownership and standard signup below.
            if (!in_array($proof['phone']['platform_type'] ?? null, ['CLOUD_API', 'NOT_APPLICABLE'], true)) { $this->block('phone_platform_unknown'); }
            if (!$proof['granular_access']) { $this->block('asset_permission_missing'); }
            if (($proof['phone']['code_verification_status'] ?? null) !== 'VERIFIED') { $this->block('number_ownership_unverified'); }
            if ($proof['registration_state'] !== 'not_registered') { $this->block('phone_not_eligible'); }
            if (!$proof['subscribed']) { $this->block('webhook_not_subscribed'); }
            $pin = $this->startRegistration($admin, $connection, $operationId);
            $attempted = true;
            $outcome = $this->registration->register($token, (string) $connection['phone_number_id'], $pin);
            if ($outcome['status'] === 'registered') {
                return $this->registered($admin, $connection, $operationId);
            }
            return $this->finish($admin, $connection, $operationId,
                $outcome['status'] === 'rejected' ? 'blocked' : 'unknown', $outcome['error_code'], $proof['registration_state']);
        } catch (AdminWhatsAppActivationBlocked $error) {
            return $this->finish($admin, $connection, $operationId, $attempted ? 'unknown' : 'blocked', $error->reason);
        } catch (MetaCallException $error) {
            $code = preg_match('/\A[A-Za-z0-9_]{1,48}\z/', $error->metaCode) === 1 ? 'meta_' . $error->metaCode : 'meta_read_failed';
            return $this->finish($admin, $connection, $operationId, $attempted ? 'unknown' : 'blocked', $code);
        } catch (AuthException $error) {
            $this->finish($admin, $connection, $operationId, $attempted ? 'unknown' : 'blocked', 'access_or_connection_changed');
            throw $error;
        } catch (\Throwable) {
            return $this->finish($admin, $connection, $operationId, $attempted ? 'unknown' : 'blocked', 'operation_incomplete');
        } finally {
            unset($token, $pin);
        }
    }

    /** Claim is committed before network I/O. Old workers must still match operation_id. */
    private function claim(array $admin, string $publicId, int $connectionId): array
    {
        if ($this->pdo->inTransaction()) { throw new AuthException('Operasi bertindih tidak dibenarkan.', 409); }
        $this->pdo->beginTransaction();
        try {
            $connection = $this->target($publicId, $connectionId, true);
            $this->assertAvailable($admin, $connection);
            $statement = $this->pdo->prepare('SELECT * FROM platform_whatsapp_operations WHERE connection_id=? AND business_id=? FOR UPDATE');
            $statement->execute([$connectionId, $connection['business_id']]);
            $old = $statement->fetch();
            if (is_array($old) && in_array($old['status'], ['checking', 'registering'], true)
                && strtotime((string) $old['updated_at'] . ' UTC') > time() - self::CLAIM_SECONDS) {
                $this->pdo->commit();
                return ['result' => $this->result((string) $old['status'], $connectionId, 'operation_running')];
            }
            $operationId = bin2hex(random_bytes(16));
            $now = $this->now();
            if (is_array($old)) {
                $update = $this->pdo->prepare("UPDATE platform_whatsapp_operations SET operation_id=?,actor_user_id=?,status='checking',error_code=NULL,finished_at=NULL,updated_at=? WHERE connection_id=? AND business_id=?");
                $update->execute([$operationId, $admin['user_id'], $now, $connectionId, $connection['business_id']]);
            } else {
                $insert = $this->pdo->prepare("INSERT INTO platform_whatsapp_operations (connection_id,business_id,operation_id,actor_user_id,status,created_at,updated_at) VALUES (?,?,?,?,'checking',?,?)");
                $insert->execute([$connectionId, $connection['business_id'], $operationId, $admin['user_id'], $now, $now]);
            }
            $this->audit($admin, $connection, $operationId, 'admin_whatsapp_semakan', 'checking', null);
            $this->pdo->commit();
            return ['connection' => $connection, 'operation_id' => $operationId,
                'registration_started_at' => is_array($old) ? $old['registration_started_at'] : null];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function inspect(string $token, array $connection): array
    {
        if ($token === '' || strlen($token) > 1800 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) { $this->block('token_invalid'); }
        $debug = $this->graph->debugToken($token);
        $data = $debug['data'] ?? null;
        if (!is_array($data) || ($data['is_valid'] ?? null) !== true || (string) ($data['app_id'] ?? '') !== $this->appId) {
            $this->block('token_invalid');
        }
        foreach (['expires_at', 'data_access_expires_at'] as $expiry) {
            if (isset($data[$expiry]) && (!is_numeric($data[$expiry]) || ((int) $data[$expiry] > 0 && (int) $data[$expiry] <= time()))) {
                $this->block('token_expired');
            }
        }
        $granular = true;
        foreach (['whatsapp_business_management', 'whatsapp_business_messaging'] as $scope) {
            $target = false;
            foreach (is_array($data['granular_scopes'] ?? null) ? $data['granular_scopes'] : [] as $grant) {
                if (!is_array($grant) || ($grant['scope'] ?? null) !== $scope || !is_array($grant['target_ids'] ?? null)) { continue; }
                foreach ($grant['target_ids'] as $id) {
                    if ((is_string($id) || is_int($id)) && (string) $id === (string) $connection['waba_id']) { $target = true; }
                }
            }
            if (!$target && !in_array($scope, is_array($data['scopes'] ?? null) ? $data['scopes'] : [], true)) {
                $this->block('asset_permission_missing');
            }
            $granular = $granular && $target;
        }
        $waba = $this->graph->ownerWaba($token, (string) $connection['waba_id']);
        if ((string) ($waba['id'] ?? '') !== (string) $connection['waba_id']) { $this->block('waba_mismatch'); }
        $owningPortfolio = $waba['owner_business_info']['id'] ?? null;
        if ((!is_string($owningPortfolio) && !is_int($owningPortfolio))
            || preg_match('/\A[1-9]\d{4,31}\z/', (string) $owningPortfolio) !== 1) { $this->block('waba_owner_unverified'); }
        $phones = $this->graph->ownerPhones($token, (string) $connection['waba_id']);
        $matches = array_values(array_filter(is_array($phones['data'] ?? null) ? $phones['data'] : [],
            static fn ($phone): bool => is_array($phone) && (string) ($phone['id'] ?? '') === (string) $connection['phone_number_id']));
        if (count($matches) !== 1) { $this->block('phone_waba_mismatch'); }
        $phone = $this->registration->inspectPhone($token, (string) $connection['phone_number_id']);
        if ((string) ($phone['id'] ?? '') !== (string) $connection['phone_number_id']
            || $this->phone($phone['display_phone_number'] ?? null) !== $this->phone($connection['display_phone'])
            || $this->phone($matches[0]['display_phone_number'] ?? null) !== $this->phone($connection['display_phone'])) {
            $this->block('phone_display_mismatch');
        }
        $apps = $this->graph->ownerSubscribedApps($token, (string) $connection['waba_id']);
        $subscribed = false;
        foreach (is_array($apps['data'] ?? null) ? $apps['data'] : [] as $app) {
            if (is_array($app) && (string) ($app['whatsapp_business_api_data']['id'] ?? $app['id'] ?? '') === $this->appId) { $subscribed = true; }
        }
        $state = match (strtoupper(is_string($phone['status'] ?? null) ? $phone['status'] : '')) {
            'CONNECTED' => 'registered', 'PENDING' => 'not_registered',
            'DELETED', 'BANNED', 'RESTRICTED' => 'failed', default => 'unknown',
        };
        return ['phone' => $phone, 'registration_state' => $state, 'subscribed' => $subscribed,
            'granular_access' => $granular, 'coexistence' => $this->localCoexistence($connection) || ($phone['is_on_biz_app'] ?? null) === true];
    }

    /** A read snapshot must not invent message proof or weaken Coexistence delivery evidence. */
    private function snapshot(array $admin, array $expected, string $operationId, array $proof): void
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->guardClaim($admin, $expected, $operationId);
            $webhook = $proof['coexistence'] || !is_bool($proof['phone']['is_on_biz_app'] ?? null)
                ? (string) $current['webhook_state'] : ($proof['subscribed'] ? 'subscribed' : 'not_subscribed');
            if (($current['messaging_state'] === 'send_and_receive_verified' && $proof['registration_state'] !== 'registered')
                || (in_array($current['messaging_state'], ['receive_verified', 'send_and_receive_verified'], true) && $webhook !== 'subscribed')) {
                $this->block('evidence_conflict');
            }
            $subscribedAt = $webhook === 'subscribed' ? ($current['subscribed_at'] ?? $this->now()) : null;
            $lastError = $current['last_error_code'];
            if (in_array($lastError, ['expired', '190', 'waba', 'phone'], true)
                || ($lastError === 'subscribe_failed' && $webhook === 'subscribed')
                || (in_array($lastError, ['registration_pending', 'registration_unconfirmed', 'registration_transport', 'registration_response_unknown'], true)
                    && $proof['registration_state'] === 'registered')) {
                $lastError = null;
            }
            $update = $this->pdo->prepare('UPDATE whatsapp_connections SET registration_state=?,webhook_state=?,subscribed_at=?,last_error_code=?,last_verified_at=?,updated_at=? WHERE id=? AND business_id=?');
            $update->execute([$proof['registration_state'], $webhook, $subscribedAt, $lastError, $this->now(), $this->now(), $current['id'], $current['business_id']]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** Save the PIN and dispatch marker before the external write. */
    private function startRegistration(array $admin, array $expected, string $operationId): string
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->guardClaim($admin, $expected, $operationId);
            if ($this->localCoexistence($current)) { $this->block('coexistence_or_unknown'); }
            $this->assertEmbeddedConsent($current);
            $statement = $this->pdo->prepare("SELECT * FROM platform_whatsapp_operations WHERE connection_id=? AND business_id=? AND operation_id=? AND status='checking' FOR UPDATE");
            $statement->execute([$current['id'], $current['business_id'], $operationId]);
            $operation = $statement->fetch();
            if (!is_array($operation) || $operation['registration_started_at'] !== null) { $this->block('registration_unconfirmed'); }
            if ($operation['pin_ciphertext'] !== null) {
                $pin = $this->seal->open((string) $operation['pin_ciphertext'], (string) $operation['pin_nonce'],
                    (string) $operation['pin_tag'], (int) $operation['pin_key_version']);
                $sealed = ['ciphertext' => $operation['pin_ciphertext'], 'nonce' => $operation['pin_nonce'],
                    'tag' => $operation['pin_tag'], 'version' => $operation['pin_key_version']];
            } else {
                $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $sealed = $this->seal->seal($pin, 6);
            }
            if (preg_match('/\A\d{6}\z/', $pin) !== 1) { $this->block('pin_record_invalid'); }
            $now = $this->now();
            $update = $this->pdo->prepare("UPDATE platform_whatsapp_operations SET status='registering',error_code=NULL,pin_ciphertext=UNHEX(?),pin_nonce=UNHEX(?),pin_tag=UNHEX(?),pin_key_version=?,registration_started_at=?,updated_at=? WHERE connection_id=? AND business_id=? AND operation_id=? AND status='checking' AND registration_started_at IS NULL");
            $update->execute([bin2hex((string) $sealed['ciphertext']), bin2hex((string) $sealed['nonce']), bin2hex((string) $sealed['tag']),
                $sealed['version'], $now, $now, $current['id'], $current['business_id'], $operationId]);
            if ($update->rowCount() !== 1) { $this->block('operation_changed'); }
            $this->audit($admin, $current, $operationId, 'admin_whatsapp_pendaftaran', 'registering', null);
            $this->pdo->commit();
            return $pin;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function assertEmbeddedConsent(array $connection): void
    {
        $statement = $this->pdo->prepare("SELECT s.id,s.consumed_at FROM onboarding_sessions s INNER JOIN business_users bu ON bu.business_id=s.business_id AND bu.user_id=s.user_id AND bu.role='owner' INNER JOIN users u ON u.id=s.user_id AND u.status='active' WHERE s.business_id=? AND s.user_id=? AND s.reported_waba_id=? AND s.reported_phone_number_id=? AND s.status='completed' AND s.consumed_at IS NOT NULL AND (s.finish_event='FINISH' OR s.finish_event IS NULL) ORDER BY s.consumed_at DESC,s.id DESC LIMIT 1 FOR UPDATE");
        $statement->execute([$connection['business_id'], $connection['owner_user_id'], $connection['waba_id'], $connection['phone_number_id']]);
        $consent = $statement->fetch();
        if (!is_array($consent)) { $this->block('embedded_consent_missing'); }
        $completed = strtotime((string) $consent['consumed_at'] . ' UTC');
        if ($completed === false || $completed > time() + 60 || $completed < time() - 14 * 86400) { $this->block('embedded_consent_expired'); }
    }

    private function registered(array $admin, array $expected, string $operationId): array
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->guardClaim($admin, $expected, $operationId, 'registering');
            $update = $this->pdo->prepare("UPDATE whatsapp_connections SET registration_state='registered',last_error_code=NULL,last_verified_at=?,updated_at=? WHERE id=? AND business_id=?");
            $update->execute([$this->now(), $this->now(), $current['id'], $current['business_id']]);
            $this->finishRow($admin, $current, $operationId, 'succeeded', null);
            $this->pdo->commit();
            return $this->result('succeeded', (int) $current['id'], null, 'registered');
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function target(string $publicId, int $connectionId, bool $lock): array
    {
        $statement = $this->pdo->prepare('SELECT c.*,b.public_id,b.status AS business_status,b.owner_user_id,u.status AS owner_status FROM whatsapp_connections c INNER JOIN businesses b ON b.id=c.business_id LEFT JOIN users u ON u.id=b.owner_user_id WHERE b.public_id=? AND c.id=?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$publicId, $connectionId]);
        $row = $statement->fetch();
        if (!is_array($row)) { throw new AuthException('Sambungan tidak dijumpai untuk pelanggan ini.', 404); }
        return $row;
    }

    private function assertAvailable(array $admin, array $connection): void
    {
        PlatformAccess::requireAdmin($this->pdo, (int) $admin['user_id']);
        PlatformAccess::assertBusinessActive($this->pdo, (int) $connection['business_id']);
        if ($connection['business_status'] !== 'active' || $connection['owner_status'] !== 'active'
            || $connection['disconnected_at'] !== null || $connection['token_state'] !== 'verified'
            || !is_string($connection['token_ciphertext']) || $connection['token_ciphertext'] === ''
            || ($connection['token_expires_at'] !== null && strtotime((string) $connection['token_expires_at'] . ' UTC') <= time())) {
            throw new AuthException('Sambungan tidak aktif atau kebenaran Meta perlu disambungkan semula.', 409);
        }
    }

    /** Recheck permission, tenant identity, credentials and durable claim before writes. */
    private function guardClaim(array $admin, array $expected, string $operationId, string $status = 'checking'): array
    {
        $current = $this->target((string) $expected['public_id'], (int) $expected['id'], true);
        $this->assertAvailable($admin, $current);
        foreach (['business_id', 'owner_user_id', 'waba_id', 'phone_number_id', 'display_phone', 'token_ciphertext', 'token_nonce', 'token_tag', 'token_key_version'] as $key) {
            if ((string) ($current[$key] ?? '') !== (string) ($expected[$key] ?? '')) { $this->block('connection_changed'); }
        }
        $statement = $this->pdo->prepare('SELECT status FROM platform_whatsapp_operations WHERE connection_id=? AND business_id=? AND operation_id=? FOR UPDATE');
        $statement->execute([$current['id'], $current['business_id'], $operationId]);
        if ($statement->fetchColumn() !== $status) { $this->block('operation_changed'); }
        return $current;
    }

    private function finish(array $admin, array $connection, string $operationId, string $status, ?string $code, ?string $registration = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $changed = $this->finishRow($admin, $connection, $operationId, $status, $code);
            $this->pdo->commit();
            return $this->result($changed ? $status : 'unknown', (int) $connection['id'], $changed ? $code : 'operation_changed', $registration);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function finishRow(array $admin, array $connection, string $operationId, string $status, ?string $code): bool
    {
        $statement = $this->pdo->prepare('UPDATE platform_whatsapp_operations SET status=?,error_code=?,finished_at=?,updated_at=? WHERE connection_id=? AND business_id=? AND operation_id=?');
        $statement->execute([$status, $code, $this->now(), $this->now(), $connection['id'], $connection['business_id'], $operationId]);
        if ($statement->rowCount() !== 1) { return false; }
        $this->audit($admin, $connection, $operationId, 'admin_whatsapp_keputusan', $status, $code);
        return true;
    }

    private function audit(array $admin, array $connection, string $operationId, string $action, string $status, ?string $code): void
    {
        $statement = $this->pdo->prepare("INSERT INTO audit_logs (business_id,actor_user_id,actor_kind,action,entity_type,entity_id,meta_json,created_at) VALUES (?,?,'user',?,'whatsapp_connection',?,?,?)");
        $statement->execute([$connection['business_id'], $admin['user_id'], $action, $connection['id'],
            json_encode(['operation_id' => $operationId, 'status' => $status, 'error_code' => $code], JSON_THROW_ON_ERROR), $this->now()]);
    }

    private function result(string $status, int $connectionId, ?string $code, ?string $registration = null): array
    {
        $message = match ($code) {
            null => 'Nombor telah didaftarkan untuk Cloud API. Status AI dan bukti mesej kekal berasingan.',
            'operation_running' => 'Operasi untuk nombor ini sedang berjalan. Muat semula status selepas selesai.',
            'registration_unconfirmed', 'registration_transport', 'registration_response_unknown' => 'Keputusan pengaktifan belum dapat dipastikan. Gunakan Kemas kini status; pendaftaran tidak akan dihantar semula.',
            'coexistence_or_unknown' => 'Nombor WhatsApp Business App atau jenis sambungan yang belum pasti tidak didaftarkan melalui tindakan ini.',
            'embedded_consent_missing' => 'Rekod kebenaran Embedded Signup bagi pemilik pelanggan dan nombor ini belum lengkap.',
            'embedded_consent_expired' => 'Kebenaran pendaftaran telah melepasi tetingkap Meta. Pelanggan perlu melengkapkan sambungan Meta semula.',
            'asset_permission_missing' => 'Kebenaran Meta untuk aset pelanggan ini belum mencukupi.',
            'number_ownership_unverified' => 'Pengesahan pemilikan nombor oleh Meta belum lengkap.',
            'webhook_not_subscribed' => 'Langganan webhook belum disahkan. Tindakan ini tidak mengubah langganan.',
            'phone_not_eligible', 'phone_platform_unknown' => 'Status nombor belum membenarkan pengaktifan Cloud API baharu.',
            'evidence_conflict' => 'Status Meta berbeza daripada bukti mesej sedia ada. Semakan diperlukan sebelum perubahan lanjut.',
            'phone_display_mismatch', 'phone_waba_mismatch', 'waba_mismatch', 'waba_owner_unverified', 'connection_changed' => 'Aset semasa tidak sepadan dengan sambungan pelanggan. Tiada pendaftaran baharu dihantar.',
            'registration_pending' => 'Nombor belum disahkan berdaftar untuk Cloud API.',
            default => str_starts_with((string) $code, 'meta_')
                ? 'Meta tidak mengesahkan tindakan ini. Kod rujukan tersedia dalam rekod operasi; jangan ulang pendaftaran secara manual.'
                : 'Operasi belum lengkap. Kemas kini status sebelum menentukan tindakan seterusnya.',
        };
        return ['status' => $status, 'message' => $message, 'connection_id' => $connectionId,
            'error_code' => $code, 'registration_state' => $registration];
    }

    private function localCoexistence(array $connection): bool
    {
        return ($connection['sync_contacts'] ?? null) !== 'not_applicable'
            || ($connection['sync_history'] ?? null) !== 'not_applicable';
    }

    private function phone(mixed $value): string
    {
        if (!is_string($value)) { $this->block('phone_display_mismatch'); }
        $digits = preg_replace('/[^0-9]/', '', $value);
        if (!is_string($digits) || preg_match('/\A[1-9]\d{6,14}\z/', $digits) !== 1) { $this->block('phone_display_mismatch'); }
        return $digits;
    }

    private function block(string $reason): never
    {
        throw new AdminWhatsAppActivationBlocked($reason);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
