#!/usr/bin/env python3
"""Disposable PHP + MySQL-compatible MariaDB HTTP tests. No external DB accepted.
Requires python3, php (pdo_mysql/mbstring), mariadbd, mariadb-install-db, mariadb.
Creates a new private datadir, disables DB networking, binds HTTP to loopback,
uses synthetic config/session/data, and terminates both processes on exit.
"""
import base64
import hashlib
import hmac
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[2]
PHP = shutil.which('php')
DB = shutil.which('mariadbd')
INIT = shutil.which('mariadb-install-db')
CLIENT = shutil.which('mariadb')
assert all([PHP, DB, INIT, CLIENT]), 'Install PHP with pdo_mysql/mbstring and MariaDB; put their bin directories on PATH'
count = 0

def check(name, condition):
    global count
    assert condition, name
    count += 1
    print('OK', name, flush=True)

def wait_until(fn):
    for _ in range(200):
        if fn():
            return
        time.sleep(.1)
    raise RuntimeError('local lab did not start')

with tempfile.TemporaryDirectory(prefix='beshare-p0-', dir='/tmp') as temp:
    lab = Path(temp)
    data = lab / 'db'
    sock = lab / 'db.sock'
    store = lab / 'store'
    sessions = lab / 'sessions'
    store.mkdir(mode=0o700)
    sessions.mkdir(mode=0o700)
    env = {k: v for k, v in os.environ.items() if not k.startswith('BESHARE_')}
    env.update(BESHARE_P0_LAB='disposable', BESHARE_STOR=str(store),
               BESHARE_MUAT=str(lab / 'uploads'), BESHARE_OS_WEBHOOK_CONFIG=str(lab / 'private.php'),
               BESHARE_OS_DB_CONFIG=str(lab / 'database.php'), BESHARE_OS_RATE_DIR=str(lab / 'rates'),
               BESHARE_OS_DSN=f'mysql:unix_socket={sock};dbname=u879723783_beshare_os;charset=utf8mb4')
    processes = []
    log = open(lab / 'process.log', 'w+')
    try:
        subprocess.run([INIT, '--no-defaults', f'--datadir={data}', '--auth-root-authentication-method=normal', '--skip-test-db'], check=True, stdout=log, stderr=log, env=env)
        processes.append(subprocess.Popen([DB, '--no-defaults', f'--datadir={data}', f'--socket={sock}', '--skip-networking', f'--pid-file={lab}/db.pid', f'--log-error={lab}/db.log'], stdout=log, stderr=log, env=env))
        wait_until(lambda: sock.exists())
        subprocess.run([CLIENT, '--no-defaults', f'--socket={sock}', '-uroot', '-e', "CREATE DATABASE u879723783_beshare_os CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; ALTER USER 'root'@'localhost' IDENTIFIED BY 'synthetic-unused'"], check=True, stdout=log, stderr=log)
        php_args = [PHP, '-d', f'session.save_path={sessions}', '-d', 'disable_functions=curl_exec', '-d', 'allow_url_fopen=0']
        def fixture(*args):
            return subprocess.check_output(php_args + [str(ROOT / 'os/tests/p0-fixture.php'), *args], env=env, text=True).strip()
        ids = json.loads(fixture('seed'))
        private = {'app_secret': 'synthetic-app-secret', 'verify_token': 'synthetic-verify', 'token_key': base64.b64encode(b'x' * 32).decode(),
                   'legacy_business_id': str(ids['business_a']), 'legacy_waba_id': '100000000001'}
        def config(values):
            (lab / 'private.php').write_text('<?php return ' + '[' + ','.join(repr(k) + '=>' + repr(v) for k, v in values.items()) + '];')
        config(private)
        (lab / 'database.php').write_text("<?php return ['dsn'=>" + repr(env['BESHARE_OS_DSN']) + ", 'user'=>'root', 'password'=>'synthetic-unused'];")
        (store / 'meja.json').write_text(json.dumps({'bank_name': 'PRIVATE_BANK_SENTINEL', 'account_no': 'PRIVATE_ACCOUNT', 'account_holder': 'Synthetic', 'token_meta': 'PRIVATE_TOKEN_SENTINEL'}))
        (store / 'perbualan.json').write_text(json.dumps({'perbualan': {'60120000000': [{'role':'user','content':'PRIVATE_CHAT_SENTINEL','masa':'lab'}]}, 'pesanan':[{'phone':'60120000000','time':'lab','details':'PRIVATE_ORDER_SENTINEL'}], 'jeda': []}))
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        processes.append(subprocess.Popen(php_args + ['-S', f'127.0.0.1:{port}', '-t', str(ROOT)], env=env, stdout=log, stderr=log))
        def ready():
            try:
                with socket.create_connection(('127.0.0.1', port), .1):
                    return True
            except OSError:
                return False
        wait_until(ready)
        def request(path, method='GET', body=b'', headers=None):
            conn = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
            conn.request(method, path, body, headers or {})
            response = conn.getresponse()
            result = (response.status, response.read().decode(), dict(response.getheaders()))
            conn.close()
            return result
        def snapshot():
            return {p.name: p.read_bytes() for p in store.iterdir() if p.is_file()}, fixture('snapshot')
        def signature(raw):
            return 'sha256=' + hmac.new(private['app_secret'].encode(), raw, hashlib.sha256).hexdigest()
        def envelope(waba='100000000001', phone='1443799975474056', mid='synthetic-1'):
            return json.dumps({'object':'whatsapp_business_account','entry':[{'id':waba,'changes':[{'field':'messages','value':{'metadata':{'phone_number_id':phone},'messages':[{'id':mid,'from':'60120000001','type':'text','text':{'body':'Synthetic incoming'}}]}}]}]}).encode()
        raw = envelope()
        before = snapshot()
        for path in ['/webhook/', '/whatsapp/sambungan/webhook.php']:
            for label, payload, sig in [('unsigned',raw,''), ('tampered',raw+b' ',signature(raw)), ('malformed',raw,'sha256=bad'), ('wrong-secret',raw,'sha256='+'0'*64), ('unsigned-invalid-json',b'{','')]:
                status, body, _ = request(path,'POST',payload,{'X-Hub-Signature-256':sig})
                check(path+' '+label+' rejected',status==403)
                check(label+' no storage/DB effects',snapshot()==before)
            status, _, _ = request(path,'PUT',raw)
            check(path+' wrong method', status==405)
            status, _, _ = request(path,'POST',b'{',{'X-Hub-Signature-256':signature(b'{')})
            check(path+' signed malformed JSON', status==400)
        for label, payload in [('wrong WABA',envelope(waba='999999999999')),('wrong phone',envelope(phone='999999999999')),('excluded phone',envelope(phone='1207159062490441'))]:
            status, _, _ = request('/webhook/','POST',payload,{'X-Hub-Signature-256':signature(payload)})
            check(label+' ignored without side effects',status==200 and snapshot()==before)
        check('legacy signed intake', request('/webhook/','POST',raw,{'X-Hub-Signature-256':signature(raw)})[0]==200)
        conversations = json.loads((store/'perbualan.json').read_text())['perbualan']
        check('only inbound message recorded',conversations['60120000001']==[dict(role='user',content='Synthetic incoming',masa=conversations['60120000001'][0]['masa'])])
        after = snapshot()
        request('/webhook/','POST',raw,{'X-Hub-Signature-256':signature(raw)})
        check('signed replay deduplicated',snapshot()==after)
        check('transport hard OFF',fixture('transport')=='OFF')
        empty = b'{"object":"whatsapp_business_account","entry":[]}'
        check('OS signed empty event',request('/whatsapp/sambungan/webhook.php','POST',empty,{'X-Hub-Signature-256':signature(empty)})[0]==200)
        for waba, expected in [('999999999999',None), ('',None), ('100000000001',ids['business_a'])]:
            payload=envelope(waba=waba,mid='os-shared-id')
            check('OS signed message accepted',request('/whatsapp/sambungan/webhook.php','POST',payload,{'X-Hub-Signature-256':signature(payload)})[0]==200)
            events=json.loads(fixture('events'))
            check('OS WABA and phone jointly scope tenant',events[-1]['business_id']==expected)
        check('OS GET challenge preserved',request('/whatsapp/sambungan/webhook.php?hub.mode=subscribe&hub.verify_token=synthetic-verify&hub.challenge=123')[0:2]==(200,'123'))
        check('legacy wrong challenge refused',request('/webhook/?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123')[0]==403)
        # Legacy verifier hash stays byte-for-byte unchanged; never use its real secret.
        original = subprocess.check_output(['git','show','origin/main:webhook/index.php'],cwd=ROOT,text=True)
        current = (ROOT/'webhook/index.php').read_text()
        check('legacy GET block unchanged',original[original.index("if (($_SERVER['REQUEST_METHOD']"):original.index("header('Content-Type: application/json")]==current[current.index("if (($_SERVER['REQUEST_METHOD']"):current.index("header('Content-Type: application/json")])
        config({k:v for k,v in private.items() if k!='app_secret'})
        before = snapshot()
        for path in ['/webhook/', '/whatsapp/sambungan/webhook.php']:
            check(path+' missing secret fails closed',request(path,'POST',raw,{'X-Hub-Signature-256':signature(raw)})[0]==503)
        check('missing secret no effects',snapshot()==before)
        (lab/'private.php').write_text("<?php echo 'SECRET_CONFIG_SENTINEL'; throw new RuntimeException('SECRET_CONFIG_SENTINEL');")
        for path in ['/webhook/', '/whatsapp/sambungan/webhook.php']:
            status,body,_=request(path,'POST',raw)
            check('throwing config fails closed without leak',status==503 and 'SECRET_CONFIG_SENTINEL' not in body)
        config(private)
        actors={role:json.loads(fixture('session',role)) for role in ['a','b','admin','staff','disabled']}
        pages=['/whatsapp/meja/','/whatsapp/meja/perbualan/','/whatsapp/meja/pesanan/']
        def post(path, actor, fields):
            return request(path,'POST',urllib.parse.urlencode(fields).encode(),{'Cookie':actor['cookie'],'Content-Type':'application/x-www-form-urlencoded'})
        for page in pages:
            for role,expected in [('anonymous',401),('b',403),('staff',403),('disabled',401),('a',200),('admin',200)]:
                status,body,_=request(page,headers={} if role=='anonymous' else {'Cookie':actors[role]['cookie']})
                check(page+' '+role,status==expected)
                if expected!=200:
                    check('denial contains no sensitive form or data',all(x not in body for x in ['PRIVATE_','<form','token_meta']))
        before=snapshot()
        for page,actions in [(pages[0],['bank','token','skrip','latih']), (pages[1],['jeda','sambung','balas']), (pages[2],['bank'])]:
            for action in actions:
                fields={'tindakan':action,'csrf':actors['b']['csrf'],'telefon':'60120000000','prompt_text':'attacker','bank_name':'attacker'}
                check('anonymous '+action,request(page,'POST',urllib.parse.urlencode(fields).encode(),{'Content-Type':'application/x-www-form-urlencoded'})[0]==401)
                check('cross tenant '+action,post(page,actors['b'],fields)[0]==403)
            for token in ['', 'wrong']:
                check(page+' CSRF rejected',post(page,actors['admin'],{'tindakan':actions[0],'csrf':token})[0]==403)
        for key in ['business_id','tenant_id','phone_number_id','waba_id']:
            check('reject client scope '+key,post(pages[0],actors['admin'],{'tindakan':'skrip','csrf':actors['admin']['csrf'],'prompt_text':'bad',key:'2'})[0]==403)
        for action in ['unknown','latih','balas','sambung']:
            check('disallowed action '+action,post(pages[0] if action=='latih' else pages[1],actors['admin'],{'tindakan':action,'csrf':actors['admin']['csrf']})[0]==403)
        check('all denied actions unchanged',snapshot()==before)
        check('admin authorized write',post(pages[0],actors['admin'],{'tindakan':'skrip','csrf':actors['admin']['csrf'],'prompt_text':'Approved synthetic script'})[0]==303)
        check('authorized script saved',json.loads((store/'meja.json').read_text())['prompt']=='Approved synthetic script')
        check('owner pause allowed',post(pages[1],actors['a'],{'tindakan':'jeda','csrf':actors['a']['csrf'],'telefon':'60120000000'})[0]==303)
        check('pause saved', '60120000000' in json.loads((store/'perbualan.json').read_text())['jeda'])
        for action in ['bank','token']:
            fields={'tindakan':action,'csrf':actors['admin']['csrf'],'bank_name':'Synthetic Bank','account_no':'123','account_holder':'Synthetic','token_meta':'X'*48}
            check('admin allowed '+action,post(pages[0],actors['admin'],fields)[0]==303)
        before=snapshot()
        check('staff forged role cannot POST',post(pages[0],actors['staff'],{'tindakan':'skrip','csrf':actors['staff']['csrf'],'prompt_text':'bad'})[0]==403)
        check('staff denial unchanged',snapshot()==before)
        dbconfig=(lab/'database.php').read_text()
        (lab/'database.php').write_text("<?php echo 'SECRET_DB_SENTINEL'; throw new RuntimeException('SECRET_DB_SENTINEL');")
        status,body,_=request(pages[0],headers={'Cookie':actors['admin']['cookie']})
        check('throwing DB config no leak',status==503 and 'SECRET_DB_SENTINEL' not in body)
        (lab/'database.php').write_text(dbconfig)
        fixture('disable-admin')
        check('revoked identity rejected next request',request(pages[0],headers={'Cookie':actors['admin']['cookie']})[0]==401)
        config({k:v for k,v in private.items() if k!='legacy_business_id'})
        before=snapshot()
        check('legacy missing binding requests retry',request('/webhook/','POST',raw,{'X-Hub-Signature-256':signature(raw)})[0]==503)
        check('missing binding no intake effects',snapshot()==before)
        check('missing tenant binding fails closed',request(pages[0],headers={'Cookie':actors['a']['cookie']})[0]==503)
        config(private)
        fixture('suspend')
        check('suspended tenant rejected',request(pages[0],headers={'Cookie':actors['a']['cookie']})[0]==403)
        print(f'PASS {count} checks; synthetic, socket-only DB; outbound disabled',flush=True)
        if '--extended' in __import__('sys').argv:
            copied=lab/'repo'
            for directory in ['os','whatsapp','webhook','admin']:
                shutil.copytree(ROOT/directory,copied/directory,ignore=shutil.ignore_patterns('__pycache__'))
            pw=lab/'lab.pw'
            pw.write_text('synthetic-unused')
            pw.chmod(0o600)
            for source in (copied/'os/bin').glob('*.php'):
                source.write_text(source.read_text().replace('/tmp/maria-lab.pw',str(pw)).replace('host=127.0.0.1;port=3307',f'unix_socket={sock}'))
            extended_env={k:v for k,v in env.items() if not k.startswith('BESHARE_')}
            # Existing suites use mocked Meta transports, and local curl for HTTP.
            # Fail outbound PHP curl/file HTTP if any code accidentally invokes it.
            ini=lab/'php.ini'
            ini.write_text('disable_functions=curl_exec\nallow_url_fopen=0\n')
            extended_env['PHP_INI_SCAN_DIR']=str(lab/'ini')
            (lab/'ini').mkdir()
            shutil.copy(ini,lab/'ini'/'p0.ini')
            for suite in ['check-auth.php','check-meta.php','check-coexistence.php','check-worker.php','check-override.php','check-lifecycle.php']:
                result=subprocess.run([PHP,str(copied/'os/bin'/suite)],cwd=copied,env=extended_env,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=120)
                print(suite+': '+str(result.returncode)+'; '+str(sum(line.startswith('OK ') for line in result.stdout.splitlines()))+' checks',flush=True)
                if result.returncode:
                    print(result.stdout)
                    raise AssertionError(suite)

    except Exception:
        log.flush()
        print('Lab failed; process diagnostics:')
        print((lab/'db.log').read_text()[-2000:] if (lab/'db.log').exists() else '')
        log.seek(0)
        print(log.read()[-3000:])
        raise
    finally:
        for process in reversed(processes):
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        log.close()
