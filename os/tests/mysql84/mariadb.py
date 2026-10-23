import lab
from pathlib import Path
lab.PREFIX='beshare-migrator-maria-20261023-r1'
lab.EVID=lab.HERE/'evidence-mariadb';lab.EVID.mkdir(exist_ok=True)
lab.MYSQL_PIN='mariadb@sha256:f16d7a344413e519dff518f27bdb221388f84b0d274c97c4fb5f2cb0dc1b34fa'
original=lab.docker
def docker(*args,**kwargs):
 args=[{'mysql':'mariadb','mysqldump':'mariadb-dump'}.get(a,a) for a in args if a not in ['--mysqlx=0','--set-gtid-purged=OFF']]
 return original(*args,**kwargs)
lab.docker=docker
if __name__=='__main__':
 lab.main()
 r=lab.migrate();lab.save('rerun.json',{'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode()});assert r.returncode==0 and b'already_applied' in r.stdout
 lab.save('ledger.txt',lab.sql('source',f'USE {lab.DB}; SELECT version,name,checksum FROM schema_migrations').stdout.decode())
 print('MariaDB foundation + idempotent ledger PASS')
