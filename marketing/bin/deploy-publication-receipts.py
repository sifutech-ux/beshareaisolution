#!/usr/bin/env python3
"""Prepare and apply a guarded account-only publication receipt release.

Requires an exact reviewed Git commit/tree. Only the account image changes;
environment, mounts, runtime, worker and other services must remain unchanged.
Raw inspection and configuration output stays in a root-only backup directory.
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
OLD_TAG = 'copilot-account:connection-health-d5169e189ed0'
OLD_ID = 'sha256:958863fa1e84021eccb88ed3465f1e89106c11ab3d1a144eb6c547fc6712fce6'
STATUS = Path('/root/beshare-publication-receipts-release-status.json')
STORAGE = ROOT / 'marketing-media'
TARGET = '/var/lib/beshare-marketing-media'
MEDIA_ENV = {'MARKETING_MEDIA_ENABLED': '1', 'MARKETING_MEDIA_ROOT': TARGET,
             'MARKETING_MEDIA_PUBLIC_BASE_URL': 'https://akaun.beshareaisolution.com'}
FILES = ('marketing_social.py', 'marketing_publication.py', 'pelanggan_marketing.py', 'marketing_facebook_ads.py', 'marketing_lifecycle.py')
UNCHANGED_HASHES = {
    'akaun_pelayan.py': '5469759c6b2af14e343ada7932546c55180d747f9afaf69c65e1844a09fe5127',
}
BACKUP = None


def need(ok, message):
    if not ok:
        raise RuntimeError(message)


def save(path, data):
    path.write_bytes(data if isinstance(data, bytes) else data.encode())
    path.chmod(0o600)


def progress(phase, **data):
    save(STATUS, json.dumps({'phase': phase, 'backup': str(BACKUP), **data}, sort_keys=True))


def run(args, label, *, cwd=None, timeout=60):
    try:
        result = subprocess.run(args, cwd=cwd, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        raise RuntimeError(label + ' timed out; no next step was run.') from None
    save(BACKUP / (label + '.log'), result.stdout + '\n' + result.stderr)
    need(result.returncode == 0, label + ' failed; details are in the private log.')
    return result.stdout


def inspect(name):
    label = 'inspect-' + name.replace(':', '-')
    return json.loads(run(['docker', 'inspect', name], label))[0]


def env(row):
    return dict(value.split('=', 1) for value in row['Config'].get('Env', []))


def inventory():
    ids = run(['docker', 'ps', '-q'], 'container-list').split()
    need(bool(ids), 'No running containers found.')
    rows = json.loads(run(['docker', 'inspect', *ids], 'container-inventory'))
    return {row['Name']: [row['Id'], row['State']['StartedAt']]
            for row in rows if row['Name'].lstrip('/') != NAME}


def config():
    return json.loads(run(COMPOSE + ['config', '--format', 'json'], 'compose-resolved'))


def runtime(row):
    """Exclude container IDs and generated Compose labels, retain real settings."""
    return {
        'config': {key: row['Config'].get(key) for key in
                   ('User', 'Entrypoint', 'Cmd', 'WorkingDir', 'Healthcheck',
                    'ExposedPorts', 'Volumes', 'StopSignal', 'Shell')},
        'environment': env(row),
        'host': row.get('HostConfig'),
        'mounts': row.get('Mounts'),
        'networks': sorted(row.get('NetworkSettings', {}).get('Networks', {})),
    }


def baseline():
    row, cfg = inspect(NAME), config()
    need(row['Image'] == OLD_ID and inspect(OLD_TAG)['Id'] == OLD_ID, 'Account baseline image changed.')
    need(cfg['services']['account']['image'] == OLD_TAG, 'Configured account image changed.')
    need(row['State']['Running'] and row['State'].get('Health', {}).get('Status') == 'healthy', 'Account is not healthy.')
    need(row['Config']['User'] == 'nobody', 'Account runtime user changed.')
    values = env(row)
    need(values.get('MARKETING_PUBLISH_ENABLED') == '1', 'Publishing baseline changed.')
    need(all(values.get(key) == value for key, value in MEDIA_ENV.items()), 'Media configuration changed.')
    mounts = row.get('Mounts') or []
    need(len(mounts) == 1 and mounts[0].get('Type') == 'bind'
         and mounts[0].get('Source') == str(STORAGE) and mounts[0].get('Destination') == TARGET
         and mounts[0].get('RW') is True, 'Media storage mount changed.')
    need(STORAGE.is_dir(), 'Existing media storage is unavailable.')
    return cfg, row


def candidate_check(image, release):
    hashes = {**UNCHANGED_HASHES,
              **{name: hashlib.sha256((release / 'copilot/account' / name).read_bytes()).hexdigest() for name in FILES}}
    probe = """import hashlib,json,os,shutil,sys
