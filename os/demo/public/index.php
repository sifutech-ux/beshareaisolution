<?php
declare(strict_types=1);
require __DIR__.'/../runtime.php';
$host=$_SERVER['HTTP_HOST']??'';
if(!preg_match('/\A127\.0\.0\.1:[0-9]+\z/',$host)){http_response_code(403);exit;}
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(in_array($path,['/app.js','/style.css'],true)){header('Content-Type: '.($path==='/app.js'?'text/javascript':'text/css'));readfile(__DIR__.$path);exit;}
ini_set('session.use_strict_mode','1');session_name('beshare_demo');session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','path'=>'/']);session_start();
function reply(array $data,int $code=200): never {http_response_code($code);header('Content-Type: application/json');echo json_encode($data);exit;}
if(str_starts_with($path,'/api/')) {
 try {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(($_SERVER['HTTP_ORIGIN']??'')!=='http://'.$host)reply(['error'=>'Permintaan tidak dibenarkan.'],403);
        if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)reply(['error'=>'Teks terlalu panjang.'],413);
        $raw=file_get_contents('php://input',false,null,0,12001);if(strlen($raw)>12000)reply(['error'=>'Teks terlalu panjang.'],413);
        $in=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    } else if($_SERVER['REQUEST_METHOD']!=='GET')reply(['error'=>'Kaedah tidak dibenarkan.'],405);
    if($path==='/api/login' && $_SERVER['REQUEST_METHOD']==='POST'){
        $lock=fopen(stateDir().'/login-limit','c+');flock($lock,LOCK_EX);$rate=json_decode(stream_get_contents($lock),true)?:['start'=>time(),'count'=>0];
        if(time()-$rate['start']>300)$rate=['start'=>time(),'count'=>0];$rate['count']++;
        ftruncate($lock,0);rewind($lock);fwrite($lock,json_encode($rate));flock($lock,LOCK_UN);fclose($lock);
        if($rate['count']>20)reply(['error'=>'Terlalu banyak cubaan. Tunggu 5 minit.'],429);
        if(!is_string($in['password']??null)||!password_verify($in['password'],file_get_contents(stateDir().'/password.hash')))reply(['error'=>'Kata laluan tidak betul.'],401);
        session_regenerate_id(true);$_SESSION=['owner'=>true,'expires'=>time()+3600,'csrf'=>bin2hex(random_bytes(24))];reply(['ok'=>true]);
    }
    if(empty($_SESSION['owner'])||($_SESSION['expires']??0)<time())reply(['error'=>'Sila log masuk.'],401);
    if($_SERVER['REQUEST_METHOD']==='POST'&&!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))reply(['error'=>'Sesi tidak sah. Muat semula.'],403);
    $db=db();$f=foundation($db);
    if($path==='/api/state'&&$_SERVER['REQUEST_METHOD']==='GET'){
        $k=$db->query("SELECT version,body,approved_by,expires FROM knowledge WHERE tenant='demo-kedai' ORDER BY version DESC LIMIT 1")->fetch();
        $q=$db->prepare('SELECT calls FROM budget WHERE tenant=? AND day=?');$q->execute([TENANT,gmdate('Y-m-d')]);
        $items=$f->inbox(TENANT,ACTOR);
        foreach($items as &$item){$s=$db->prepare('SELECT body FROM knowledge WHERE tenant=? AND version=?');$s->execute([TENANT,$item['knowledge_version']]);$item['source']=$s->fetchColumn()?:null;$p=$db->prepare('SELECT mode FROM demo_provenance WHERE tenant=? AND event=?');$p->execute([TENANT,$item['event']]);$item['mode']=$p->fetchColumn()?:'UNKNOWN';}
        reply(['csrf'=>$_SESSION['csrf'],'mode'=>liveMode()?'LIVE Gemini':'MOCK','knowledge'=>$k,'items'=>array_reverse($items),'calls'=>(int)$q->fetchColumn(),'limit'=>20]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Tidak ditemui.'],404);
    switch($path){
      case '/api/knowledge':
        $body=$in['body']??null;if(!is_string($body)||trim($body)===''||strlen($body)>2500)reply(['error'=>'Maklumat mesti 1–2500 bait.'],400);
        $db->exec('BEGIN IMMEDIATE');$q=$db->prepare('INSERT INTO knowledge SELECT ?,COALESCE(MAX(version),0)+1,?,NULL,? FROM knowledge WHERE tenant=?');$q->execute([TENANT,$body,time()+604800,TENANT]);$db->exec('COMMIT');break;
      case '/api/approve':
        $q=$db->prepare('UPDATE knowledge SET approved_by=?,expires=? WHERE tenant=? AND version=? AND version=(SELECT MAX(version) FROM knowledge WHERE tenant=?)');$q->execute([ACTOR,time()+604800,TENANT,$in['version']??0,TENANT]);if(!$q->rowCount())reply(['error'=>'Versi berubah. Muat semula.'],409);break;
      case '/api/simulate':
        $body=$in['message']??null;if(!is_string($body)||trim($body)===''||strlen($body)>4000)reply(['error'=>'Mesej mesti 1–4000 bait.'],400);
        if((int)$db->query("SELECT COUNT(*) FROM handoffs WHERE tenant='demo-kedai'")->fetchColumn()>=100)reply(['error'=>'Demo penuh (100 mesej). Mulakan stor demo baharu.'],429);
        simulate($f,$body);break;
      case '/api/claim':$f->claim(TENANT,(string)($in['event']??''),ACTOR);break;
      case '/api/logout':$_SESSION=[];session_destroy();reply(['ok'=>true]);
      default:reply(['error'=>'Tidak ditemui.'],404);
    }
    reply(['ok'=>true]);
 }catch(Throwable){reply(['error'=>'Permintaan gagal. Semak peti semakan; tiada mesej dihantar.'],400);}
}
if($path!=='/'){http_response_code(404);exit;}
header('Content-Type: text/html; charset=utf-8');readfile(__DIR__.'/index.html');
