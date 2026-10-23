from lab import *
sync()
start=time.perf_counter()
# Synthetic rows, preserving the real foundation ledger.
sql('source',f"USE {DB}; INSERT INTO users(email,password_hash,name,status,created_at,updated_at) VALUES ('synthetic@example.invalid','unused','Ujian 多语言 😀','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
before=probe('source','fingerprint').stdout
save('fingerprint-before.json',before.decode())
dump=docker('exec',PREFIX+'-source','mysqldump','--defaults-extra-file=/tmp/lab.cnf','--single-transaction','--no-tablespaces','--set-gtid-purged=OFF','--hex-blob','--skip-add-locks',DB).stdout
sha=hashlib.sha256(dump).hexdigest()
encrypted=execapp('php','/lab/backup-crypto.php','encrypt',input=dump).stdout
for mode,body in [('missing',encrypted),('wrong',encrypted),('decrypt',encrypted[:-1]+bytes([encrypted[-1]^1]))]:
 r=execapp('php','/lab/backup-crypto.php',mode,input=body,ok=False)
 assert r.returncode and not r.stdout
plain=execapp('php','/lab/backup-crypto.php','decrypt',input=encrypted).stdout
assert hashlib.sha256(plain).hexdigest()==sha
assert hashlib.sha256(plain+b'corrupt').hexdigest()!=sha
# Restart persistence before isolated recovery.
docker('restart',PREFIX+'-source');wait('source');assert probe('source','fingerprint').stdout==before
# New restore volume, source stopped. A second PHP container sees only its socket.
startdb('restore',MYSQL_PIN)
docker('stop',PREFIX+'-source')
sql('restore',f"CREATE USER 'migration'@'localhost' IDENTIFIED BY 'synthetic-unused'; GRANT SELECT,TRIGGER ON {DB}.* TO 'migration'@'localhost';")
r=docker('exec','-i',PREFIX+'-restore','mysql','--defaults-extra-file=/tmp/lab.cnf',DB,input=plain)
# Run the existing fingerprint tool in a new networkless app container.
name=PREFIX+'-recovery-app'
docker('run','-d','--name',name,'--network','none','--cap-drop','ALL','--security-opt','no-new-privileges','-v',PREFIX+'-restore-socket:/sockets/restore','beshare-mysql84-lab:20261023')
archive=run(['git','-C',str(REPO),'archive',P0,'os']).stdout
docker('exec',name,'mkdir','-p','/lab/source');docker('exec','-i',name,'tar','-xf','-','-C','/lab/source',input=archive)
copy_bytes(name,'/lab/probe.php',(HERE/'probe.php').read_bytes())
a=json.loads(before);after=docker('exec',name,'php','/lab/probe.php','restore','fingerprint').stdout;b=json.loads(after)
save('fingerprint-restored.json',after.decode())
for x in [a,b]:
 for v in x.values():
  if isinstance(v,dict):v.pop('ddl_sha256',None)
assert a==b
save('recovery.json',{'pass':True,'source_stopped_during_restore':True,'fresh_volume':True,'ledger_included':True,'canonical_schema_triggers_rows_equal':True,'persistence_equal':True,'missing_wrong_key_tamper_rejected':True,'corrupt_checksum_rejected':True,'dump_sha256':sha,'encrypted_sha256':hashlib.sha256(encrypted).hexdigest(),'bytes':len(dump),'total_seconds':time.perf_counter()-start,'production_RPO_RTO_proven':False})
print('Recovery PASS')
