#!/usr/bin/env python3
"""Scoped production activation; run on the VPS as root, not in this workspace."""
import argparse
import copy
import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import urllib.request

ROOT = Path('/opt/beshare-copilot-production')
ENV = ROOT / 'marketing.env'
OVERRIDE = ROOT / 'docker-compose.override.yaml'
BASE = ROOT / 'docker-compose.yaml'
SERVICES = ('account', 'marketing-worker')
IMAGE = 'copilot-account:marketing-meta-expiry-1b10042'
IMAGE_ID = 'sha256:7ce3bda8068969aef0f1468695e908540713cbfe689b8a43bc3d6130cc548321'
COMPOSE = ['docker', 'compose', '--project-name', 'copilot-production',
           '-f', str(BASE), '-f', str(OVERRIDE)]
STAMP = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
BACKUP = Path('/opt/beshare-release-backups') / ('publishing-enable-' + STAMP)
DB_CHECK = r'''
import json, time
from pelanggan_pangkalan import buka
c = buka()
try:
    q = c.cursor()
    q.execute("SELECT COUNT(*) FROM marketing_posts WHERE status IN ('queued','publishing','uncertain')")
    active = q.fetchone()[0]
    q.execute("SELECT status, COUNT(*) FROM marketing_posts GROUP BY status ORDER BY status")
    counts = dict(q.fetchall())
    q.execute("SELECT id FROM marketing_campaigns WHERE id=%s", ('2f5ddac43468b201dfbd4d6455d141d6',))
    campaign_found = q.fetchone() is not None
    q.execute("""SELECT account_id, account_name, status, token_expires_at
        FROM marketing_connections WHERE platform='facebook' AND akaun_id=(
            SELECT akaun_id FROM marketing_campaigns WHERE id=%s)""",
        ('2f5ddac43468b201dfbd4d6455d141d6',))
    row = q.fetchone()
    facebook = None if row is None else {'account_id': row[0], 'account_name': row[1],
        'status': row[2], 'reconnect_required': row[2] != 'connected' or bool(row[3] and row[3] <= time.time())}
    print(json.dumps({'active_jobs': active, 'post_counts': counts,
        'campaign_found': campaign_found, 'facebook': facebook}))
finally:
    c.close()
'''


def need(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(args, data=None, timeout=55):
    result = subprocess.run(args, input=data, text=True, capture_output=True, timeout=timeout)
    if result.returncode:
        # Private command output can contain environment values: never print it.
        with (BACKUP / 'private-command-errors.log').open('a') as out:
            out.write(result.stdout + '\n' + result.stderr + '\n')
        raise RuntimeError('Command failed; private details retained in backup directory.')
    return result.stdout


def private(name, data):
    path = BACKUP / name
    path.write_text(data)
    path.chmod(0o600)


def atomic(path, data, stat):
    temporary = path.with_name(path.name + '.publishing-enable.tmp')
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, stat.st_mode & 0o777)
    try:
        os.fchmod(fd, stat.st_mode & 0o777)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(temporary, stat.st_uid, stat.st_gid)
        os.replace(temporary, path)
    finally:
        if temporary.exists():
            temporary.unlink()


