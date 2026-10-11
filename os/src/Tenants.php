<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

final class Tenants
{
    /** @return array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string} */
    public static function provision(PDO $pdo, int $userId, string $businessName): array
    {
        if ($userId < 1) {
            throw new AuthException('Pengguna tidak sah.', 400);
        }
        $businessName = Identifiers::businessName($businessName);
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $businessId = self::insertBusiness($pdo, $businessName);
            $member = $pdo->prepare(
                'INSERT INTO business_users (business_id, user_id, role, created_at, updated_at)
                 VALUES (?, ?, \'owner\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $member->execute([$businessId, $userId]);
            $owner = $pdo->prepare(
                'UPDATE businesses SET owner_user_id = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = \'provisioning\' AND owner_user_id IS NULL'
            );
            $owner->execute([$userId, $businessId]);
            if ($owner->rowCount() !== 1) {
                throw new AuthException('Pemilik tidak dapat ditetapkan.', 500);
            }
            $active = $pdo->prepare(
                'UPDATE businesses SET status = \'active\', updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = \'provisioning\' AND owner_user_id = ?'
            );
            $active->execute([$businessId, $userId]);
            if ($active->rowCount() !== 1) {
                throw new AuthException('Perniagaan tidak dapat diaktifkan.', 500);
            }
            if ($own) {
                $pdo->commit();
            }
            return Membership::require($pdo, $userId, $businessId);
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof AuthException) {
                throw $error;
            }
            throw SqlGuard::translate($error);
        }
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session
     *  @return list<array{name:string,email:string,role:string}>
     */
    public static function members(PDO $pdo, array $session): array
    {
        self::assertReader($session);
        $statement = $pdo->prepare(
            'SELECT u.name, u.email, bu.role
             FROM business_users bu
             INNER JOIN users u ON u.id = bu.user_id
             WHERE bu.business_id = ?
             ORDER BY FIELD(bu.role, \'owner\', \'admin\', \'staff\'), u.email'
        );
        $statement->execute([self::businessId($session)]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'name' => (string) $row['name'],
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
            ];
        }
        return $rows;
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session
     *  @return list<array{at:string,actor:string,action:string}>
     */
    public static function audit(PDO $pdo, array $session): array
    {
        self::assertReader($session);
        $statement = $pdo->prepare(
            'SELECT a.created_at, u.name AS actor, a.action
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.business_id = ?
             ORDER BY a.id DESC
             LIMIT 50'
        );
        $statement->execute([self::businessId($session)]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'at' => (string) $row['created_at'],
                'actor' => (string) ($row['actor'] ?? ''),
                'action' => (string) $row['action'],
            ];
        }
        return $rows;
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session */
    public static function addMember(PDO $pdo, array $session, string $name, string $email, string $password, string $role): void
    {
        self::assertOwner($session);
        if ($role !== 'admin' && $role !== 'staff') {
            throw new AuthException('Peranan tidak dibenarkan.', 403);
        }
        $email = Identifiers::email($email);
        $businessId = self::businessId($session);
        $existing = $pdo->prepare('SELECT id, status FROM users WHERE email = ?');
        $existing->execute([$email]);
        $user = $existing->fetch();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            if (is_array($user)) {
                if ((string) $user['status'] !== 'active') {
                    throw new AuthException('Ahli tidak dapat ditambah.', 409);
                }
                $userId = (int) $user['id'];
            } else {
                $name = Identifiers::personName($name);
                Password::assertAcceptable($password);
                $insert = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, name, status, created_at, updated_at)
                     VALUES (?, ?, ?, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                $insert->execute([$email, Password::hash($password), $name]);
                $userId = (int) $pdo->lastInsertId();
            }
            $member = $pdo->prepare(
                'INSERT INTO business_users (business_id, user_id, role, created_at, updated_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $member->execute([$businessId, $userId, $role]);
            Accounts::audit($pdo, $businessId, (int) $session['user_id'], 'ahli_ditambah', 'user', $userId);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof AuthException) {
                throw $error;
            }
            $text = $error->getMessage();
            if (str_contains($text, 'PRIMARY') || str_contains($text, 'Duplicate')) {
                throw new AuthException('Ahli ini sudah ada.', 409);
            }
            throw SqlGuard::translate($error);
        }
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session */
    public static function removeMember(PDO $pdo, array $session, string $email): void
    {
        self::assertOwner($session);
        $email = Identifiers::email($email);
        $businessId = self::businessId($session);
        $statement = $pdo->prepare(
            'SELECT bu.user_id, bu.role
             FROM business_users bu
             INNER JOIN users u ON u.id = bu.user_id
             WHERE bu.business_id = ? AND u.email = ?'
        );
        $statement->execute([$businessId, $email]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new AuthException('Ahli tidak dijumpai.', 404);
        }
        if ((string) $row['role'] === 'owner') {
            throw new AuthException('Pemilik tidak boleh dibuang.', 403);
        }
        $delete = $pdo->prepare('DELETE FROM business_users WHERE business_id = ? AND user_id = ? AND role <> \'owner\'');
        $delete->execute([$businessId, (int) $row['user_id']]);
        if ($delete->rowCount() !== 1) {
            throw new AuthException('Ahli tidak dapat dibuang.', 409);
        }
        Accounts::audit($pdo, $businessId, (int) $session['user_id'], 'ahli_dibuang', 'user', (int) $row['user_id']);
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session */
    public static function changeRole(PDO $pdo, array $session, string $email, string $role): void
    {
        self::assertOwner($session);
        if ($role !== 'admin' && $role !== 'staff') {
            throw new AuthException('Peranan tidak dibenarkan.', 403);
        }
        $email = Identifiers::email($email);
        $businessId = self::businessId($session);
        $statement = $pdo->prepare(
            'SELECT bu.user_id, bu.role
             FROM business_users bu
             INNER JOIN users u ON u.id = bu.user_id
             WHERE bu.business_id = ? AND u.email = ?'
        );
        $statement->execute([$businessId, $email]);
        $row = $statement->fetch();
        if (!is_array($row) || (string) $row['role'] === 'owner') {
            throw new AuthException('Ahli tidak dijumpai.', 404);
        }
        $update = $pdo->prepare(
            'UPDATE business_users SET role = ?, updated_at = UTC_TIMESTAMP()
             WHERE business_id = ? AND user_id = ? AND role IN (\'admin\', \'staff\')'
        );
        $update->execute([$role, $businessId, (int) $row['user_id']]);
        if ($update->rowCount() !== 1) {
            throw new AuthException('Peranan tidak dapat ditukar.', 409);
        }
        Accounts::audit($pdo, $businessId, (int) $session['user_id'], 'peranan_ditukar', 'user', (int) $row['user_id']);
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session
     * @return array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string}
     */
    public static function changeStatus(PDO $pdo, array $session, string $kind, string $nextStatus, ?string $ownerEmail): array
    {
        self::assertOwner($session);
        $businessId = self::businessId($session);
        $nextOwner = null;
        if ($kind === 'reassign_owner') {
            if ($ownerEmail === null || trim($ownerEmail) === '') {
                throw new AuthException('Pemilik baharu diperlukan.', 400);
            }
            $email = Identifiers::email($ownerEmail);
            $lookup = $pdo->prepare(
                'SELECT u.id FROM users u
                 INNER JOIN business_users bu ON bu.user_id = u.id
                 WHERE bu.business_id = ? AND u.email = ? AND u.status = \'active\''
            );
            $lookup->execute([$businessId, $email]);
            $found = $lookup->fetch();
            if (!is_array($found)) {
                throw new AuthException('Ahli tidak dijumpai.', 404);
            }
            $nextOwner = (int) $found['id'];
        }
        $action = ['kind' => $kind, 'next_status' => $nextStatus];
        if ($nextOwner !== null) {
            $action['next_owner_user_id'] = $nextOwner;
        }
        if (OwnershipAccess::decide($session, $action) !== 'allowed') {
            throw new AuthException('Tindakan ditolak.', 403);
        }
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            if ($kind === 'reassign_owner') {
                self::reassign($pdo, $session, $businessId, (int) $nextOwner);
            } else {
                $update = $pdo->prepare(
                    'UPDATE businesses SET status = ?, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = ? AND owner_user_id = ?'
                );
                $update->execute([$nextStatus, $businessId, $session['status'], $session['owner_user_id']]);
                if ($update->rowCount() !== 1) {
                    throw new AuthException('Status tidak dapat ditukar.', 409);
                }
            }
            Accounts::audit($pdo, $businessId, (int) $session['user_id'], 'status_' . $kind, 'business', $businessId);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof AuthException) {
                throw $error;
            }
            throw SqlGuard::translate($error);
        }
        return Membership::require($pdo, (int) $session['user_id'], $businessId);
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session */
    public static function rename(PDO $pdo, array $session, string $name): void
    {
        if ($session['role'] !== 'owner' && $session['role'] !== 'admin') {
            throw new AuthException('Tindakan ditolak.', 403);
        }
        $name = Identifiers::businessName($name);
        $update = $pdo->prepare('UPDATE businesses SET name = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([$name, self::businessId($session)]);
        if ($update->rowCount() !== 1) {
            throw new AuthException('Nama tidak dapat disimpan.', 409);
        }
        Accounts::audit($pdo, self::businessId($session), (int) $session['user_id'], 'nama_dikemas_kini', 'business', self::businessId($session));
    }

    /** @param array{role:string,owner_user_id:int,user_id:int} $session */
    private static function assertOwner(array $session): void
    {
        if ($session['role'] !== 'owner' || (int) $session['user_id'] !== (int) $session['owner_user_id']) {
            throw new AuthException('Tindakan ditolak.', 403);
        }
    }

    /** @param array{role:string} $session */
    private static function assertReader(array $session): void
    {
        if ($session['role'] !== 'owner' && $session['role'] !== 'admin') {
            throw new AuthException('Tindakan ditolak.', 403);
        }
    }

    /** @param array{business_id?:int} $session */
    private static function businessId(array $session): int
    {
        $id = (int) ($session['business_id'] ?? 0);
        if ($id < 1) {
            throw new AuthException('Sesi tidak sah.', 401);
        }
        return $id;
    }

    private static function insertBusiness(PDO $pdo, string $businessName): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO businesses (public_id, name, status, owner_user_id, created_at, updated_at)
             VALUES (?, ?, \'provisioning\', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $error = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $insert->execute([Identifiers::publicId(), $businessName]);
                $error = null;
                break;
            } catch (\PDOException $caught) {
                $error = $caught;
                if (!str_contains($caught->getMessage(), 'uq_businesses_public')) {
                    throw $caught;
                }
            }
        }
        if ($error instanceof \PDOException) {
            throw $error;
        }
        $businessId = (int) $pdo->lastInsertId();
        if ($businessId < 1) {
            throw new AuthException('Perniagaan tidak dapat dicipta.', 500);
        }
        return $businessId;
    }

    /** @param array{owner_user_id:int} $session */
    private static function reassign(PDO $pdo, array $session, int $businessId, int $nextOwner): void
    {
        $demote = $pdo->prepare(
            'UPDATE business_users SET role = \'admin\', updated_at = UTC_TIMESTAMP()
             WHERE business_id = ? AND user_id = ? AND role = \'owner\''
        );
        $demote->execute([$businessId, (int) $session['owner_user_id']]);
        if ($demote->rowCount() !== 1) {
            throw new AuthException('Pemilik tidak dapat dipindahkan.', 409);
        }
        $promote = $pdo->prepare(
            'UPDATE business_users SET role = \'owner\', updated_at = UTC_TIMESTAMP()
             WHERE business_id = ? AND user_id = ? AND role IN (\'admin\', \'staff\')'
        );
        $promote->execute([$businessId, $nextOwner]);
        if ($promote->rowCount() !== 1) {
            throw new AuthException('Pemilik tidak dapat dipindahkan.', 409);
        }
        $own = $pdo->prepare(
            'UPDATE businesses SET owner_user_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = \'provisioning\' AND owner_user_id = ?'
        );
        $own->execute([$nextOwner, $businessId, (int) $session['owner_user_id']]);
        if ($own->rowCount() !== 1) {
            throw new AuthException('Pemilik tidak dapat dipindahkan.', 409);
        }
    }
}
