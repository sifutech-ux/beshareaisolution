<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\Db;
use BesharOs\MetaGraph;
use BesharOs\PrivateConfig;
use BesharOs\RateLimit;
use BesharOs\SessionCookie;
use BesharOs\TokenSeal;
use BesharOs\WhatsAppGateway;

if (PHP_SAPI === 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/os/src/bootstrap.php';
require dirname(__DIR__) . '/sambungan/ids.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name(SessionCookie::NAME);
session_set_cookie_params(SessionCookie::parameters($secure));
session_start();

$send = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = (string) ($_GET['tindakan'] ?? '');
    if (preg_match('/^[a-z]{3,20}$/', $action) !== 1) {
        $send(404, ['ok' => false, 'error' => 'Tindakan tidak dikenali.']);
    }
    $body = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            $raw = '';
        }
        if (strlen($raw) > 65536) {
            $send(413, ['ok' => false, 'error' => 'Permintaan terlalu besar.']);
        }
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $send(400, ['ok' => false, 'error' => 'JSON tidak sah.']);
            }
            $body = $decoded;
        }
    }
    $headers = ['x-csrf-token' => (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')];
    if (isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])) {
        $headers['origin'] = $_SERVER['HTTP_ORIGIN'];
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
    $pdo = Db::connect();
    $private = PrivateConfig::load();
    $secret = is_array($private) ? (string) ($private['app_secret'] ?? '') : '';
    $seal = TokenSeal::tryFrom(is_array($private) ? $private : null);
    $gateway = null;
    if ($secret !== '' && $seal instanceof TokenSeal && BESHARE_META_APP_ID !== '' && BESHARE_META_CONFIG_ID !== '') {
        try {
            $gateway = new WhatsAppGateway($pdo, new MetaGraph(BESHARE_META_APP_ID, $secret, BESHARE_META_GRAPH), $seal, BESHARE_META_APP_ID);
        } catch (AuthException) {
            $gateway = null;
        }
    }
    $http = new AuthHttp($pdo, new RateLimit(RateLimit::resolveDirectory()), $gateway);
    $result = $http->handle($method, $action, $_SESSION, $body, $headers, $remote, $host, session_id());
    if ($result['regenerate']) {
        session_regenerate_id(true);
    }
    $send($result['status'], $result['body']);
} catch (AuthException $error) {
    $send($error->status(), ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    $send(500, ['ok' => false, 'error' => 'Permintaan tidak dapat diselesaikan.']);
}
