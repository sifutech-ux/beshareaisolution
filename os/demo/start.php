<?php
declare(strict_types=1);
require __DIR__.'/runtime.php';
if (PHP_SAPI!=='cli') exit(1);
umask(0077);
if (!in_array(getenv('BESHARE_DEMO_MODE') ?: 'mock',['mock','live'],true)) die("Mode must be mock or live.\n");
$dir=stateDir();
if (!is_dir($dir)) mkdir($dir,0700,true);
if (is_link($dir) || fileowner($dir)!==posix_getuid() || (fileperms($dir)&0077)!==0) die("State directory must be private and owned by you.\n");
if (!file_exists($dir.'/password.hash')) {
    $password=bin2hex(random_bytes(16));
    file_put_contents($dir.'/login.txt',"Demo owner password (local synthetic demo only):\n".$password."\n");
    file_put_contents($dir.'/password.hash',password_hash($password,PASSWORD_DEFAULT));
    file_put_contents($dir.'/signing.key',random_bytes(32));
}
$db=db();
if (!$db->query("SELECT name FROM sqlite_master WHERE name='tenants'")->fetch()) {
    $db->exec(file_get_contents(__DIR__.'/../mvp/schema.sql'));
    $db->exec("INSERT INTO tenants VALUES('demo-kedai',1,20,81920); INSERT INTO routes VALUES('synthetic-waba','synthetic-phone','demo-kedai'); INSERT INTO operators VALUES('demo-kedai','demo-owner',1)");
    $q=$db->prepare('INSERT INTO knowledge VALUES(?,?,?,?,?)');
    $q->execute([TENANT,1,"Kedai Seri Demo (bisnes rekaan).\nWaktu operasi: Isnin–Sabtu, 9 pagi–6 petang.\nPakej asas: RM99 sebulan.\nKonsultasi pengenalan: 30 minit, percuma.\nTempahan perlu disahkan oleh staf.",ACTOR,time()+604800]);
}
$db->exec('CREATE TABLE IF NOT EXISTS demo_provenance (tenant TEXT NOT NULL,event TEXT NOT NULL,mode TEXT NOT NULL,PRIMARY KEY(tenant,event),FOREIGN KEY(tenant,event) REFERENCES handoffs(tenant,event))');
if(liveMode()&&!getenv('GEMINI_API_KEY'))die("Live mode requires GEMINI_API_KEY. No fallback performed.\n");
$port=getenv('BESHARE_DEMO_PORT') ?: '8766';
if(!ctype_digit($port)||(int)$port<1024||(int)$port>65535)die("Invalid port\n");
echo "Demo: http://127.0.0.1:$port\nMode: ".(liveMode()?'LIVE Gemini':'MOCK (no Gemini request)')."\nPassword file: $dir/login.txt\nStop: Ctrl-C. Synthetic data only.\n";
passthru(escapeshellarg(PHP_BINARY).' -d display_errors=0 -d log_errors=0 -d session.save_path='.escapeshellarg($dir).' -S 127.0.0.1:'.$port.' '.escapeshellarg(__DIR__.'/public/index.php'));
