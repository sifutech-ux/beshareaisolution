<?php
declare(strict_types=1);

namespace BesharOs;

use Closure;
use PDO;
use Throwable;

require_once __DIR__ . '/KnowledgeReply.php';

/**
 * A single-attempt owner pilot worker over the already authenticated OS queue.
 *
 * Generation and sending claims are committed before their respective external
 * call. An interrupted claim is handed to a person, never retried externally.
 * The incoming archive and job are atomic; queue ciphertext is purged only
 * after an encrypted message archive exists. History/echo events are untouched.
 */
final class AiReplyWorker
{
    private Closure $generate;
    private Closure $send;
    private Closure $now;
    private const LEASE_SECONDS = 180;
    private const SEND_MARGIN_SECONDS = 60;

    public function __construct(
        private PDO $pdo,
        private TokenSeal $seal,
        callable $generate,
        callable $send,
        ?callable $now = null,
        private ?KnowledgeCentre $knowledgeCentre = null
    ) {
        $this->generate = Closure::fromCallable($generate);
        $this->send = Closure::fromCallable($send);
        $this->now = Closure::fromCallable($now ?? static fn (): int => time());
    }

    /** @return array<string,int|string> */
    public function run(int $businessId, int $limit = 10): array
    {
        if ($businessId < 1 || $limit < 1 || $limit > 50 || $this->pdo->inTransaction()) {
            throw new AuthException('Pemprosesan AI tidak sah.', 400);
        }
        $counts = [
            'mode' => 'processed', 'archived' => 0, 'generated' => 0,
            'sent' => 0, 'handoff' => 0, 'unknown' => 0, 'skipped' => 0,
            'failed' => 0, 'statuses' => 0, 'recovered' => 0,
        ];
        $lockName = 'beshare_ai_reply_' . $businessId;
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            $counts['mode'] = 'busy';
            return $counts;
        }
        try {
            $counts['recovered'] = $this->recover($businessId);
            $query = $this->pdo->prepare(
                "SELECT id FROM webhook_events
                 WHERE business_id = ? AND status = 'accepted'
                   AND event_kind IN ('message', 'status')
                 ORDER BY id LIMIT " . $limit
            );
            $query->execute([$businessId]);
            $ids = $query->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $claim = $this->claim($businessId, (int) $id);
                $kind = $claim['kind'];
                if ($kind !== 'generate') {
                    if (isset($counts[$kind])) {
                        $counts[$kind]++;
                    }
                    if (($claim['archived'] ?? false) === true) {
                        $counts['archived']++;
                    }
                    continue;
                }
                $counts['archived']++;
                try {
                    $result = ($this->generate)($claim['knowledge'], $claim['body']['text'], $claim['history'],
                        KnowledgeReply::catalog($claim['runtime']));
                    $plan = is_array($result) ? KnowledgeReply::plan($claim['runtime'], $result) : null;
                } catch (Throwable) {
                    $this->stop($businessId, (int) $id, 'generation_failed');
                    $counts['handoff']++;
                    continue;
                }
                if ($plan === null) {
                    $this->stop($businessId, (int) $id, 'human_review');
                    $counts['handoff']++;
                    continue;
                }
                $counts['generated']++;
                if (!$this->saveGenerated($businessId, (int) $id, $plan, (string) $claim['body']['wamid'])) {
                    $counts['handoff']++;
                    continue;
                }
                if (!$this->prepareSend($businessId, (int) $id, $claim['body'])) {
                    $counts['handoff']++;
                    continue;
                }
                $outcome = $this->dispatch($businessId, (int) $id, $claim['body'], $plan);
                $counts[$outcome]++;
            }
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
        return $counts;
    }

    private function claim(int $businessId, int $eventId): array
    {
        return $this->transaction(function () use ($businessId, $eventId): array {
            $event = $this->one(
                "SELECT * FROM webhook_events WHERE id = ? AND business_id = ? AND status = 'accepted' FOR UPDATE",
                [$eventId, $businessId]
            );
            if ($event === null || !in_array($event['event_kind'], ['message', 'status'], true)) {
                return ['kind' => 'skipped'];
            }
            // Settings precede the connection lock, matching owner settings changes.
            $this->one('SELECT business_id FROM ai_reply_settings WHERE business_id = ? FOR UPDATE', [$businessId]);
            $this->one(
                'SELECT b.id FROM businesses b JOIN users u ON u.id = b.owner_user_id
                 JOIN business_users bu ON bu.business_id = b.id AND bu.user_id = b.owner_user_id
                 WHERE b.id = ? FOR UPDATE', [$businessId]
            );
            $connectionId = (int) $event['connection_id'];
            $connection = $this->one(
                'SELECT * FROM whatsapp_connections WHERE id = ? AND business_id = ? FOR UPDATE',
                [$connectionId, $businessId]
            );
            try {
                $plain = $this->open($event, 'payload');
                if (!hash_equals((string) $event['payload_hash'], hash('sha256', $plain))) {
                    throw new \RuntimeException('invalid_hash');
                }
                $body = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($body) || $connection === null
                    || ($body['waba_id'] ?? null) !== $connection['waba_id']
                    || ($body['phone_number_id'] ?? null) !== $connection['phone_number_id']
                    || !is_string($body['wamid'] ?? null)
                    || preg_match('/\A[A-Za-z0-9._+\/=-]{8,180}\z/', $body['wamid']) !== 1) {
                    throw new \RuntimeException('invalid_scope');
                }
            } catch (Throwable) {
                // Preserve undecipherable/unscoped evidence for a human; never attach it to an inbox.
                $this->execute(
                    "UPDATE webhook_events SET status = 'failed', last_error = 'ai_invalid_payload',
                     locked_until = NULL, next_retry_at = NULL WHERE id = ? AND business_id = ?",
                    [$eventId, $businessId]
                );
                return ['kind' => 'failed'];
            }
            if ($event['event_kind'] === 'status') {
                $this->deliveryStatus($businessId, $connectionId, $body);
                $this->purge($businessId, $eventId, 'no_reply');
                return ['kind' => 'statuses'];
            }
            if (!is_string($body['from'] ?? null) || preg_match('/\A[0-9]{6,20}\z/', $body['from']) !== 1
                || !is_string($body['text'] ?? null) || strlen($body['text']) > 4000
                || !is_string($body['type'] ?? null)) {
                $this->execute(
                    "UPDATE webhook_events SET status = 'failed', last_error = 'ai_invalid_message'
                     WHERE id = ? AND business_id = ?", [$eventId, $businessId]
                );
                return ['kind' => 'failed'];
            }
            $already = $this->one(
                'SELECT id FROM ai_messages WHERE business_id = ? AND connection_id = ? AND wamid = ? FOR UPDATE',
                [$businessId, $connectionId, $body['wamid']]
            );
            if ($already !== null) {
                $this->purge($businessId, $eventId, 'no_reply');
                return ['kind' => 'skipped'];
            }
            $now = $this->clock();
            $date = $this->date($now);
            $original = $this->originalTime($body);
            $validTime = $original > 0 && $original <= $now;
            $messageAt = $this->date($validTime ? $original : $now);
            $this->execute(
                "INSERT INTO ai_conversations
                 (business_id, connection_id, recipient, mode, last_inbound_at, created_at, updated_at)
                 VALUES (?, ?, ?, 'ai', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = updated_at",
                [$businessId, $connectionId, $body['from'], $validTime ? $messageAt : null, $date, $date]
            );
            $conversation = $this->one(
                'SELECT * FROM ai_conversations WHERE business_id = ? AND connection_id = ? AND recipient = ? FOR UPDATE',
                [$businessId, $connectionId, $body['from']]
            );
            if ($conversation === null) {
                throw new \RuntimeException('conversation_unavailable');
            }
            $conversationId = (int) $conversation['id'];
            $archive = $this->seal->seal($plain, 200000);
            $this->execute(
                "INSERT INTO ai_messages
                 (business_id, connection_id, conversation_id, source_event_id, direction, wamid,
                  body_ciphertext, body_nonce, body_tag, body_key_version, message_at, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'in', ?, ?, ?, ?, ?, ?, 'received', ?, ?)",
                [$businessId, $connectionId, $conversationId, $eventId, $body['wamid'],
                 $archive['ciphertext'], $archive['nonce'], $archive['tag'], $archive['version'], $messageAt, $date, $date]
            );
            if ($validTime) {
                $this->execute(
                    'UPDATE ai_conversations SET last_inbound_at = GREATEST(COALESCE(last_inbound_at, ?), ?),
                     updated_at = ? WHERE id = ? AND business_id = ?',
                    [$messageAt, $messageAt, $date, $conversationId, $businessId]
                );
            }
            $context = $this->context($businessId, $connectionId, $conversationId);
            $reason = $this->gate($context, $body, null);
            $knowledge = '';
            $history = [];
            $runtime = [];
            if ($reason === null && $body['type'] !== 'text') {
                $reason = 'unsupported_message';
            }
            if ($reason === null && trim($body['text']) === '') {
                $reason = 'empty_message';
            }
            if ($reason === null && $this->wantsHuman($body['text'])) {
                $reason = 'human_requested';
            }
            if ($reason === null) {
                try {
                    $knowledge = $this->open($context['settings'], 'knowledge');
                    if (strlen($knowledge) > 8000 || trim($knowledge) === ''
                        || !hash_equals((string) $context['settings']['knowledge_hash'], hash('sha256', $knowledge))) {
                        throw new \RuntimeException('invalid_knowledge');
                    }
                    $history = $this->history($businessId, $conversationId, $eventId);
                    $runtime = $this->knowledgeCentre?->runtime($businessId) ?? [];
                    $this->assertRuntimeHash($runtime, $context['settings']);
                } catch (Throwable) {
                    $reason = 'knowledge_or_history_unreadable';
                }
            }
            if ($reason === null) {
                $settings = $context['settings'];
                $quotaDate = gmdate('Y-m-d', $now);
                $used = $settings['quota_date'] === $quotaDate ? (int) $settings['quota_used'] : 0;
                if ((int) $settings['daily_limit'] < 1 || $used >= (int) $settings['daily_limit']) {
                    $reason = 'daily_limit';
                } else {
                    // This reservation commits before the generation call and is never refunded/reused.
                    $this->execute(
                        'UPDATE ai_reply_settings SET quota_date = ?, quota_used = ?, updated_at = ? WHERE business_id = ?',
                        [$quotaDate, $used + 1, $date, $businessId]
                    );
                }
            }
            $human = $reason !== null && !in_array($reason, [
                'disabled', 'not_allowlisted', 'before_activation', 'expired_window', 'inactive_connection',
                'inactive_business', 'conversation_human', 'wrong_connection',
            ], true);
            $status = $reason === null ? 'generating' : ($human ? 'handoff' : 'skipped');
            $revision = max(1, (int) ($context['settings']['knowledge_revision'] ?? 1));
            $this->execute(
                'INSERT INTO ai_reply_jobs
                 (event_id, business_id, connection_id, conversation_id, knowledge_revision, status,
                  generation_attempts, send_attempts, locked_until, last_error, claimed_at, created_at, updated_at, finished_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
                [$eventId, $businessId, $connectionId, $conversationId, $revision, $status,
                 $reason === null ? 1 : 0, $reason === null ? $this->date($now + self::LEASE_SECONDS) : null,
                 $reason, $date, $date, $date, $reason === null ? null : $date]
            );
            if ($reason !== null) {
                if ($human) {
                    $this->human($businessId, $conversationId);
                }
                $this->purge($businessId, $eventId, 'no_reply');
                return ['kind' => $human ? 'handoff' : 'skipped', 'archived' => true];
            }
            $this->execute(
                "UPDATE webhook_events SET status = 'processing', attempt_count = LEAST(attempt_count + 1, max_attempts),
                 locked_until = ?, next_retry_at = NULL WHERE id = ? AND business_id = ?",
                [$this->date($now + self::LEASE_SECONDS), $eventId, $businessId]
            );
            return ['kind' => 'generate', 'body' => $body, 'knowledge' => $knowledge, 'history' => $history, 'runtime' => $runtime];
        });
    }

    private function saveGenerated(int $businessId, int $eventId, array $plan, string $replyTo): bool
    {
        return $this->transaction(function () use ($businessId, $eventId, $plan, $replyTo): bool {
            $job = $this->job($businessId, $eventId);
            if ($job === null || $job['status'] !== 'generating' || (int) $job['generation_attempts'] !== 1) {
                return false;
            }
            $date = $this->date($this->clock());
            $body = json_encode(array_merge($plan, ['reply_to' => $replyTo]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $archive = $this->seal->seal($body, 200000);
            $this->execute(
                "INSERT INTO ai_messages
                 (business_id, connection_id, conversation_id, source_event_id, direction, wamid,
                  body_ciphertext, body_nonce, body_tag, body_key_version, message_at, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'out', NULL, ?, ?, ?, ?, ?, 'generated', ?, ?)",
                [$businessId, $job['connection_id'], $job['conversation_id'], $eventId,
                 $archive['ciphertext'], $archive['nonce'], $archive['tag'], $archive['version'], $date, $date, $date]
            );
            $replyId = (int) $this->pdo->lastInsertId();
            $this->execute(
                "UPDATE ai_reply_jobs SET status = 'generated', reply_message_id = ?, updated_at = ?,
                 locked_until = ? WHERE event_id = ? AND business_id = ?",
                [$replyId, $date, $this->date($this->clock() + self::LEASE_SECONDS), $eventId, $businessId]
            );
            return true;
        });
    }

    private function prepareSend(int $businessId, int $eventId, array $body): bool
    {
        return $this->transaction(function () use ($businessId, $eventId, $body): bool {
            $job = $this->job($businessId, $eventId);
            if ($job === null || $job['status'] !== 'generated' || (int) $job['send_attempts'] !== 0) {
                return false;
            }
            $context = $this->context($businessId, (int) $job['connection_id'], (int) $job['conversation_id']);
            $reason = $this->gate($context, $body, (int) $job['knowledge_revision']);
            if ($reason !== null) {
                $this->terminal($job, 'handoff', $reason, true);
                return false;
            }
            $now = $this->clock();
            $this->execute(
                "UPDATE ai_reply_jobs SET status = 'sending', send_attempts = 1, locked_until = ?, updated_at = ?
                 WHERE event_id = ? AND business_id = ?",
                [$this->date($now + self::LEASE_SECONDS), $this->date($now), $eventId, $businessId]
            );
            $this->execute(
                "UPDATE ai_messages SET status = 'sending', updated_at = ? WHERE id = ? AND business_id = ?",
                [$this->date($now), $job['reply_message_id'], $businessId]
            );
            return true;
        });
    }

    private function dispatch(int $businessId, int $eventId, array $body, array $plan): string
    {
        // Sending was durably claimed in the previous transaction. Keep control rows locked
        // during the bounded transport call so pause/disable cannot commit between check and send.
        return $this->transaction(function () use ($businessId, $eventId, $body, $plan): string {
            $job = $this->job($businessId, $eventId);
            if ($job === null || $job['status'] !== 'sending' || (int) $job['send_attempts'] !== 1) {
                return 'skipped';
            }
            $context = $this->context($businessId, (int) $job['connection_id'], (int) $job['conversation_id']);
            $reason = $this->gate($context, $body, (int) $job['knowledge_revision']);
            if ($reason !== null) {
                $this->terminal($job, 'handoff', $reason, true);
                return 'handoff';
            }
            $media = null;
            try {
                $runtime = $this->knowledgeCentre?->runtime($businessId) ?? [];
                $this->assertRuntimeHash($runtime, $context['settings']);
                if (!KnowledgeReply::stillApproved($runtime, $plan)) {
                    $this->terminal($job, 'handoff', 'content_unapproved', true);
                    return 'handoff';
                }
                if ($plan['type'] === 'image') {
                    if ($this->knowledgeCentre === null) { throw new \RuntimeException('media_unavailable'); }
                    $media = $this->knowledgeCentre->mediaForWorker($businessId, $plan['media_id']);
                }
            } catch (Throwable) {
                $this->terminal($job, 'handoff', 'media_unreadable', true);
                return 'handoff';
            }
            try {
                $token = $this->open($context['connection'], 'token');
            } catch (Throwable) {
                $this->terminal($job, 'handoff', 'token_unreadable', true);
                return 'handoff';
            }
            try {
                $result = ($this->send)(
                    $token, $context['connection']['phone_number_id'], $body['from'], $plan['text'], $body['wamid'], $media
                );
            } catch (Throwable) {
                $result = ['sent' => false, 'ambiguous' => true];
            } finally {
                unset($token, $media);
            }
            $sent = is_array($result) && ($result['sent'] ?? false) === true
                && ($result['ambiguous'] ?? true) === false
                && is_string($result['message_id'] ?? null)
                && preg_match('/\A[A-Za-z0-9._+\/=-]{8,180}\z/', $result['message_id']) === 1;
            if ($sent) {
                $this->execute(
                    "UPDATE ai_messages SET wamid = ?, status = 'sent', updated_at = ?
                     WHERE id = ? AND business_id = ?",
                    [$result['message_id'], $this->date($this->clock()), $job['reply_message_id'], $businessId]
                );
                $this->execute(
                    "UPDATE whatsapp_connections SET messaging_state = CASE
                       WHEN messaging_state IN ('receive_verified', 'send_and_receive_verified')
                         AND display_phone IS NOT NULL AND subscribed_at IS NOT NULL AND registration_state = 'registered'
                       THEN 'send_and_receive_verified' ELSE 'send_verified' END, updated_at = ?
                     WHERE id = ? AND business_id = ? AND token_state = 'verified' AND disconnected_at IS NULL",
                    [$this->date($this->clock()), $job['connection_id'], $businessId]
                );
                $this->terminal($job, 'sent', null, false);
                return 'sent';
            }
            $definiteFailure = is_array($result) && ($result['sent'] ?? null) === false
                && ($result['ambiguous'] ?? null) === false;
            $this->terminal($job, $definiteFailure ? 'failed' : 'unknown',
                $definiteFailure ? 'send_rejected' : 'send_unknown', true);
            return $definiteFailure ? 'failed' : 'unknown';
        });
    }

    private function recover(int $businessId): int
    {
        $query = $this->pdo->prepare(
            "SELECT event_id FROM ai_reply_jobs WHERE business_id = ?
             AND status IN ('generating', 'generated', 'sending')
             AND (locked_until IS NULL OR locked_until <= ?) ORDER BY event_id LIMIT 50"
        );
        $query->execute([$businessId, $this->date($this->clock())]);
        $recovered = 0;
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $eventId) {
            $changed = $this->transaction(function () use ($businessId, $eventId): bool {
                $job = $this->job($businessId, (int) $eventId);
                if ($job === null || !in_array($job['status'], ['generating', 'generated', 'sending'], true)
                    || ($job['locked_until'] !== null && $job['locked_until'] > $this->date($this->clock()))) {
                    return false;
                }
                $unknown = $job['status'] === 'sending' || (int) $job['send_attempts'] > 0;
                $this->terminal($job, $unknown ? 'unknown' : 'handoff',
                    $unknown ? 'interrupted_send' : 'interrupted_generation', true);
                return true;
            });
            $recovered += $changed ? 1 : 0;
        }
        return $recovered;
    }

    private function stop(int $businessId, int $eventId, string $reason): void
    {
        $this->transaction(function () use ($businessId, $eventId, $reason): void {
            $job = $this->job($businessId, $eventId);
            if ($job !== null && in_array($job['status'], ['generating', 'generated'], true)) {
                $this->terminal($job, 'handoff', $reason, true);
            }
        });
    }

    /** Caller holds the job lock; all mutation and queue purging remain in its transaction. */
    private function terminal(array $job, string $status, ?string $reason, bool $human): void
    {
        $businessId = (int) $job['business_id'];
        $eventId = (int) $job['event_id'];
        $archive = $this->one(
            "SELECT id FROM ai_messages WHERE business_id = ? AND source_event_id = ? AND direction = 'in'",
            [$businessId, $eventId]
        );
        if ($archive === null) {
            throw new \RuntimeException('archive_missing');
        }
        $date = $this->date($this->clock());
        $this->execute(
            'UPDATE ai_reply_jobs SET status = ?, last_error = ?, locked_until = NULL,
             updated_at = ?, finished_at = ? WHERE event_id = ? AND business_id = ?',
            [$status, $reason, $date, $date, $eventId, $businessId]
        );
        if ($job['reply_message_id'] !== null && $status !== 'sent') {
            $this->execute(
                'UPDATE ai_messages SET status = ?, updated_at = ? WHERE id = ? AND business_id = ?',
                [in_array($status, ['handoff', 'unknown', 'failed'], true) ? $status : 'handoff',
                 $date, $job['reply_message_id'], $businessId]
            );
        }
        if ($human) {
            $this->human($businessId, (int) $job['conversation_id']);
        }
        $this->purge($businessId, $eventId,
            $status === 'sent' ? 'reply_sent' : ($status === 'unknown' ? 'reply_unknown' : 'no_reply'));
    }

    private function context(int $businessId, int $connectionId, int $conversationId): array
    {
        $settings = $this->one('SELECT * FROM ai_reply_settings WHERE business_id = ? FOR UPDATE', [$businessId]);
        $business = $this->one(
            "SELECT b.status, u.status AS owner_status, bu.role
             FROM businesses b JOIN users u ON u.id = b.owner_user_id
             JOIN business_users bu ON bu.business_id = b.id AND bu.user_id = b.owner_user_id
             WHERE b.id = ? FOR UPDATE", [$businessId]
        );
        $connection = $this->one(
            'SELECT * FROM whatsapp_connections WHERE business_id = ? AND id = ? FOR UPDATE',
            [$businessId, $connectionId]
        );
        $conversation = $this->one(
            'SELECT * FROM ai_conversations WHERE business_id = ? AND connection_id = ? AND id = ? FOR UPDATE',
            [$businessId, $connectionId, $conversationId]
        );
        return compact('settings', 'business', 'connection', 'conversation');
    }

    /** Returns a fixed, non-secret refusal code or null. */
    private function gate(array $context, array $body, ?int $revision): ?string
    {
        $s = $context['settings'];
        $b = $context['business'];
        $c = $context['connection'];
        $v = $context['conversation'];
        $now = $this->clock();
        if ($s === null || (int) $s['enabled'] !== 1) {
            return 'disabled';
        }
        if ($b === null || $b['status'] !== 'active' || $b['owner_status'] !== 'active' || $b['role'] !== 'owner') {
            return 'inactive_business';
        }
        if ($c === null || $v === null || (int) $s['connection_id'] !== (int) $c['id']
            || ($body['waba_id'] ?? null) !== $c['waba_id']
            || ($body['phone_number_id'] ?? null) !== $c['phone_number_id']
            || ($body['from'] ?? null) !== $v['recipient']) {
            return 'wrong_connection';
        }
        if ($c['token_state'] !== 'verified' || $c['disconnected_at'] !== null
            || $c['webhook_state'] !== 'subscribed' || $c['registration_state'] !== 'registered'
            || ($c['token_expires_at'] !== null && $this->timeOf($c['token_expires_at']) <= $now + self::SEND_MARGIN_SECONDS)) {
            return 'inactive_connection';
        }
        if ($v['mode'] !== 'ai') {
            return 'conversation_human';
        }
        if ($s['approved_by'] === null || $s['approved_at'] === null
            || $s['knowledge_ciphertext'] === null || $s['knowledge_nonce'] === null
            || $s['knowledge_tag'] === null || $s['knowledge_key_version'] === null
            || !is_string($s['knowledge_hash']) || preg_match('/\A[0-9a-f]{64}\z/', $s['knowledge_hash']) !== 1) {
            return 'knowledge_unapproved';
        }
        if ($revision !== null && (int) $s['knowledge_revision'] !== $revision) {
            return 'knowledge_changed';
        }
        try {
            $allowlist = json_decode((string) $s['allowlist_json'], true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $allowlist = null;
        }
        if (!is_array($allowlist) || !array_is_list($allowlist) || count($allowlist) > 10
            || !in_array($body['from'] ?? '', $allowlist, true)) {
            return 'not_allowlisted';
        }
        $original = $this->originalTime($body);
        if ($original < 1 || $original > $now) {
            return 'invalid_timestamp';
        }
        // Reserve both bounded image upload and message dispatch inside the original window.
        if ($original + 86400 <= $now + self::SEND_MARGIN_SECONDS) {
            return 'expired_window';
        }
        $activated = $this->timeOf($s['activation_at']);
        if ($activated < 1 || $original < $activated) {
            return 'before_activation';
        }
        return null;
    }

    private function history(int $businessId, int $conversationId, int $eventId): array
    {
        $query = $this->pdo->prepare(
            "SELECT direction, body_ciphertext, body_nonce, body_tag, body_key_version
             FROM ai_messages WHERE business_id = ? AND conversation_id = ?
               AND (source_event_id IS NULL OR source_event_id <> ?)
               AND (direction = 'in' OR status IN ('sent', 'delivered', 'read'))
             ORDER BY id DESC LIMIT 8"
        );
        $query->execute([$businessId, $conversationId, $eventId]);
        $history = [];
        foreach (array_reverse($query->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $body = json_decode($this->open($row, 'body'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !is_string($body['text'] ?? null)) {
                throw new \RuntimeException('history_unreadable');
            }
            $text = $row['direction'] === 'out' && in_array($body['payment'] ?? 'none', ['bank', 'qr'], true)
                ? 'Maklumat pembayaran yang diluluskan telah dihantar.' : $body['text'];
            $history[] = ['role' => $row['direction'] === 'in' ? 'user' : 'assistant', 'text' => $text];
        }
        return $history;
    }

    private function assertRuntimeHash(array $runtime, array $settings): void
    {
        if ($runtime !== [] && (!is_string($runtime['_compiled_hash'] ?? null)
            || !hash_equals((string) $settings['knowledge_hash'], $runtime['_compiled_hash']))) {
            throw new \RuntimeException('published_knowledge_mismatch');
        }
    }

    private function deliveryStatus(int $businessId, int $connectionId, array $body): void
    {
        $incoming = $body['status'] ?? '';
        if (!in_array($incoming, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        $message = $this->one(
            "SELECT id, status FROM ai_messages WHERE business_id = ? AND connection_id = ? AND wamid = ?
             AND direction = 'out' FOR UPDATE", [$businessId, $connectionId, $body['wamid']]
        );
        if ($message === null) {
            return;
        }
        $ranks = ['generated' => 0, 'sending' => 0, 'unknown' => 0, 'failed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        $current = (string) $message['status'];
        $advance = $incoming === 'failed'
            ? !in_array($current, ['delivered', 'read'], true)
            : ($ranks[$incoming] ?? 0) > ($ranks[$current] ?? 0);
        if ($advance) {
            $this->execute(
                'UPDATE ai_messages SET status = ?, updated_at = ? WHERE id = ? AND business_id = ?',
                [$incoming, $this->date($this->clock()), $message['id'], $businessId]
            );
        }
    }

    private function purge(int $businessId, int $eventId, string $result): void
    {
        $date = $this->date($this->clock());
        $this->execute(
            "UPDATE webhook_events SET status = 'processed', result_code = ?, payload_ciphertext = NULL,
             payload_nonce = NULL, payload_tag = NULL, payload_key_version = NULL, payload_purged_at = ?,
             processed_at = ?, locked_until = NULL, next_retry_at = NULL, last_error = NULL
             WHERE id = ? AND business_id = ?",
            [$result, $date, $date, $eventId, $businessId]
        );
    }

    private function human(int $businessId, int $conversationId): void
    {
        $this->execute(
            "UPDATE ai_conversations SET mode = 'human', updated_at = ? WHERE id = ? AND business_id = ?",
            [$this->date($this->clock()), $conversationId, $businessId]
        );
    }

    private function job(int $businessId, int $eventId): ?array
    {
        return $this->one(
            'SELECT * FROM ai_reply_jobs WHERE business_id = ? AND event_id = ? FOR UPDATE',
            [$businessId, $eventId]
        );
    }

    private function open(array $row, string $prefix): string
    {
        return $this->seal->open(
            (string) ($row[$prefix . '_ciphertext'] ?? ''), (string) ($row[$prefix . '_nonce'] ?? ''),
            (string) ($row[$prefix . '_tag'] ?? ''), (int) ($row[$prefix . '_key_version'] ?? 0)
        );
    }

    private function originalTime(array $body): int
    {
        $value = $body['timestamp'] ?? '';
        return is_string($value) && preg_match('/\A[0-9]{10}\z/', $value) === 1 ? (int) $value : 0;
    }

    private function wantsHuman(string $text): bool
    {
        return preg_match('/(?:\b(?:speak|talk|connect)\s+(?:to|with)\s+(?:a\s+)?(?:human|person|agent)\b|\b(?:nak|mahu|ingin|hubungi|panggil|sambung)\s+(?:(?:bercakap|cakap|dengan|kepada)\s+)*(?:admin|staf|staff|manusia|pegawai)\b)/iu', $text) === 1;
    }

    private function clock(): int
    {
        $now = ($this->now)();
        if (!is_int($now) || $now < 1) {
            throw new \RuntimeException('invalid_clock');
        }
        return $now;
    }

    private function date(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    private function timeOf(mixed $date): int
    {
        if (!is_string($date) || $date === '') {
            return 0;
        }
        $value = strtotime($date . ' UTC');
        return is_int($value) ? $value : 0;
    }

    private function one(string $sql, array $values): ?array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($values);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function execute(string $sql, array $values): void
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($values);
    }

    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
