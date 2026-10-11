<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

final class Membership
{
    /**
     * @return array{
     *   user_id:int,business_id:int,role:string,owner_user_id:int,status:string,
     *   email:string,name:string,business_name:string,public_id:string
     * }
     */
    public static function require(PDO $pdo, int $userId, int $businessId): array
    {
        if ($userId < 1 || $businessId < 1) {
            throw new AuthException('Sesi diperlukan.', 401);
        }
        $statement = $pdo->prepare(
            'SELECT b.id AS business_id, b.public_id, b.name AS business_name, b.status, b.owner_user_id,
                    bu.role, u.id AS user_id, u.email, u.name
             FROM business_users bu
             INNER JOIN businesses b ON b.id = bu.business_id
             INNER JOIN users u ON u.id = bu.user_id
             WHERE bu.user_id = ? AND bu.business_id = ? AND u.status = \'active\''
        );
        $statement->execute([$userId, $businessId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new AuthException('Sesi tidak sah.', 401);
        }
        return self::shape($row);
    }

    /**
     * @return array{
     *   user_id:int,business_id:int,role:string,owner_user_id:int,status:string,
     *   email:string,name:string,business_name:string,public_id:string
     * }
     */
    public static function primary(PDO $pdo, int $userId): array
    {
        $rows = self::rows($pdo, $userId);
        if ($rows === []) {
            throw new AuthException('Tiada keahlian perniagaan.', 403);
        }
        foreach ($rows as $row) {
            if ($row['status'] === 'active') {
                return $row;
            }
        }
        return $rows[0];
    }

    /**
     * @return list<array{
     *   user_id:int,business_id:int,role:string,owner_user_id:int,status:string,
     *   email:string,name:string,business_name:string,public_id:string
     * }>
     */
    public static function list(PDO $pdo, int $userId): array
    {
        return self::rows($pdo, $userId);
    }

    public static function byPublicId(PDO $pdo, int $userId, string $publicId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId) !== 1) {
            throw new AuthException('Perniagaan tidak sah.', 400);
        }
        $statement = $pdo->prepare(
            'SELECT b.id AS business_id, b.public_id, b.name AS business_name, b.status, b.owner_user_id,
                    bu.role, u.id AS user_id, u.email, u.name
             FROM business_users bu
             INNER JOIN businesses b ON b.id = bu.business_id
             INNER JOIN users u ON u.id = bu.user_id
             WHERE bu.user_id = ? AND b.public_id = ? AND u.status = \'active\''
        );
        $statement->execute([$userId, $publicId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new AuthException('Perniagaan tidak dijumpai.', 404);
        }
        return self::shape($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   user_id:int,business_id:int,role:string,owner_user_id:int,status:string,
     *   email:string,name:string,business_name:string,public_id:string
     * }
     */
    private static function shape(array $row): array
    {
        return [
            'user_id' => (int) $row['user_id'],
            'business_id' => (int) $row['business_id'],
            'role' => (string) $row['role'],
            'owner_user_id' => (int) $row['owner_user_id'],
            'status' => (string) $row['status'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'business_name' => (string) $row['business_name'],
            'public_id' => (string) $row['public_id'],
        ];
    }

    /** @return list<array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string}> */
    private static function rows(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT b.id AS business_id, b.public_id, b.name AS business_name, b.status, b.owner_user_id,
                    bu.role, u.id AS user_id, u.email, u.name
             FROM business_users bu
             INNER JOIN businesses b ON b.id = bu.business_id
             INNER JOIN users u ON u.id = bu.user_id
             WHERE bu.user_id = ? AND u.status = \'active\'
             ORDER BY b.id ASC'
        );
        $statement->execute([$userId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = self::shape($row);
            }
        }
        return $rows;
    }
}
