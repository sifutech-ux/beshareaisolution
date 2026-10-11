<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/**
 * Processes authorized coexistence jobs. The CLI calls this only after the activation flag is exactly true.
 */
final class CoexistenceWorker
{
    /** @return array{mode:string,recovered:int,sent:int,review:int,failed:int} */
    public static function process(PDO $pdo, MetaGraph $graph, TokenSeal $seal, int $now, bool $enabled): array
    {
        if ($enabled !== true) {
            return ['mode' => 'disabled', 'recovered' => 0, 'sent' => 0, 'review' => 0, 'failed' => 0];
        }
        if (!WebhookReadiness::ready()) {
            return ['mode' => 'processor', 'recovered' => 0, 'sent' => 0, 'review' => 0, 'failed' => 0];
        }
        $sync = new CoexistenceSync($pdo, $graph, $seal, true);
        $recovered = $sync->recoverInterrupted();
        $sent = 0;
        $review = 0;
        $failed = 0;
        foreach ($sync->dueJobs(5) as $job) {
            $result = $sync->dispatch((int) $job['business_id'], (int) $job['id'], $now);
            $status = (string) ($result['status'] ?? '');
            if (($result['calls_graph'] ?? false) === true && $status === 'in_progress') {
                $sent++;
            } elseif ($status === 'review') {
                $review++;
            } elseif ($status === 'failed' || $status === 'expired') {
                $failed++;
            }
        }
        return ['mode' => 'enabled', 'recovered' => $recovered, 'sent' => $sent, 'review' => $review, 'failed' => $failed];
    }
}
