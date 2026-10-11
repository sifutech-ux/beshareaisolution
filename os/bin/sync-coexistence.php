<?php
declare(strict_types=1);

use BesharOs\ActivationGate;
use BesharOs\CoexistenceSync;
use BesharOs\CoexistenceWorker;
use BesharOs\Db;
use BesharOs\MetaGraph;
use BesharOs\PrivateConfig;
use BesharOs\StreamMetaTransport;
use BesharOs\TokenSeal;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/ActivationGate.php';

$command = $argv[1] ?? 'run';
$confirm = in_array('--confirm', $argv, true);
$connectionId = 0;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--connection=')) {
        $connectionId = (int) substr($argument, strlen('--connection='));
    }
}

if ($command === 'record-callback' || $command === 'record-fields') {
    if (!$confirm || $connectionId < 1) {
        fwrite(STDOUT, "Rekod tidak ditulis. Tiada panggilan Meta.\n");
        exit(1);
    }
    require dirname(__DIR__) . '/src/bootstrap.php';
    try {
        CoexistenceSync::record(Db::connect(), $connectionId, $command === 'record-callback' ? 'callback' : 'fields');
    } catch (Throwable) {
        fwrite(STDOUT, "Rekod tidak ditulis. Tiada panggilan Meta.\n");
        exit(1);
    }
    fwrite(STDOUT, "Rekod kesediaan disimpan. Tiada panggilan Meta.\n");
    exit(0);
}

if ($command !== 'run' || !ActivationGate::enabled()) {
    fwrite(STDOUT, "Penyegerakan langsung dimatikan. Tiada panggilan Meta.\n");
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/whatsapp/sambungan/ids.php';

$config = PrivateConfig::load();
$seal = TokenSeal::tryFrom(is_array($config) ? $config : null);
if (!is_array($config) || !isset($config['app_secret']) || !$seal instanceof TokenSeal) {
    fwrite(STDOUT, "Konfigurasi pelayan tidak lengkap. Tiada panggilan Meta.\n");
    exit(1);
}

try {
    $graph = new MetaGraph(BESHARE_META_APP_ID, $config['app_secret'], BESHARE_META_GRAPH, new StreamMetaTransport());
    $result = CoexistenceWorker::process(Db::connect(), $graph, $seal, time(), true);
} catch (Throwable) {
    fwrite(STDOUT, "Konfigurasi pelayan tidak lengkap. Tiada panggilan Meta.\n");
    exit(1);
}

fwrite(STDOUT, 'Penyegerakan disemak. dihantar=' . $result['sent'] . ' semak=' . $result['review'] . ' gagal=' . $result['failed'] . "\n");
exit(0);
