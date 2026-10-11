<?php
declare(strict_types=1);

namespace BesharOs;

final class PrivateConfig
{
    /** @return array<string,string>|null */
    public static function load(): ?array
    {
        $path = getenv('BESHARE_OS_WEBHOOK_CONFIG');
        if (!is_string($path) || $path === '') {
            $path = dirname(__DIR__, 3) . '/private/beshare-os-webhook.php';
        }
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $real = realpath($path);
        $repo = realpath(dirname(__DIR__, 2));
        if ($real === false || $repo === false) {
            return null;
        }
        $prefix = rtrim($repo, '/') . '/';
        if ($real === $repo || str_starts_with($real, $prefix) || preg_match('#(?:^|/)public_html(?:/|$)#', $real) === 1) {
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
}
