<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use PDOException;

/** Platform grants are independent of tenant membership and legacy admin cookies. */
final class PlatformAccess
{
    public static function installed(PDO $pdo): bool
    {
        try {
            return $pdo->query("SELECT version FROM schema_migrations WHERE version='0006'")->fetchColumn() === '0006';
        } catch (PDOException $error) {
            if (self::missingTable($error)) { return false; }
            throw $error;
        }
    }

    public static function admin(PDO $pdo, int $userId): ?array
    {
        if ($userId < 1 || !self::installed($pdo)) { return null; }
        $statement = $pdo->prepare("SELECT u.id AS user_id,u.name,u.email,p.role AS platform_role
            FROM platform_admins p JOIN users u ON u.id=p.user_id
            WHERE p.user_id=? AND p.status='active' AND p.role='owner' AND u.status='active'");
        $statement->execute([$userId]);
        $row = $statement->fetch();
        if (!is_array($row)) { return null; }
        $row['user_id'] = (int) $row['user_id'];
        return $row;
    }

    public static function requireAdmin(PDO $pdo, int $userId): array
    {
        if ($userId < 1) { throw new AuthException('Sila masuk menggunakan akaun BeShare anda.', 401); }
        $admin = self::admin($pdo, $userId);
        if ($admin === null) { throw new AuthException('Akses ini khusus untuk pentadbir sistem BeShare.', 403); }
        return $admin;
    }

    /** Missing additive schema preserves existing access; other DB errors fail closed. */
    public static function businessStatus(PDO $pdo, int $businessId): string
    {
        try {
            $statement = $pdo->prepare('SELECT status FROM platform_business_access WHERE business_id=?');
            $statement->execute([$businessId]);
            $status = $statement->fetchColumn();
            return $status === false ? 'active' : ($status === 'active' ? 'active' : 'suspended');
        } catch (PDOException $error) {
            if (self::missingTable($error) && !self::installed($pdo)) { return 'active'; }
            throw $error;
        }
    }

    public static function assertBusinessAccess(PDO $pdo, int $businessId): void
    {
        if ($businessId < 1 || self::businessStatus($pdo, $businessId) !== 'active') {
            throw new AuthException('Akses perniagaan ini digantung oleh pentadbir BeShare. Hubungi pemilik sistem.', 403);
        }
    }

    public static function assertBusinessActive(PDO $pdo, int $businessId): void
    {
        self::assertBusinessAccess($pdo, $businessId);
        $query = $pdo->prepare("SELECT b.id FROM businesses b JOIN users u ON u.id=b.owner_user_id
            JOIN business_users bu ON bu.business_id=b.id AND bu.user_id=b.owner_user_id
            WHERE b.id=? AND b.status='active' AND u.status='active' AND bu.role='owner'");
        $query->execute([$businessId]);
        if ($query->fetchColumn() === false) { throw new AuthException('Perniagaan belum aktif.', 409); }
    }

    public static function audit(PDO $pdo, int $businessId, int $adminId, string $action, ?int $entityId = null, array $metadata = []): void
    {
        $query = $pdo->prepare("INSERT INTO audit_logs
            (business_id,actor_user_id,actor_kind,action,entity_type,entity_id,meta_json,created_at)
            VALUES (?,?,'user',?,'platform_admin',?,?,UTC_TIMESTAMP())");
        $query->execute([$businessId, $adminId, $action, $entityId, json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }

    private static function missingTable(PDOException $error): bool
    {
        return (string) $error->getCode() === '42S02' || (int) ($error->errorInfo[1] ?? 0) === 1146;
    }
}
