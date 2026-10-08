<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

ini_set('display_errors', '0');
mula_sesi('beshare_admin');
kepala_selamat();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    sahkan_csrf();
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $param = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $param['path'], $param['domain'], (bool) $param['secure'], (bool) $param['httponly']);
}
session_destroy();
pergi('/admin/');
