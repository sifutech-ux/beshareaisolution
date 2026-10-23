<?php
declare(strict_types=1);

use BesharOs\AiReplyWorker;
use BesharOs\AiReplySchema;
use BesharOs\Db;
use BesharOs\GeminiTransport;
use BesharOs\KnowledgeCentre;
use BesharOs\PrivateConfig;
use BesharOs\TokenSeal;
use BesharOs\WhatsAppTextSender;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Every run is explicitly scoped to the owner's business. No secrets in argv.
$businessId = 0;
$limit = 10;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--business=([1-9][0-9]{0,14})\z/', $argument, $match) === 1 && $businessId === 0) {
        $businessId = (int) $match[1];
    } elseif (preg_match('/\A--limit=([1-9][0-9]?)\z/', $argument, $match) === 1) {
        $limit = (int) $match[1];
    } else {
        fwrite(STDERR, "Penggunaan: php os/bin/process-ai-replies.php --business=ID [--limit=10]\n");
        exit(1);
    }
}
if ($businessId < 1 || $limit < 1 || $limit > 50) {
    fwrite(STDERR, "Perniagaan dan had pemprosesan mesti sah.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/AiReplyWorker.php';
require_once dirname(__DIR__) . '/src/AiReplySchema.php';
require_once dirname(__DIR__) . '/src/GeminiTransport.php';
require_once dirname(__DIR__) . '/src/WhatsAppTextSender.php';
require_once dirname(__DIR__, 2) . '/whatsapp/sambungan/ids.php';

try {
    $config = PrivateConfig::load();
    if (!is_array($config) || ($config['owner_ai_enabled'] ?? '') !== 'true'
        || ($config['owner_ai_business_id'] ?? '') !== (string) $businessId) {
        fwrite(STDOUT, "AI pemilik belum diaktifkan untuk perniagaan ini. Tiada panggilan penyedia.\n");
        exit(0);
    }
    $seal = TokenSeal::tryFrom($config);
    $key = $config['gemini_api_key'] ?? '';
    $model = $config['gemini_model'] ?? '';
    if (!$seal instanceof TokenSeal || !is_string($key) || $key === '' || !is_string($model) || $model === ''
        || !function_exists('curl_init')) {
        throw new RuntimeException('configuration_unavailable');
    }
    $pdo = Db::connect();
    $schemaSql = file_get_contents(dirname(__DIR__) . '/migrations/0004_ai_replies.up.sql');
    if (!is_string($schemaSql) || (new AiReplySchema($pdo, $schemaSql))->inspect()['status'] !== 'installed') {
        throw new RuntimeException('schema_unavailable');
    }
    $generator = new GeminiTransport($key, $model);
    $sender = new WhatsAppTextSender(BESHARE_META_GRAPH);
    $worker = new AiReplyWorker(
        $pdo,
        $seal,
        [$generator, 'reply'],
        [$sender, 'send'],
        null,
        new KnowledgeCentre($pdo, $seal)
    );
    $result = $worker->run($businessId, $limit);
    // Only aggregate counters leave the server. Tokens, prompts and message bodies never do.
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Pemprosesan AI tidak selesai. Semak konfigurasi dan rekod kerja pada pelayan. Tiada percubaan automatik semula untuk penghantaran yang tidak pasti.\n");
    exit(1);
}
