<?php
declare(strict_types=1);

/**
 * Calon webhook BeShare AI OS.
 * Laluan ini berasingan daripada /webhook/ yang sedang hidup.
 * Jangan daftarkan URL ini pada aplikasi Meta yang sedang berkhidmat.
 *
 * Konfigurasi di luar web root, fail PHP yang memulangkan tatasusunan:
 *   ['verify_token' => '...', 'app_secret' => '...']
 * Laluan lalai: induk public_html + /private/beshare-os-webhook.php
 * Atau tetapkan BESHARE_OS_WEBHOOK_CONFIG ke laluan fail itu.
 * Tanpa konfigurasi yang sah, skrip membalas 503 dan tidak menggemakan cabaran.
 */

function beshare_os_webhook_finish(int $status, string $body, string $type = 'text/plain; charset=UTF-8'): void
{
    http_response_code($status);
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
}

function beshare_os_webhook_config(): ?array
{
    $path = getenv('BESHARE_OS_WEBHOOK_CONFIG');
    if (!is_string($path) || $path === '') {
        $path = dirname(__DIR__, 3) . '/private/beshare-os-webhook.php';
    }
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $real = realpath($path);
    $publicRoot = realpath(dirname(__DIR__, 2));
    if ($real === false || ($publicRoot !== false && str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR))) {
        return null;
    }
    ob_start();
    $config = include $real;
    $leak = ob_get_clean();
    if ($leak !== '' || !is_array($config)) {
        return null;
    }
    $token = $config['verify_token'] ?? null;
    $secret = $config['app_secret'] ?? null;
    if (!is_string($token) || $token === '' || !is_string($secret) || $secret === '') {
        return null;
    }
    return ['verify_token' => $token, 'app_secret' => $secret];
}

$config = beshare_os_webhook_config();
if ($config === null) {
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
    if ($mode !== 'subscribe' || $challenge === '' || !hash_equals($config['verify_token'], $token)) {
        beshare_os_webhook_finish(403, 'Forbidden');
    }
    beshare_os_webhook_finish(200, $challenge);
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 1048576) {
        beshare_os_webhook_finish(413, 'Payload too large');
    }
    $header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!is_string($header) || !preg_match('/\Asha256=([0-9a-f]{64})\z/i', $header, $match)) {
        beshare_os_webhook_finish(403, 'Forbidden');
    }
    $expected = hash_hmac('sha256', $raw, $config['app_secret']);
    if (!hash_equals($expected, strtolower($match[1]))) {
        beshare_os_webhook_finish(403, 'Forbidden');
    }
    beshare_os_webhook_finish(200, '{"ok":true}', 'application/json; charset=UTF-8');
}

beshare_os_webhook_finish(405, 'Method not allowed');
