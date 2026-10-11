<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'stored' => false,
    'error' => 'Pertukaran kod menggunakan sesi workspace.',
], JSON_UNESCAPED_UNICODE);
