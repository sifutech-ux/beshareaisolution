<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\Db;
use BesharOs\Identifiers;
use BesharOs\PlatformAccess;

// Operator-only CLI. No HTTP grant route, hard-coded owner email, or default password.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
$email='';$userId=0;$action='inspect';$apply=false;
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--email=')){$email=substr($arg,8);}
    elseif(preg_match('/\A--user-id=([1-9][0-9]{0,15})\z/',$arg,$m)===1){$userId=(int)$m[1];}
    elseif($arg==='--grant'&&$action==='inspect'){$action='grant';}
    elseif($arg==='--revoke'&&$action==='inspect'){$action='revoke';}
    elseif($arg==='--apply'){$apply=true;}
    else{fwrite(STDERR,"Pilihan tidak sah. Gunakan --email=EMAIL untuk semakan; tambah --user-id=ID --grant|--revoke --apply untuk perubahan.\n");exit(1);}
}
try{
    $email=Identifiers::email($email);$pdo=Db::connect();
    if(!PlatformAccess::installed($pdo)){throw new AuthException('Pasang skema admin 0006 dahulu.',409);}
    $query=$pdo->prepare('SELECT u.id,u.name,u.email,u.status,p.status AS platform_status FROM users u LEFT JOIN platform_admins p ON p.user_id=u.id WHERE u.email=?');
    $query->execute([$email]);$user=$query->fetch();
    if(!is_array($user)){throw new AuthException('Akaun BeShare tidak dijumpai. Daftar akaun individu dahulu.',404);}
    if($apply){
        if($action==='inspect'||$userId!==(int)$user['id']){throw new AuthException('Padankan ID pengguna dan e-mel daripada semakan sebelum memberi atau menarik akses.',409);}
        $pdo->beginTransaction();
        try{
            $query=$pdo->prepare('SELECT id,email,status FROM users WHERE id=? FOR UPDATE');$query->execute([$userId]);$locked=$query->fetch();
            if(!is_array($locked)||$locked['email']!==$email||($action==='grant'&&$locked['status']!=='active')){throw new AuthException('Akaun pengguna telah berubah atau tidak aktif.',409);}
            $next=$action==='grant'?'active':'revoked';
            $write=$pdo->prepare("INSERT INTO platform_admins (user_id,role,status,created_at,updated_at) VALUES (?,'owner',?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=?,updated_at=UTC_TIMESTAMP()");
            $write->execute([$userId,$next,$next]);
            $audit=$pdo->prepare("INSERT INTO audit_logs (business_id,actor_user_id,actor_kind,action,entity_type,entity_id,meta_json,created_at) VALUES (NULL,NULL,'system',?,'platform_admin',?,?,UTC_TIMESTAMP())");
            $audit->execute([$action==='grant'?'platform_admin_granted':'platform_admin_revoked',$userId,json_encode(['via'=>'operator_cli','status'=>$next],JSON_THROW_ON_ERROR)]);
            $pdo->commit();$user['platform_status']=$next;
        }catch(Throwable $error){if($pdo->inTransaction()){$pdo->rollBack();}throw $error;}
    }
    fwrite(STDOUT,json_encode(['mode'=>$apply?'applied':'inspect','user_id'=>(int)$user['id'],'name'=>$user['name'],'email'=>$user['email'],
        'account_status'=>$user['status'],'platform_status'=>$user['platform_status']??'none','role'=>'owner'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
}catch(AuthException $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
catch(Throwable){fwrite(STDERR,"Akses admin tidak dapat dikemas kini. Tiada kata laluan atau token dipaparkan.\n");exit(1);}
