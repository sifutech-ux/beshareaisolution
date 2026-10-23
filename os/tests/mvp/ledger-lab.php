<?php
require '/lab/source/os/src/Contract.php';require '/lab/source/os/src/SqlScript.php';require '/lab/source/os/mvp/ForwardLedger.php';
use BesharOs\Mvp\ForwardLedger;
$db=new PDO('mysql:unix_socket=/sockets/source/mysql.sock;dbname=u879723783_beshare_os','migration','synthetic-unused',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("SET time_zone='+00:00'");
$mode=$argv[1];$root='/lab/source';
if($mode==='capture') {
 $m=['server'=>$db->query('SELECT VERSION()')->fetchColumn(),'checksums'=>[],'steps'=>[]];
 foreach(['0001'=>'foundation','0002'=>'coexistence_sync','0003'=>'coexistence_worker'] as $v=>$name)$m['checksums'][$v]=hash_file('sha256',"$root/os/migrations/{$v}_{$name}.up.sql");
 foreach(['0002'=>'coexistence_sync','0003'=>'coexistence_worker'] as $v=>$name){$before=ForwardLedger::fingerprint($db);foreach(BesharOs\SqlScript::statements(file_get_contents("$root/os/migrations/{$v}_{$name}.up.sql")) as $sql)$db->exec($sql);$m['steps'][$v]=['before'=>$before,'after'=>ForwardLedger::fingerprint($db)];}echo json_encode($m,JSON_PRETTY_PRINT);exit;
}
$m=json_decode(file_get_contents('/lab/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if($mode==='apply'){foreach(['0002','0003'] as $v){echo "$v ".ForwardLedger::apply($db,$root,$m,$v)."\n";echo "$v ".ForwardLedger::apply($db,$root,$m,$v)."\n";}echo json_encode($db->query('SELECT version,checksum FROM schema_migrations ORDER BY version')->fetchAll());}
if($mode==='partial'){$db->exec(BesharOs\SqlScript::statements(file_get_contents("$root/os/migrations/0002_coexistence_sync.up.sql"))[0]);try{ForwardLedger::apply($db,$root,$m,'0002');exit(1);}catch(RuntimeException $e){echo $e->getMessage();if($e->getMessage()!=='partial_drift_or_unrecorded')exit(2);}}
