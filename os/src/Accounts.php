<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

final class Accounts
{
    /** @return array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string} */
    public static function register(PDO $pdo, string $name, string $email, string $password, string $businessName): array
    {
        $name = Identifiers::personName($name);
        $email = Identifiers::email($email);
        $businessName = Identifiers::businessName($businessName);
        Password::assertAcceptable($password);
        $hash = Password::hash($password);
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $insert = $pdo->prepare(
                'INSERT INTO users (email, password_hash, name, status, created_at, updated_at)
                 VALUES (?, ?, ?, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([$email, $hash, $name]);
            $userId = (int) $pdo->lastInsertId();
            if ($userId < 1) {
                throw new AuthException('Akaun tidak dapat didaftarkan.', 500);
            }
            $context = Tenants::provision($pdo, $userId, $businessName);
            self::audit($pdo, $context['business_id'], $userId, 'akaun_didaftarkan', 'user', $userId);
            if ($own) {
                $pdo->commit();
            }
            return $context;
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof AuthException) {
                throw $error;
            }
            $text = $error->getMessage();
            if (str_contains($text, 'uq_users_email') || str_contains($text, 'Duplicate')) {
                throw new AuthException('E-mel ini sudah didaftarkan.', 409);
            }
            throw SqlGuard::translate($error);
        }
    }

    /** @return array{id:int,email:string,name:string} */
    public static function authenticate(PDO $pdo, string $email, string $password): array
    {
        try {
            $email = Identifiers::email($email);
        } catch (AuthException $error) {
            Password::verify($password, Password::dummyHash());
            throw $error;
        }
        $statement = $pdo->prepare('SELECT id, email, name, status, password_hash FROM users WHERE email = ?');
        $statement->execute([$email]);
        $row = $statement->fetch();
        $hash = is_array($row) ? (string) $row['password_hash'] : Password::dummyHash();
        $matches = Password::verify($password, $hash);
        if (!is_array($row) || !$matches || (string) $row['status'] !== 'active') {
            throw new AuthException('E-mel atau kata laluan tidak sah.', 401);
        }
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
        ];
    }

    public static function audit(PDO $pdo, int $businessId, int $userId, string $action, string $entity, ?int $entityId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO audit_logs (business_id, actor_user_id, actor_kind, action, entity_type, entity_id, meta_json, created_at)
             VALUES (?, ?, \'user\', ?, ?, ?, NULL, UTC_TIMESTAMP())'
        );
        $statement->execute([$businessId, $userId, $action, $entity, $entityId]);
    }
}
