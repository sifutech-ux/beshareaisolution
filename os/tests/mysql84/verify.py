from lab import *
results=[]
def check(name,condition):
 results.append({'check':name,'pass':bool(condition)})
 save('checks.json',results)
 if not condition:raise AssertionError(name)
def expect(name,code,word,**kwargs):
 r=migrate(**kwargs);check(name,r.returncode==code and word in (r.stdout+r.stderr).decode());return r
sync()
expect('idempotent actual ledger',0,'already_applied')
ledger=sql('source',f'USE {DB}; SELECT version,name,checksum FROM schema_migrations').stdout.decode();save('ledger.txt',ledger)
check('one real checksum row',ledger.strip()=='0001\tfoundation\t'+hashlib.sha256((REPO/'os/migrations/0001_foundation.up.sql').read_bytes()).hexdigest())
sql('source',f"CREATE USER IF NOT EXISTS 'app'@'localhost' IDENTIFIED BY 'synthetic-unused'; GRANT SELECT ON {DB}.* TO 'app'@'localhost'; GRANT INSERT,UPDATE,DELETE ON {DB}.users TO 'app'@'localhost';")
for name,q in [('app DDL denied','CREATE TABLE forbidden (id INT)'),('app ledger insert denied',"INSERT INTO schema_migrations VALUES ('bad','bad',REPEAT('a',64),NOW())"),('app ledger update denied',"UPDATE schema_migrations SET checksum=REPEAT('a',64)"),('app trigger denied','DROP TRIGGER bi_businesses_active_owner')]:
 r=docker('exec','-i',PREFIX+'-source','mysql','--defaults-extra-file=/tmp/lab.cnf','--user=app',DB,input=q.encode(),ok=False);check(name,r.returncode!=0)
save('grants.txt',sql('source',"SHOW GRANTS FOR 'migration'@'localhost'; SHOW GRANTS FOR 'app'@'localhost'").stdout.decode())
# Controlled corruption of disposable fixture, then restore its exact original values.
original=hashlib.sha256((REPO/'os/migrations/0001_foundation.up.sql').read_bytes()).hexdigest()
sql('source',f"USE {DB}; UPDATE schema_migrations SET checksum=REPEAT('a',64)");expect('checksum drift denied',5,'checksum_mismatch')
sql('source',f"USE {DB}; UPDATE schema_migrations SET checksum='{original}'")
sql('source',f"USE {DB}; ALTER TABLE users ALTER CHECK chk_users_status NOT ENFORCED");expect('disabled CHECK denied',4,'check_not_enforced')
sql('source',f"USE {DB}; ALTER TABLE users ALTER CHECK chk_users_status ENFORCED")
sql('source',f"USE {DB}; ALTER TABLE users MODIFY name VARCHAR(190) NOT NULL");expect('column drift denied',5,'definition_mismatch')
sql('source',f"USE {DB}; ALTER TABLE users MODIFY name VARCHAR(191) NOT NULL")
sql('source',f"USE {DB}; ALTER TABLE business_users MODIFY owner_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN role='admin' THEN business_id ELSE NULL END) STORED");expect('generated expression drift denied',5,'definition_mismatch')
sql('source',f"USE {DB}; ALTER TABLE business_users MODIFY owner_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN role='owner' THEN business_id ELSE NULL END) STORED")
expect('post-negative integrity',0,'already_applied')
# Backup binding tampering must stop before DDL.
empty=execapp('cat','/tmp/empty.sql').stdout;proof=execapp('cat','/tmp/empty.proof').stdout
for name,body in [('different server',empty.replace(b'8.4.13',b'8.4.12')),('different database',empty.replace(DB.encode(),b'wrong_database'))]:
 sha=hashlib.sha256(body).hexdigest();p=proof.replace(hashlib.sha256(empty).hexdigest().encode(),sha.encode())
 if name=='different server':p=p.replace(b'8.4.13',b'8.4.12')
 copy_bytes(PREFIX+'-app','/tmp/empty.sql',body);copy_bytes(PREFIX+'-app','/tmp/bad.proof',p)
 r=migrate(proof='/tmp/bad.proof',sha=sha);check(name+' rejected',r.returncode!=0 and ('backup_server_mismatch' if name=='different server' else 'backup_other_database') in r.stderr.decode())
copy_bytes(PREFIX+'-app','/tmp/empty.sql',empty)
expect('wrong checksum rejected',4,'export_checksum_mismatch',sha='a'*64)
# Independent connection owns lock; no DDL or ledger change.
code="$p=new PDO('mysql:unix_socket=/sockets/source/mysql.sock;dbname="+DB+"','root','synthetic-unused');$p->query(\"SELECT GET_LOCK('beshare_os_foundation_0001',0)\");echo 'locked';flush();sleep(5);"
p=subprocess.Popen(DOCKER+['exec',PREFIX+'-app','php','-r',code],stdout=subprocess.PIPE)
assert p.stdout.read(6)==b'locked'
expect('concurrent migration denied',3,'migration_lock_busy');p.wait()
print(json.dumps(results,indent=2))
