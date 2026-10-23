import sys,json
import lab
if 'maria' in sys.argv:
 import mariadb
lab.docker('start',lab.PREFIX+'-source');lab.wait('source');lab.sync()
# Capture exact foundation catalog before historical regression suites replace fixture data.
r=lab.execapp('php','/lab/catalog.php');lab.save('catalog.json',r.stdout.decode())
if 'maria' not in sys.argv:
 x=json.loads(r.stdout);(lab.REPO/'os/tests/mysql-8413-foundation.json').write_text(json.dumps(x['rows'],indent=2)+'\n')
# Historical suites initialize their own schema: use a separate empty volume.
lab.docker('stop',lab.PREFIX+'-source')
lab.PREFIX += '-regression'
lab.startdb('source',lab.MYSQL_PIN)
lab.docker('run','-d','--name',lab.PREFIX+'-app','--network','none','--cap-drop','ALL','--security-opt','no-new-privileges','-v',lab.PREFIX+'-source-socket:/sockets/source','beshare-mysql84-lab:20261023')
lab.sync()
base=lab.run(['git','-C',str(lab.REPO),'show','69e4d3fb7693db6d199dce7005eed73d65f60069:webhook/index.php']).stdout
lab.copy_bytes(lab.PREFIX+'-app','/lab/baseline-webhook.php',base)
adapter=(lab.HERE/'adapt-regression.py').read_text().replace('/sockets/p0/mysql.sock','/sockets/source/mysql.sock')
if 'maria' in sys.argv:adapter=adapter.replace(".replace('NO_AUTO_CREATE_USER,','')",'')
lab.copy_bytes(lab.PREFIX+'-app','/lab/adapt-regression.py',adapter.encode());lab.execapp('python3','/lab/adapt-regression.py')
r=lab.execapp('python3','/lab/source/os/tests/run-security-p0.py','--extended',ok=False);lab.save('regression.json',{'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode()});print(r.returncode,r.stdout.decode()[-1800:],r.stderr.decode()[-800:]);assert r.returncode==0
r=lab.execapp('node','/lab/source/os/bin/check-signup-event.mjs',ok=False);lab.save('signup.json',{'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode()});assert r.returncode==0
