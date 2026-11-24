#!/usr/bin/env python3
"""Release the entitlement gates on the existing production Copilot services."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time

ROOT = Path('/opt/beshare-copilot-production')
CADDY = Path('/opt/beshare-copilot/deploy/copilot/Caddyfile')
PROXY = 'copilot-copilot-proxy-1'
OVERRIDE = ROOT/'docker-compose.override.yaml'
COMPOSE = ['docker','compose','--project-name','copilot-production','-f',str(ROOT/'docker-compose.yaml'),'-f',str(OVERRIDE)]
FILES = {
    'account': ['pelanggan_akaun','pelanggan_kuota','marketing_media'],
    'marketing-worker': ['marketing_worker'],
    'marketing-group-worker': ['marketing_group_worker'],
}
GROUP_PRODUCTION = 'e1a3a02131216e78a85344f74fc066b724ac7d0fa8c4754da482df8679ce06d4'
BASELINE = 'fb242605ed028f564ce795a0d2895d74d1b6d6ed'


def run(args, data=None):
    return subprocess.run(args, input=data, stdout=subprocess.PIPE, check=True).stdout


def require(value, message):
    if not value:
        raise RuntimeError(message)


def gates(text):
    old = re.search(r'(?m)^(\s*)@copilot path ([^\n]+)\n\s*handle @copilot \{\s*reverse_proxy copilot-production-advice:3000\s*\}',text)
    require(old and set(old[2].split()) == {'/api/copilot/nasihat','/api/penasihat-suara','/api/penasihat-dengar','/api/copilot/bijak','/api/copilot/suara','/api/marketing/draf'},'Review current production advice routes')
    section = ''
    for product,paths in [('marketing','/api/marketing/draf'),('copilot',' '.join(p for p in old[2].split() if p!='/api/marketing/draf'))]:
        section += f"""
    @{product}Access path {paths}
    handle @{product}Access {{
        @needsAuth not method OPTIONS
        forward_auth @needsAuth copilot-production-account:8767 {{
            uri /api/akaun/izin/{product}
        }}
        reverse_proxy copilot-production-advice:3000
    }}"""
    return text[:old.start()]+section+text[old.end():]


def deploy(repo, commit):
    repo = repo.resolve()
    require(run(['git','-C',str(repo),'rev-parse','HEAD']).decode().strip()==commit,'Unexpected release commit')
    rev = commit[:12]
    backup = Path(tempfile.mkdtemp(prefix='product-access-'+rev+'-',dir='/root'))
    cfg = json.loads(run(COMPOSE+['config','--format','json']))['services']
    original = OVERRIDE.read_text()
    proposed = original
    old_proxy = CADDY.read_text()
    new_proxy = gates(old_proxy)
    shutil.copy2(OVERRIDE,backup/'compose.original')
    shutil.copy2(CADDY,backup/'Caddyfile.original')
    for svc, modules in FILES.items():
        live = 'copilot-production-'+svc+'-1'
        row = json.loads(run(['docker','inspect',live]))[0]
        base = row['Config']['Image']
        require(row['State']['Running'] and cfg[svc]['image']==base,'Runtime/config mismatch: '+svc)
        actual_env = dict(x.split('=',1) for x in row['Config']['Env'])
        require(all(actual_env.get(k)==v for k,v in cfg[svc].get('environment',{}).items()),'Review configuration drift: '+svc)
        require(run(['docker','image','inspect','-f','{{.Id}}',base]).decode().strip()==row['Image'],'Image tag changed')
        stage = backup/svc
        stage.mkdir()
        for module in modules:
            fn = module+'.py'
            path = 'copilot/account/'+fn
            old_file = run(['docker','exec',live,'cat','/app/'+fn])
            tracked = run(['git','-C',str(repo),'show',BASELINE+':'+path])
            if old_file == tracked:
                shutil.copy(repo/path,stage/fn)
            else:
                require(module=='marketing_group_worker' and hashlib.sha256(old_file).hexdigest()==GROUP_PRODUCTION,'Baseline changed: '+svc+'/'+fn)
                (stage/fn).write_bytes(old_file)
                patch = run(['git','-C',str(repo),'diff','-U1',BASELINE,commit,'--',path])
                run(['patch','--batch','--fuzz=0',str(stage/fn)],patch)
        shutil.copy(repo/'copilot/account/pelanggan_akses.py',stage/'pelanggan_akses.py')
        (stage/'Dockerfile').write_text('FROM '+base+'\nCOPY *.py /app/\nRUN python -c "import pelanggan_akses,'+','.join(modules)+'"\n')
        tag = 'copilot-access-'+svc+':'+rev
        run(['docker','build','--pull=false','-t',tag,str(stage)])
        proposed, changes = re.subn(r'(?m)^(  '+re.escape(svc)+r':\s*\n    image: )'+re.escape(base)+r'\s*$',r'\g<1>'+tag,proposed)
        require(changes==1,'Review compose image declaration: '+svc)
    (backup/'Caddyfile.new').write_text(new_proxy)
    run(['docker','cp',str(backup/'Caddyfile.new'),PROXY+':/tmp/product-access.caddy'])
    run(['docker','exec',PROXY,'caddy','validate','--adapter','caddyfile','--config','/tmp/product-access.caddy'])
    # Initialize the additive tables with the dedicated account runtime DB guard.
    install = (repo/'copilot/account/pelanggan_akses.py').read_bytes()+b'\nfrom pelanggan_pangkalan import buka\nc=buka()\nschema(c)\nc.commit()\nc.close()\n'
    run(['docker','exec','-i','copilot-production-account-1','python','-'],install)
    try:
        CADDY.write_text(new_proxy)
        run(['docker','exec',PROXY,'caddy','reload','--config','/etc/caddy/Caddyfile','--adapter','caddyfile'])
        OVERRIDE.write_text(proposed)
        run(COMPOSE+['up','-d','--no-deps',*FILES])
        for _ in range(24):
            states = [json.loads(run(['docker','inspect','copilot-production-'+svc+'-1']))[0] for svc in FILES]
            if all(s['State']['Running'] and s['State'].get('Health',{}).get('Status','healthy')=='healthy' and s['Config']['Image'].endswith(':'+rev) for s in states):
                break
            time.sleep(2)
        else:
            raise RuntimeError('Release health checks failed')
        print('DEPLOYED',commit,', '.join(FILES),'and product-specific advice proxy',flush=True)
    except BaseException:
        OVERRIDE.write_text(original)
        CADDY.write_text(old_proxy)
        run(COMPOSE+['up','-d','--no-deps',*FILES])
        run(['docker','exec',PROXY,'caddy','reload','--config','/etc/caddy/Caddyfile','--adapter','caddyfile'])
        raise


if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('repository',type=Path)
    parser.add_argument('commit')
    args=parser.parse_args()
    require(os.geteuid()==0 and re.fullmatch('[a-f0-9]{40}',args.commit),'Root and an exact commit required')
    deploy(args.repository,args.commit)
