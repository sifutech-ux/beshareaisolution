<?php
declare(strict_types=1);

use BesharOs\AuthException;
use BesharOs\Db;
use BesharOs\PlatformAdminSchema;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/SqlScript.php';
require_once dirname(__DIR__).'/src/PlatformAdminSchema.php';
$apply=false;$checksum='';
foreach(array_slice($argv,1) as $arg){
    if($arg==='--apply'){$apply=true;}
    elseif(preg_match('/\A--checksum=([a-f0-9]{64})\z/',$arg,$m)===1){$checksum=$m[1];}
    else{fwrite(STDERR,"Pilihan tidak sah. Semak dahulu, kemudian --apply --checksum=HASH.\n");exit(1);}
}
try{
    $sql=file_get_contents(dirname(__DIR__).'/migrations/0006_platform_admin.up.sql');
    if(!is_string($sql)){throw new RuntimeException();}
    $schema=new PlatformAdminSchema(Db::connect(),$sql);
    $result=$apply?$schema->apply($checksum):$schema->inspect();
    fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
}catch(AuthException $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
catch(Throwable){fwrite(STDERR,"Pemasangan admin tidak dapat diselesaikan. Tiada pemadaman atau pemberian akses automatik.\n");exit(1);}
