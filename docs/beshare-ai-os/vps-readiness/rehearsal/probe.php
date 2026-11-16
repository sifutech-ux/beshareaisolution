<?php
// Diagnostic lab only; never an alternative production migration runner.
declare(strict_types=1);
require '/lab/source/os/src/bootstrap.php';
require_once '/lab/source/os/src/SqlScript.php';
$target = $argv[1] ?? '';
if (!in_array($target, ['source','restore','partial','p0'], true)) exit(2);
$pdo = new PDO("mysql:unix_socket=/sockets/$target/mysql.sock;dbname=u879723783_beshare_os;charset=utf8mb4", 'root', 'synthetic-unused', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone='+00:00'");
$mode=$argv[2]??'';
if ($mode==='version') {
 echo json_encode($pdo->query('SELECT VERSION() AS version, @@version_comment AS vendor, @@sql_mode AS sql_mode, @@time_zone AS time_zone, @@collation_database AS collation, @@log_bin AS log_bin')->fetch(),JSON_PRETTY_PRINT);
} elseif ($mode==='apply') {
 if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0) {fwrite(STDERR,"nonempty_schema_refused\n");exit(5);}
 if ((int)$pdo->query("SELECT GET_LOCK('beshare_disposable_migration',0)")->fetchColumn()!==1) exit(3);
 $stop=(int)($argv[3]??0);$n=0;
 foreach (glob('/lab/source/os/migrations/*.up.sql') as $file) {
  foreach (BesharOs\SqlScript::statements(file_get_contents($file)) as $index=>$sql) {
   $start=hrtime(true);
   try {$pdo->exec($sql);$state='PASS';} catch(PDOException $e) {$state='BLOCKED';}
   echo json_encode(['migration'=>basename($file),'file_sha256'=>hash_file('sha256',$file),'statement'=>$index+1,'statement_sha256'=>hash('sha256',$sql),'result'=>$state,'ms'=>(hrtime(true)-$start)/1e6,'error'=>$state==='BLOCKED'?$e->getMessage():null])."\n";
   if ($state==='BLOCKED') exit(6);
   if (++$n===$stop) {fwrite(STDERR,"injected_interruption_after_statement_$n\n");exit(9);}
  }
  echo json_encode(['postcondition'=>basename($file),'tables'=>(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn(),'triggers'=>(int)$pdo->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchColumn()])."\n";
 }
 // Intentionally no synthetic ledger row: the approved foundation runner has not passed.
 $pdo->query("SELECT RELEASE_LOCK('beshare_disposable_migration')");
} elseif ($mode==='fingerprint') {
 $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);$out=[];
 foreach($tables as $table) {
  $ddl=$pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
  $rows=$pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_NUM);
  $rows=array_map('serialize',$rows);sort($rows,SORT_STRING);
  $out[$table]=['count'=>count($rows),'rows_sha256'=>hash('sha256',serialize($rows)),'ddl_sha256'=>hash('sha256',$ddl),'canonical_ddl_sha256'=>hash('sha256',str_replace(' CHARACTER SET utf8mb4','',$ddl))];
 }
 $triggers=$pdo->query('SHOW TRIGGERS')->fetchAll();
 foreach($triggers as &$t) unset($t['Created']);unset($t);
 $out['_triggers_sha256']=hash('sha256',serialize($triggers));
 echo json_encode($out,JSON_PRETTY_PRINT)."\n";
} elseif ($mode==='catalog-probe') {
 try {$pdo->query('SELECT cc.TABLE_NAME FROM information_schema.CHECK_CONSTRAINTS cc LIMIT 1');echo 'unexpected success';exit(1);} catch(PDOException $e) {echo $e->getMessage()."\n";}
} else {exit(2);}
