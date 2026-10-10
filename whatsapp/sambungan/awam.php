<?php
declare(strict_types=1);

require __DIR__ . '/ids.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode(
    beshare_os_public_meta(BESHARE_META_APP_ID, BESHARE_META_CONFIG_ID),
    JSON_UNESCAPED_SLASHES
);
