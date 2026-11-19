#!/usr/bin/env python3
"""Guarded account-only rollout for the BeShare Marketing upload feature.

Run prepare with an exact reviewed Git commit/tree, then apply its backup path.
All raw Docker/configuration output remains in a root-only backup directory.
"""
import argparse
import copy
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile

ROOT = Path('/opt/beshare-copilot-production')
BASE = ROOT / 'docker-compose.yaml'
OVERRIDE = ROOT / 'docker-compose.override.yaml'
COMPOSE = ['docker', 'compose', '--project-name', 'copilot-production', '-f', str(BASE), '-f', str(OVERRIDE)]
NAME = 'copilot-production-account-1'
OLD_TAG = 'copilot-account:marketing-ai-recovery-8f28db7'
OLD_ID = 'sha256:3976f87f4a36b38e9fdf15f3a9b2cb65c2b5381639197d7022108076b51902cb'
STATUS = Path('/root/beshare-media-release-status.json')
STORAGE = ROOT / 'marketing-media'
TARGET = '/var/lib/beshare-marketing-media'
MEDIA_ENV = {'MARKETING_MEDIA_ENABLED': '1', 'MARKETING_MEDIA_ROOT': TARGET,
             'MARKETING_MEDIA_PUBLIC_BASE_URL': 'https://akaun.beshareaisolution.com'}
FILES = ('akaun_pelayan.py', 'pelanggan_marketing.py', 'marketing_media.py')
BACKUP = None


def need(ok, message):
    if not ok:
        raise RuntimeError(message)


def save(path, data):
    path.write_bytes(data if isinstance(data, bytes) else data.encode())
    path.chmod(0o600)


def progress(phase, **data):
    save(STATUS, json.dumps({'phase': phase, 'backup': str(BACKUP), **data}, sort_keys=True))


def run(args, label, *, data=None, cwd=None, timeout=60, binary=False):
    try:
        r = subprocess.run(args, input=data, cwd=cwd, capture_output=True, text=not binary, timeout=timeout)
    except subprocess.TimeoutExpired:
        raise RuntimeError(label + ' timed out; no next step was run.') from None
    save(BACKUP / (label + '.log'), r.stdout + (b'\n' if binary else '\n') + r.stderr)
    need(r.returncode == 0, label + ' failed; details are in the private log.')
    return r.stdout


def inspect(name):
    return json.loads(run(['docker', 'inspect', name], 'inspect-' + name.replace(':', '-')))[0]


def env(row):
    return dict(value.split('=', 1) for value in row['Config'].get('Env', []))


def inventory():
    ids = run(['docker', 'ps', '-q'], 'container-list').split()
    rows = json.loads(run(['docker', 'inspect', *ids], 'container-inventory'))
    return {row['Name']: [row['Id'], row['State']['StartedAt']] for row in rows if row['Name'].lstrip('/') != NAME}


def config():
    return json.loads(run(COMPOSE + ['config', '--format', 'json'], 'compose-resolved'))


def baseline():
    row = inspect(NAME)
    cfg = config()
    need(row['Image'] == OLD_ID and inspect(OLD_TAG)['Id'] == OLD_ID, 'Account baseline image changed.')
    need(cfg['services']['account']['image'] == OLD_TAG, 'Configured account image changed.')
    need(row['State']['Running'] and row['State'].get('Health', {}).get('Status') == 'healthy', 'Account is not healthy.')
    need(row['Config']['User'] == 'nobody' and not row.get('Mounts'), 'Account runtime baseline changed.')
    need(env(row).get('MARKETING_PUBLISH_ENABLED') == '1', 'Publishing baseline changed.')
    need(not set(MEDIA_ENV).intersection(env(row)), 'Media configuration already exists; inspect before repeating.')
    return cfg, row


