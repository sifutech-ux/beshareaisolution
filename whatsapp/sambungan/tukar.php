<?php
declare(strict_types=1);

require __DIR__ . '/ids.php';
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function beshare_os_tukar_finish(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    beshare_os_tukar_finish(405, ['ok' => false]);
}

$public = beshare_os_public_meta(BESHARE_META_APP_ID, BESHARE_META_CONFIG_ID);
$private = beshare_os_private_config();
$secret = is_array($private) ? ($private['app_secret'] ?? '') : '';
if ($public['ready'] !== true || !is_string($secret) || $secret === '') {
    beshare_os_tukar_finish(503, ['ok' => false, 'stored' => false]);
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
$code = is_array($payload) ? ($payload['code'] ?? '') : '';
if (!is_string($code) || !preg_match('/\A[A-Za-z0-9_\-]{8,512}\z/', $code)) {
    beshare_os_tukar_finish(400, ['ok' => false, 'stored' => false]);
}

$ch = curl_init('https://graph.facebook.com/' . BESHARE_META_GRAPH . '/oauth/access_token');
if ($ch === false) {
    beshare_os_tukar_finish(503, ['ok' => false, 'stored' => false]);
}
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $public['appId'],
        'client_secret' => $secret,
        'code' => $code,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$response = curl_exec($ch);
curl_close($ch);
$decoded = json_decode(is_string($response) ? $response : '', true);
$token = is_array($decoded) ? ($decoded['access_token'] ?? '') : '';
if (!is_string($token) || $token === '') {
    beshare_os_tukar_finish(502, ['ok' => false, 'stored' => false]);
}

$real = realpath((string) getenv('BESHARE_OS_WEBHOOK_CONFIG'));
if ($real === false) {
    $real = realpath(dirname(__DIR__, 3) . '/private/beshare-os-webhook.php');
}
if ($real === false) {
    beshare_os_tukar_finish(200, ['ok' => true, 'stored' => false]);
}
$publicRoot = realpath(dirname(__DIR__, 2));
$dir = dirname($real);
if ($publicRoot !== false && str_starts_with($dir, $publicRoot . DIRECTORY_SEPARATOR)) {
    beshare_os_tukar_finish(200, ['ok' => true, 'stored' => false]);
}
$stored = file_put_contents(
    $dir . '/signup-token.php',
    "<?php\ndeclare(strict_types=1);\nreturn " . var_export(['saved_at' => gmdate('c'), 'access_token' => $token], true) . ";\n",
    LOCK_EX
);
if ($stored !== false) {
    @chmod($dir . '/signup-token.php', 0600);
}
beshare_os_tukar_finish(200, ['ok' => true, 'stored' => $stored !== false]);
