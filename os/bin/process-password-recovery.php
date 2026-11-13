<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

use BesharOs\CredentialSession;
use BesharOs\Db;
use BesharOs\PasswordRecovery;
use BesharOs\PrivateConfig;
use BesharOs\RateLimit;
use BesharOs\RecoveryMail;
use BesharOs\TokenSeal;

try {
    $pdo = Db::connect();
    $seal = TokenSeal::tryFrom(PrivateConfig::load());
    if (!$seal instanceof TokenSeal || !CredentialSession::installed($pdo) || !function_exists('mail')) {
        throw new RuntimeException('not_ready');
    }
    $service = new PasswordRecovery($pdo,$seal,new RateLimit(RateLimit::resolveDirectory()));
    $counts = $service->deliver([RecoveryMail::class,'send']);
    // HTTP requests fail closed if this scheduled worker stops running.
    $heartbeat = dirname(__DIR__,3) . '/private/password-recovery-ready';
    if (file_put_contents($heartbeat,'ready',LOCK_EX) === false || !chmod($heartbeat,0600)) { throw new RuntimeException('heartbeat'); }
    echo json_encode(['ready'=>true] + $counts,JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    fwrite(STDERR,"Pemulihan e-mel belum tersedia. Semak skema, kunci token, mail dan folder private.\n");
    exit(1);
}