def candidate_check(image, release):
    hashes = {name: hashlib.sha256((release / 'copilot/account' / name).read_bytes()).hexdigest() for name in FILES}
    probe = """import hashlib,json,os,shutil,sys
from pathlib import Path
from PIL import Image
import marketing_media
expected=json.loads(sys.argv[1])
assert all(hashlib.sha256(Path('/app',p).read_bytes()).hexdigest()==v for p,v in expected.items())
assert os.getuid()==65534 and shutil.which('ffprobe')
assert marketing_media.capabilities()['enabled'] is False
print(json.dumps({'source_match':True,'pillow':Image.__version__,'ffprobe':True,'uid':os.getuid()}))
"""
    run(['docker', 'run', '--rm', '--network=none', '--read-only', '--cap-drop=ALL',
         '--security-opt=no-new-privileges', '--entrypoint=python', image, '-c', probe, json.dumps(hashes)], 'candidate-probe')
    row = inspect(image)
    need(row['Config']['User'] == 'nobody', 'Candidate runtime user changed.')
    old = inspect(OLD_ID)
    for key in ('Entrypoint', 'Cmd', 'WorkingDir', 'Healthcheck'):
        need(row['Config'].get(key) == old['Config'].get(key), 'Candidate runtime changed: ' + key)
    need(env(row) == env(old), 'Candidate environment changed.')
    return row['Id']


def prepare(commit, tree):
    global BACKUP
    need(re.fullmatch(r'[a-f0-9]{40}', commit or '') and re.fullmatch(r'[a-f0-9]{40}', tree or ''), 'Exact commit and tree required.')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    BACKUP = Path('/opt/beshare-release-backups') / ('marketing-media-' + stamp + '-' + secrets.token_hex(3))
    BACKUP.mkdir(mode=0o700)
    progress('checking_baseline')
    cfg, row = baseline()
    others = inventory()
    save(BACKUP / 'baseline.json', json.dumps({'config': cfg, 'account': row, 'others': others}))
    hashes = {}
    for path in (BASE, OVERRIDE, ROOT / '.env', ROOT / 'marketing.env', ROOT / 'marketing-worker.env'):
        data = path.read_bytes()
        save(BACKUP / ('saved-' + path.name), data)
        hashes[str(path)] = hashlib.sha256(data).hexdigest()
    progress('backing_up_database')
    dump = run(['docker', 'exec', '-u', 'postgres', 'copilot-production-copilot-postgres-1', 'pg_dump', '-Fc',
                '--username', 'copilot_akaun_production', '--dbname', 'copilot_akaun_production'],
               'database-backup', binary=True, timeout=180)
    save(BACKUP / 'account.dump', dump)
    run(['docker', 'exec', '-i', '-u', 'postgres', 'copilot-production-copilot-postgres-1', 'pg_restore', '--list'],
        'database-backup-check', data=dump, binary=True)
    release = ROOT / 'releases' / ('marketing-media-' + commit[:12])
    need(not release.exists(), 'Release directory already exists; inspect before repeating.')
    progress('building_account')
    old_mask = os.umask(0o022)
    try:
        run(['git', 'clone', '--no-checkout', 'https://github.com/sifutech-ux/beshareaisolution.git', str(release)], 'clone', timeout=180)
        run(['git', 'checkout', '--detach', commit], 'checkout', cwd=release)
    finally:
        os.umask(old_mask)
    need(run(['git', 'rev-parse', 'HEAD^{tree}'], 'source-tree', cwd=release).strip() == tree, 'Source tree mismatch.')
    image = 'copilot-account:marketing-media-' + commit[:12]
    run(['docker', 'build', '--pull=false', '-f', 'marketing/Dockerfile.media', '-t', image,
         '--build-arg', 'ACCOUNT_BASE_IMAGE=' + OLD_TAG, '.'], 'build', cwd=release, timeout=900)
    candidate_id = candidate_check(image, release)
    baseline()
    need(inventory() == others, 'Other containers changed during preparation.')
    save(BACKUP / 'manifest.json', json.dumps({'hashes': hashes, 'image': image, 'candidate_id': candidate_id,
                                             'release': str(release), 'commit': commit, 'tree': tree}))
    progress('prepared', image=image)


def atomic_override(data):
    stat = OVERRIDE.stat()
    fd, name = tempfile.mkstemp(prefix='.media-release-', dir=ROOT)
    try:
        with os.fdopen(fd, 'wb') as target:
            os.fchmod(target.fileno(), stat.st_mode & 0o777)
            os.fchown(target.fileno(), stat.st_uid, stat.st_gid)
            target.write(data); target.flush(); os.fsync(target.fileno())
        os.replace(name, OVERRIDE)
    finally:
        if Path(name).exists():
            Path(name).unlink()


