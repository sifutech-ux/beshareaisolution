<?php
require '/lab/source/os/src/Contract.php';require '/lab/source/os/src/SqlScript.php';require '/lab/source/os/mvp/ForwardLedger.php';
use BesharOs\Mvp\ForwardLedger;
$dsn='mysql:unix_socket=/sockets/source/mysql.sock;dbname=u879723783_beshare_os';
$db=new PDO($dsn,'migration','synthetic-unused',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$m=json_decode(file_get_contents('/lab/manifest.json'),true);$checks=[];
function deny(Closure $f,string $expected):void {global $checks;try{$f();throw new RuntimeException('missing denial');}catch(RuntimeException $e){if($e->getMessage()!==$expected)throw $e;$checks[]=$expected;}}
deny(fn()=>ForwardLedger::apply($db,'/lab/source',$m,'0002'),'partial_drift_or_unrecorded');
$bad=$m;$bad['checksums']['0002']=str_repeat('0',64);deny(fn()=>ForwardLedger::apply($db,'/lab/source',$bad,'0002'),'checksum_mismatch');
$bad=$m;$bad['server']='wrong';deny(fn()=>ForwardLedger::apply($db,'/lab/source',$bad,'0002'),'target_mismatch');
$other=new PDO($dsn,'migration','synthetic-unused');$q=$other->prepare('SELECT GET_LOCK(?,0)');$q->execute([BesharOs\Contract::LOCK]);
deny(fn()=>ForwardLedger::apply($db,'/lab/source',$m,'0002'),'migration_lock_busy');$other=null;
if((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==1)throw new RuntimeException('ledger fabricated');
$checks[]='complete unrecorded retained only genuine 0001';echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT);
