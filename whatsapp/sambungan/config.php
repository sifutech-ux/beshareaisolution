<?php
declare(strict_types=1);

/**
 * Konfigurasi peribadi di luar web root.
 * Fail itu memulangkan tatasusunan dengan verify_token, app_secret, dan token_key.
 * Laluan lalai: induk public_html + /private/beshare-os-webhook.php
 * Atau tetapkan BESHARE_OS_WEBHOOK_CONFIG.
 */

require_once dirname(__DIR__, 2) . '/os/src/bootstrap.php';

function beshare_os_private_config(): ?array
{
    return BesharOs\PrivateConfig::load();
}
