#!/usr/bin/env python3
"""Dedicated local no-network disposable MySQL; never accepts a remote DSN."""
import importlib.util,pathlib,json
HERE=pathlib.Path(__file__).resolve().parent
s=importlib.util.spec_from_file_location('lab',HERE.parent/'mysql84/lab.py');l=importlib.util.module_from_spec(s);s.loader.exec_module(l)
l.PREFIX='beshare-forward-20261023-reference';l.EVID=HERE/'evidence';l.EVID.mkdir(exist_ok=True)
names=[]
def setup(suffix):
 l.PREFIX='beshare-forward-20261023-'+suffix;names.extend([l.PREFIX+'-source',l.PREFIX+'-app']);l.main()
 l.execapp('mkdir','-p','/lab/source/os/mvp')
 for f in (l.REPO/'os/mvp').glob('*.php'):l.copy_bytes(l.PREFIX+'-app','/lab/source/os/mvp/'+f.name,f.read_bytes())
 l.copy_bytes(l.PREFIX+'-app','/lab/ledger-lab.php',(HERE/'ledger-lab.php').read_bytes())
try:
 setup('reference');manifest=l.execapp('php','/lab/ledger-lab.php','capture').stdout;l.save('mysql84-ledger-manifest.json',manifest.decode())
 for mode in ['apply','partial']:
  setup(mode);l.copy_bytes(l.PREFIX+'-app','/lab/manifest.json',manifest)
  r=l.execapp('php','/lab/ledger-lab.php',mode,ok=False);l.save('ledger-'+mode+'.json',{'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode()});print(mode,r.returncode,r.stdout.decode(),r.stderr.decode());assert r.returncode==0
finally:
 for name in names:l.docker('stop',name,ok=False)
 l.save('shutdown.json',{'names':names,'running':l.docker('ps','--filter','name=beshare-forward-20261023','--format','{{.Names}}').stdout.decode()})
