<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(STDOUT, "Penyegerakan langsung dimatikan. Tiada panggilan Meta.\n");
exit(0);
