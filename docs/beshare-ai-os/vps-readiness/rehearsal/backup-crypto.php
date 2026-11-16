<?php
// Synthetic backup envelope; key is a private file in the disposable app container.
declare(strict_types=1);
$mode=$argv[1]??'';$keyPath='/tmp/beshare-lab-backup.key';
if($mode==='encrypt'){$key=random_bytes(32);file_put_contents($keyPath,$key);chmod($keyPath,0600);$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(stream_get_contents(STDIN),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'beshare-synthetic-v1',16);if($cipher===false)exit(2);echo $iv.$tag.$cipher;exit;}
if($mode==='missing')$keyPath='/tmp/intentionally-missing-backup.key';
if(!is_file($keyPath)){fwrite(STDERR,"backup_key_missing\n");exit(3);}
$key=file_get_contents($keyPath);if($mode==='wrong')$key=str_repeat('z',32);
$envelope=stream_get_contents(STDIN);$plain=openssl_decrypt(substr($envelope,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($envelope,0,12),substr($envelope,12,16),'beshare-synthetic-v1');
if($plain===false){fwrite(STDERR,"backup_authentication_failed\n");exit(4);}echo $plain;
