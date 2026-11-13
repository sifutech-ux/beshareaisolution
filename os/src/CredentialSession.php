<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** Password-bound sessions, including legacy sessions issued before this release. */
final class CredentialSession
{
    public static function installed(PDO $pdo): bool
    {
        return $pdo->query("SELECT version FROM schema_migrations WHERE version='0008'")->fetchColumn() === '0008';
    }

    public static function stamp(PDO $pdo, int $userId): string
    {
        $query = $pdo->prepare("SELECT password_hash FROM users WHERE id=? AND status='active'");
        $query->execute([$userId]);
        $hash = $query->fetchColumn();
        if (!is_string($hash) || $hash === '') { throw new AuthException('Sila masuk semula.', 401); }
        return hash('sha256', $hash);
    }

    public static function assertCurrent(PDO $pdo, array $session): void
    {
        $userId = (int) ($session['user_id'] ?? 0);
        if ($userId < 1) { throw new AuthException('Sesi diperlukan.', 401); }
        if (isset($session['credential_stamp'])) {
            if (!is_string($session['credential_stamp']) || !hash_equals(self::stamp($pdo, $userId), $session['credential_stamp'])) {
                throw new AuthException('Kata laluan telah berubah. Sila masuk semula.', 401);
            }
            return;
        }
        // Preserve pre-release sessions only until that account's first reset.
        if (self::installed($pdo)) {
            $query = $pdo->prepare('SELECT user_id FROM password_recovery_versions WHERE user_id=?');
            $query->execute([$userId]);
            if ($query->fetchColumn() !== false) { throw new AuthException('Sila masuk semula.', 401); }
        }
    }
}