def planned_override(original, image):
    import yaml
    data = yaml.safe_load(original)
    account = data['services']['account']
    need(account['image'] == OLD_TAG and not account.get('volumes'), 'Override baseline changed.')
    environment = account.get('environment') or {}
    need(isinstance(environment, dict) and not set(MEDIA_ENV).intersection(environment), 'Unsupported environment baseline.')
    account['image'] = image
    account['environment'] = {**environment, **MEDIA_ENV}
    account['volumes'] = [str(STORAGE) + ':' + TARGET]
    return yaml.safe_dump(data, sort_keys=False).encode()


def apply(backup):
    global BACKUP
    BACKUP = Path(backup)
    need(BACKUP.parent == Path('/opt/beshare-release-backups') and BACKUP.is_dir(), 'Unexpected backup path.')
    manifest = json.loads((BACKUP / 'manifest.json').read_text())
    saved = json.loads((BACKUP / 'baseline.json').read_text())
    cfg, row = baseline()
    need(cfg == saved['config'], 'Configuration changed after preparation.')
    for path, digest in manifest['hashes'].items():
        need(hashlib.sha256(Path(path).read_bytes()).hexdigest() == digest, 'Configuration file changed.')
    need(candidate_check(manifest['image'], Path(manifest['release'])) == manifest['candidate_id'], 'Candidate tag changed.')
    before = inventory()
    original = OVERRIDE.read_bytes()
    modified = planned_override(original, manifest['image'])
    need(not STORAGE.exists(), 'Media directory already exists; inspect before repeating.')
    STORAGE.mkdir(mode=0o700)
    os.chown(STORAGE, 65534, 65534)
    progress('applying_account')
    atomic_override(modified)
    try:
        target = config()
        expected = copy.deepcopy(cfg)
        expected['services']['account']['image'] = manifest['image']
        expected['services']['account']['environment'].update(MEDIA_ENV)
        volumes = target['services']['account'].get('volumes')
        need(len(volumes or []) == 1, 'Unexpected storage mounts.')
        mount = volumes[0]
        need(mount.get('type') == 'bind' and mount.get('source') == str(STORAGE)
             and mount.get('target') == TARGET and not mount.get('read_only', False), 'Unexpected media mount.')
        expected['services']['account']['volumes'] = volumes
        need(target == expected, 'Unrelated resolved configuration changed.')
        run(COMPOSE + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '45', 'account'],
            'apply-account', timeout=60)
        live = inspect(NAME)
        need(live['Image'] == manifest['candidate_id'] and live['State'].get('Health', {}).get('Status') == 'healthy', 'New account is unhealthy.')
        need(live['Config']['User'] == row['Config']['User'] and env(live) == {**env(row), **MEDIA_ENV}, 'Account runtime changed unexpectedly.')
        need(inventory() == before, 'An unrelated container was recreated.')
        run(['docker', 'exec', NAME, 'python', '-c',
             "import json,marketing_media; from pathlib import Path; c=marketing_media.capabilities(); assert c['enabled']; p=Path(marketing_media._config()['root'])/'.write-probe'; p.write_bytes(b''); p.unlink(); print(json.dumps({'upload_enabled':True,'storage_writable':True}))"], 'live-media-capability')
        progress('deployed', image=manifest['image'], upload_enabled=True, publishing_enabled=True,
                 other_services_unchanged=True)
    except Exception:
        atomic_override(original)
        run(COMPOSE + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '45', 'account'],
            'rollback-account', timeout=60)
        progress('rolled_back')
        raise


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=('prepare', 'apply'))
    parser.add_argument('--commit')
    parser.add_argument('--tree')
    parser.add_argument('--backup')
    args = parser.parse_args()
    with (ROOT / '.marketing-media-release.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            if args.mode == 'prepare':
                prepare(args.commit, args.tree)
            else:
                need(bool(args.backup), 'Backup path required.')
                apply(args.backup)
        except Exception as error:
            progress('failed', error=str(error))
            raise SystemExit(1)


if __name__ == '__main__':
    main()
