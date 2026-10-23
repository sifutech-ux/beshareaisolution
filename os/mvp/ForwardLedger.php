<?php
declare(strict_types=1);
namespace BesharOs\Mvp;
use PDO;
use RuntimeException;
/** Reviewed fresh isolated database only. No adoption, repair, down SQL or fabricated rows. */
final class ForwardLedger
{
    public static function fingerprint(PDO $db): string
    {
        $out=[];
        foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
            if($r[1]!=='BASE TABLE') throw new RuntimeException('unexpected_view');
            $name=$r[0];if(!preg_match('/\A[a-z0-9_]+\z/',$name))throw new RuntimeException('object_denied');
            $ddl=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
            $out['table:'.$name]=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);
        }
        foreach($db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            unset($r['Created']);$out['trigger:'.$r['Trigger']]=$r;
        }
        foreach(['ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $table=>$column)
            if((int)$db->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column=DATABASE()")->fetchColumn()!==0)throw new RuntimeException('unexpected_routine_or_event');
        ksort($out);return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
    }
    public static function apply(PDO $db,string $root,array $manifest,string $version): string
    {
        if(!in_array($version,['0002','0003'],true))throw new RuntimeException('version_denied');
        if($db->query('SELECT DATABASE()')->fetchColumn()!==\BesharOs\Contract::DATABASE || $db->query('SELECT VERSION()')->fetchColumn()!==$manifest['server'])throw new RuntimeException('target_mismatch');
        $step=$manifest['steps'][$version]??null;if(!$step)throw new RuntimeException('manifest_missing');
        $files=['0001'=>'0001_foundation.up.sql','0002'=>'0002_coexistence_sync.up.sql','0003'=>'0003_coexistence_worker.up.sql'];
        foreach($files as $v=>$file)if(!hash_equals($manifest['checksums'][$v],hash_file('sha256',"$root/os/migrations/$file")))throw new RuntimeException('checksum_mismatch');
        $lock=$db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([\BesharOs\Contract::LOCK]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('migration_lock_busy');
        try {
            $rows=$db->query('SELECT version,checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
            $prior=$version==='0002'?['0001']:['0001','0002'];$after=[...$prior,$version];
            foreach($rows as $v=>$hash)if(!isset($manifest['checksums'][$v])||!hash_equals($manifest['checksums'][$v],$hash))throw new RuntimeException('ledger_mismatch');
            $fingerprint=self::fingerprint($db);
            if(array_keys($rows)===$after && hash_equals($step['after'],$fingerprint))return 'already_applied';
            if(array_keys($rows)!==$prior || !hash_equals($step['before'],$fingerprint))throw new RuntimeException('partial_drift_or_unrecorded');
            // Synthetic fresh-only gate: never run over customer/business rows.
            foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)
                if($table!=='schema_migrations' && (int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0)throw new RuntimeException('fresh_only');
            $sql=file_get_contents("$root/os/migrations/".$files[$version]);
            if(!hash_equals($manifest['checksums'][$version],hash('sha256',$sql)))throw new RuntimeException('checksum_changed');
            foreach(\BesharOs\SqlScript::statements($sql) as $statement)$db->exec($statement);
            if(!hash_equals($step['after'],self::fingerprint($db)))throw new RuntimeException('postcondition_failed_unrecorded');
            $db->beginTransaction();
            try {$q=$db->prepare('INSERT INTO schema_migrations(version,name,checksum,applied_at) VALUES(?,?,?,UTC_TIMESTAMP())');$q->execute([$version,$version==='0002'?'coexistence_sync':'coexistence_worker',$manifest['checksums'][$version]]);$db->commit();}
            catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            return 'applied';
        } finally {$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([\BesharOs\Contract::LOCK]);}
    }
}
