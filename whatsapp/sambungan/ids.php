<?php
declare(strict_types=1);

/**
 * Nilai awam untuk membuka tetingkap Embedded Signup.
 * App ID dan Configuration ID memang dipaparkan pada pelayar.
 * Rahsia aplikasi tidak diletakkan di sini.
 */
const BESHARE_META_APP_ID = '';
const BESHARE_META_CONFIG_ID = '';
const BESHARE_META_GRAPH = 'v26.0';

function beshare_os_public_meta(string $appId, string $configId, string $version = BESHARE_META_GRAPH): array
{
    if (!preg_match('/\A\d{5,20}\z/', $appId) || !preg_match('/\A\d{5,20}\z/', $configId)) {
        return ['ready' => false];
    }
    if (!preg_match('/\Av\d+\.\d+\z/', $version)) {
        $version = 'v26.0';
    }
    return [
        'ready' => true,
        'appId' => $appId,
        'configId' => $configId,
        'version' => $version,
    ];
}
