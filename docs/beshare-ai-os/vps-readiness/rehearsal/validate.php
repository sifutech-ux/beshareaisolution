<?php
declare(strict_types=1);
require '/lab/source/os/src/bootstrap.php';
$t=$argv[1]??'';if(!in_array($t,['source','restore'],true))exit(2);
$dsn="mysql:unix_socket=/sockets/$t/mysql.sock;dbname=u879723783_beshare_os;charset=utf8mb4";
function db(string $user):PDO {global $dsn;return new PDO($dsn,$user,'synthetic-unused',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
function check(string $name,bool $pass):void{echo ($pass?'OK ':'FAIL ').$name."\n";if(!$pass)exit(1);}
function deny(PDO $p,string $sql,int $code):void {try{$p->exec($sql);check($sql,false);}catch(PDOException $e){check('denied '.strtok($sql,' ').' errno='.$code,(int)$e->errorInfo[1]===$code);}}
$p=db('root');$schema='u879723783_beshare_os';$mode=$argv[2]??'';
if($mode==='roles') {
 foreach(['web','worker','backup','definer'] as $u)$p->exec("CREATE USER IF NOT EXISTS '$u'@'localhost' IDENTIFIED BY 'synthetic-unused'".($u==='definer'?' ACCOUNT LOCK':''));
 foreach(['users','businesses','business_users','whatsapp_connections','onboarding_sessions','webhook_events','coexistence_sync_jobs','coexistence_contacts','coexistence_messages'] as $table)$p->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON $schema.$table TO 'web'@'localhost'");
 $p->exec("GRANT INSERT ON $schema.audit_logs TO 'web'@'localhost'");$p->exec("GRANT SELECT ON $schema.schema_migrations TO 'web'@'localhost'");
 foreach(['users','businesses','business_users'] as $table)$p->exec("GRANT SELECT ON $schema.$table TO 'worker'@'localhost'");
 foreach(['whatsapp_connections','webhook_events','coexistence_sync_jobs'] as $table)$p->exec("GRANT SELECT,UPDATE ON $schema.$table TO 'worker'@'localhost'");
 foreach(['coexistence_contacts','coexistence_messages'] as $table)$p->exec("GRANT SELECT,INSERT,UPDATE ON $schema.$table TO 'worker'@'localhost'");
 $p->exec("GRANT INSERT ON $schema.audit_logs TO 'worker'@'localhost'");
 $p->exec("GRANT SELECT,SHOW VIEW,TRIGGER ON $schema.* TO 'backup'@'localhost'");
 foreach(['businesses','business_users'] as $table)$p->exec("GRANT SELECT,TRIGGER ON $schema.$table TO 'definer'@'localhost'");
 // Preserve bodies; replace only the lab root definer with a locked lab identity.
 foreach($p->query('SHOW TRIGGERS')->fetchAll() as $tr){$row=$p->query('SHOW CREATE TRIGGER `'.$tr['Trigger'].'`')->fetch();$ddl=$row['SQL Original Statement'];$ddl=preg_replace('/DEFINER=`[^`]+`@`[^`]+`/','DEFINER=`definer`@`localhost`',$ddl);$p->exec('DROP TRIGGER `'.$tr['Trigger'].'`');$p->exec($ddl);}
 foreach(['web','worker','backup','definer'] as $u){echo "$u\n";foreach($p->query("SHOW GRANTS FOR '$u'@'localhost'")->fetchAll(PDO::FETCH_COLUMN) as $g)echo "$g\n";}
} elseif($mode==='seed') {
 $w=db('web');$a=BesharOs\Accounts::register($w,'Synthetic Owner A','a@example.test','synthetic-password-only','Sintetik 日本語 😀');
 $b=BesharOs\Accounts::register($w,'Synthetic Owner B','b@example.test','synthetic-password-only','Synthetic B');
 $seal=new BesharOs\TokenSeal(str_repeat('x',32),1);$sealed=$seal->seal("synthetic-token\0\xff",2048);
 $s=$w->prepare("INSERT INTO whatsapp_connections (business_id,waba_id,phone_number_id,token_state,token_ciphertext,token_nonce,token_tag,token_key_version,created_at,updated_at) VALUES (?, '100000000001','100000000002','stored',?,?,?,?,NOW(),NOW())");$s->execute([$a['business_id'],$sealed['ciphertext'],$sealed['nonce'],$sealed['tag'],$sealed['version']]);
 $connection=(int)$w->lastInsertId();
 for($i=0;$i<200;$i++){$w->prepare("INSERT INTO coexistence_messages (business_id,connection_id,source_kind,wamid,message_type,body_text,created_at) VALUES (?,?,'history',?,'text',?,NOW())")->execute([$a['business_id'],$connection,'synthetic-'.$i,'Sintetik 日本語 😀 '.$i]);}
 $w->prepare("INSERT INTO coexistence_sync_jobs (business_id,connection_id,authorized_by,sync_type,status,deadline_at,authorized_at,created_at,updated_at) VALUES (?,?,?,'history','dispatching',NOW(),NOW(),NOW(),NOW())")->execute([$a['business_id'],$connection,$a['user_id']]);
 check('synthetic seeded via restricted web identity',true);
} elseif($mode==='verify') {
 $w=db('web');$worker=db('worker');$backup=db('backup');
 check('11 tables',(int)$p->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===11);
 check('5 triggers',(int)$p->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchColumn()===5);
 check('no fabricated ledger rows',(int)$p->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===0);
 check('UTF8 roundtrip',$w->query('SELECT name FROM businesses WHERE id=1')->fetchColumn()==='Sintetik 日本語 😀');
 $r=$w->query('SELECT * FROM whatsapp_connections WHERE id=1')->fetch();$seal=new BesharOs\TokenSeal(str_repeat('x',32),1);
 check('encrypted binary roundtrip',$seal->open($r['token_ciphertext'],$r['token_nonce'],$r['token_tag'],(int)$r['token_key_version'])==="synthetic-token\0\xff");
 try{(new BesharOs\TokenSeal(str_repeat('y',32),1))->open($r['token_ciphertext'],$r['token_nonce'],$r['token_tag'],1);check('wrong key rejected',false);}catch(BesharOs\AuthException){check('wrong key rejected',true);}
 deny($w,"UPDATE users SET status='invalid' WHERE id=1",3819);
 deny($w,"DELETE FROM business_users WHERE business_id=1 AND role='owner'",1644);
 deny($w,"UPDATE business_users SET role='staff' WHERE business_id=1 AND role='owner'",1644);
 deny($w,"INSERT INTO coexistence_contacts (business_id,connection_id,phone_number,sync_action,changed_at,created_at) VALUES(2,1,'100000000003','add','1',NOW())",1452);
 deny($w,"INSERT INTO whatsapp_connections (business_id,waba_id,phone_number_id,token_state,created_at,updated_at) VALUES(2,'100000000004','100000000002','cleared',NOW(),NOW())",1062);
 deny($w,"INSERT INTO coexistence_messages (business_id,connection_id,source_kind,wamid,message_type,created_at) VALUES(1,1,'history','synthetic-0','text',NOW())",1062);
 foreach([$w,$worker,$backup] as $account){deny($account,'CREATE TABLE forbidden(id INT)',1142);deny($account,'SELECT * FROM mysql.user',1142);deny($account,"GRANT SELECT ON $schema.users TO 'web'@'localhost'",1142);}
 deny($worker,"UPDATE users SET name='bad' WHERE id=1",1142);deny($worker,'DELETE FROM coexistence_sync_jobs',1142);deny($backup,"UPDATE users SET name='bad' WHERE id=1",1142);
 $w->beginTransaction();$w->query('SELECT * FROM users WHERE id=1 FOR UPDATE');$other=db('web');$other->exec('SET innodb_lock_wait_timeout=1');deny($other,"UPDATE users SET name='blocked' WHERE id=1",1205);$w->rollBack();check('transaction rollback',$w->query('SELECT name FROM users WHERE id=1')->fetchColumn()==='Synthetic Owner A');
 $transport=new class implements BesharOs\MetaTransport {public function call(string $method,string $url,array $headers,string $body):array{throw new RuntimeException('transport_must_not_run');}};
 $sync=new BesharOs\CoexistenceSync($worker,new BesharOs\MetaGraph('10000001','synthetic','v23.0',$transport),$seal,false);
 check('worker actual dueJobs query with restricted role',$sync->dueJobs(5)===[]);
 $before=(int)$p->query("SELECT COUNT(*) FROM coexistence_sync_jobs WHERE status='dispatching'")->fetchColumn();
 check('worker recoverInterrupted query and update',$sync->recoverInterrupted()===$before);
 check('ambiguous job remains review',(int)$p->query("SELECT COUNT(*) FROM coexistence_sync_jobs WHERE status='review'")->fetchColumn()===1);
 check('duplicate recovery idempotent',$sync->recoverInterrupted()===0);
 check('backup SELECT',(int)$backup->query('SELECT COUNT(*) FROM coexistence_messages')->fetchColumn()===200);
} else exit(2);
