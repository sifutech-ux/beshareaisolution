<?php
declare(strict_types=1);
use BesharOs\{AuthException,Db,StripeBilling};
require dirname(__DIR__,2).'/os/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){throw new AuthException('Kaedah tidak dibenarkan.',405);}
    if((int)($_SERVER['CONTENT_LENGTH']??0)>500000){throw new AuthException('Permintaan terlalu besar.',413);}
    $raw=file_get_contents('php://input',false,null,0,500001);
    if(!is_string($raw)||strlen($raw)>500000){throw new AuthException('Permintaan terlalu besar.',413);}
    $config=StripeBilling::config();
    if(!is_array($config)){throw new AuthException('Webhook belum tersedia.',503);}
    $event=StripeBilling::event($raw,(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??''),
        (string)($config['webhook_secret']??''),($config['mode']??'')==='live',time());
    $result=(new StripeBilling(Db::connect(),$config))->webhook($event);
    echo json_encode($result,JSON_UNESCAPED_UNICODE);
}catch(AuthException $error){http_response_code($error->status());echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}
catch(Throwable){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Webhook akan dicuba semula.']);}
