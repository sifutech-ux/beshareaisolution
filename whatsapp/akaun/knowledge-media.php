<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\Csrf;
use BesharOs\Db;
use BesharOs\KnowledgeCentre;
use BesharOs\Membership;
use BesharOs\PrivateConfig;
use BesharOs\SessionCookie;
use BesharOs\TokenSeal;

if (PHP_SAPI === 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/os/src/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; sandbox");
header('Referrer-Policy: no-referrer');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name(SessionCookie::NAME);
session_set_cookie_params(SessionCookie::parameters($secure));
session_start();

$send = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'POST'], true)) { throw new AuthException('Kaedah tidak dibenarkan.', 405); }
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $businessId = (int) ($_SESSION['business_id'] ?? 0);
    if ($userId < 1 || $businessId < 1) { throw new AuthException('Sesi diperlukan.', 401); }
    if ($method === 'POST') {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $expected = parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
            if (!is_string($originHost) || !is_string($expected) || strcasecmp($originHost, $expected) !== 0) {
                throw new AuthException('Permintaan ditolak.', 403);
            }
        }
        Csrf::check($_SESSION, (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data;')) {
            throw new AuthException('Gunakan muat naik gambar multipart.', 400);
        }
        $contentLength = (string) ($_SERVER['CONTENT_LENGTH'] ?? '');
        if ($contentLength !== '' && (!ctype_digit($contentLength) || (float) $contentLength > KnowledgeCentre::MEDIA_MAX_BYTES + 65536)) {
            throw new AuthException('Gambar melebihi had 2 MiB.', 413);
        }
    }
    $pdo = Db::connect();
    $actor = Membership::require($pdo, $userId, $businessId);
    \BesharOs\PlatformAccess::assertBusinessAccess($pdo, $businessId);
    $private = PrivateConfig::load();
    $seal = TokenSeal::tryFrom(is_array($private) ? $private : null);
    if (!$seal instanceof TokenSeal) { throw new AuthException('Pusat Pengetahuan belum tersedia.', 503); }
    $centre = new KnowledgeCentre($pdo, $seal);
    if ($method === 'GET') {
        $id = $_GET['id'] ?? '';
        if (!is_string($id)) { throw new AuthException('Gambar tidak dijumpai.', 404); }
        $media = $centre->previewMedia($actor, $id);
        session_write_close();
        header('Content-Type: ' . $media['mime']);
        header('Content-Length: ' . strlen($media['bytes']));
        header('Content-Disposition: inline; filename="' . $id . ($media['mime'] === 'image/png' ? '.png' : '.jpg') . '"');
        echo $media['bytes'];
        exit;
    }
    $centre->assertRequestScope($actor, $_POST['expected_business_public_id'] ?? null);
    $centre->assertCanManage($actor);
    $file = $_FILES['file'] ?? null;
    if (count($_FILES) !== 1 || !is_array($file) || !is_int($file['error'] ?? null)
        || $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
        || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > KnowledgeCentre::MEDIA_MAX_BYTES
        || !is_uploaded_file($file['tmp_name'])) {
        throw new AuthException('Muat naik satu gambar JPEG atau PNG sehingga 2 MiB.', 400);
    }
    $bytes = file_get_contents($file['tmp_name'], false, null, 0, KnowledgeCentre::MEDIA_MAX_BYTES + 1);
    if (!is_string($bytes) || strlen($bytes) !== $file['size']) { throw new AuthException('Fail gambar tidak dapat dibaca.', 400); }
    $record = $centre->uploadMedia($actor, $bytes);
    $csrf = Csrf::issue($_SESSION);
    session_write_close();
    $send(200, ['ok' => true, 'media' => $record, 'csrf' => $csrf]);
} catch (AuthException $error) {
    $send($error->status(), ['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable) {
    $send(500, ['ok' => false, 'error' => 'Gambar tidak dapat diproses.']);
}
