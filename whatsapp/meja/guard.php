<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/os/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/os/src/LegacyDesk.php';

function meja_require_access(array $actions): void
{
    ini_set('display_errors', '0');
    kepala_selamat();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name(\BesharOs\SessionCookie::NAME);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(\BesharOs\SessionCookie::parameters($secure));
    session_start();
    try {
        if (empty($_SESSION['user_id']) || empty($_SESSION['business_id'])) {
            throw new \BesharOs\AuthException('Unauthorized', 401);
        }
        \BesharOs\LegacyDesk::authorize(
            \BesharOs\Db::connect(), $_SESSION, \BesharOs\PrivateConfig::load() ?? [],
            (string) ($_SERVER['REQUEST_METHOD'] ?? ''), array_merge($_GET, $_POST), $actions
        );
    } catch (\BesharOs\AuthException $error) {
        http_response_code($error->status());
        exit('Akses tidak dibenarkan.');
    } catch (Throwable) {
        http_response_code(503);
        exit('Tidak tersedia.');
    }
}
