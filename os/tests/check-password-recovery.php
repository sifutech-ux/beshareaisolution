<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-fixture.php';
require_once __DIR__ . '/../src/PasswordRecoverySchema.php';

use BesharOs\{Accounts,AuthHttp,AuthException,CredentialSession,Csrf,LegacyDesk,Password,PasswordRecovery,PasswordRecoverySchema,PlatformAdminHttp,PlatformAdminStore,RateLimit,TokenSeal};

if (($argv[1] ?? '') === '--race') {
    [$dsn,$user,$password] = adminTestConnection();
    $pdo = new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $service = new PasswordRecovery($pdo,new TokenSeal(str_repeat('F',32),1),new RateLimit(sys_get_temp_dir().'/beshare-reset-race-'.getmypid()));
    try { $service->reset($argv[2],'replacement-synthetic-789','replacement-synthetic-789','race'); echo 'accepted'; }
    catch (AuthException $error) { echo $error->status()===400?'rejected':'unexpected'; }
    exit;
}

$f=adminFixture(); $pdo=$f['pdo']; $uid=$f['users']['ownerA'];
$old='synthetic-original-123'; $next='synthetic-replacement-456';
adminSql($pdo,'UPDATE users SET password_hash=?',[Password::hash($old)]);
$sql=file_get_contents(__DIR__.'/../migrations/0008_password_recovery.up.sql');
$schema=new PasswordRecoverySchema($pdo,$sql);
$legacy=['user_id'=>$uid,'business_id'=>$f['tenantA']['business_id']];
CredentialSession::assertCurrent($pdo,$legacy);
adminDenied(fn()=>$schema->apply(str_repeat('0',64)),409,'wrong checksum rejected');
$schema->apply(hash('sha256',$sql));
adminAssert($schema->apply(hash('sha256',$sql))['status']==='installed','migration idempotent');
$session=$legacy+['credential_stamp'=>CredentialSession::stamp($pdo,$uid)];
$otherSession=['user_id'=>$f['users']['ownerB'],'credential_stamp'=>CredentialSession::stamp($pdo,$f['users']['ownerB'])];
CredentialSession::assertCurrent($pdo,$legacy);
$rates=new RateLimit(sys_get_temp_dir().'/beshare-reset-test-'.bin2hex(random_bytes(5)));
$service=new PasswordRecovery($pdo,$f['seal'],$rates);
$http=new AuthHttp($pdo,$rates,null,null,null,$service);
$anonymous=[]; $csrf=Csrf::issue($anonymous); $headers=['x-csrf-token'=>$csrf,'origin'=>'https://fixture.invalid'];
$post=static function($action,$body,$remote='request') use($http,&$anonymous,$headers){return $http->handle('POST',$action,$anonymous,$body,$headers,$remote,'fixture.invalid');};
adminDenied(fn()=>$http->handle('GET','lupa',$anonymous,[],[],'a','fixture.invalid'),405,'GET cannot request or consume');
adminDenied(fn()=>$http->handle('POST','lupa',$anonymous,['email'=>'ownera@admin-fixture.invalid'],[],'a','fixture.invalid'),403,'CSRF required');
adminDenied(fn()=>$http->handle('POST','lupa',$anonymous,['email'=>'ownera@admin-fixture.invalid'],['x-csrf-token'=>$csrf,'origin'=>'https://evil.invalid'],'a','fixture.invalid'),403,'cross origin rejected');
$yes=$post('lupa',['email'=>'OwnerA@admin-fixture.invalid']);
$no=$post('lupa',['email'=>'absent@example.invalid']);
adminAssert($yes===$no,'known and unknown account responses identical');
adminAssert((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn()===1,'unknown account not queued');
$queued=$pdo->query('SELECT * FROM password_reset_tokens')->fetch();
adminAssert($queued['mail_ciphertext']!==null && strlen($queued['token_hash'])===64,'encrypted outbox and token digest');
adminAssert(Password::verify($old,adminSql($pdo,'SELECT password_hash FROM users WHERE id=?',[$uid])->fetchColumn()),'request never changes password');
$post('lupa',['email'=>'ownera@admin-fixture.invalid']);
adminAssert((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn()===1,'cooldown prevents mail flooding');
$failed=$service->deliver(static fn()=>false);
adminAssert($failed===['accepted'=>0,'failed'=>1],'delivery failures recorded');
adminSql($pdo,'UPDATE password_reset_tokens SET mail_next_at=UTC_TIMESTAMP()');
$captured=[];
$delivered=$service->deliver(static function($email,$link)use(&$captured){$captured[]=[$email,$link];return true;});
adminAssert($delivered===['accepted'=>1,'failed'=>0] && count($captured)===1,'retry accepted by transport');
adminAssert($captured[0][0]==='ownera@admin-fixture.invalid' && str_starts_with($captured[0][1],PasswordRecovery::URL),'fixed trusted origin and stored recipient');
$token=substr($captured[0][1],strlen(PasswordRecovery::URL));
adminAssert(hash('sha256',$token)===$queued['token_hash'] && !str_contains($queued['mail_ciphertext'],$token),'plaintext token never stored');
adminAssert($pdo->query('SELECT mail_ciphertext FROM password_reset_tokens')->fetchColumn()===null,'delivered secret removed');
adminAssert($service->deliver(static fn()=>throw new RuntimeException('must not resend'))['accepted']===0,'delivered mail not resent');
adminDenied(fn()=>$service->reset($token,$next,'mismatch','mismatch'),400,'confirmation mismatch rejected');
adminDenied(fn()=>$service->reset($token,'short','short','short'),400,'password policy enforced');
adminSql($pdo,'UPDATE password_reset_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)');
adminDenied(fn()=>$service->reset($token,$next,$next,'expired'),400,'expired token rejected');
adminSql($pdo,'UPDATE password_reset_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)');
adminSql($pdo,"UPDATE users SET status='disabled' WHERE id=?",[$uid]);
adminDenied(fn()=>$service->reset($token,$next,$next,'inactive'),400,'disabled account rejected');
adminSql($pdo,"UPDATE users SET status='active' WHERE id=?",[$uid]);
$result=$post('pulih',['token'=>$token,'password'=>$next,'password2'=>$next],'reset');
adminAssert($result['body']['user']===null && $result['regenerate'] && !isset($anonymous['user_id']),'reset does not log in and rotates session');
adminDenied(fn()=>$service->reset($token,$next,$next,'replay'),400,'used token rejected');
adminDenied(fn()=>Accounts::authenticate($pdo,'ownera@admin-fixture.invalid',$old),401,'old password rejected');
$account=Accounts::authenticate($pdo,'ownera@admin-fixture.invalid',$next);
adminAssert($account['id']===$uid,'new password authenticates');
adminDenied(fn()=>CredentialSession::assertCurrent($pdo,$session),401,'password bound session revoked');
adminDenied(fn()=>CredentialSession::assertCurrent($pdo,$legacy),401,'legacy session revoked');
$loggedOut=$http->handle('GET','sesi',$legacy,[],[],'a','fixture.invalid');
adminAssert($loggedOut['body']['user']===null,'HTTP session returns anonymous after reset');
$adminSession=['user_id'=>$uid,'business_id'=>$f['tenantA']['business_id']];
$adminHttp=new PlatformAdminHttp($pdo,$rates,new PlatformAdminStore($pdo,[]));
adminDenied(fn()=>$adminHttp->handle('GET','sesi',$adminSession,[],[],'a','fixture.invalid'),401,'admin boundary rejects revoked session');
require_once __DIR__.'/../src/LegacyDesk.php';
adminDenied(fn()=>LegacyDesk::authorize($pdo,$adminSession,[], 'GET',[],[]),401,'legacy desk rejects revoked session');
CredentialSession::assertCurrent($pdo,['user_id'=>$uid,'credential_stamp'=>$account['credential_stamp']]);
CredentialSession::assertCurrent($pdo,$otherSession);
adminAssert(Accounts::authenticate($pdo,'ownerb@admin-fixture.invalid',$old)['id']===$f['users']['ownerB'],'other account credentials and session unchanged');
for($i=0;$i<5;$i++){$service->request('absent@example.invalid','rate');}
adminDenied(fn()=>$service->request('absent@example.invalid','rate'),429,'IP limit covers unknown accounts');
// A fresh token consumed simultaneously can succeed exactly once.
adminSql($pdo,'UPDATE password_reset_tokens SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)');
$service->request('ownera@admin-fixture.invalid','race-request');
$captured=[];$service->deliver(static function($email,$link)use(&$captured){$captured[]=$link;return true;});
$raceToken=substr($captured[0],strlen(PasswordRecovery::URL));
$processes=[];
foreach([1,2] as $n){$pipes=[];$processes[]=[proc_open([PHP_BINARY,__FILE__,'--race',$raceToken],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes),$pipes];}
$out=[];
foreach($processes as [$process,$pipes]){fclose($pipes[0]);$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);adminAssert(proc_close($process)===0 && $err==='','race worker clean');}
sort($out);adminAssert($out===['accepted','rejected'],'concurrent consumption succeeds exactly once');
echo "PASS $adminChecks password recovery checks; synthetic accounts and mail only\n";