def containers():
    ids = run(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=copilot-production']).split()
    need(bool(ids), 'Production containers not found.')
    rows = json.loads(run(['docker', 'inspect', *ids]))
    return {row['Config']['Labels']['com.docker.compose.service']: row for row in rows}


def unchanged_other(rows):
    return {name: (row['Id'], row['State']['StartedAt'], row['State']['Status'])
            for name, row in rows.items() if name not in SERVICES}


def runtime_env(row):
    return dict(value.split('=', 1) for value in row['Config']['Env'])


def up():
    return run(COMPOSE + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never',
                          '--wait', '--wait-timeout', '45', *SERVICES], timeout=55)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true', help='Enable publishing after read-only preflight succeeds.')
    args = parser.parse_args()
    need(os.geteuid() == 0, 'Run this command as root in the Hostinger VPS terminal.')
    os.umask(0o077)
    BACKUP.mkdir(mode=0o700, parents=True, exist_ok=False)
    originals = {}
    changed = False
    recreating = False
    try:
        for path in (BASE, OVERRIDE, ENV):
            need(path.is_file() and not path.is_symlink(), 'Expected production file missing or a symlink.')
            originals[path] = (path.read_bytes(), path.stat())
            (BACKUP / path.name).write_bytes(originals[path][0])
            (BACKUP / path.name).chmod(0o600)
        before = json.loads(run(COMPOSE + ['config', '--format', 'json']))
        private('compose-before.json', json.dumps(before))
        image_rows = json.loads(run(['docker', 'image', 'inspect', IMAGE]))
        need(len(image_rows) == 1 and image_rows[0]['Id'] == IMAGE_ID,
             'Configured image tag no longer points to the verified image; stop for review.')
        live_before = containers()
        private('containers-before.json', json.dumps(live_before))
        need(all(name in live_before for name in SERVICES), 'Target service missing.')
        for name in SERVICES:
            need(before['services'][name]['image'] == IMAGE, 'Unexpected configured image; stop for review.')
            need(live_before[name]['Image'] == IMAGE_ID, 'Unexpected running image; stop for review.')
            need(live_before[name]['State']['Running'] and not live_before[name]['State']['Restarting'],
                 'Target service is not running steadily; stop for review.')
            current_env = runtime_env(live_before[name])
            need(current_env.get('MARKETING_PUBLISH_ENABLED') == '0',
                 'Live publishing flag is not the expected disabled state; stop for review.')
            need(all(current_env.get(key) == str(value)
                     for key, value in before['services'][name]['environment'].items()),
                 'Resolved environment differs from the running service; stop for review.')
            need(str(before['services'][name]['environment'].get('MARKETING_PUBLISH_ENABLED')) == '0',
                 'Publishing flag is not the expected disabled state; stop for review.')
        db = json.loads(run(['docker', 'exec', '-i', live_before['account']['Id'], 'python', '-'], DB_CHECK))
        need(db['active_jobs'] == 0 and db['campaign_found'], 'Pending jobs or missing test campaign; stop for review.')
        private('queue-before.json', json.dumps(db))

        if not args.apply:
            print(json.dumps({'ok': True, 'ready_to_enable': True, 'applied': False,
                              'queue': db, 'backup': str(BACKUP)}))
            return 0

        env_text = originals[ENV][0].decode()
        env_text, env_count = re.subn(r'(?m)^(\s*MARKETING_PUBLISH_ENABLED\s*=\s*)([\"\x27]?)0\2(\s*(?:#.*)?)$',
                                     lambda m: m[1] + m[2] + '1' + m[2] + m[3], env_text)
        override_text = originals[OVERRIDE][0].decode()
        override_text, override_count = re.subn(r'(?m)^(\s*MARKETING_PUBLISH_ENABLED\s*:\s*)([\"\x27]?)0\2(\s*(?:#.*)?)$',
                                                lambda m: m[1] + m[2] + '1' + m[2] + m[3], override_text)
        need(env_count == 1 and override_count == 2, 'Expected exactly one env flag and two service overrides.')
        changed = True
        atomic(ENV, env_text.encode(), originals[ENV][1])
        atomic(OVERRIDE, override_text.encode(), originals[OVERRIDE][1])
        candidate = json.loads(run(COMPOSE + ['config', '--format', 'json']))
        expected = copy.deepcopy(before)
        for name in SERVICES:
            expected['services'][name]['environment']['MARKETING_PUBLISH_ENABLED'] = '1'
        need(candidate == expected, 'Resolved configuration changed outside the two publishing flags.')
        private('compose-enabled.json', json.dumps(candidate))
        recreating = True
        private('activation.log', up())
        after = containers()
        need(unchanged_other(after) == unchanged_other(live_before), 'Other production services changed.')
        for name in SERVICES:
            row = after[name]
            need(row['Image'] == IMAGE_ID and row['State']['Running'] and not row['State']['Restarting'],
                 'Target service is not running the verified image.')
            desired = runtime_env(live_before[name])
            desired['MARKETING_PUBLISH_ENABLED'] = '1'
            need(runtime_env(row) == desired, 'Runtime environment changed outside publishing flag.')
            result = json.loads(run(['docker', 'exec', row['Id'], 'python', '-c',
                                    'import json, marketing_social; print(json.dumps({"enabled": marketing_social._enabled()}))']))
            need(result['enabled'] is True, 'Publishing flag not effective inside target service.')
        need(after['account']['State'].get('Health', {}).get('Status') == 'healthy', 'Account healthcheck failed.')
        worker = after['marketing-worker']
        tick = None
        deadline = time.monotonic() + 25
        while time.monotonic() < deadline and tick is None:
            logs = run(['docker', 'logs', '--since', worker['State']['StartedAt'], '--tail', '10', worker['Id']])
            for line in reversed(logs.splitlines()):
                try:
                    item = json.loads(line)
                except (ValueError, TypeError):
                    continue
                if item.get('enabled') is True and 'processed' in item:
                    tick = item
                    break
            if tick is None:
                time.sleep(2)
        need(tick is not None, 'No fresh enabled worker tick observed.')
        with urllib.request.urlopen('https://akaun.beshareaisolution.com/healthz', timeout=10) as response:
            need(response.status == 200, 'Public account healthcheck failed.')
        report = {'ok': True, 'publishing_enabled': True, 'account_health': 'healthy',
                  'worker_tick': {k: tick.get(k) for k in ('enabled', 'processed', 'published', 'failed', 'uncertain')},
                  'other_services_unchanged': True, 'facebook': db['facebook'], 'backup': str(BACKUP)}
        private('result.json', json.dumps(report))
        print(json.dumps(report))
    except Exception as error:
        if changed:
            for path in (ENV, OVERRIDE):
                data, stat = originals[path]
                atomic(path, data, stat)
            if recreating:
                try:
                    private('rollback.log', up())
                except Exception:
                    print(json.dumps({'ok': False, 'error': 'Activation failed; configuration restored, service rollback needs checking.',
                                      'backup': str(BACKUP)}))
                    return 1
        print(json.dumps({'ok': False, 'error': str(error), 'configuration_restored': changed, 'backup': str(BACKUP)}))
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