from pathlib import Path
from PIL import Image
import marketing_media,marketing_social,marketing_publication,marketing_lifecycle,pelanggan_marketing
expected=json.loads(sys.argv[1])
assert all(hashlib.sha256(Path('/app',p).read_bytes()).hexdigest()==v for p,v in expected.items())
assert os.getuid()==65534 and shutil.which('ffprobe')
assert marketing_social.capabilities()['connection_health_checks'] is True
assert marketing_social.capabilities()['publication_receipts'] is True
assert marketing_social.capabilities()['threads_customer_access'] == 'testing'
assert marketing_media.capabilities()['enabled'] is False
print(json.dumps({'source_match':True,'health_checks':True,'publication_receipts':True,'media_dependencies':True,'uid':os.getuid()}))
"""
    run(['docker', 'run', '--rm', '--network=none', '--read-only', '--cap-drop=ALL',
         '--security-opt=no-new-privileges', '--entrypoint=python', image, '-B', '-c', probe,
         json.dumps(hashes)], 'candidate-probe')
    row, old = inspect(image), inspect(OLD_ID)
    for key in ('User', 'Entrypoint', 'Cmd', 'WorkingDir', 'Healthcheck', 'ExposedPorts',
                'Volumes', 'StopSignal', 'Shell'):
        need(row['Config'].get(key) == old['Config'].get(key), 'Candidate runtime changed: ' + key)
    need(env(row) == env(old), 'Candidate environment changed.')
    return row['Id']


def prepare(commit, tree):
    global BACKUP
    need(re.fullmatch(r'[a-f0-9]{40}', commit or '') and re.fullmatch(r'[a-f0-9]{40}', tree or ''),
         'Exact commit and tree required.')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    BACKUP = Path('/opt/beshare-release-backups') / ('publication-receipts-' + stamp + '-' + secrets.token_hex(3))
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
    release = ROOT / 'releases' / ('publication-receipts-' + commit[:12])
    need(not release.exists(), 'Release directory already exists; inspect before repeating.')
    progress('building_account')
    old_mask = os.umask(0o022)
    try:
        run(['git', 'clone', '--no-checkout', 'https://github.com/sifutech-ux/beshareaisolution.git', str(release)],
            'clone', timeout=180)
        run(['git', 'checkout', '--detach', commit], 'checkout', cwd=release)
    finally:
        os.umask(old_mask)
    need(run(['git', 'rev-parse', 'HEAD^{tree}'], 'source-tree', cwd=release).strip() == tree, 'Source tree mismatch.')
    image = 'copilot-account:publication-receipts-' + commit[:12]
    run(['docker', 'build', '--pull=false', '--network=none', '-f', 'marketing/Dockerfile.publication', '-t', image,
         '--build-arg', 'ACCOUNT_BASE_IMAGE=' + OLD_TAG, '.'], 'build', cwd=release, timeout=180)
    candidate_id = candidate_check(image, release)
    final_cfg, final_row = baseline()
    need(final_cfg == cfg and runtime(final_row) == runtime(row), 'Account changed during preparation.')
    need(inventory() == others, 'Other containers changed during preparation.')
    save(BACKUP / 'manifest.json', json.dumps({'hashes': hashes, 'image': image, 'candidate_id': candidate_id,
                                             'release': str(release), 'commit': commit, 'tree': tree}))
    progress('prepared', image=image)


def atomic_override(data):
    info = OVERRIDE.stat()
    descriptor, name = tempfile.mkstemp(prefix='.publication-receipts-release-', dir=ROOT)
    try:
        with os.fdopen(descriptor, 'wb') as target:
            os.fchmod(target.fileno(), info.st_mode & 0o777)
            os.fchown(target.fileno(), info.st_uid, info.st_gid)
            target.write(data)
            target.flush()
            os.fsync(target.fileno())
        os.replace(name, OVERRIDE)
    finally:
        if Path(name).exists():
            Path(name).unlink()


def planned_override(original, image):
    import yaml
    data = yaml.safe_load(original)
    need(data['services']['account']['image'] == OLD_TAG, 'Override baseline changed.')
    data['services']['account']['image'] = image
    return yaml.safe_dump(data, sort_keys=False).encode()


def apply(backup):
    global BACKUP
    BACKUP = Path(backup)
    need(BACKUP.parent == Path('/opt/beshare-release-backups') and BACKUP.is_dir(), 'Unexpected backup path.')
    manifest = json.loads((BACKUP / 'manifest.json').read_text())
    saved = json.loads((BACKUP / 'baseline.json').read_text())
    cfg, row = baseline()
    need(cfg == saved['config'] and runtime(row) == runtime(saved['account']),
         'Configuration or account runtime changed after preparation.')
    for path, digest in manifest['hashes'].items():
        need(hashlib.sha256(Path(path).read_bytes()).hexdigest() == digest, 'Configuration file changed.')
    release = Path(manifest['release'])
    need(run(['git', 'rev-parse', 'HEAD'], 'apply-source-commit', cwd=release).strip() == manifest['commit'],
         'Prepared source commit changed.')
    need(run(['git', 'rev-parse', 'HEAD^{tree}'], 'apply-source-tree', cwd=release).strip() == manifest['tree'],
         'Prepared source tree changed.')
    need(not run(['git', 'status', '--porcelain', '--untracked-files=no'], 'apply-source-status', cwd=release).strip(),
         'Prepared tracked source files changed.')
    need(candidate_check(manifest['image'], release) == manifest['candidate_id'], 'Candidate tag changed.')
    before = inventory()
    original = OVERRIDE.read_bytes()
    modified = planned_override(original, manifest['image'])
    progress('applying_account')
    atomic_override(modified)
    try:
        expected = copy.deepcopy(cfg)
        expected['services']['account']['image'] = manifest['image']
        need(config() == expected, 'A resolved setting other than the account image changed.')
        run(COMPOSE + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--wait',
                       '--wait-timeout', '45', 'account'], 'apply-account', timeout=60)
        live = inspect(NAME)
        need(live['Image'] == manifest['candidate_id'] and live['State'].get('Health', {}).get('Status') == 'healthy',
             'New account is unhealthy.')
        need(runtime(live) == runtime(row), 'Account runtime, environment or mounts changed unexpectedly.')
        need(inventory() == before, 'An unrelated container was recreated.')
        run(['docker', 'exec', NAME, 'python', '-B', '-c',
             "import json,os,marketing_media,marketing_social; from pathlib import Path; "
             "assert marketing_social.capabilities()['connection_health_checks'] is True; "
             "assert marketing_social.capabilities()['publication_receipts'] is True; "
             "assert marketing_media.capabilities()['enabled'] is True; "
             "p=Path(marketing_media._config()['root']); assert p.is_dir() and os.access(p,os.W_OK); "
             "print(json.dumps({'health_checks':True,'publication_receipts':True,'upload_enabled':True,'storage_preserved':True}))"],
            'live-feature-capabilities')
        progress('deployed', image=manifest['image'], health_checks=True, publication_receipts=True, upload_enabled=True,
                 other_services_unchanged=True)
    except Exception:
        atomic_override(original)
        run(COMPOSE + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', '--wait',
                       '--wait-timeout', '45', 'account'], 'rollback-account', timeout=60)
        restored = inspect(NAME)
        need(restored['Image'] == OLD_ID and runtime(restored) == runtime(row)
             and restored['State'].get('Health', {}).get('Status') == 'healthy', 'Account rollback verification failed.')
        progress('rolled_back')
        raise RuntimeError('Apply failed; the prior account image was restored. See private logs.') from None


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=('prepare', 'apply'))
    parser.add_argument('--commit')
    parser.add_argument('--tree')
    parser.add_argument('--backup')
    args = parser.parse_args()
    # Share the account deployment lock with the preceding media rollout.
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
