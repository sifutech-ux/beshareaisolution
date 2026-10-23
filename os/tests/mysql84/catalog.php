<?php
require '/lab/source/os/src/Contract.php';
require '/lab/source/os/src/SqlScript.php';
require '/lab/source/os/src/SchemaDefinition.php';
$p = new PDO('mysql:unix_socket=/sockets/source/mysql.sock;dbname=u879723783_beshare_os','root','synthetic-unused',[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$source=file_get_contents('/lab/source/os/src/FoundationMigrator.php');
$section=explode('$definitions = SchemaDefinition::fromCatalog(', $source)[1];
$section=explode('$expected =', $section)[0];
$rows=[];
foreach(explode("\n",$section) as $line) {
 $line=trim($line);
 if (!str_starts_with($line,"'SELECT")) continue;
 $line=str_replace('$this->mysql', !str_contains($p->query('SELECT VERSION()')->fetchColumn(),'MariaDB')?'true':'false',$line);
 $sql=eval('return '.$line.';');
 $rows[]=$p->query($sql)->fetchAll();
}
$a=BesharOs\SchemaDefinition::fromCatalog(...$rows);
$b=BesharOs\SchemaDefinition::fromSql(file_get_contents('/lab/source/os/migrations/0001_foundation.up.sql'));
echo json_encode(['actual'=>$a,'expected'=>$b,'rows'=>$rows],JSON_PRETTY_PRINT);
