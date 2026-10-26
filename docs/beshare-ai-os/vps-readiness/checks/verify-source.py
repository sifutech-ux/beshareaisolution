#!/usr/bin/env python3
"""Offline source gate. Does not connect to DB/Meta or start any service."""
import hashlib
import json
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[4]
manifest = json.loads(Path(__file__).with_name('source-manifest.json').read_text())
def git(*args):
    return subprocess.check_output(['git', '-C', str(root), *args])
subprocess.run(['git','-C',str(root),'merge-base','--is-ancestor',manifest['main'],manifest['security_p0']],check=True)
assert git('rev-parse',manifest['security_p0']+'^{tree}').decode().strip() == manifest['security_tree']
for path, digest in manifest['files'].items():
    assert hashlib.sha256(git('show',manifest['security_p0']+':'+path)).hexdigest() == digest, path
for path in ['os/migrations','os/src/CoexistenceWorker.php','os/src/PhoneOverride.php','os/src/LifecycleBridge.php']:
    assert not git('diff',manifest['main'],manifest['security_p0'],'--',path), path
print('PASS source ancestry, pinned tree,',len(manifest['files']),'file hashes and unchanged migration/worker/override/lifecycle paths')
print('Release approval, built image, target MySQL, production inventory and restore remain separate gates.')
