<?php
declare(strict_types=1);

/**
 * Webhook BeShare AI OS.
 * Laluan ini berasingan daripada /webhook/ yang lama.
 * Tanpa konfigurasi di luar web root, skrip membalas 503 dan tidak menggemakan cabaran.
 */

require __DIR__ . '/config.php';

use BesharOs\AuthException;
use BesharOs\Db;
use BesharOs\TokenSeal;
use BesharOs\WhatsAppWebhook;

function beshare_os_webhook_finish(int $status, string $body, string $type = 'text/plain; charset=UTF-8'): void
{
    http_response_code($status);
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

$config = beshare_os_private_config();
$tokenValue = is_array($config) ? ($config['verify_token'] ?? '') : '';
$secret = is_array($config) ? ($config['app_secret'] ?? '') : '';
if (!is_string($tokenValue) || $tokenValue === '' || !is_string($secret) || $secret === '') {
    beshare_os_webhook_finish(503, 'Unavailable');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    if (!is_string($mode) || !is_string($token) || !is_string($challenge)) {
        beshare_os_webhook_finish(400, 'Bad request');
    }
    if ($mode !== 'subscribe' || $challenge === '' || !hash_equals($tokenValue, $token)) {
        beshare_os_webhook_finish(403, 'Forbidden');
    }
    beshare_os_webhook_finish(200, $challenge);
}

if ($method !== 'POST') {
    beshare_os_webhook_finish(405, 'Method not allowed');
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 1048576) {
    beshare_os_webhook_finish(413, 'Payload too large');
}
$header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!is_string($header) || !WhatsAppWebhook::signatureMatches($raw, $header, $secret)) {
    beshare_os_webhook_finish(403, 'Forbidden');
}
$seal = TokenSeal::tryFrom($config);
if (!$seal instanceof TokenSeal) {
    beshare_os_webhook_finish(503, 'Unavailable');
}
try {
    (new WhatsAppWebhook(Db::connect(), $seal))->ingest($raw);
} catch (AuthException $error) {
    beshare_os_webhook_finish($error->status(), 'Bad request');
} catch (Throwable) {
    beshare_os_webhook_finish(500, 'Unavailable');
}
beshare_os_webhook_finish(200, '{"ok":true}', 'application/json; charset=UTF-8');
