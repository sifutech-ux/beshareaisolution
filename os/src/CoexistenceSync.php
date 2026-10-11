<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/**
 * Contact completion has no documented terminal webhook. History completes at progress 100.
 * Graph calls stay disabled unless this object is explicitly armed for a local test.
 */
final class CoexistenceSync
{
    public function __construct(private PDO $pdo, private MetaGraph $graph, private TokenSeal $seal, private bool $armed = false)
    {
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{job_id:int,status:string,calls_graph:bool}
     */
    public function authorize(array $actor, string $syncType): array
    {
        $this->assertOwner($actor);
        if (!in_array($syncType, ['smb_app_state_sync', 'history'], true)) {
            throw new AuthException('Jenis penyegerakan tidak sah.', 400);
        }
        if (!WebhookReadiness::ready()) {
            throw new AuthException('Pemproses webhook belum sedia.', 409);
        }
        $column = $syncType === 'history' ? 'sync_history' : 'sync_contacts';
        $this->pdo->beginTransaction();
        try {
            $connection = $this->lockConnection($actor['business_id']);
            if ($connection === null) {
                $this->pdo->commit();
                throw new AuthException('Sambungan Coexistence tidak dijumpai.', 404);
            }
            if ((string) $connection['token_state'] !== 'verified' || $connection['disconnected_at'] !== null) {
                $this->pdo->commit();
                throw new AuthException('Sambungan tidak layak disegerakkan.', 409);
            }
            if ((string) $connection['webhook_state'] !== 'subscribed') {
                $this->pdo->commit();
                throw new AuthException('Webhook belum sedia untuk penyegerakan.', 409);
            }
            if ((int) $connection['deadline_open'] !== 1) {
                $this->markUnavailable((int) $connection['id'], $actor['business_id']);
                $this->pdo->commit();
                throw new AuthException('Tetingkap 24 jam telah tamat.', 409);
            }
            $state = (string) $connection[$column];
            if (in_array($state, ['completed', 'declined', 'unavailable', 'not_applicable'], true)) {
                $this->pdo->commit();
                throw new AuthException('Penyegerakan ini tidak boleh dimulakan semula.', 409);
            }
            $job = $this->lockJob((int) $connection['id'], $syncType);
            if (is_array($job)) {
                $status = (string) $job['status'];
                if (in_array($status, ['authorized', 'in_progress', 'completed', 'declined'], true)) {
                    $this->pdo->commit();
                    return ['job_id' => (int) $job['id'], 'status' => $status, 'calls_graph' => false];
                }
                if ($status === 'failed' && (int) $job['attempt_count'] < (int) $job['max_attempts'] && $job['request_id'] === null) {
                    $reopen = $this->pdo->prepare(
                        'UPDATE coexistence_sync_jobs
                         SET status = \'authorized\', next_attempt_at = NULL, updated_at = UTC_TIMESTAMP()
                         WHERE id = ? AND business_id = ? AND status = \'failed\''
                    );
                    $reopen->execute([$job['id'], $actor['business_id']]);
                    $this->pdo->commit();
                    return ['job_id' => (int) $job['id'], 'status' => 'authorized', 'calls_graph' => false];
                }
                $this->pdo->commit();
                throw new AuthException('Penyegerakan ini tidak boleh dimulakan semula.', 409);
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO coexistence_sync_jobs (
                    business_id, connection_id, authorized_by, sync_type, status, attempt_count, max_attempts,
                    deadline_at, authorized_at, created_at, updated_at
                 ) VALUES (
                    ?, ?, ?, ?, \'authorized\', 0, 3, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
                 )'
            );
            $insert->execute([$actor['business_id'], $connection['id'], $actor['user_id'], $syncType, $connection['sync_deadline_at']]);
            $jobId = (int) $this->pdo->lastInsertId();
            $this->audit($actor, 'penyegerakan_dibenarkan', $jobId, ['sync_type' => $syncType]);
            $this->pdo->commit();
            return ['job_id' => $jobId, 'status' => 'authorized', 'calls_graph' => false];
        } catch (AuthException $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @return array{job_id:int,status:string,calls_graph:bool}
     */
    public function dispatch(int $businessId, int $jobId, int $now): array
    {
        if ($businessId < 1 || $jobId < 1) {
            throw new AuthException('Kerja penyegerakan tidak sah.', 400);
        }
        if (!$this->armed) {
            return ['job_id' => $jobId, 'status' => 'disabled', 'calls_graph' => false];
        }
        $this->pdo->beginTransaction();
        try {
            $job = $this->lockJobById($businessId, $jobId);
            if ($job === null) {
                $this->pdo->commit();
                throw new AuthException('Kerja penyegerakan tidak dijumpai.', 404);
            }
            if ((string) $job['status'] === 'in_progress' || $job['request_id'] !== null) {
                $this->pdo->commit();
                return ['job_id' => $jobId, 'status' => (string) $job['status'], 'calls_graph' => false];
            }
            if ((string) $job['status'] !== 'authorized') {
                $this->pdo->commit();
                return ['job_id' => $jobId, 'status' => (string) $job['status'], 'calls_graph' => false];
            }
            if ($job['next_attempt_at'] !== null && strtotime((string) $job['next_attempt_at'] . ' UTC') > $now) {
                $this->pdo->commit();
                return ['job_id' => $jobId, 'status' => 'authorized', 'calls_graph' => false];
            }
            if ((int) $job['deadline_open'] !== 1 || (string) $job['webhook_state'] !== 'subscribed' || (string) $job['token_state'] !== 'verified') {
                $this->failJob($job, (int) $job['deadline_open'] === 1 ? 'unavailable' : 'expired');
                $this->pdo->commit();
                return ['job_id' => $jobId, 'status' => (int) $job['deadline_open'] === 1 ? 'failed' : 'expired', 'calls_graph' => false];
            }
            $token = $this->openToken($job);
            $phoneId = (string) $job['phone_number_id'];
            $syncType = (string) $job['sync_type'];
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
            throw $error;
        }
        try {
            $response = $this->graph->smbAppData($token, $phoneId, $syncType);
        } catch (MetaCallException $error) {
            $this->recordFailure($businessId, $jobId, $error->metaCode, $now);
            $row = $this->jobStatus($businessId, $jobId);
            return ['job_id' => $jobId, 'status' => $row, 'calls_graph' => true];
        }
        $requestId = $response['request_id'] ?? '';
        $requestId = is_string($requestId) && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $requestId) === 1 ? $requestId : null;
        $product = (string) ($response['messaging_product'] ?? '');
        if ($product !== 'whatsapp') {
            $this->recordFailure($businessId, $jobId, 'response', $now);
            return ['job_id' => $jobId, 'status' => $this->jobStatus($businessId, $jobId), 'calls_graph' => true];
        }
        $column = $syncType === 'history' ? 'sync_history' : 'sync_contacts';
        $this->pdo->beginTransaction();
        try {
            $saved = $this->pdo->prepare(
                'UPDATE coexistence_sync_jobs
                 SET status = \'in_progress\', request_id = ?, started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                     updated_at = UTC_TIMESTAMP(), last_error_code = NULL
                 WHERE id = ? AND business_id = ? AND status = \'authorized\''
            );
            $saved->execute([$requestId, $jobId, $businessId]);
            $mark = $this->pdo->prepare(
                'UPDATE whatsapp_connections
                 SET ' . $column . ' = \'in_progress\', updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND business_id = ? AND ' . $column . ' IN (\'pending\', \'failed\')'
            );
            $mark->execute([(int) $job['connection_id'], $businessId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['job_id' => $jobId, 'status' => 'in_progress', 'calls_graph' => true];
    }

    /**
     * Contacts have no documented completion signal, so this stays explicit.
     *
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{status:string}
     */
    public function settleContacts(array $actor): array
    {
        $this->assertOwner($actor);
        $statement = $this->pdo->prepare(
            'SELECT j.id
             FROM coexistence_sync_jobs j
             INNER JOIN whatsapp_connections c ON c.id = j.connection_id AND c.business_id = j.business_id
             WHERE j.business_id = ? AND j.sync_type = \'smb_app_state_sync\' AND j.status = \'in_progress\'
               AND j.request_id IS NOT NULL AND c.disconnected_at IS NULL
               AND EXISTS (
                 SELECT 1 FROM webhook_events e
                 WHERE e.connection_id = c.id AND e.business_id = c.business_id AND e.event_kind = \'smb_app_state_sync\'
               )
             ORDER BY j.id DESC
             LIMIT 1'
        );
        $statement->execute([$actor['business_id']]);
        $jobId = $statement->fetchColumn();
        if ($jobId === false) {
            throw new AuthException('Kenalan belum sedia ditanda selesai.', 409);
        }
        $this->pdo->beginTransaction();
        try {
            $job = $this->pdo->prepare(
                'UPDATE coexistence_sync_jobs
                 SET status = \'completed\', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND business_id = ? AND status = \'in_progress\''
            );
            $job->execute([(int) $jobId, $actor['business_id']]);
            $connection = $this->pdo->prepare(
                'UPDATE whatsapp_connections c
                 INNER JOIN coexistence_sync_jobs j ON j.connection_id = c.id AND j.business_id = c.business_id
                 SET c.sync_contacts = \'completed\', c.updated_at = UTC_TIMESTAMP()
                 WHERE j.id = ? AND c.business_id = ? AND c.sync_contacts = \'in_progress\''
            );
            $connection->execute([(int) $jobId, $actor['business_id']]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['status' => 'completed'];
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor */
    private function assertOwner(array $actor): void
    {
        if ($actor['status'] !== 'active' || $actor['role'] !== 'owner' || (int) $actor['user_id'] !== (int) $actor['owner_user_id']) {
            throw new AuthException('Tindakan ditolak.', 403);
        }
    }

    /** @return array<string,mixed>|null */
    private function lockConnection(int $businessId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, token_state, webhook_state, sync_contacts, sync_history, sync_deadline_at, disconnected_at,
                    sync_deadline_at > UTC_TIMESTAMP() AS deadline_open
             FROM whatsapp_connections
             WHERE business_id = ? AND disconnected_at IS NULL AND sync_deadline_at IS NOT NULL
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([$businessId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockJob(int $connectionId, string $syncType): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, request_id, attempt_count, max_attempts
             FROM coexistence_sync_jobs
             WHERE connection_id = ? AND sync_type = ?
             FOR UPDATE'
        );
        $statement->execute([$connectionId, $syncType]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockJobById(int $businessId, int $jobId): ?array
    {
            $statement = $this->pdo->prepare(
            'SELECT j.id, j.business_id, j.status, j.request_id, j.sync_type, j.connection_id, j.attempt_count, j.max_attempts,
                    j.next_attempt_at, j.deadline_at > UTC_TIMESTAMP() AS deadline_open,
                    c.phone_number_id, c.webhook_state, c.token_state,
                    HEX(c.token_ciphertext) AS token_ciphertext, HEX(c.token_nonce) AS token_nonce,
                    HEX(c.token_tag) AS token_tag, c.token_key_version
             FROM coexistence_sync_jobs j
             INNER JOIN whatsapp_connections c ON c.id = j.connection_id AND c.business_id = j.business_id
             WHERE j.id = ? AND j.business_id = ?
             FOR UPDATE'
        );
        $statement->execute([$jobId, $businessId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $job */
    private function openToken(array $job): string
    {
        $cipher = hex2bin((string) ($job['token_ciphertext'] ?? ''));
        $nonce = hex2bin((string) ($job['token_nonce'] ?? ''));
        $tag = hex2bin((string) ($job['token_tag'] ?? ''));
        if (!is_string($cipher) || !is_string($nonce) || !is_string($tag)) {
            throw new AuthException('Kelayakan Meta tidak dapat dibaca.', 409);
        }
        return $this->seal->open($cipher, $nonce, $tag, (int) $job['token_key_version']);
    }

    private function markUnavailable(int $connectionId, int $businessId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE whatsapp_connections
             SET sync_contacts = IF(sync_contacts IN (\'pending\', \'in_progress\'), \'unavailable\', sync_contacts),
                 sync_history = IF(sync_history IN (\'pending\', \'in_progress\'), \'unavailable\', sync_history),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND business_id = ?'
        );
        $statement->execute([$connectionId, $businessId]);
        $jobs = $this->pdo->prepare(
            'UPDATE coexistence_sync_jobs
             SET status = \'expired\', finished_at = UTC_TIMESTAMP(), last_error_code = \'deadline\', updated_at = UTC_TIMESTAMP()
             WHERE connection_id = ? AND business_id = ? AND status IN (\'authorized\', \'in_progress\')'
        );
        $jobs->execute([$connectionId, $businessId]);
    }

    /** @param array<string,mixed> $job */
    private function failJob(array $job, string $code): void
    {
        $status = $code === 'expired' ? 'expired' : 'failed';
        $statement = $this->pdo->prepare(
            'UPDATE coexistence_sync_jobs
             SET status = ?, last_error_code = ?, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = \'authorized\''
        );
        $statement->execute([$status, $code, $job['id']]);
        if ($code === 'expired') {
            $this->markUnavailable((int) $job['connection_id'], (int) $job['business_id']);
        }
    }

    private function recordFailure(int $businessId, int $jobId, string $code, int $now): void
    {
        $safe = preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $code) === 1 ? $code : 'meta';
        $this->pdo->beginTransaction();
        try {
            $job = $this->lockJobById($businessId, $jobId);
            if ($job === null || (string) $job['status'] !== 'authorized') {
                $this->pdo->commit();
                return;
            }
            $attempt = (int) $job['attempt_count'] + 1;
            $terminal = $attempt >= (int) $job['max_attempts'];
            $statement = $this->pdo->prepare(
                'UPDATE coexistence_sync_jobs
                 SET attempt_count = ?, status = ?, last_error_code = ?, next_attempt_at = ?,
                     finished_at = CASE WHEN ? = 1 THEN UTC_TIMESTAMP() ELSE NULL END, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND business_id = ? AND status = \'authorized\''
            );
            $statement->execute([
                $attempt,
                $terminal ? 'failed' : 'authorized',
                $safe,
                $terminal ? null : gmdate('Y-m-d H:i:s', $now + 60),
                $terminal ? 1 : 0,
                $jobId,
                $businessId,
            ]);
            if ($terminal) {
                $column = (string) $job['sync_type'] === 'history' ? 'sync_history' : 'sync_contacts';
                $mark = $this->pdo->prepare(
                    'UPDATE whatsapp_connections
                     SET ' . $column . ' = \'failed\', last_error_code = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND business_id = ? AND ' . $column . ' IN (\'pending\', \'in_progress\')'
                );
                $mark->execute([$safe, $job['connection_id'], $businessId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function jobStatus(int $businessId, int $jobId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM coexistence_sync_jobs WHERE id = ? AND business_id = ?');
        $statement->execute([$jobId, $businessId]);
        $status = $statement->fetchColumn();
        return is_string($status) ? $status : 'failed';
    }

    /** @param array{user_id:int,business_id:int} $actor @param array<string,string> $meta */
    private function audit(array $actor, string $action, int $entityId, array $meta): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (business_id, actor_user_id, actor_kind, action, entity_type, entity_id, meta_json, created_at)
             VALUES (?, ?, \'user\', ?, \'coexistence_sync\', ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([
            $actor['business_id'],
            $actor['user_id'],
            $action,
            $entityId,
            json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
    }
}
