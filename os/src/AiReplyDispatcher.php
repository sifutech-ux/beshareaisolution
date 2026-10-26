<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use Throwable;

/** Bounded round-robin dispatch across authenticated tenant queues. */
final class AiReplyDispatcher
{
    private const LOCK = 'beshare_ai_dispatch_all';

    public function __construct(private PDO $pdo) {}

    /** Read-only: no lease, cursor update, queue claim, or provider call. */
    public function inspect(int $businessLimit = 20): array
    {
        $this->bounds($businessLimit, 1);
        $cursor = $this->cursor();
        $query = $this->pdo->query('SELECT COUNT(*) FROM businesses b ' . self::ELIGIBLE);
        return [
            'candidate_businesses' => (int) $query->fetchColumn(),
            'last_business_id' => $cursor,
            'next_businesses' => $this->candidates($cursor, $businessLimit),
        ];
    }

    /**
     * The callback receives business ID, event limit, and a wall-time
     * deadline. A worker finishes its current provider operation before stopping.
     */
    public function run(callable $process, int $businessLimit = 20, int $eventLimit = 10, float $budgetSeconds = 50.0): array
    {
        $this->bounds($businessLimit, $eventLimit);
        if ($budgetSeconds < 1 || $budgetSeconds > 300 || $this->pdo->inTransaction()) {
            throw new AuthException('Had pemprosesan AI tidak sah.', 400);
        }
        $result = ['mode' => 'processed', 'businesses' => 0, 'busy_businesses' => 0,
            'failed_businesses' => 0, 'archived' => 0, 'generated' => 0, 'sent' => 0,
            'handoff' => 0, 'unknown' => 0, 'skipped' => 0, 'failed' => 0,
            'statuses' => 0, 'recovered' => 0];
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([self::LOCK]);
        if ((int) $lock->fetchColumn() !== 1) {
            $result['mode'] = 'busy';
            return $result;
        }
        try {
            $deadline = microtime(true) + $budgetSeconds;
            $ids = $this->candidates($this->cursor(), $businessLimit);
            foreach ($ids as $businessId) {
                if (microtime(true) >= $deadline) { break; }
                try {
                    $counts = $process($businessId, $eventLimit, $deadline);
                    if (!is_array($counts)) { throw new \RuntimeException('invalid_worker_result'); }
                    $result['businesses']++;
                    if (($counts['mode'] ?? '') === 'busy') { $result['busy_businesses']++; }
                    foreach (['archived', 'generated', 'sent', 'handoff', 'unknown', 'skipped', 'failed', 'statuses', 'recovered'] as $key) {
                        if (is_int($counts[$key] ?? null) && $counts[$key] >= 0) { $result[$key] += $counts[$key]; }
                    }
                } catch (Throwable) {
                    // A broken tenant must not prevent other customers receiving
                    // their own scheduled work. The worker retains uncertain sends.
                    $result['failed_businesses']++;
                }
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                $cursor = $this->pdo->prepare("UPDATE ai_reply_dispatch_state SET last_business_id=?,updated_at=UTC_TIMESTAMP() WHERE name='all'");
                $cursor->execute([$businessId]);
            }
        } finally {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::LOCK]);
        }
        return $result;
    }

    // Suspended businesses and disabled owners still need interrupted-send
    // recovery and delivery receipts. Only the worker can authorize sending.
    private const ELIGIBLE = "WHERE (EXISTS (SELECT 1 FROM webhook_events e WHERE e.business_id=b.id
            AND e.status='accepted' AND e.event_kind IN ('message','status'))
            OR EXISTS (SELECT 1 FROM ai_reply_jobs j WHERE j.business_id=b.id
                AND j.status IN ('generating','generated','sending')
                AND (j.locked_until IS NULL OR j.locked_until<=UTC_TIMESTAMP())))";

    private function candidates(int $cursor, int $limit): array
    {
        $query = $this->pdo->prepare('SELECT b.id FROM businesses b ' . self::ELIGIBLE
            . ' ORDER BY (b.id<=?),b.id LIMIT ' . $limit);
        $query->execute([$cursor]);
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    private function cursor(): int
    {
        $value = $this->pdo->query("SELECT last_business_id FROM ai_reply_dispatch_state WHERE name='all'")->fetchColumn();
        if ($value === false || !is_numeric($value) || (int) $value < 0) {
            throw new AuthException('Keadaan pemprosesan AI belum tersedia.', 409);
        }
        return (int) $value;
    }

    private function bounds(int $businessLimit, int $eventLimit): void
    {
        if ($businessLimit < 1 || $businessLimit > 100 || $eventLimit < 1 || $eventLimit > 50) {
            throw new AuthException('Had pemprosesan AI tidak sah.', 400);
        }
    }
}
