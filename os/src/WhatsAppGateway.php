<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use PDOException;

final class WhatsAppGateway
{
    private const TOKEN_MAX = 1800;

    public function __construct(private PDO $pdo, private MetaGraph $graph, private TokenSeal $seal, private string $appId)
    {
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{state:string}
     */
    public function begin(array $actor, string $sessionKey): array
    {
        $this->assertOwner($actor);
        if ($sessionKey === '') {
            throw new AuthException('Sesi tidak sah.', 401);
        }
        $state = bin2hex(random_bytes(32));
        $insert = $this->pdo->prepare(
            'INSERT INTO onboarding_sessions
                (business_id, user_id, correlation_hash, session_hash, status, expires_at, created_at)
             VALUES (?, ?, ?, ?, \'pending\', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), UTC_TIMESTAMP())'
        );
        $insert->execute([
            $actor['business_id'],
            $actor['user_id'],
            hash('sha256', $state),
            hash('sha256', $sessionKey),
        ]);
        $this->audit($actor, 'sambungan_dimulakan', (int) $this->pdo->lastInsertId(), null);
        return ['state' => $state];
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @param array<string,mixed> $body
     */
    public function record(array $actor, string $sessionKey, array $body): void
    {
        $this->assertOwner($actor);
        $event = $this->eventName($body);
        $waba = $this->metaId($body, 'waba_id');
        $phone = $this->metaId($body, 'phone_number_id');
        $step = $this->step($body);
        $errorCode = $this->safeCode($body['error_code'] ?? '');
        if ($errorCode === null) {
            $errorCode = $this->safeCode($body['error_id'] ?? '');
        }
        $sessionId = $this->safeCode($body['session_id'] ?? '');
        $this->pdo->beginTransaction();
        try {
            $row = $this->lock($actor, $this->text($body, 'state'), $sessionKey);
            if (in_array($row['status'], ['completed', 'abandoned', 'rejected', 'incomplete', 'expired'], true)) {
                $this->pdo->commit();
                return;
            }
            if ($event === 'CANCEL') {
                $this->finishRow((int) $row['id'], $actor['business_id'], 'abandoned', $event, $step, $errorCode, $waba, $phone);
                $this->audit($actor, 'sambungan_dibatalkan', (int) $row['id'], $this->cancelMeta($step, $errorCode, $sessionId));
            } elseif ($event === 'ERROR') {
                $this->finishRow((int) $row['id'], $actor['business_id'], 'rejected', $event, $step, $errorCode ?: 'error', $waba, $phone);
                $this->audit($actor, 'sambungan_ditolak', (int) $row['id'], null);
            } elseif ($event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
                if ($waba === null) {
                    $this->finishRow((int) $row['id'], $actor['business_id'], 'incomplete', $event, $step, 'coexistence_waba', null, null);
                } else {
                    $update = $this->pdo->prepare(
                        'UPDATE onboarding_sessions
                         SET finish_event = ?, current_step = ?, reported_waba_id = ?, reported_phone_number_id = ?
                         WHERE id = ? AND business_id = ?'
                    );
                    $update->execute([$event, $step, $waba, $phone, $row['id'], $actor['business_id']]);
                }
            } elseif ($event === 'FINISH_ONLY_WABA' || $phone === null || $waba === null) {
                $this->finishRow((int) $row['id'], $actor['business_id'], 'incomplete', $event, $step, null, $waba, null);
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE onboarding_sessions
                     SET finish_event = ?, current_step = ?, reported_waba_id = ?, reported_phone_number_id = ?
                     WHERE id = ? AND business_id = ?'
                );
                $update->execute([$event, $step, $waba, $phone, $row['id'], $actor['business_id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if ($event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' && $waba !== null) {
            $this->bindIfHeld($actor, $this->text($body, 'state'), $sessionKey);
        } elseif ($event === 'FINISH' && $waba !== null && $phone !== null) {
            $this->bindIfHeld($actor, $this->text($body, 'state'), $sessionKey);
        }
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     */
    public function exchangeCode(array $actor, string $sessionKey, string $state, string $code): void
    {
        $this->assertOwner($actor);
        if (preg_match('/\A[A-Za-z0-9_\-\.~]{8,1024}\z/', $code) !== 1) {
            throw new AuthException('Kod kebenaran tidak sah.', 400);
        }
        $this->pdo->beginTransaction();
        try {
            $row = $this->lock($actor, $state, $sessionKey);
            if ($row['status'] === 'exchanging') {
                $this->pdo->commit();
                throw new AuthException('Pertukaran sedang diproses.', 409);
            }
            if ($row['status'] !== 'pending') {
                $this->pdo->commit();
                throw new AuthException('Kod ini sudah digunakan.', 409);
            }
            $claim = $this->pdo->prepare(
                'UPDATE onboarding_sessions SET status = \'exchanging\' WHERE id = ? AND business_id = ? AND status = \'pending\''
            );
            $claim->execute([$row['id'], $actor['business_id']]);
            if ($claim->rowCount() !== 1) {
                $this->pdo->commit();
                throw new AuthException('Pertukaran sedang diproses.', 409);
            }
            $this->pdo->commit();
        } catch (AuthException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw SqlGuard::translate($error);
        }
        try {
            $token = $this->graph->exchangeCode($code);
        } catch (MetaCallException $error) {
            $this->rejectId((int) $row['id'], $actor['business_id'], $error->metaCode);
            $this->audit($actor, 'sambungan_ditolak', (int) $row['id'], null);
            throw new AuthException('Pertukaran kod Meta gagal.', 502);
        }
        $sealed = $this->seal->seal($token, self::TOKEN_MAX);
        $this->pdo->beginTransaction();
        try {
            $held = $this->pdo->prepare(
                'UPDATE onboarding_sessions
                 SET status = \'token_held\', token_ciphertext = UNHEX(?), token_nonce = UNHEX(?), token_tag = UNHEX(?), token_key_version = ?
                 WHERE id = ? AND business_id = ? AND status = \'exchanging\''
            );
            $held->execute([
                bin2hex($sealed['ciphertext']),
                bin2hex($sealed['nonce']),
                bin2hex($sealed['tag']),
                $sealed['version'],
                $row['id'],
                $actor['business_id'],
            ]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw SqlGuard::translate($error);
        }
        $this->bindIfHeld($actor, $state, $sessionKey);
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,name?:string} $actor
     * @return array{token:string,webhook:string,registration:string,messaging:string,note:string,phone:string,linked:bool}
     */
    public function view(array $actor): array
    {
        $this->expireDue($actor['business_id']);
        $statement = $this->pdo->prepare(
            'SELECT token_state, webhook_state, registration_state, messaging_state, sync_contacts, sync_history,
                    display_phone, last_error_code, sync_deadline_at, disconnected_at
             FROM whatsapp_connections
             WHERE business_id = ? AND disconnected_at IS NULL
             ORDER BY updated_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute([$actor['business_id']]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            $closed = $this->pdo->prepare(
                'SELECT token_state, webhook_state, registration_state, messaging_state, sync_contacts, sync_history,
                        display_phone, last_error_code, sync_deadline_at, disconnected_at
                 FROM whatsapp_connections
                 WHERE business_id = ? AND disconnected_at IS NOT NULL
                 ORDER BY disconnected_at DESC, id DESC
                 LIMIT 1'
            );
            $closed->execute([$actor['business_id']]);
            $row = $closed->fetch();
            if (is_array($row)) {
                $card = $this->labels($row, ($actor['role'] ?? '') === 'owner');
                $card['linked'] = false;
                return $card;
            }
        }
        if (is_array($row)) {
            return $this->labels($row, ($actor['role'] ?? '') === 'owner');
        }
        $open = $this->pdo->prepare(
            'SELECT status, meta_error_code FROM onboarding_sessions WHERE business_id = ? ORDER BY id DESC LIMIT 1'
        );
        $open->execute([$actor['business_id']]);
        $session = $open->fetch();
        $note = '';
        $token = 'Belum';
        if (is_array($session) && $session['status'] === 'token_held') {
            $token = 'Tersimpan';
            $note = 'Kod ditukar. Menunggu pengesahan nombor daripada Meta.';
        } elseif (is_array($session) && in_array($session['status'], ['rejected', 'incomplete', 'expired', 'abandoned'], true)) {
            $note = $this->noteFor((string) ($session['meta_error_code'] ?? ''), (string) $session['status']);
        }
        return [
            'token' => $token,
            'webhook' => 'Belum',
            'registration' => 'Belum',
            'messaging' => 'Belum',
            'note' => $note,
            'phone' => '',
            'linked' => false,
        ];
    }

    /**
     * @param array{business_id:int} $actor
     * @return array{conversations:list<array<string,mixed>>,contacts:list<array<string,mixed>>}
     */
    public function inbox(array $actor): array
    {
        $statement = $this->pdo->prepare(
            'SELECT event_kind, HEX(payload_ciphertext) AS payload_ciphertext, HEX(payload_nonce) AS payload_nonce,
                    HEX(payload_tag) AS payload_tag, payload_key_version
             FROM webhook_events
             WHERE business_id = ? AND status = \'accepted\' AND event_kind IN (\'message\', \'status\')
             ORDER BY id ASC
             LIMIT 200'
        );
        $statement->execute([$actor['business_id']]);
        $messages = [];
        $statuses = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $plain = $this->seal->open(
                    $this->binary((string) $row['payload_ciphertext']),
                    $this->binary((string) $row['payload_nonce']),
                    $this->binary((string) $row['payload_tag']),
                    (int) $row['payload_key_version']
                );
            } catch (AuthException) {
                continue;
            }
            $event = json_decode($plain, true);
            if (!is_array($event)) {
                continue;
            }
            if ($row['event_kind'] === 'status') {
                $statuses[] = $event;
                continue;
            }
            $messages[] = $event;
        }
        $statusByKey = [];
        foreach ($statuses as $status) {
            $phone = (string) ($status['phone_number_id'] ?? '');
            $wamid = (string) ($status['wamid'] ?? '');
            $name = (string) ($status['status'] ?? '');
            if ($phone === '' || $wamid === '' || !in_array($name, ['sent', 'delivered', 'read', 'failed'], true)) {
                continue;
            }
            $statusByKey[$phone . '|' . $wamid] = $name;
        }
        $grouped = [];
        foreach ($messages as $message) {
            $phone = (string) ($message['phone_number_id'] ?? '');
            $from = (string) ($message['from'] ?? '');
            $wamid = (string) ($message['wamid'] ?? '');
            if ($phone === '' || $from === '' || $wamid === '') {
                continue;
            }
            $key = $phone . ':' . $from;
            if (!isset($grouped[$key])) {
                $name = (string) ($message['name'] ?? '');
                $grouped[$key] = [
                    'id' => 'wa-' . $phone . '-' . $from,
                    'name' => $name !== '' ? $name : $from,
                    'phone' => $from,
                    'mode' => 'human_takeover',
                    'unread' => true,
                    'tag' => 'whatsapp',
                    'assignee' => '',
                    'window' => 'closed',
                    'note' => 'Mesej webhook yang disahkan. Balasan keluar belum disambung. AI tidak membalas automatik.',
                    'messages' => [],
                    'latest' => 0,
                ];
            }
            $stamp = (int) ($message['timestamp'] ?? 0);
            if ($stamp > $grouped[$key]['latest']) {
                $grouped[$key]['latest'] = $stamp;
            }
            $grouped[$key]['messages'][] = [
                'from' => 'customer',
                'text' => (string) ($message['text'] ?? ''),
                'wamid' => $wamid,
                'status' => $statusByKey[$phone . '|' . $wamid] ?? '',
            ];
        }
        $now = time();
        $conversations = [];
        $contacts = [];
        foreach ($grouped as $item) {
            $item['window'] = $item['latest'] > 0 && ($now - $item['latest']) < 86400 ? 'open' : 'closed';
            unset($item['latest']);
            $conversations[] = $item;
            $contacts[] = [
                'id' => $item['id'],
                'conversationId' => $item['id'],
                'name' => $item['name'],
                'phone' => $item['phone'],
                'tag' => 'whatsapp',
                'assignee' => '',
                'priority' => '',
                'note' => '',
            ];
        }
        return ['conversations' => $conversations, 'contacts' => $contacts];
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     */
    private function bindIfHeld(array $actor, string $state, string $sessionKey): void
    {
        $coexistence = false;
        $this->pdo->beginTransaction();
        try {
            $row = $this->lock($actor, $state, $sessionKey);
            $coexistence = (string) ($row['finish_event'] ?? '') === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';
            $ready = $row['status'] === 'token_held'
                && is_string($row['reported_waba_id'])
                && is_string($row['token_ciphertext'])
                && $row['token_ciphertext'] !== ''
                && ($coexistence || is_string($row['reported_phone_number_id']));
            if (!$ready) {
                $this->pdo->commit();
                return;
            }
            $token = $this->seal->open(
                $this->binary((string) $row['token_ciphertext']),
                $this->binary((string) $row['token_nonce']),
                $this->binary((string) $row['token_tag']),
                (int) $row['token_key_version']
            );
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if ($coexistence) {
            $this->bindCoexistence($actor, $row, $token);
            return;
        }
        $this->bind($actor, $row, $token);
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @param array<string,mixed> $row
     */
    private function bind(array $actor, array $row, string $token): void
    {
        $wabaId = (string) $row['reported_waba_id'];
        $phoneId = (string) $row['reported_phone_number_id'];
        try {
            $debug = $this->graph->debugToken($token);
            $data = is_array($debug['data'] ?? null) ? $debug['data'] : [];
            if (($data['is_valid'] ?? false) !== true || (string) ($data['app_id'] ?? '') !== $this->appId) {
                throw new MetaCallException('190', 401);
            }
            $expiresAt = $data['expires_at'] ?? 0;
            $expiresAt = is_int($expiresAt) ? $expiresAt : (is_numeric($expiresAt) ? (int) $expiresAt : 0);
            if ($expiresAt > 0 && $expiresAt <= time()) {
                throw new MetaCallException('190', 401);
            }
            if (!in_array($wabaId, $this->targetIds($data, 'whatsapp_business_management'), true)
                || !in_array($wabaId, $this->targetIds($data, 'whatsapp_business_messaging'), true)) {
                throw new MetaCallException('waba', 409);
            }
            $waba = $this->graph->waba($token, $wabaId);
            if ((string) ($waba['id'] ?? '') !== $wabaId) {
                throw new MetaCallException('waba', 409);
            }
            $listed = $this->graph->phones($token, $wabaId);
            $match = null;
            foreach (is_array($listed['data'] ?? null) ? $listed['data'] : [] as $item) {
                if (is_array($item) && (string) ($item['id'] ?? '') === $phoneId) {
                    $match = $item;
                    break;
                }
            }
            if ($match === null) {
                throw new MetaCallException('phone', 409);
            }
        } catch (MetaCallException $error) {
            $this->rejectId((int) $row['id'], $actor['business_id'], $error->metaCode);
            $this->audit($actor, 'sambungan_ditolak', (int) $row['id'], null);
            $message = $error->metaCode === '190' ? 'Kelayakan Meta telah tamat atau tidak sah.' : 'Aset WhatsApp tidak disahkan.';
            throw new AuthException($message, $error->metaCode === '190' ? 401 : 409);
        }
        $subscribed = $this->graph->subscribe($token, $wabaId);
        $display = $match['display_phone_number'] ?? '';
        $display = is_string($display) && preg_match('/\A[0-9 +\-]{6,32}\z/', $display) === 1 ? $display : null;
        $registration = $this->registration((string) ($match['status'] ?? ''));
        $kind = $this->kind($data, $expiresAt);
        $sealed = $this->seal->seal($token, self::TOKEN_MAX);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO whatsapp_connections (
                    business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
                    registration_state, messaging_state, token_kind, token_ciphertext, token_nonce, token_tag,
                    token_key_version, token_expires_at, refresh_supported, last_verified_at, last_error_code,
                    subscribed_at, disconnected_at, created_at, updated_at
                 ) VALUES (
                    ?, ?, ?, ?, \'verified\', ?, ?, \'unverified\', ?, UNHEX(?), UNHEX(?), UNHEX(?), ?,
                    CASE WHEN ? > 0 THEN FROM_UNIXTIME(?) ELSE NULL END, 0, UTC_TIMESTAMP(), ?,
                    CASE WHEN ? = 1 THEN UTC_TIMESTAMP() ELSE NULL END, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                 )'
            );
            $insert->execute([
                $actor['business_id'],
                $wabaId,
                $phoneId,
                $display,
                $subscribed ? 'subscribed' : 'subscribe_failed',
                $registration,
                $kind,
                bin2hex($sealed['ciphertext']),
                bin2hex($sealed['nonce']),
                bin2hex($sealed['tag']),
                $sealed['version'],
                $expiresAt,
                $expiresAt,
                $subscribed ? null : 'subscribe_failed',
                $subscribed ? 1 : 0,
            ]);
            $connectionId = (int) $this->pdo->lastInsertId();
            $done = $this->pdo->prepare(
                'UPDATE onboarding_sessions
                 SET status = \'completed\', consumed_at = UTC_TIMESTAMP(),
                     token_ciphertext = NULL, token_nonce = NULL, token_tag = NULL, token_key_version = NULL
                 WHERE id = ? AND business_id = ? AND status = \'token_held\''
            );
            $done->execute([$row['id'], $actor['business_id']]);
            if ($done->rowCount() !== 1) {
                throw new PDOException('onboarding completion rejected');
            }
            $this->audit($actor, 'sambungan_diikat', $connectionId, [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneId,
            ]);
            $this->pdo->commit();
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->rejectId((int) $row['id'], $actor['business_id'], 'phone');
            if (str_contains($error->getMessage(), 'uq_wa_phone') || str_contains($error->getMessage(), 'Duplicate')) {
                throw new AuthException('Nombor ini sudah disambungkan.', 409);
            }
            throw SqlGuard::translate($error);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Bind a WhatsApp Business App number without subscribing webhooks or registering the phone.
     * Eligibility is the Coexistence flag pair, not the number of phone records.
     *
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @param array<string,mixed> $row
     */
    private function bindCoexistence(array $actor, array $row, string $token): void
    {
        $wabaId = (string) $row['reported_waba_id'];
        $reportedPhone = is_string($row['reported_phone_number_id'] ?? null) ? (string) $row['reported_phone_number_id'] : null;
        try {
            $debug = $this->graph->debugToken($token);
            $data = is_array($debug['data'] ?? null) ? $debug['data'] : [];
            if (($data['is_valid'] ?? false) !== true || (string) ($data['app_id'] ?? '') !== $this->appId) {
                throw new MetaCallException('190', 401);
            }
            $expiresAt = $data['expires_at'] ?? 0;
            $expiresAt = is_int($expiresAt) ? $expiresAt : (is_numeric($expiresAt) ? (int) $expiresAt : 0);
            if ($expiresAt > 0 && $expiresAt <= time()) {
                throw new MetaCallException('190', 401);
            }
            if (!in_array($wabaId, $this->targetIds($data, 'whatsapp_business_management'), true)
                || !in_array($wabaId, $this->targetIds($data, 'whatsapp_business_messaging'), true)) {
                throw new MetaCallException('waba', 409);
            }
            $waba = $this->graph->waba($token, $wabaId);
            if ((string) ($waba['id'] ?? '') !== $wabaId) {
                throw new MetaCallException('waba', 409);
            }
            $eligible = $this->eligibleCoexistencePhones($this->graph->coexistencePhones($token, $wabaId));
        } catch (MetaCallException $error) {
            if ($error->metaCode === '190') {
                $this->rejectId((int) $row['id'], $actor['business_id'], $error->metaCode);
                $this->audit($actor, 'sambungan_ditolak', (int) $row['id'], null);
                throw new AuthException('Kelayakan Meta telah tamat atau tidak sah.', 401);
            }
            $this->stopIncomplete((int) $row['id'], $actor['business_id'], $error->metaCode === 'waba' ? 'waba' : 'coexistence_unavailable');
            return;
        }
        if (count($eligible) === 0) {
            $this->stopIncomplete((int) $row['id'], $actor['business_id'], 'coexistence_absent');
            return;
        }
        if (count($eligible) !== 1) {
            $this->stopIncomplete((int) $row['id'], $actor['business_id'], 'coexistence_ambiguous');
            return;
        }
        $match = $eligible[0];
        $phoneId = (string) $match['id'];
        if ($reportedPhone !== null && $reportedPhone !== $phoneId) {
            $this->stopIncomplete((int) $row['id'], $actor['business_id'], 'coexistence_mismatch');
            return;
        }
        $display = $match['display_phone_number'] ?? '';
        $display = is_string($display) && preg_match('/\A[0-9 +\-]{6,32}\z/', $display) === 1 ? $display : null;
        $registration = $this->registration((string) ($match['status'] ?? ''));
        $kind = $this->kind($data, $expiresAt);
        $sealed = $this->seal->seal($token, self::TOKEN_MAX);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO whatsapp_connections (
                    business_id, waba_id, phone_number_id, display_phone, token_state, webhook_state,
                    registration_state, messaging_state, sync_contacts, sync_history, token_kind, token_ciphertext, token_nonce, token_tag,
                    token_key_version, token_expires_at, refresh_supported, last_verified_at, last_error_code,
                    subscribed_at, disconnected_at, created_at, updated_at, onboarded_at, sync_deadline_at
                 ) VALUES (
                    ?, ?, ?, ?, \'verified\', \'not_subscribed\', ?, \'unverified\', \'pending\', \'pending\', ?, UNHEX(?), UNHEX(?), UNHEX(?), ?,
                    CASE WHEN ? > 0 THEN FROM_UNIXTIME(?) ELSE NULL END, 0, UTC_TIMESTAMP(), NULL,
                    NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
                 )'
            );
            $insert->execute([
                $actor['business_id'],
                $wabaId,
                $phoneId,
                $display,
                $registration,
                $kind,
                bin2hex($sealed['ciphertext']),
                bin2hex($sealed['nonce']),
                bin2hex($sealed['tag']),
                $sealed['version'],
                $expiresAt,
                $expiresAt,
            ]);
            $connectionId = (int) $this->pdo->lastInsertId();
            $done = $this->pdo->prepare(
                'UPDATE onboarding_sessions
                 SET status = \'completed\', consumed_at = UTC_TIMESTAMP(),
                     token_ciphertext = NULL, token_nonce = NULL, token_tag = NULL, token_key_version = NULL,
                     reported_phone_number_id = ?
                 WHERE id = ? AND business_id = ? AND status = \'token_held\''
            );
            $done->execute([$phoneId, $row['id'], $actor['business_id']]);
            if ($done->rowCount() !== 1) {
                throw new PDOException('onboarding completion rejected');
            }
            $this->audit($actor, 'sambungan_diikat', $connectionId, [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneId,
            ]);
            $this->pdo->commit();
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->rejectId((int) $row['id'], $actor['business_id'], 'phone');
            if (str_contains($error->getMessage(), 'uq_wa_phone') || str_contains($error->getMessage(), 'Duplicate')) {
                throw new AuthException('Nombor ini sudah disambungkan.', 409);
            }
            throw SqlGuard::translate($error);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * A phone is eligible only when both Coexistence flags match.
     *
     * @param array<string,mixed> $listed
     * @return list<array<string,mixed>>
     */
    private function eligibleCoexistencePhones(array $listed): array
    {
        $eligible = [];
        foreach (is_array($listed['data'] ?? null) ? $listed['data'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = is_int($item['id'] ?? null) ? (string) $item['id'] : (is_string($item['id'] ?? null) ? $item['id'] : '');
            if (preg_match('/\A\d{5,32}\z/', $id) !== 1) {
                continue;
            }
            if (($item['is_on_biz_app'] ?? null) !== true || ($item['platform_type'] ?? null) !== 'CLOUD_API') {
                continue;
            }
            $item['id'] = $id;
            $eligible[] = $item;
        }
        return $eligible;
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function targetIds(array $data, string $scope): array
    {
        $ids = [];
        $granular = $data['granular_scopes'] ?? [];
        if (!is_array($granular)) {
            return [];
        }
        foreach ($granular as $item) {
            if (!is_array($item) || ($item['scope'] ?? '') !== $scope || !is_array($item['target_ids'] ?? null)) {
                continue;
            }
            foreach ($item['target_ids'] as $id) {
                $text = is_int($id) ? (string) $id : (is_string($id) ? $id : '');
                if (preg_match('/\A\d{5,32}\z/', $text) === 1) {
                    $ids[] = $text;
                }
            }
        }
        return $ids;
    }

    /** @param array<string,mixed> $data */
    private function kind(array $data, int $expiresAt): string
    {
        $type = (string) ($data['type'] ?? '');
        if ($type === 'SYSTEM_USER' || ($type === '' && $expiresAt === 0)) {
            return 'system_user';
        }
        if ($expiresAt > 0 && ($expiresAt - time()) < 7200) {
            return 'user_short_lived';
        }
        return 'user_long_lived';
    }

    private function registration(string $status): string
    {
        return match (strtoupper($status)) {
            'CONNECTED' => 'registered',
            'PENDING' => 'not_registered',
            'DELETED', 'BANNED', 'RESTRICTED' => 'failed',
            default => 'unknown',
        };
    }

    private function expireDue(int $businessId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE whatsapp_connections
             SET token_state = \'expired\', messaging_state = \'unverified\', last_error_code = \'expired\',
                 token_ciphertext = NULL, token_nonce = NULL, token_tag = NULL, token_key_version = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE business_id = ? AND disconnected_at IS NULL
               AND token_state IN (\'stored\', \'verified\')
               AND token_expires_at IS NOT NULL AND token_expires_at <= UTC_TIMESTAMP()'
        );
        $statement->execute([$businessId]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{token:string,webhook:string,registration:string,messaging:string,note:string,phone:string,linked:bool}
     */
    private function labels(array $row, bool $showPhone): array
    {
        $token = [
            'stored' => 'Tersimpan',
            'verified' => 'Disahkan',
            'expired' => 'Tamat',
            'revoked' => 'Ditarik balik',
            'error' => 'Ralat',
            'cleared' => 'Dikosongkan',
        ][(string) $row['token_state']] ?? 'Belum';
        $webhook = [
            'not_subscribed' => 'Belum dilanggan',
            'subscribed' => 'Dilanggan',
            'subscribe_failed' => 'Langganan gagal',
        ][(string) $row['webhook_state']] ?? 'Belum';
        $registration = [
            'unknown' => 'Belum',
            'not_registered' => 'Belum didaftarkan',
            'registered' => 'Didaftarkan',
            'failed' => 'Gagal',
        ][(string) $row['registration_state']] ?? 'Belum';
        $messaging = [
            'unverified' => 'Belum',
            'send_verified' => 'Hantar disahkan',
            'receive_verified' => 'Terima disahkan',
            'send_and_receive_verified' => 'Hantar dan terima disahkan',
        ][(string) $row['messaging_state']] ?? 'Belum';
        $phone = $showPhone && is_string($row['display_phone']) ? $row['display_phone'] : '';
        return [
            'token' => $token,
            'webhook' => $webhook,
            'registration' => $registration,
            'messaging' => $messaging,
            'note' => $this->noteFor((string) ($row['last_error_code'] ?? ''), ''),
            'phone' => $phone,
            'linked' => true,
            'contacts_sync' => $this->syncLabel((string) ($row['sync_contacts'] ?? 'not_applicable')),
            'history_sync' => $this->syncLabel((string) ($row['sync_history'] ?? 'not_applicable')),
            'sync_deadline' => is_string($row['sync_deadline_at'] ?? null) ? (string) $row['sync_deadline_at'] : '',
        ];
    }

    private function syncLabel(string $status): string
    {
        return [
            'not_applicable' => 'Tidak berkenaan',
            'pending' => 'Menunggu',
            'in_progress' => 'Sedang disegerak',
            'completed' => 'Selesai',
            'declined' => 'Ditolak',
            'unavailable' => 'Tidak tersedia',
            'failed' => 'Gagal',
        ][$status] ?? 'Tidak berkenaan';
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{job_id:int,status:string,calls_graph:bool}
     */
    public function authorizeSync(array $actor, string $syncType): array
    {
        return (new CoexistenceSync($this->pdo, $this->graph, $this->seal, false))->authorize($actor, $syncType);
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{status:string}
     */
    public function settleContacts(array $actor): array
    {
        return (new CoexistenceSync($this->pdo, $this->graph, $this->seal, false))->settleContacts($actor);
    }

    /** @return array{ready:bool,calls_graph:bool,fields:list<string>} */
    public function subscriptionReadiness(): array
    {
        return [
            'ready' => WebhookReadiness::ready(),
            'calls_graph' => false,
            'fields' => WebhookReadiness::FIELDS,
        ];
    }

    private function noteFor(string $code, string $status): string
    {
        return match ($code) {
            'subscribe_failed' => 'Token disahkan, tetapi langganan webhook gagal.',
            'expired', '190' => 'Kelayakan Meta telah tamat atau tidak sah.',
            'waba', 'phone' => 'Aset WhatsApp tidak disahkan untuk tenant ini.',
            'coexistence_waba' => 'Meta tidak memulangkan akaun WhatsApp.',
            'coexistence_absent' => 'Nombor WhatsApp Business App yang sah tidak dijumpai.',
            'coexistence_ambiguous' => 'Lebih daripada satu nombor WhatsApp Business App yang sah. Sambungan tidak diikat.',
            'coexistence_mismatch' => 'Nombor yang dilaporkan tidak sepadan dengan nombor WhatsApp Business App yang sah.',
            'coexistence_unavailable' => 'Pengesahan nombor WhatsApp Business App tidak selesai.',
            '2593109' => 'Perniagaan tidak berkongsi sejarah sembang.',
            'PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED', 'ACCOUNT_DISCONNECTED', 'BUSINESS_DOWNGRADE', 'CHANGE_NUMBER', 'COMPANION_INACTIVITY', 'PRIMARY_INACTIVITY', 'USER_RE_REGISTERED' => 'Sambungan platform perniagaan diputuskan.',
            default => match ($status) {
                'abandoned' => $code === '' ? 'Aliran Meta dibatalkan.' : 'Aliran Meta dibatalkan. Kod ralat ' . $code . '.',
                'incomplete' => 'Meta tidak memulangkan nombor telefon.',
                'expired' => 'Sesi sambungan telah tamat.',
                'rejected' => 'Sambungan Meta ditolak.',
                default => $code === '' ? '' : 'Sambungan Meta tidak selesai.',
            },
        };
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array<string,mixed>
     */
    private function lock(array $actor, string $state, string $sessionKey): array
    {
        if ($sessionKey === '' || preg_match('/\A[0-9a-f]{64}\z/', $state) !== 1) {
            throw new AuthException('Keadaan tidak sah.', 403);
        }
        $statement = $this->pdo->prepare(
            'SELECT id, status, finish_event, session_hash, reported_waba_id, reported_phone_number_id,
                    HEX(token_ciphertext) AS token_ciphertext, HEX(token_nonce) AS token_nonce,
                    HEX(token_tag) AS token_tag, token_key_version,
                    expires_at <= UTC_TIMESTAMP() AS is_expired
             FROM onboarding_sessions
             WHERE correlation_hash = ? AND business_id = ? AND user_id = ?
             FOR UPDATE'
        );
        $statement->execute([hash('sha256', $state), $actor['business_id'], $actor['user_id']]);
        $row = $statement->fetch();
        if (!is_array($row) || !hash_equals((string) $row['session_hash'], hash('sha256', $sessionKey))) {
            throw new AuthException('Keadaan tidak sah.', 403);
        }
        $terminal = ['completed', 'abandoned', 'rejected', 'incomplete', 'expired'];
        if ((int) $row['is_expired'] === 1 && !in_array((string) $row['status'], $terminal, true)) {
            $this->finishRow((int) $row['id'], $actor['business_id'], 'expired', null, null, 'expired', null, null);
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            throw new AuthException('Sesi sambungan telah tamat.', 409);
        }
        return $row;
    }

    private function finishRow(int $id, int $businessId, string $status, ?string $event, ?string $step, ?string $error, ?string $waba, ?string $phone): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE onboarding_sessions
             SET status = ?, finish_event = COALESCE(?, finish_event), current_step = COALESCE(?, current_step),
                 meta_error_code = ?, reported_waba_id = COALESCE(?, reported_waba_id),
                 reported_phone_number_id = COALESCE(?, reported_phone_number_id),
                 consumed_at = UTC_TIMESTAMP(), token_ciphertext = NULL, token_nonce = NULL,
                 token_tag = NULL, token_key_version = NULL
             WHERE id = ? AND business_id = ?'
        );
        $statement->execute([$status, $event, $step, $error, $waba, $phone, $id, $businessId]);
    }

    private function rejectId(int $id, int $businessId, string $code): void
    {
        $safe = preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $code) === 1 ? $code : 'error';
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
        $this->finishRow($id, $businessId, 'rejected', null, null, $safe, null, null);
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    private function stopIncomplete(int $id, int $businessId, string $code): void
    {
        $safe = preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $code) === 1 ? $code : 'coexistence_unavailable';
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
        $this->finishRow($id, $businessId, 'incomplete', null, null, $safe, null, null);
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /** @param array{user_id:int,business_id:int} $actor @param array<string,string>|null $meta */
    private function audit(array $actor, string $action, int $entityId, ?array $meta): void
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
            $entityId > 0 ? $entityId : null,
            $meta === null ? null : json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @param array{role:string,user_id:int,owner_user_id:int,status:string} $actor */
    private function assertOwner(array $actor): void
    {
        if ($actor['status'] !== 'active' || $actor['role'] !== 'owner' || (int) $actor['user_id'] !== (int) $actor['owner_user_id']) {
            throw new AuthException('Tindakan ditolak.', 403);
        }
    }

    /** @param array<string,mixed> $body */
    private function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** @param array<string,mixed> $body */
    private function eventName(array $body): string
    {
        $event = $this->text($body, 'event');
        $allowed = ['FINISH', 'FINISH_ONLY_WABA', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'CANCEL', 'ERROR'];
        if (!in_array($event, $allowed, true)) {
            throw new AuthException('Peristiwa Meta tidak sah.', 400);
        }
        return $event;
    }

    /** @param array<string,mixed> $body */
    private function metaId(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            return null;
        }
        $value = $body[$key];
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/\A\d{5,32}\z/', $text) !== 1) {
            throw new AuthException('Pengenal Meta tidak sah.', 400);
        }
        return $text;
    }

    /** @param array<string,mixed> $body */
    private function step(array $body): ?string
    {
        $step = $this->text($body, 'current_step');
        if ($step === '') {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $step) !== 1) {
            return null;
        }
        return $step;
    }

    /** @return array<string,string> */
    private function cancelMeta(?string $step, ?string $errorCode, ?string $sessionId): array
    {
        $meta = ['event' => 'CANCEL'];
        if ($step !== null) {
            $meta['current_step'] = $step;
        }
        if ($errorCode !== null) {
            $meta['error_code'] = $errorCode;
        }
        if ($sessionId !== null) {
            $meta['session_id'] = $sessionId;
        }
        return $meta;
    }

    private function safeCode(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = (string) $value;
        return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $text) === 1 ? $text : null;
    }

    private function binary(string $hex): string
    {
        $binary = hex2bin($hex);
        if (!is_string($binary)) {
            throw new AuthException('Rekod tidak dapat dibaca.', 500);
        }
        return $binary;
    }
}
