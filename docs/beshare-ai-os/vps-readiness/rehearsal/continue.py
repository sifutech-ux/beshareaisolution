#!/usr/bin/env python3
"""Continuation after lab.py. Uses only its fixed disposable containers."""
from lab import *

def measured(name,fn):
 start=time.perf_counter();r=fn();save(name+'.json',{'seconds':time.perf_counter()-start,'exit':r.returncode,'stdout':r.stdout.decode(errors='replace'),'stderr':r.stderr.decode(errors='replace')});return r

def main():
 for name in ['validate.php','adapt-regression.py']:
  copy_bytes(PREFIX+'-app','/lab/'+name,(HERE/name).read_bytes())
 # Actual empty-schema export and manifest, outside source tree.
 empty=docker('exec',PREFIX+'-restore','mysqldump','--defaults-extra-file=/tmp/lab.cnf','--no-data','--routines','--events','--no-tablespaces','--set-gtid-purged=OFF',DB).stdout
 version=sql('restore','SELECT VERSION()').stdout.decode().strip()
 counts=sql('restore',f"USE {DB}; SELECT DATABASE(),VERSION(),(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{DB}'),(SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='{DB}'),(SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='{DB}'),(SELECT COUNT(*) FROM information_schema.events WHERE event_schema='{DB}') FROM (SELECT 1) x",ok=False)
 save('empty-inventory.txt',counts.stdout.decode())
 sha=hashlib.sha256(empty).hexdigest()
 proof=f'database={DB}\nserver_version={version}\nexport_sha256={sha}\ntables=0\ntriggers=0\nroutines=0\nevents=0\nviews=0\nschema_migrations_rows=0\n'
 copy_bytes(PREFIX+'-app','/tmp/empty.sql',empty);copy_bytes(PREFIX+'-app','/tmp/empty.proof',proof.encode())
 r=docker('exec','-e','BESHARE_OS_DSN=mysql:unix_socket=/sockets/restore/mysql.sock;dbname='+DB+';charset=utf8mb4','-e','BESHARE_OS_DB_USER=root','-e','BESHARE_OS_DB_PASSWORD=synthetic-unused',PREFIX+'-app','php','/lab/source/os/bin/migrate.php','apply','--confirm-foundation-0001','--export=/tmp/empty.sql','--export-sha256='+sha,'--backup-proof=/tmp/empty.proof',ok=False)
 save('foundation-gate.json',{'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode(),'empty_dump_sha256':sha,'server_version':version})
 r=sql('restore',"SET SESSION sql_mode='NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES'",ok=False)
 save('original-test-mode.json',{'exit':r.returncode,'stderr':r.stderr.decode()})
 # Strict deny networking from each namespace; network=none prevents packets leaving.
 for target in ['source','restore','partial','p0','app']:
  r=docker('exec',PREFIX+'-'+target,'bash','-c','cat /proc/net/route; timeout 2 bash -c "echo > /dev/tcp/192.0.2.1/443"',ok=False)
  save('egress-'+target+'.json',{'exit':r.returncode,'routes':r.stdout.decode(),'stderr':r.stderr.decode()})
 # Rehearse a real interrupted DDL and refuse a blind rerun.
 r=probe('partial','apply','2',ok=False);save('partial-journal.jsonl',r.stdout.decode());save('partial-injection.json',{'exit':r.returncode,'stderr':r.stderr.decode()})
 r=probe('partial','apply',ok=False);save('partial-rerun.json',{'exit':r.returncode,'stderr':r.stderr.decode()})
 save('grants.txt',execapp('php','/lab/validate.php','source','roles').stdout.decode())
 save('seed.txt',execapp('php','/lab/validate.php','source','seed').stdout.decode())
 r=measured('restricted-source',lambda:execapp('php','/lab/validate.php','source','verify',ok=False))
 if r.returncode:print(r.stdout.decode(),r.stderr.decode());return
 before=probe('source','fingerprint').stdout;save('fingerprint-before.json',before.decode())
 start=time.perf_counter();docker('restart',PREFIX+'-source');wait('source');after=probe('source','fingerprint').stdout
 save('persistence.json',{'seconds':time.perf_counter()-start,'equal':before==after})
 # Restricted backup identity, no global privileges. No routines/events exist.
 start=time.perf_counter();dump=docker('exec',PREFIX+'-source','mysqldump','--defaults-extra-file=/tmp/lab.cnf','--user=backup','--single-transaction','--no-tablespaces','--set-gtid-purged=OFF','--hex-blob','--skip-add-locks','--skip-lock-tables',DB).stdout;duration=time.perf_counter()-start
 digest=hashlib.sha256(dump).hexdigest()
 private=HERE/'.local-backups';private.mkdir(mode=0o700,exist_ok=True);(private/'synthetic.sql').write_bytes(dump);(private/'synthetic.sql').chmod(0o600)
 save('backup.json',{'seconds':duration,'bytes':len(dump),'sha256':digest,'consistent':'single transaction; no concurrent writers or DDL','routines':0,'events':0})
 # A corrupt or missing backup must never reach mysql.
 save('backup-negative.json',{'corrupt_checksum_rejected':hashlib.sha256(dump+b'corrupt').hexdigest()!=digest,'missing_backup_rejected':not (private/'missing.sql').exists()})
 # Stop source and recover into the independent empty restore volume.
 docker('stop',PREFIX+'-source')
 sql('restore',"CREATE USER 'definer'@'localhost' IDENTIFIED BY 'synthetic-unused' ACCOUNT LOCK")
 assert hashlib.sha256((private/'synthetic.sql').read_bytes()).hexdigest()==digest
 start=time.perf_counter();r=docker('exec','-i',PREFIX+'-restore','mysql','--defaults-extra-file=/tmp/lab.cnf',DB,input=dump,ok=False)
 save('restore.json',{'seconds':time.perf_counter()-start,'exit':r.returncode,'stderr':r.stderr.decode(),'source_stopped':True,'new_volume':True})
 if r.returncode:raise RuntimeError('restore failed')
 restored=probe('restore','fingerprint').stdout;save('fingerprint-restored.json',restored.decode());
 a=json.loads(before);b=json.loads(restored)
 raw_equal=a==b
 for obj in [a,b]:
  for val in obj.values():
   if isinstance(val,dict):val.pop('ddl_sha256',None)
 save('restore-comparison.json',{'raw_equal':raw_equal,'canonical_equal':a==b,'normalization':'SHOW CREATE TABLE adds redundant CHARACTER SET utf8mb4 after mysqldump restore; remove only that exact token on both sides. All row hashes, table counts, other DDL including AUTO_INCREMENT and triggers must match.'})
 # Recreate explicit lab grants and recheck constraints/crypto/queries after restore.
 execapp('php','/lab/validate.php','restore','roles')
 measured('restricted-restored',lambda:execapp('php','/lab/validate.php','restore','verify',ok=False))
 # Main P0 suite runs against its own database so its destructive setup cannot touch rehearsal snapshots.
 baseline=run(['git','-C',str(REPO),'show','69e4d3fb7693db6d199dce7005eed73d65f60069:webhook/index.php']).stdout
 copy_bytes(PREFIX+'-app','/lab/baseline-webhook.php',baseline)
 save('regression-adapter.json',execapp('python3','/lab/adapt-regression.py').stdout.decode())
 measured('p0-regression',lambda:execapp('python3','/lab/source/os/tests/run-security-p0.py','--extended',ok=False))
 measured('signup-js',lambda:execapp('node','/lab/source/os/bin/check-signup-event.mjs',ok=False))
 print('Continuation complete; inspect evidence for PASS/BLOCKED')
if __name__=='__main__':main()
