<?php
declare(strict_types=1);

namespace BesharOs;

final class RecoveryPolicy
{
    /**
     * Manual recovery is a review result. This method never changes the database.
     * A review is reachable only when the live structure matches the approved
     * migration and the migration file checksum matches the approved checksum.
     * Object names alone are not structure. The runner does not call this method.
     */
    public static function assess(
        string $catalogState,
        bool $structureMatches,
        string $migrationChecksum,
        string $approvedChecksum
    ): string {
        if (!$structureMatches) {
            return 'blocked';
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $migrationChecksum) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $approvedChecksum) !== 1
            || !hash_equals($approvedChecksum, $migrationChecksum)) {
            return 'blocked';
        }
        if (!in_array($catalogState, ['partial', 'complete_unrecorded'], true)) {
            return 'blocked';
        }
        return 'manual_review';
    }
}
