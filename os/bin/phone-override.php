<?php
declare(strict_types=1);

use BesharOs\Db;
use BesharOs\Membership;
use BesharOs\MetaGraph;
use BesharOs\PhoneOverride;
use BesharOs\PhoneOverrideGate;
use BesharOs\PrivateConfig;
use BesharOs\StreamMetaTransport;
use BesharOs\TokenSeal;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/PhoneOverrideGate.php';

$command = $argv[1] ?? '';
$confirm = in_array('--confirm', $argv, true);
$connectionId = 0;
$ownerId = 0;
foreach ($argv as $argument) {
    if (!is_string($argument)) {
        continue;
    }
    if (str_starts_with($argument, '--connection=')) {
        $connectionId = (int) substr($argument, strlen('--connection='));
    }
    if (str_starts_with($argument, '--owner=')) {
        $ownerId = (int) substr($argument, strlen('--owner='));
    }
}

if (!in_array($command, ['inspect', 'apply', 'rollback', 'note-subscribed'], true)) {
    fwrite(STDOUT, "Panggilan telefon dimatikan. Tiada panggilan Meta.\n");
    exit(0);
}

if ($command !== 'inspect' && (!$confirm || $connectionId < 1 || $ownerId < 1)) {
    fwrite(STDOUT, "Panggilan tidak diubah. Tiada panggilan Meta.\n");
    exit(1);
}

if ($command !== 'note-subscribed' && !PhoneOverrideGate::enabled()) {
    fwrite(STDOUT, "Panggilan telefon dimatikan. Tiada panggilan Meta.\n");
    exit(0);
}

if ($connectionId < 1 || $ownerId < 1) {
    fwrite(STDOUT, "Panggilan tidak diubah. Tiada panggilan Meta.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/whatsapp/sambungan/ids.php';

$config = PrivateConfig::load();
$seal = TokenSeal::tryFrom(is_array($config) ? $config : null);
$secret = is_array($config) ? (string) ($config['app_secret'] ?? '') : '';
$verify = is_array($config) ? (string) ($config['verify_token'] ?? '') : '';
if (!is_array($config) || $secret === '' || !$seal instanceof TokenSeal || ($command === 'apply' && $verify === '')) {
    fwrite(STDOUT, "Konfigurasi pelayan tidak lengkap. Tiada panggilan Meta.\n");
    exit(1);
}

try {
    $pdo = Db::connect();
    $actor = ownerActor($pdo, $ownerId, $connectionId);
    $transport = $command === 'note-subscribed' ? null : new StreamMetaTransport();
    $graph = new MetaGraph(BESHARE_META_APP_ID, $secret, BESHARE_META_GRAPH, $transport);
    $override = new PhoneOverride($pdo, $graph, $seal, BESHARE_META_APP_ID, $transport instanceof StreamMetaTransport);
    $result = match ($command) {
        'inspect' => $override->inspect($actor, $connectionId),
        'apply' => $override->apply($actor, $connectionId, $verify),
        'rollback' => $override->rollback($actor, $connectionId),
        'note-subscribed' => $override->noteSubscribed($actor, $connectionId),
        default => ['status' => 'disabled'],
    };
} catch (Throwable) {
    fwrite(STDOUT, "Panggilan tidak diubah. Tiada panggilan Meta.\n");
    exit(1);
}

$status = (string) ($result['status'] ?? '');
if (preg_match('/\A[a-z_]{3,32}\z/', $status) !== 1) {
    fwrite(STDOUT, "Panggilan tidak diubah. Tiada panggilan Meta.\n");
    exit(1);
}
fwrite(STDOUT, $status . "\n");
exit(0);

/** @return array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string} */
function ownerActor(PDO $pdo, int $ownerId, int $connectionId): array
{
    $statement = $pdo->prepare(
        'SELECT c.business_id
         FROM whatsapp_connections c
         INNER JOIN businesses b ON b.id = c.business_id AND b.owner_user_id = ?
         WHERE c.id = ? AND c.disconnected_at IS NULL'
    );
    $statement->execute([$ownerId, $connectionId]);
    $businessId = $statement->fetchColumn();
    if ($businessId === false) {
        throw new BesharOs\AuthException('Sambungan tidak dijumpai.', 404);
    }
    $actor = Membership::require($pdo, $ownerId, (int) $businessId);
    if ($actor['status'] !== 'active' || $actor['role'] !== 'owner' || (int) $actor['user_id'] !== (int) $actor['owner_user_id']) {
        throw new BesharOs\AuthException('Tindakan ditolak.', 403);
    }
    return $actor;
}
