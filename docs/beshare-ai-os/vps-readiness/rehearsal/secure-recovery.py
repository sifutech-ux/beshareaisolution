#!/usr/bin/env python3
from lab import *

def main():
 private=HERE/'.local-backups';plain=(private/'synthetic.sql').read_bytes();meta=json.loads((EVID/'backup.json').read_text())
 assert hashlib.sha256(plain).hexdigest()==meta['sha256']
 copy_bytes(PREFIX+'-app','/lab/backup-crypto.php',(HERE/'backup-crypto.php').read_bytes())
 start=time.perf_counter();cipher=execapp('php','/lab/backup-crypto.php','encrypt',input=plain).stdout;enctime=time.perf_counter()-start
 (private/'synthetic.sql.aesgcm').write_bytes(cipher);(private/'synthetic.sql.aesgcm').chmod(0o600)
 digest=hashlib.sha256(cipher).hexdigest()
 negatives={}
 for name,data in [('missing',cipher),('wrong',cipher),('decrypt',cipher[:-1]+bytes([cipher[-1]^1]))]:
  r=execapp('php','/lab/backup-crypto.php',name,input=data,ok=False)
  negatives[name]={'exit':r.returncode,'plaintext_bytes':len(r.stdout),'stderr':r.stderr.decode()}
  assert r.returncode and not r.stdout
 # These gate failures occur before SQL import and cannot mutate the target.
 assert not (private/'missing.sql.aesgcm').exists()
 negatives['checksum_corruption_rejected']=hashlib.sha256(cipher+b'x').hexdigest()!=digest
 start=time.perf_counter();assert hashlib.sha256((private/'synthetic.sql.aesgcm').read_bytes()).hexdigest()==digest
 decrypted=execapp('php','/lab/backup-crypto.php','decrypt',input=cipher).stdout
 assert hashlib.sha256(decrypted).hexdigest()==meta['sha256'];dectime=time.perf_counter()-start
 recovery_start=time.perf_counter()
 startdb('recovery',image(MYSQL_PIN)['RepoDigests'][0])
 sql('recovery',"CREATE USER 'definer'@'localhost' IDENTIFIED BY 'synthetic-unused' ACCOUNT LOCK")
 start=time.perf_counter();docker('exec','-i',PREFIX+'-recovery','mysql','--defaults-extra-file=/tmp/lab.cnf',DB,input=decrypted);importtime=time.perf_counter()-start
 app=PREFIX+'-recovery-app';docker('run','-d','--name',app,'--label','beshare.disposable=true','--network','none','--restart','no','--memory','512m','--cpus','1','--cap-drop','ALL','--security-opt','no-new-privileges','-v',PREFIX+'-recovery-socket:/sockets/restore',image('beshare-mysql84-lab:20261023')['Id'])
 archive=execapp('tar','-cf','-','-C','/lab','source','probe.php','validate.php').stdout;docker('exec','-i',app,'tar','-xf','-','-C','/lab',input=archive)
 restored=docker('exec',app,'php','/lab/probe.php','restore','fingerprint').stdout
 a=json.loads((EVID/'fingerprint-before.json').read_text());b=json.loads(restored)
 for obj in [a,b]:
  for val in obj.values():
   if isinstance(val,dict):val.pop('ddl_sha256',None)
 assert a==b,'encrypted recovery fingerprint mismatch'
 docker('exec',app,'php','/lab/validate.php','restore','roles')
 check=docker('exec',app,'php','/lab/validate.php','restore','verify',ok=False)
 save('encrypted-recovery.json',{'cipher_bytes':len(cipher),'cipher_sha256':digest,'plain_sha256':meta['sha256'],'encrypt_seconds':enctime,'decrypt_seconds':dectime,'import_seconds':importtime,'new_volume_start_import_validation_seconds':time.perf_counter()-recovery_start,'canonical_fingerprint_equal':a==b,'checks_exit':check.returncode,'checks_stdout':check.stdout.decode(),'checks_stderr':check.stderr.decode(),'negative_tests':negatives,'scope':'local synthetic recovery only; key remains in disposable app; no offsite custody or production RPO/RTO claim'})
 print('Encrypted recovery complete')
if __name__=='__main__':main()
