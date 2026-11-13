#!/usr/bin/env python3
"""Local disposable MySQL 8.4 diagnostic. Never accepts a production DSN.
Uses a dedicated Colima socket, no host mounts, network=none, no published ports.
Retains stopped containers/volumes for inspection. No global prune or volume delete.
"""
import hashlib,json,os,pathlib,subprocess,sys,time,tarfile,io
HERE=pathlib.Path(__file__).resolve().parent
REPO=HERE.parents[3]
SOCKET=pathlib.Path.home()/'.colima/beshare-rehearsal/docker.sock'
DOCKER=['docker','--host','unix://'+str(SOCKET)]
PREFIX='beshare-mysql84-20261023-r2'
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
 return docker('exec','-i',PREFIX+'-'+target,'mysql','--defaults-extra-file=/tmp/lab.cnf','-N','-B',input=query.encode(),ok=ok)
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
  '-v',name+'-data:/var/lib/mysql','-v',name+'-socket:/var/run/mysqld',img,'--skip-networking','--mysqlx=0','--innodb-buffer-pool-size=128M')
 copy_bytes(name,'/tmp/lab.cnf',b'[client]\nuser=root\npassword=synthetic-unused\nsocket=/var/run/mysqld/mysqld.sock\n')
 # The official image defaults to mysqld.sock; expose a stable socket path to PHP.
 docker('exec',name,'sh','-c','ln -sf mysqld.sock /var/run/mysqld/mysql.sock')
 wait(target)
def main():
 mi=image(MYSQL_PIN);pi=image(PHP_PIN);ai=image('beshare-mysql84-lab:20261023')
 save('images.json',{k:{field:v.get(field) for field in ['Id','RepoDigests','Architecture','Os','Created']} for k,v in [('mysql',mi),('php_base',pi),('lab',ai)]})
 # Refuse collisions rather than reuse a possibly populated target.
 existing=docker('ps','-a','--filter','name='+PREFIX,'--format','{{.Names}}').stdout.decode().strip()
 if existing:raise RuntimeError('existing rehearsal containers: use retained evidence or a distinct reviewed prefix')
 for target in ['source','restore','partial','p0']:startdb(target,mi['RepoDigests'][0])
 mounts=[]
 for target in ['source','restore','partial','p0']:mounts+=['-v',PREFIX+'-'+target+'-socket:/sockets/'+target]
 docker('run','-d','--name',PREFIX+'-app','--label','beshare.disposable=true','--network','none','--restart','no','--memory','768m','--cpus','1','--cap-drop','ALL','--security-opt','no-new-privileges',*mounts,ai['Id'])
 archive=run(['git','-C',str(REPO),'archive',P0,'os','whatsapp','webhook','admin']).stdout
 execapp('mkdir','-p','/lab/source')
 execapp('tar','-xf','-','-C','/lab/source',input=archive)
 for p in HERE.glob('*.php'):copy_bytes(PREFIX+'-app','/lab/'+p.name,p.read_bytes())
 save('version.json',probe('source','version').stdout.decode())
 save('php.txt',execapp('php','-v').stdout.decode()+execapp('php','-r','echo "PDO=".phpversion("pdo_mysql")." mysqlnd=".phpversion("mysqlnd")."\\n";').stdout.decode())
 for target in ['source','restore','partial','p0','app']:
  raw=json.loads(docker('inspect',PREFIX+'-'+target).stdout)[0]
  save('isolation-'+target+'.json',{'network':raw['HostConfig']['NetworkMode'],'ports':raw['HostConfig']['PortBindings'],'mounts':raw['Mounts'],'restart':raw['HostConfig']['RestartPolicy'],'memory':raw['HostConfig']['Memory'],'cpus':raw['HostConfig']['NanoCpus']})
 r=probe('source','apply',ok=False);save('migration-journal.jsonl',r.stdout.decode());save('migration-exit.json',{'exit':r.returncode,'stderr':r.stderr.decode()})
 save('catalog-probe.txt',probe('source','catalog-probe').stdout.decode())
 print('migration exit',r.returncode,flush=True)
 print(r.stdout.decode()[-2500:],flush=True)
if __name__=='__main__':main()
