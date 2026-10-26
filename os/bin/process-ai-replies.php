<?php
declare(strict_types=1);

use BesharOs\AiProductionSchema;
use BesharOs\AiReplyDispatcher;
use BesharOs\AiReplyPolicy;
use BesharOs\AiReplyWorker;
use BesharOs\AiReplySchema;
use BesharOs\Db;
use BesharOs\GeminiTransport;
use BesharOs\KnowledgeCentre;
use BesharOs\PrivateConfig;
use BesharOs\TokenSeal;
use BesharOs\WhatsAppTextSender;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$businessId = 0;
$all = false;
$inspect = false;
$limit = 10;
$businessLimit = 20;
$seen = [];
foreach (array_slice($argv, 1) as $argument) {
    $name = explode('=', $argument, 2)[0];
    if (isset($seen[$name])) { $valid = false; }
    elseif ($argument === '--all') { $all = true; $valid = true; }
    elseif ($argument === '--inspect') { $inspect = true; $valid = true; }
    elseif (preg_match('/\A--business=([1-9][0-9]{0,14})\z/', $argument, $match) === 1) {
        $businessId = (int) $match[1]; $valid = true;
    } elseif (preg_match('/\A--limit=([1-9][0-9]?)\z/', $argument, $match) === 1) {
        $limit = (int) $match[1]; $valid = $limit <= 50;
    } elseif (preg_match('/\A--business-limit=([1-9][0-9]{0,2})\z/', $argument, $match) === 1) {
        $businessLimit = (int) $match[1]; $valid = $businessLimit <= 100;
    } else { $valid = false; }
    if (!$valid) {
        fwrite(STDERR, "Penggunaan: php os/bin/process-ai-replies.php (--all | --business=ID) [--limit=10] [--business-limit=20] [--inspect]\n");
        exit(1);
    }
    $seen[$name] = true;
}
if (($all && $businessId > 0) || (!$all && $businessId < 1)
    || (!$all && isset($seen['--business-limit']))) {
    fwrite(STDERR, "Pilih satu skop: --all atau --business=ID.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/AiReplyWorker.php';
require_once dirname(__DIR__) . '/src/AiReplySchema.php';
require_once dirname(__DIR__) . '/src/AiReplyPolicy.php';
require_once dirname(__DIR__) . '/src/AiProductionSchema.php';
require_once dirname(__DIR__) . '/src/AiReplyDispatcher.php';
require_once dirname(__DIR__) . '/src/GeminiTransport.php';
require_once dirname(__DIR__) . '/src/WhatsAppTextSender.php';
require_once dirname(__DIR__, 2) . '/whatsapp/sambungan/ids.php';

try {
    $config = PrivateConfig::load();
    if (!is_array($config)) { throw new RuntimeException('configuration_unavailable'); }
    $rollout = AiReplyPolicy::allBusinesses($config);
    $eligible = $all ? $rollout : AiReplyPolicy::businessAllowed($config, $businessId);
    $ready = AiReplyPolicy::serverReady($config);
    if (!$inspect && (!$eligible || !$ready)) {
        fwrite(STDOUT, json_encode(['mode' => 'disabled', 'eligible' => $eligible,
            'server_ready' => $ready, 'provider_calls' => 0], JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }
    $pdo = Db::connect();
    $schemaSql = file_get_contents(dirname(__DIR__) . '/migrations/0004_ai_replies.up.sql');
    $baseReady = is_string($schemaSql) && (new AiReplySchema($pdo, $schemaSql))->inspect()['status'] === 'installed';
    $productionReady = AiProductionSchema::available($pdo);
    if ($inspect) {
        $result = ['mode' => 'inspect', 'scope' => $all ? 'all' : 'business',
            'rollout' => $rollout ? 'all' : 'pilot', 'eligible' => $eligible,
            'server_ready' => $ready, 'base_schema_ready' => $baseReady,
            'production_schema_ready' => $productionReady, 'provider_calls' => 0];
        if ($all && $productionReady) { $result += (new AiReplyDispatcher($pdo))->inspect($businessLimit); }
        elseif (!$all && $baseReady) {
            $query = $pdo->prepare("SELECT COUNT(*) FROM webhook_events WHERE business_id=? AND status='accepted' AND event_kind IN ('message','status')");
            $query->execute([$businessId]);
            $result += ['business_id' => $businessId, 'pending_events' => (int) $query->fetchColumn()];
        }
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }
    if (!$baseReady || ($rollout && !$productionReady)) { throw new RuntimeException('schema_unavailable'); }
    $seal = TokenSeal::tryFrom($config);
    if (!$seal instanceof TokenSeal || !function_exists('curl_init')) { throw new RuntimeException('configuration_unavailable'); }
    $generator = new GeminiTransport($config['gemini_api_key'], $config['gemini_model']);
    $sender = new WhatsAppTextSender(BESHARE_META_GRAPH);
    $worker = new AiReplyWorker($pdo, $seal, [$generator, 'reply'], [$sender, 'send'], null,
        new KnowledgeCentre($pdo, $seal), $rollout);
    $result = $all
        ? (new AiReplyDispatcher($pdo))->run([$worker, 'run'], $businessLimit, $limit)
        : $worker->run($businessId, $limit);
    // Aggregate counters only. Tokens, prompts and message bodies never leave the host.
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(($result['failed_businesses'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Pemprosesan AI tidak selesai. Semak konfigurasi dan rekod kerja pada pelayan. Tiada percubaan automatik semula untuk penghantaran yang tidak pasti.\n");
    exit(1);
}
