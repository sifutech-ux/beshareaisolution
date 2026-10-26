<?php
declare(strict_types=1);

use BesharOs\AdminWhatsAppActivation;
use BesharOs\AuthException;
use BesharOs\CloudPhoneRegistration;
use BesharOs\Db;
use BesharOs\MetaGraph;
use BesharOs\PlatformAdminHttp;
use BesharOs\PlatformAdminStore;
use BesharOs\PrivateConfig;
use BesharOs\RateLimit;
use BesharOs\SessionCookie;
use BesharOs\StreamMetaTransport;
use BesharOs\TokenSeal;

if(PHP_SAPI==='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require dirname(__DIR__,2).'/os/src/bootstrap.php';
require dirname(__DIR__).'/sambungan/ids.php';
require_once dirname(__DIR__,2).'/os/src/PlatformAdminStore.php';
require_once dirname(__DIR__,2).'/os/src/CloudPhoneRegistration.php';
require_once dirname(__DIR__,2).'/os/src/AdminWhatsAppActivation.php';
require_once dirname(__DIR__,2).'/os/src/PlatformAdminHttp.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.use_trans_sid','0');
session_name(SessionCookie::NAME); session_set_cookie_params(SessionCookie::parameters($secure)); session_start();
$send=static function(int $status,array $body):never{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;};
try {
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    $action=$_GET['tindakan']??'';
    if(!is_string($action)||preg_match('/\A[a-z]{2,20}\z/',$action)!==1){throw new AuthException('Tindakan tidak dikenali.',404);}
    $input=$_GET;
    if($method==='POST') {
        if(preg_match('#\Aapplication/json(?:\s*;|\z)#i',(string)($_SERVER['CONTENT_TYPE']??''))!==1){throw new AuthException('Gunakan permintaan JSON.',415);}
        $raw=file_get_contents('php://input',false,null,0,16385);
        if(!is_string($raw)||strlen($raw)>16384){throw new AuthException('Permintaan terlalu besar.',413);}
        $input=json_decode($raw,true);
        if(!is_array($input)||($input!==[]&&array_is_list($input))){throw new AuthException('JSON tidak sah.',400);}
    }
    $pdo=Db::connect();
    $private=PrivateConfig::load()??[]; $seal=TokenSeal::tryFrom($private); $activation=null;
    if($seal instanceof TokenSeal&&!empty($private['app_secret'])&&BESHARE_META_APP_ID!=='') {
        $transport=new StreamMetaTransport();
        $graph=new MetaGraph(BESHARE_META_APP_ID,$private['app_secret'],BESHARE_META_GRAPH,$transport);
        $activation=new AdminWhatsAppActivation($pdo,$graph,$seal,BESHARE_META_APP_ID,new CloudPhoneRegistration(BESHARE_META_GRAPH,$transport));
    }
    $http=new PlatformAdminHttp($pdo,new RateLimit(RateLimit::resolveDirectory(),20,60),new PlatformAdminStore($pdo,$private,$activation!==null),$activation);
    $headers=['x-csrf-token'=>(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')];
    if(isset($_SERVER['HTTP_ORIGIN'])){$headers['origin']=(string)$_SERVER['HTTP_ORIGIN'];}
    $result=$http->handle($method,$action,$_SESSION,$input,$headers,(string)($_SERVER['REMOTE_ADDR']??'0'),(string)($_SERVER['HTTP_HOST']??''),$secure?'https':'http');
    $send($result['status'],$result['body']);
} catch(AuthException $error){$send($error->status(),['ok'=>false,'error'=>$error->getMessage()]);}
catch(Throwable){$send(500,['ok'=>false,'error'=>'Tindakan tidak dapat diselesaikan. Muat semula butiran sebelum mencuba tindakan seterusnya.']);}
