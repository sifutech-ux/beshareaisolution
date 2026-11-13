<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

final class PasswordRecovery
{
    public const MESSAGE = 'Jika e-mel itu berdaftar dan akaun aktif, pautan pemulihan akan dihantar dalam beberapa minit. Semak juga folder Spam. Pautan sah selama 30 minit.';
    public const INVALID = 'Pautan tidak sah atau telah tamat tempoh. Minta pautan baharu.';
    // Fixed canonical URL; never derive a credential-bearing link from HTTP_HOST.
    public const URL = 'https://beshareaisolution.com/whatsapp/ruang.html#pulih=';

    public function __construct(private PDO $pdo, private TokenSeal $seal, private RateLimit $rates) {}

    public function request(string $email, string $remote): void
    {
        if (!$this->rates->consume('recovery-ip|' . $remote)) { throw new AuthException('Terlalu banyak permintaan. Cuba lagi dalam 15 minit.', 429); }
        $email = Identifiers::email($email);
        if (!$this->rates->consume('recovery-email|' . $email)) { return; }
        // Queue only. SMTP latency and delivery failures cannot reveal account existence.
        $token = bin2hex(random_bytes(32));
        $sealed = $this->seal->seal($token, 64);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("SELECT id,password_hash FROM users WHERE email=? AND status='active' FOR UPDATE");
            $query->execute([$email]);
            $user = $query->fetch();
            if (is_array($user)) {
                $recent = $this->pdo->prepare('SELECT token_hash FROM password_reset_tokens WHERE user_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 SECOND) LIMIT 1');
                $recent->execute([$user['id']]);
                if ($recent->fetchColumn() === false) {
                    $insert = $this->pdo->prepare('INSERT INTO password_reset_tokens
                        (token_hash,user_id,credential_stamp,expires_at,mail_ciphertext,mail_nonce,mail_tag,key_version,mail_next_at,created_at)
                        VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE),?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                    $insert->execute([hash('sha256', $token),$user['id'],hash('sha256', $user['password_hash']),
                        $sealed['ciphertext'],$sealed['nonce'],$sealed['tag'],$sealed['version']]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $error) { $this->pdo->rollBack(); throw $error; }
    }

    public function reset(string $token, string $password, string $confirmation, string $remote): void
    {
        if (!$this->rates->consume('recovery-reset|' . $remote)) { throw new AuthException('Terlalu banyak percubaan. Cuba lagi dalam 15 minit.', 429); }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) { throw new AuthException(self::INVALID, 400); }
        if ($password !== $confirmation) { throw new AuthException('Kata laluan tidak sepadan.', 400); }
        $hash = Password::hash($password);
        $tokenHash = hash('sha256', $token);
        // Lock user first in every writer; serialize two different links for one user.
        $lookup = $this->pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=?');
        $lookup->execute([$tokenHash]);
        $userId = $lookup->fetchColumn();
        if ($userId === false) { throw new AuthException(self::INVALID, 400); }
        $this->pdo->beginTransaction();
        try {
            $userQuery = $this->pdo->prepare("SELECT password_hash FROM users WHERE id=? AND status='active' FOR UPDATE");
            $userQuery->execute([$userId]);
            $oldHash = $userQuery->fetchColumn();
            $query = $this->pdo->prepare('SELECT credential_stamp FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $query->execute([$tokenHash]);
            $stamp = $query->fetchColumn();
            if (!is_string($oldHash) || !is_string($stamp) || !hash_equals(hash('sha256', $oldHash), $stamp)) { throw new AuthException(self::INVALID, 400); }
            $this->pdo->prepare('UPDATE users SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$hash,$userId]);
            $this->pdo->prepare('INSERT INTO password_recovery_versions (user_id,changed_at) VALUES (?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE changed_at=UTC_TIMESTAMP()')->execute([$userId]);
            $this->pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP(),mail_ciphertext=NULL,mail_nonce=NULL,mail_tag=NULL WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
            $this->pdo->commit();
        } catch (\Throwable $error) { $this->pdo->rollBack(); throw $error; }
    }

    /** One process at a time, bounded retries. Never log recipients, bodies or tokens. */
    public function deliver(callable $send, int $limit = 5): array
    {
        $counts = ['accepted'=>0,'failed'=>0];
        if ($this->pdo->query("SELECT GET_LOCK('beshare:password-mail',0)")->fetchColumn() != 1) { return $counts; }
        try {
            $this->pdo->exec('UPDATE password_reset_tokens SET mail_ciphertext=NULL,mail_nonce=NULL,mail_tag=NULL WHERE expires_at<=UTC_TIMESTAMP() OR used_at IS NOT NULL OR mail_attempts>=3');
            $this->pdo->exec('DELETE FROM password_reset_tokens WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)');
            $rows = $this->pdo->query('SELECT r.*,u.email,u.password_hash,u.status FROM password_reset_tokens r JOIN users u ON u.id=r.user_id
                WHERE r.mail_ciphertext IS NOT NULL AND r.mail_sent_at IS NULL AND r.mail_next_at<=UTC_TIMESTAMP()
                ORDER BY r.created_at LIMIT ' . max(1,min(5,$limit)))->fetchAll();
            foreach ($rows as $row) {
                if ($row['status'] !== 'active' || !hash_equals($row['credential_stamp'],hash('sha256',$row['password_hash']))) {
                    $this->pdo->prepare('UPDATE password_reset_tokens SET mail_ciphertext=NULL,mail_nonce=NULL,mail_tag=NULL WHERE token_hash=?')->execute([$row['token_hash']]);
                    continue;
                }
                $this->pdo->prepare('UPDATE password_reset_tokens SET mail_attempts=mail_attempts+1,mail_next_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE token_hash=?')->execute([$row['token_hash']]);
                try {
                    $token = $this->seal->open($row['mail_ciphertext'],$row['mail_nonce'],$row['mail_tag'],(int)$row['key_version']);
                    if (!hash_equals($row['token_hash'],hash('sha256',$token))) { throw new \RuntimeException('invalid_mail'); }
                    $ok = $send($row['email'],self::URL . $token) === true;
                } catch (\Throwable) { $ok = false; }
                if ($ok) {
                    $this->pdo->prepare('UPDATE password_reset_tokens SET mail_sent_at=UTC_TIMESTAMP(),mail_ciphertext=NULL,mail_nonce=NULL,mail_tag=NULL WHERE token_hash=?')->execute([$row['token_hash']]);
                }
                $counts[$ok?'accepted':'failed']++;
            }
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('beshare:password-mail')"); }
        return $counts;
    }
}
