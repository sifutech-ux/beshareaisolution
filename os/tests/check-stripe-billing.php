<?php
declare(strict_types=1);
require __DIR__.'/../src/AuthException.php';
require __DIR__.'/../src/StripeBilling.php';
use BesharOs\{AuthException,StripeBilling};
$checks=0;
function check(bool $value,string $label):void{global $checks;if(!$value){throw new RuntimeException('FAIL '.$label);}++$checks;}
function denied(callable $f,int $status,string $label):void{try{$f();}catch(AuthException $e){check($e->status()===$status,$label);return;}throw new RuntimeException('FAIL '.$label);}
$mysql=getenv('BESHARE_BILLING_TEST_MYSQL')==='1';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$pdo=$mysql
 ?new PDO('mysql:host=127.0.0.1;port=33306;dbname=beshare_billing_test','root','synthetic-billing-ci-only',$options)
 :new PDO('sqlite::memory:',null,null,$options);
if($mysql && $pdo->query('SHOW TABLES')->fetchAll()!==[]){throw new RuntimeException('Refusing non-empty fixture database.');}
$schema="CREATE TABLE businesses(id INTEGER PRIMARY KEY,public_id VARCHAR(64),status VARCHAR(32));
CREATE TABLE whatsapp_connections(id INTEGER PRIMARY KEY,business_id INTEGER,token_state VARCHAR(32),webhook_state VARCHAR(32),disconnected_at VARCHAR(32));
CREATE TABLE ai_reply_settings(business_id INTEGER,connection_id INTEGER);
CREATE TABLE ai_messages(business_id INTEGER,connection_id INTEGER,direction VARCHAR(8),status VARCHAR(32),wamid VARCHAR(64));";
if($mysql){$schema=str_replace('businesses(id INTEGER','businesses(id BIGINT UNSIGNED',$schema);}
$pdo->exec($schema);
if($mysql){
 require __DIR__.'/../src/SqlScript.php';
 require __DIR__.'/../src/StripeBillingSchema.php';
 $pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(16) PRIMARY KEY,name VARCHAR(80),checksum CHAR(64),applied_at DATETIME)');
 $sql=file_get_contents(__DIR__.'/../migrations/0009_stripe_billing.up.sql');
 $migration=new \BesharOs\StripeBillingSchema($pdo,$sql);
 check($migration->inspect()['status']==='ready','migration ready on empty fixture');
 denied(fn()=>$migration->apply(str_repeat('0',64)),409,'wrong migration checksum denied');
 check($migration->apply(hash('sha256',$sql))['status']==='installed','actual MySQL migration installed');
 check($migration->apply(hash('sha256',$sql))['status']==='installed','repeated migration is safe');
}else{
 $pdo->exec("CREATE TABLE os_billing(business_id INTEGER,mode TEXT,customer TEXT UNIQUE,subscription TEXT UNIQUE,status TEXT DEFAULT 'none',paid_until INTEGER DEFAULT 0,period_start INTEGER DEFAULT 0,setup_paid INTEGER DEFAULT 0,cancel_at_end INTEGER DEFAULT 0,attempt TEXT,attempt_at INTEGER DEFAULT 0,checkout TEXT UNIQUE,updated_at INTEGER DEFAULT 0,PRIMARY KEY(business_id,mode));
 CREATE TABLE os_billing_events(event_id TEXT PRIMARY KEY,processed_at INTEGER);");
}
$pdo->exec("INSERT INTO businesses VALUES(1,'businessA','active'),(2,'businessB','active');
INSERT INTO whatsapp_connections VALUES(1,1,'verified','subscribed',NULL),(2,2,'verified','subscribed',NULL);
INSERT INTO ai_reply_settings VALUES(1,1),(2,2);
INSERT INTO ai_messages VALUES(1,1,'in','received','wamid_in'),(1,1,'out','sent','wamid_out');");
$now=1800000000;
$config=['enabled'=>true,'mode'=>'test','secret_key'=>'rk_test_fixture','price'=>'price_month','setup_price'=>'price_setup','portal'=>'bpc_fixture','business_allowlist'=>['businessA','businessB']];
$owner=['business_id'=>1,'public_id'=>'businessA','user_id'=>1,'owner_user_id'=>1,'role'=>'owner','status'=>'active','email'=>'qa@example.invalid'];
$other=array_replace($owner,['business_id'=>2,'public_id'=>'businessB','user_id'=>2,'owner_user_id'=>2]);
$objects=[
 'prices/price_month'=>['active'=>true,'livemode'=>false,'currency'=>'myr','unit_amount'=>19900,'recurring'=>['interval'=>'month','interval_count'=>1]],
 'prices/price_setup'=>['active'=>true,'livemode'=>false,'currency'=>'myr','unit_amount'=>29900,'recurring'=>null],
];
$writes=[];$calls=[];$lost=false;
$api=static function(string $path,?array $data,?string $key)use(&$objects,&$writes,&$calls,&$lost):array{
 $calls[]=[$path,$data,$key];
 if($data===null){if(!isset($objects[$path])){throw new RuntimeException('Unexpected '.$path);}return $objects[$path];}
 if(isset($writes[$key])){check($writes[$key][0]===$data,'retry preserves Stripe parameters');return $writes[$key][1];}
 if($path==='customers'){$result=['id'=>'cus_'.$data['metadata[beshare_owner]'],'livemode'=>false];}
 elseif($path==='checkout/sessions'){$sid='cs_test_'.count($writes);$result=['id'=>$sid,'customer'=>$data['customer'],'status'=>'open','subscription'=>null,'livemode'=>false,'url'=>'https://checkout.stripe.com/c/pay/'.$sid];$objects[$path.'/'.$sid]=$result;}
 elseif($path==='billing_portal/sessions'){$result=['id'=>'bps_fixture','url'=>'https://billing.stripe.com/p/session/test'];}
 else{throw new RuntimeException('Unexpected '.$path);}
 $writes[$key]=[$data,$result];
 if($path==='checkout/sessions'&&$lost){$lost=false;throw new AuthException('Timeout',503);}
 return $result;
};
$billing=new StripeBilling($pdo,$config,$api,$now);
denied(fn()=>$billing->checkout(array_replace($owner,['role'=>'staff']),'businessA'),403,'staff denied');
denied(fn()=>$billing->checkout($owner,'businessB'),409,'stale tenant rejected');
denied(fn()=>$billing->checkout($other,'businessB'),409,'untested WhatsApp cannot be charged');
check(count($calls)===0,'denials do not contact Stripe');
$lost=true;denied(fn()=>$billing->checkout($owner,'businessA'),503,'ambiguous response retained for retry');
$first=$billing->checkout($owner,'businessA');$again=$billing->checkout($owner,'businessA');check($first===$again,'same checkout on double click');
$row=$pdo->query('SELECT * FROM os_billing WHERE business_id=1')->fetch();
$checkoutWrites=array_values(array_filter($writes,static fn($pair)=>isset($pair[0]['line_items[0][price]'])));
check(count($checkoutWrites)===1,'one remote checkout after timeout retry');
check($checkoutWrites[0][0]['line_items[1][price]']==='price_setup','first checkout includes one-off setup');
check(!$billing->view($owner)['active'],'checkout does not activate access');
$objects['checkout/sessions/'.$row['checkout']]['status']='complete';
$objects['checkout/sessions/'.$row['checkout']]['subscription']='sub_fixture';
$objects['subscriptions/sub_fixture']=['id'=>'sub_fixture','customer'=>$row['customer'],'livemode'=>false,'status'=>'active',
 'metadata'=>['beshare_product'=>'os','beshare_owner'=>'businessA','beshare_attempt'=>$row['attempt']],
 'items'=>['data'=>[['price'=>['id'=>'price_month'],'quantity'=>1,'current_period_start'=>$now,'current_period_end'=>$now+2592000]]],
 'latest_invoice'=>'in_fixture','cancel_at_period_end'=>false];
$objects['invoices/in_fixture']=['status'=>'open','customer'=>$row['customer'],
 'parent'=>['subscription_details'=>['subscription'=>'sub_fixture']],
 'lines'=>['data'=>[['pricing'=>['price_details'=>['price'=>'price_month']],'period'=>['end'=>$now+2592000]],['pricing'=>['price_details'=>['price'=>'price_setup']]]]]];
$event=['id'=>'evt_unpaid','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>['customer'=>$row['customer'],'subscription'=>'sub_fixture']]];
$billing->webhook($event);check(!$billing->view($owner)['active'],'unpaid invoice grants no access');
$objects['invoices/in_fixture']['status']='paid';$event['id']='evt_paid';$billing->webhook($event);
check($billing->view($owner)['active'],'paid recurring invoice activates account');
check($billing->view($owner)['setup_amount']===0,'paid setup remembered');
check(!$billing->view($other)['active'],'other tenant remains inactive');
$before=count($calls);check($billing->webhook($event)['duplicate']===true&&count($calls)===$before,'duplicate event has no side effects');
$objects['subscriptions/sub_fixture']['cancel_at_period_end']=true;$event['id']='evt_cancel_scheduled';$billing->webhook($event);
check($billing->view($owner)['active']&&$billing->view($owner)['cancel_at_period_end'],'scheduled cancellation retains paid period');
$objects['subscriptions/sub_fixture']['status']='canceled';$event['id']='evt_delayed_paid';$event['type']='invoice.paid';$billing->webhook($event);
check(!$billing->view($owner)['active'],'late paid event cannot revive canceled subscription');
$billing->portal($owner,'businessA');check(end($calls)[1]['customer']===$row['customer'],'portal binds server-side customer');
$billing->checkout($owner,'businessA');$lastWrite=end($writes)[0];check(!isset($lastWrite['line_items[1][price]']),'resubscription never repeats paid setup fee');
$raw=json_encode(['id'=>'evt_signature','livemode'=>false]);$sig=hash_hmac('sha256',$now.'.'.$raw,'whsec_fixture');$header='t='.$now.',v1='.$sig;
check(StripeBilling::event($raw,$header,'whsec_fixture',false,$now)['id']==='evt_signature','valid raw-body signature');
denied(fn()=>StripeBilling::event($raw.' ',$header,'whsec_fixture',false,$now),400,'changed body rejected');
denied(fn()=>StripeBilling::event($raw,$header,'whsec_fixture',true,$now),400,'wrong environment rejected');
denied(fn()=>StripeBilling::event($raw,$header,'whsec_fixture',false,$now+301),400,'expired signature rejected');
denied(fn()=>StripeBilling::event($raw,$header.',t=1','whsec_fixture',false,$now),400,'duplicate timestamp rejected');
denied(fn()=>StripeBilling::url('https://checkout.stripe.com.evil.invalid/','checkout.stripe.com'),503,'unsafe redirect rejected');
denied(fn()=>new StripeBilling($pdo,array_replace($config,['mode'=>'live']),$api,$now),503,'live configuration requires separate approval and key');
echo "PASS $checks Stripe billing checks; synthetic Stripe only\n";
