<?php
declare(strict_types=1);
require __DIR__.'/../../mvp/GeminiFoundation.php';
use BesharOs\Mvp\GeminiFoundation;
$n=0;$names=[];
function check(bool $ok,string $name): void {global $n,$names;if(!$ok)throw new RuntimeException($name);$n++;$names[]=$name;}
function denied(Closure $fn,string $expected): void {try{$fn();}catch(RuntimeException $e){check($e->getMessage()===$expected,$expected);return;}throw new RuntimeException('Expected '.$expected);}
$dir=sys_get_temp_dir().'/beshare-mvp-'.bin2hex(random_bytes(8));mkdir($dir,0700);$file=$dir.'/synthetic.sqlite';
try {
$db=new PDO('sqlite:'.$file);chmod($file,0600);$db->exec(file_get_contents(__DIR__.'/../../mvp/schema.sql'));
$now=1800000000;$clock=function()use(&$now){return $now;};$secret=str_repeat('s',32);$calls=0;$last=null;
$transport=function($req)use(&$calls,&$last){$calls++;$last=$req;return ['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>'Draf sintetik: waktu operasi 9–5.']]]]]];};
$db->exec("INSERT INTO tenants VALUES('a',1,10,40960),('b',1,10,40960); INSERT INTO routes VALUES('waba-a','phone-a','a'),('waba-b','phone-b','b'); INSERT INTO operators VALUES('a','alice',1),('a','ann',1),('b','bob',1); INSERT INTO knowledge VALUES('a',1,'Waktu operasi 9–5.','alice',1800001000),('b',1,'SECRET TENANT B','bob',1800001000)");
$m=new GeminiFoundation($db,$transport,$clock,$secret,true);
function raw(string $id='one',string $waba='waba-a',string $phone='phone-a',string $text='Buka pukul berapa?'): string {global $now;return json_encode(['object'=>'whatsapp_business_account','entry'=>[['id'=>$waba,'changes'=>[['field'=>'messages','value'=>['metadata'=>['phone_number_id'=>$phone],'messages'=>[['id'=>$id,'timestamp'=>(string)$now,'type'=>'text','text'=>['body'=>$text]]]]]]]]],JSON_THROW_ON_ERROR);}
function ingest(GeminiFoundation $m,string $raw):array {global $secret;return $m->ingest($raw,'sha256='.hash_hmac('sha256',$raw,$secret));}
$r=raw();denied(fn()=>(new GeminiFoundation($db,$transport,$clock,$secret))->ingest($r,''),'foundation_disabled');
denied(fn()=>$m->ingest($r,'sha256='.str_repeat('0',64)),'signature_denied');check((int)$db->query('SELECT COUNT(*) FROM handoffs')->fetchColumn()===0,'invalid signature has no writes');
denied(fn()=>ingest($m,raw('x','waba-b','phone-a')),'route_denied');
check(!ingest($m,$r)['replayed'],'accepted');check(ingest($m,$r)['replayed'],'duplicate');denied(fn()=>ingest($m,raw('one',text:'tampered')),'replay_conflict');
check($m->generate('a','one')==='draft','draft generated');check($calls===1,'one call');$m->generate('a','one');check($calls===1,'repeat worker no call');check(!str_contains(json_encode($last),'SECRET TENANT B'),'knowledge scoped');
check($last['generationConfig']['maxOutputTokens']===512,'output token cap');check(count($m->inbox('a','alice'))===1,'operator sees handoff');denied(fn()=>$m->inbox('a','bob'),'operator_denied');denied(fn()=>$m->generate('b','one'),'handoff_denied');
$m->claim('a','one','alice');denied(fn()=>$m->claim('a','one','ann'),'claim_denied');check($m->inbox('a','alice')[0]['status']==='claimed','human ownership persisted');
$db2=new PDO('sqlite:'.$file);$m2=new GeminiFoundation($db2,$transport,$clock,$secret,true);check($m2->inbox('a','alice')[0]['actor']==='alice','persistence across connection');
ingest($m,raw('rate'));check($m->generate('a','rate')==='review_or_existing','rate rejected');check($calls===1,'rate no call');
$now+=11;$db->exec("UPDATE knowledge SET approved_by=NULL WHERE tenant='a'");ingest($m,raw('unapproved'));$m->generate('a','unapproved');check($calls===1,'unapproved no call');
$db->exec("UPDATE knowledge SET approved_by='alice',expires=1 WHERE tenant='a'");ingest($m,raw('expired'));$m->generate('a','expired');check($calls===1,'expired no call');
$db->exec("UPDATE knowledge SET expires=1800001000 WHERE tenant='a'; INSERT INTO knowledge VALUES('a',2,'unreviewed',NULL,1800001000)");ingest($m,raw('newrev'));$m->generate('a','newrev');check($calls===1,'no fallback to old approval');$db->exec("DELETE FROM knowledge WHERE tenant='a' AND version=2");
$fail=new GeminiFoundation($db,function()use(&$calls){$calls++;throw new RuntimeException('SENSITIVE PROVIDER ERROR');},$clock,$secret,true);
ingest($fail,raw('failure'));check($fail->generate('a','failure')==='review','failure handoff');$fail->generate('a','failure');check($calls===2,'failure no automatic retry');check(!str_contains(json_encode($m->inbox('a','alice')),'SENSITIVE'),'exception not persisted');
check((int)$db->query("SELECT units FROM budget WHERE tenant='a'")->fetchColumn()===8192,'failure keeps reservation');
$now+=11;$db->exec("UPDATE tenants SET daily_units=8192 WHERE id='a'");ingest($m,raw('budget'));$m->generate('a','budget');check($calls===2,'cost ceiling');$db->exec("UPDATE tenants SET daily_units=40960,daily_calls=2 WHERE id='a'");ingest($m,raw('calls'));$m->generate('a','calls');check($calls===2,'call ceiling');$db->exec("UPDATE tenants SET daily_calls=10 WHERE id='a'");
$race=new GeminiFoundation($db,function($req)use($transport,$db){$r=$transport($req);$db->exec("UPDATE knowledge SET approved_by=NULL WHERE tenant='a'");return $r;},$clock,$secret,true);
ingest($race,raw('race'));check($race->generate('a','race')==='review','revoked knowledge discards draft');check($db->query("SELECT draft FROM handoffs WHERE event='race'")->fetchColumn()===null,'revoked draft absent');
$db->exec("UPDATE knowledge SET approved_by='alice' WHERE tenant='a'");$now+=11;
$claim=new GeminiFoundation($db,function($req)use($transport,$m){$r=$transport($req);$m->claim('a','claim-race','alice');return $r;},$clock,$secret,true);ingest($claim,raw('claim-race'));check($claim->generate('a','claim-race')==='review','human claim wins race');
$now+=11;$malformed=new GeminiFoundation($db,fn($req)=>['candidates'=>[['finishReason'=>'MAX_TOKENS','content'=>['parts'=>[['text'=>'truncated']]]]]],$clock,$secret,true);ingest($malformed,raw('malformed'));check($malformed->generate('a','malformed')==='review','malformed response handoff');
// Independent processes share durable dedupe/reservation, not an in-memory lock.
if (function_exists('pcntl_fork')) {
 ingest($m,raw('concurrent','waba-b','phone-b'));$log=$dir.'/calls';$children=[];
 for($i=0;$i<2;$i++) { $pid=pcntl_fork(); if($pid===0) { $child=new GeminiFoundation(new PDO('sqlite:'.$file),function($req)use($log,$transport){file_put_contents($log,"call\n",FILE_APPEND|LOCK_EX);usleep(100000);return $transport($req);},$clock,$secret,true);$child->generate('b','concurrent');exit(0); } $children[]=$pid; }
 foreach($children as $pid){pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'concurrent worker exits');}
 check(file_get_contents($log)==="call\n",'concurrent workers one call');
 $now+=11;ingest($m,raw('crash','waba-b','phone-b'));$pid=pcntl_fork();
 if($pid===0){$child=new GeminiFoundation(new PDO('sqlite:'.$file),function($req){exit(19);},$clock,$secret,true);$child->generate('b','crash');exit(1);}
 pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===19,'crash after reservation');
 $beforeCalls=$calls;$m->generate('b','crash');check($beforeCalls===$calls,'crashed call never automatically retried');
 check($db->query("SELECT reason FROM handoffs WHERE tenant='b' AND event='crash'")->fetchColumn()==='transport_incomplete','crashed call has durable handoff');
}
$stale=raw('stale');$now+=301;denied(fn()=>ingest($m,$stale),'stale_event');
$db->exec("UPDATE tenants SET active=0 WHERE id='a'");denied(fn()=>ingest($m,raw('paused')),'tenant_denied');denied(fn()=>$m->inbox('a','alice'),'tenant_denied');$db->exec("UPDATE tenants SET active=1 WHERE id='a'; UPDATE operators SET active=0 WHERE actor='alice'");denied(fn()=>$m->inbox('a','alice'),'operator_denied');
check(count(array_filter($m->inbox('b','bob'),fn($r)=>$r['event']==='one'))===0,'other inbox excludes tenant a');
// Database enforces tenant-scoped approval independently of application code.
try{$db->exec("INSERT INTO knowledge VALUES('a',3,'bad','bob',1800001000)");throw new RuntimeException('fk missing');}catch(PDOException){check(true,'cross tenant approval FK');}
echo json_encode(['passed'=>$n,'checks'=>$names,'transport'=>'injected mock only','outbound'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} finally {unset($m,$m2,$db2,$db);foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
