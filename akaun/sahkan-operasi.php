<?php
declare(strict_types=1);
use BesharOs\AccountBridge;
require dirname(__DIR__).'/os/src/bootstrap.php';
require_once dirname(__DIR__).'/os/src/AccountBridge.php';
header('Cache-Control: no-store');
header('Content-Type: application/json');
$body = json_decode((string)file_get_contents('php://input',false,null,0,512),true);
$ok = ($_SERVER['REQUEST_METHOD']??'') === 'POST' && is_array($body)
    && is_string($body['ticket']??null) && is_string($body['hash']??null) && AccountBridge::verify($body['ticket'],$body['hash']);
http_response_code($ok ? 200 : 403);
echo json_encode(['ok'=>$ok]);
