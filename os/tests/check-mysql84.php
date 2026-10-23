<?php
declare(strict_types=1);
foreach (['Contract','SqlScript','SchemaDefinition','BackupProof'] as $name) require __DIR__.'/../src/'.$name.'.php';
use BesharOs\SchemaDefinition;
use BesharOs\BackupProof;
use BesharOs\Contract;
$n=0;
function check(string $name,bool $ok):void {global $n;if(!$ok)throw new RuntimeException($name);$n++;echo "OK $name\n";}
$rows=json_decode(file_get_contents(__DIR__.'/mysql-8413-foundation.json'),true,512,JSON_THROW_ON_ERROR);
$expected=SchemaDefinition::fromSql(file_get_contents(__DIR__.'/../migrations/0001_foundation.up.sql'));
check('MySQL 8.4.13 catalog equals original migration',SchemaDefinition::same(SchemaDefinition::fromCatalog(...$rows),$expected));
foreach (['8.4.13','8.4.0','8.4.13-commercial','11.8.9-MariaDB','11.8.9-MariaDB-log'] as $v) check('supported '.$v,BackupProof::supportedVersion($v));
foreach (['','8.0.40','9.0.0','fake-MariaDB','8.4.13-unknown',"8.4.13\n"] as $v) check('unsupported '.json_encode($v),!BackupProof::supportedVersion($v));
foreach (['8.4.13','11.8.9-MariaDB-log'] as $version) {
 $dump="-- Host: localhost    Database: ".Contract::DATABASE."\n-- Server version\t$version\n";
 $sha=hash('sha256',$dump);
 $manifest=['database'=>Contract::DATABASE,'server_version'=>$version,'export_sha256'=>$sha];
 foreach(['tables','triggers','routines','events','views','schema_migrations_rows'] as $key)$manifest[$key]='0';
 BackupProof::verify($dump,$manifest,$sha);check('bound dump '.$version,true);
 $manifest['server_version']=$version.'-wrong';
 try {BackupProof::verify($dump,$manifest,$sha);$rejected=false;}catch(InvalidArgumentException){$rejected=true;}
 check('wrong version bound '.$version,$rejected);
}
$changed=$rows;$changed[3][0]['enforced']='NO';
try{SchemaDefinition::fromCatalog(...$changed);$rejected=false;}catch(InvalidArgumentException $e){$rejected=$e->getMessage()==='check_not_enforced';}
check('NOT ENFORCED rejected',$rejected);
$changed=$rows;$changed[3][0]['check_clause']=str_replace('provisioning','active',$changed[3][0]['check_clause']);
check('changed CHECK literal rejected',!SchemaDefinition::same(SchemaDefinition::fromCatalog(...$changed),$expected));
$changed=$rows;
foreach($changed[0] as &$r)if($r['column_name']==='owner_guard')$r['generation_expression']=str_replace('owner','admin',$r['generation_expression']);unset($r);
check('changed generated literal rejected',!SchemaDefinition::same(SchemaDefinition::fromCatalog(...$changed),$expected));
$changed=$rows;array_pop($changed[4]);
check('missing trigger rejected',!SchemaDefinition::same(SchemaDefinition::fromCatalog(...$changed),$expected));
echo "PASS $n checks\n";
