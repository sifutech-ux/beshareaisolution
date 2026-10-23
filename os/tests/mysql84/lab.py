#!/usr/bin/env python3
"""Local disposable MySQL 8.4 diagnostic. Never accepts a production DSN.
Uses a dedicated Colima socket, no host mounts, network=none, no published ports.
Retains stopped containers/volumes for inspection. No global prune or volume delete.
"""
import hashlib,json,os,pathlib,subprocess,sys,time,tarfile,io
HERE=pathlib.Path(__file__).resolve().parent
REPO=HERE.parents[2]
SOCKET=pathlib.Path.home()/'.colima/beshare-rehearsal/docker.sock'
DOCKER=['docker','--host','unix://'+str(SOCKET)]
PREFIX='beshare-migrator-20261023-r3'
DB='u879723783_beshare_os'
MYSQL_PIN='mysql@sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69'
PHP_PIN='php@sha256:cdb77e306d6bb99db40e513fbe036ecc6c53b575cbcb7e581387845fef26d755'
P0='c955270cf393c435bc51356ce9f261eda4ceb2fb'
EVID=HERE/'evidence';EVID.mkdir(exist_ok=True)
def run(args,input=None,ok=True):
 r=subprocess.run(args,input=input,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 if ok and r.returncode: raise RuntimeError(str(args[:5])+': '+r.stderr.decode()[-3000:])
 return r
def docker(*args,input=None,ok=True):return run(DOCKER+list(args),input,ok)
def save(name,value):
 (EVID/name).write_text(json.dumps(value,indent=2)+'\n' if not isinstance(value,str) else value)
def image(tag):return json.loads(docker('image','inspect',tag).stdout)[0]
def execapp(*args,ok=True,input=None):return docker('exec','-i',PREFIX+'-app',*args,input=input,ok=ok)
def probe(target,mode,*args,ok=True):return execapp('php','/lab/probe.php',target,mode,*args,ok=ok)
def sql(target,query,ok=True):
 return docker('exec','-i',PREFIX+'-'+target,'mysql','--defaults-extra-file=/tmp/lab.cnf','-N','-B',input=("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; "+query).encode(),ok=ok)
def copy_bytes(container,path,data):
 archive=io.BytesIO()
 with tarfile.open(fileobj=archive,mode='w') as tar:
  info=tarfile.TarInfo(path.lstrip('/'));info.size=len(data);info.mode=0o600;tar.addfile(info,io.BytesIO(data))
 docker('cp','-',container+':/',input=archive.getvalue())
def wait(target):
 for _ in range(180):
  if sql(target,'SELECT 1',ok=False).returncode==0:return
  time.sleep(1)
 raise RuntimeError('database readiness timeout: '+target)
def startdb(target,img):
 name=PREFIX+'-'+target
 for suffix in ['data','socket']:docker('volume','create','--label','beshare.disposable=true',name+'-'+suffix)
 docker('run','-d','--name',name,'--label','beshare.disposable=true','--network','none','--restart','no','--memory','768m','--cpus','1','--security-opt','no-new-privileges',
  '-e','MYSQL_ROOT_PASSWORD=synthetic-unused','-e','MYSQL_DATABASE='+DB,
  '-v',name+'-data:/var/lib/mysql','-v',name+'-socket:/var/run/mysqld',img,'--skip-networking','--mysqlx=0','--innodb-buffer-pool-size=128M','--log-bin-trust-function-creators=1')
 copy_bytes(name,'/tmp/lab.cnf',b'[client]\nuser=root\npassword=synthetic-unused\nsocket=/var/run/mysqld/mysqld.sock\n')
 # The official image defaults to mysqld.sock; expose a stable socket path to PHP.
 docker('exec',name,'sh','-c','ln -sf mysqld.sock /var/run/mysqld/mysql.sock')
 wait(target)
def sync():
 archive=run(['git','-C',str(REPO),'archive',P0,'os','whatsapp','webhook','admin']).stdout
 execapp('mkdir','-p','/lab/source')
 execapp('tar','-xf','-','-C','/lab/source',input=archive)
 for p in (REPO/'os/src').glob('*.php'):copy_bytes(PREFIX+'-app','/lab/source/os/src/'+p.name,p.read_bytes())
 for p in HERE.glob('*.php'):copy_bytes(PREFIX+'-app','/lab/'+p.name,p.read_bytes())
def migrate(user='migration',proof='/tmp/empty.proof',sha=None):
 if sha is None:sha=(EVID/'empty.sha256').read_text().strip()
 return docker('exec','-e','BESHARE_OS_DSN=mysql:unix_socket=/sockets/source/mysql.sock;dbname='+DB+';charset=utf8mb4','-e','BESHARE_OS_DB_USER='+user,'-e','BESHARE_OS_DB_PASSWORD=synthetic-unused',PREFIX+'-app','php','/lab/source/os/bin/migrate.php','apply','--confirm-foundation-0001','--export=/tmp/empty.sql','--export-sha256='+sha,'--backup-proof='+proof,ok=False)
def main(apply=True):
 if docker('ps','-a','--filter','name='+PREFIX,'--format','{{.Names}}').stdout.strip():raise RuntimeError('Use a new prefix; existing resources retained')
 startdb('source',MYSQL_PIN)
 docker('run','-d','--name',PREFIX+'-app','--network','none','--restart','no','--memory','768m','--cpus','1','--cap-drop','ALL','--security-opt','no-new-privileges','-v',PREFIX+'-source-socket:/sockets/source','beshare-mysql84-lab:20261023')
 sync()
 sql('source',f"CREATE USER 'migration'@'localhost' IDENTIFIED BY 'synthetic-unused'; GRANT SELECT, INSERT, CREATE, ALTER, INDEX, REFERENCES, TRIGGER, EVENT, SHOW VIEW ON {DB}.* TO 'migration'@'localhost';")
 empty=docker('exec',PREFIX+'-source','mysqldump','--defaults-extra-file=/tmp/lab.cnf','--no-data','--routines','--events','--no-tablespaces','--set-gtid-purged=OFF',DB).stdout
 version=sql('source','SELECT VERSION()').stdout.decode().strip();sha=hashlib.sha256(empty).hexdigest()
 proof=f'database={DB}\nserver_version={version}\nexport_sha256={sha}\ntables=0\ntriggers=0\nroutines=0\nevents=0\nviews=0\nschema_migrations_rows=0\n'
 copy_bytes(PREFIX+'-app','/tmp/empty.sql',empty);copy_bytes(PREFIX+'-app','/tmp/empty.proof',proof.encode());save('empty.sha256',sha)
 if not apply:return
 r=migrate();save('foundation.json',{'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode()});print(r.returncode,r.stdout.decode(),r.stderr.decode())
if __name__=='__main__':main()
