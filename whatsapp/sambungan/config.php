<?php
declare(strict_types=1);

/**
 * Konfigurasi peribadi di luar web root.
 * Fail itu memulangkan tatasusunan dengan verify_token dan app_secret.
 * Laluan lalai: induk public_html + /private/beshare-os-webhook.php
 * Atau tetapkan BESHARE_OS_WEBHOOK_CONFIG.
 */

function beshare_os_private_config(): ?array
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
    $clean = [];
    foreach ($config as $key => $value) {
        if (is_string($key) && is_string($value) && $value !== '') {
            $clean[$key] = $value;
        }
    }
    return $clean === [] ? null : $clean;
}
