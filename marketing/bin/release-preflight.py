#!/usr/bin/env python3
"""Inspect an explicit account container and probe its local image in isolation.

This does not deploy, modify the live container, read environment values, connect
to a database, or call a provider. The only created resource is a disposable
read-only, network-disabled probe container. Passing this gate is not evidence
that authentication, database access, OAuth or the worker are healthy.
"""
import argparse
import hashlib
import json
import posixpath
import re
import secrets
import subprocess
from pathlib import Path


BASE_HASHES = {
    '/app/pelanggan_copilot.py': 'bf4ee5fe170955833ec5c552a5d607a6135e635f086dd55f03c5412a66dc1b7c',
    '/app/pelanggan_akaun.py': '064377cd793c5ad8750a4cde743f31d50f72719631d039af533c7b6f83d736b7',
    '/app/akaun_pelayan.py': 'c24fab7943cc48c78e0a4353f13cdf02c25026179fe652ad4f2417ff75dbdedb',
}
RELEASE_FILES = (
    'pelanggan_copilot.py', 'pelanggan_hasil.py', 'pelanggan_marketing.py', 'pelanggan_kuota.py',
    'marketing_social.py', 'marketing_worker.py',
    'akaun_pelayan.py', 'marketing_lifecycle.py',
)
APP_CODE_PATHS = set(BASE_HASHES) | {'/app/' + name for name in RELEASE_FILES} | {
    '/app/pelanggan_meja.py', '/app/pelanggan_kuota.py', '/app/pelanggan_bil.py',
    '/app/pelanggan_pangkalan.py',
}
IMAGE_ID = re.compile(r'^sha256:[a-f0-9]{64}$')

# Docker emits only these selected fields. Environment, labels not named here,
# mount source paths, command arguments and healthcheck logs are never requested.
CONTAINER_FORMAT = '''{
"name":{{json .Name}},"image_id":{{json .Image}},
"state":{{json .State.Status}},"running":{{json .State.Running}},
"restarting":{{json .State.Restarting}},
"health":{{if .State.Health}}{{json .State.Health.Status}}{{else}}null{{end}},
"user":{{json .Config.User}},"working_dir":{{json .Config.WorkingDir}},
"entrypoint_present":{{if .Config.Entrypoint}}true{{else}}false{{end}},
"compose_project":{{json (index .Config.Labels "com.docker.compose.project")}},
"compose_service":{{json (index .Config.Labels "com.docker.compose.service")}},
"compose_config_files":{{json (index .Config.Labels "com.docker.compose.project.config_files")}},
"compose_working_dir":{{json (index .Config.Labels "com.docker.compose.project.working_dir")}},
"mounts":[{{range $index,$mount := .Mounts}}{{if $index}},{{end}}{
"type":{{json $mount.Type}},"destination":{{json $mount.Destination}},"read_write":{{json $mount.RW}}}{{end}}]
}'''
IMAGE_FORMAT = '''{
"image_id":{{json .Id}},"os":{{json .Os}},"architecture":{{json .Architecture}},
"user":{{json .Config.User}},"working_dir":{{json .Config.WorkingDir}},
"entrypoint":{{json (index .Config "Entrypoint")}},"cmd":{{json (index .Config "Cmd")}},
"rootfs_layers":{{json .RootFS.Layers}}
}'''

PROBE_CODE = r'''
import hashlib, importlib, json, sys
from pathlib import Path
expected = json.loads(sys.argv[1])
checks = []
for name, digest in sorted(expected.items()):
    try:
        actual = hashlib.sha256(Path(name).read_bytes()).hexdigest()
        state = 'match' if actual == digest else 'mismatch'
    except FileNotFoundError:
        actual, state = None, 'missing'
    except OSError:
        actual, state = None, 'unreadable'
    checks.append({'path': name, 'expected_sha256': digest, 'actual_sha256': actual, 'status': state})
dependencies = {}
for name in ('cryptography', 'psycopg2'):
    try:
        module = importlib.import_module(name)
        dependencies[name] = {'available': True, 'version': str(getattr(module, '__version__', 'unknown'))[:80]}
    except Exception:
        dependencies[name] = {'available': False, 'version': None}
roundtrip = False
if dependencies['cryptography']['available']:
    try:
        from cryptography.fernet import Fernet
        cipher = Fernet(Fernet.generate_key())
        roundtrip = cipher.decrypt(cipher.encrypt(b'beshare-image-probe')) == b'beshare-image-probe'
    except Exception:
        pass
print(json.dumps({'python_version': sys.version.split()[0], 'python_executable': sys.executable,
                  'files': checks, 'dependencies': dependencies, 'fernet_roundtrip': roundtrip}))
'''


class ProbeError(Exception):
    pass


def container_name(value):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,254}', value):
        raise argparse.ArgumentTypeError('Use one explicit Docker container name or ID.')
    return value


def image_reference(value):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/@:-]{0,511}', value):
        raise argparse.ArgumentTypeError('Use one existing local image name, digest or ID.')
    return value


def command_json(arguments, purpose, timeout=25):
    try:
        result = subprocess.run(arguments, capture_output=True, text=True, timeout=timeout, check=False)
    except FileNotFoundError:
        raise ProbeError('Docker is not available on this host.') from None
    except (subprocess.TimeoutExpired, OSError):
        raise ProbeError(purpose + ' did not complete. No deployment was attempted.') from None
    if result.returncode != 0:
        # Never echo raw daemon/image output; it could contain unrelated data.
        raise ProbeError(purpose + ' failed. Inspect the Docker operation locally; no raw output is included.')
    try:
        value = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise ProbeError(purpose + ' returned unexpected output; it was not displayed.') from None
    if not isinstance(value, dict):
        raise ProbeError(purpose + ' returned an invalid result.')
    return value


def shadowing_mounts(mounts):
    found = []
    if not isinstance(mounts, list):
        raise ProbeError('Container mount metadata is unavailable.')
    for item in mounts:
        if not isinstance(item, dict) or not isinstance(item.get('destination'), str):
            raise ProbeError('Container mount metadata is invalid.')
        destination = posixpath.normpath(item['destination'])
        if any(path == destination or path.startswith(destination.rstrip('/') + '/') for path in APP_CODE_PATHS):
            found.append({'type': item.get('type'), 'destination': destination,
                          'read_write': item.get('read_write') is True})
    return found


def candidate_hashes(root):
    released_paths = {'/app/' + name for name in RELEASE_FILES}
    expected = {path: digest for path, digest in BASE_HASHES.items() if path not in released_paths}
    for filename in RELEASE_FILES:
        source = root / 'copilot' / 'account' / filename
        try:
            expected['/app/' + filename] = hashlib.sha256(source.read_bytes()).hexdigest()
        except OSError:
            raise ProbeError('Candidate source files are missing from this checkout.') from None
    return expected


def clean_probe(raw, expected):
    """Whitelist image output before it can reach the operator's terminal."""
    files = raw.get('files')
    if not isinstance(files, list) or len(files) != len(expected):
        raise ProbeError('Image file checks are incomplete.')
    cleaned = []
    seen = set()
    for entry in files:
        if not isinstance(entry, dict):
            raise ProbeError('Image file checks are invalid.')
        path = entry.get('path')
        actual = entry.get('actual_sha256')
        if path not in expected or path in seen or (actual is not None and not re.fullmatch(r'[a-f0-9]{64}', str(actual))):
            raise ProbeError('Image file checks do not match the requested paths.')
        seen.add(path)
        cleaned.append({'path': path, 'expected_sha256': expected[path], 'actual_sha256': actual,
                        'matches': actual == expected[path]})
    dependencies = {}
    raw_dependencies = raw.get('dependencies')
    if not isinstance(raw_dependencies, dict):
        raise ProbeError('Image dependency checks are missing.')
    for name in ('cryptography', 'psycopg2'):
        item = raw_dependencies.get(name)
        if not isinstance(item, dict) or type(item.get('available')) is not bool:
            raise ProbeError('Image dependency checks are invalid.')
        version = item.get('version')
        if version is not None and (not isinstance(version, str) or not re.fullmatch(r'[A-Za-z0-9._+() -]{1,80}', version)):
            version = 'not_displayed'
        dependencies[name] = {'available': item['available'], 'version': version}
    python_version = raw.get('python_version')
    if not isinstance(python_version, str) or not re.fullmatch(r'\d+\.\d+\.\d+[A-Za-z0-9.]*', python_version):
        python_version = 'not_displayed'
    # Do not relay arbitrary executable paths or unexpected fields from an image.
    passed = (all(item['matches'] for item in cleaned)
              and all(item['available'] for item in dependencies.values())
              and raw.get('fernet_roundtrip') is True)
    return {'image_checks_passed': passed, 'python_version': python_version,
            'files': cleaned, 'dependencies': dependencies,
            'fernet_roundtrip': raw.get('fernet_roundtrip') is True}


def inspect_image(reference):
    metadata = command_json(['docker', 'image', 'inspect', '--format', IMAGE_FORMAT, reference], 'Local image inspection')
    identifier = metadata.get('image_id')
    if not isinstance(identifier, str) or not IMAGE_ID.fullmatch(identifier):
        raise ProbeError('An immutable local Docker image ID could not be established.')
    return metadata


def image_relation(parent, child):
    """Compare private image metadata without relaying command arguments."""
    for metadata in (parent, child):
        layers = metadata.get('rootfs_layers')
        if (not isinstance(layers, list) or not layers
                or any(not isinstance(layer, str) or not IMAGE_ID.fullmatch(layer) for layer in layers)):
            raise ProbeError('Image layer ancestry could not be established.')
        if any(not isinstance(metadata.get(field), str) for field in ('user', 'working_dir', 'os', 'architecture')):
            raise ProbeError('Image runtime identity is incomplete.')
        for field in ('entrypoint', 'cmd'):
            if field not in metadata:
                raise ProbeError('Image runtime identity is incomplete.')
            value = metadata.get(field)
            if value is not None and (not isinstance(value, list) or any(not isinstance(part, str) for part in value)):
                raise ProbeError('Image runtime identity is invalid.')
    old, new = parent['rootfs_layers'], child['rootfs_layers']
    differences = [field for field in ('user', 'working_dir', 'entrypoint', 'cmd', 'os', 'architecture')
                   if parent.get(field) != child.get(field)]
    return {'parent_image_id': parent['image_id'],
            'rootfs_descends_parent': len(new) > len(old) and new[:len(old)] == old,
            'runtime_matches_parent': not differences, 'runtime_differences': differences}


def probe_descendant(metadata, parent, expected):
    relation = image_relation(parent, metadata)
    if not relation['rootfs_descends_parent'] or not relation['runtime_matches_parent']:
        return {'image_id': metadata['image_id'], **relation, 'image_checks_passed': False,
                'probe_skipped': 'Image is not a verified descendant with the same runtime.'}
    return {**probe_image(metadata['image_id'], expected), **relation}


def remove_probe(name):
    """Clean up exactly the random name assigned to this one disposable probe."""
    if not re.fullmatch(r'beshare-marketing-probe-[a-f0-9]{24}', name):
        raise ProbeError('Refusing cleanup of a container not named by this probe.')
    try:
        result = subprocess.run(['docker', 'rm', '-f', '--', name], capture_output=True,
                                text=True, timeout=5, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return False
    if result.returncode == 0:
        return True
    # --rm normally removed it already. Never relay the raw daemon error.
    missing = ('no such container' in result.stderr.lower()
               or 'no such object' in result.stderr.lower())
    return result.returncode == 1 and missing


def probe_image(identifier, expected):
    if not isinstance(identifier, str) or not IMAGE_ID.fullmatch(identifier):
        raise ProbeError('Refusing to run an image without a verified immutable ID.')
    name = 'beshare-marketing-probe-' + secrets.token_hex(12)
    command = [
        'docker', 'run', '--rm', '--name', name, '--pull=never', '--network=none', '--read-only',
        '--cap-drop=ALL', '--security-opt=no-new-privileges', '--pids-limit=64',
        '--memory=256m', '--cpus=1', '--env', 'PYTHONDONTWRITEBYTECODE=1',
        '--entrypoint', 'python', identifier, '-c', PROBE_CODE,
        json.dumps(expected, sort_keys=True, separators=(',', ':')),
    ]
    try:
        return {'image_id': identifier, **clean_probe(command_json(command, 'Isolated image probe'), expected)}
    finally:
        if not remove_probe(name):
            raise ProbeError('Cleanup could not be verified for ' + name
                             + '. Inspect only that named probe; no live service was changed.')


def preflight(container, candidate=None, dependency=None):
    raw = command_json(['docker', 'container', 'inspect', '--format', CONTAINER_FORMAT, container], 'Account container inspection')
    identifier = raw.get('image_id')
    if not isinstance(identifier, str) or not IMAGE_ID.fullmatch(identifier):
        raise ProbeError('The selected container has no verified immutable image ID.')
    mounts = shadowing_mounts(raw.get('mounts'))
    # These values came from selected Docker metadata, never Config.Env or logs.
    safe = {key: raw.get(key) for key in (
        'name', 'image_id', 'state', 'running', 'restarting', 'health', 'user', 'working_dir',
        'entrypoint_present', 'compose_project', 'compose_service', 'compose_config_files', 'compose_working_dir')}
    base = probe_image(identifier, BASE_HASHES)
    result = {'account_container': safe, 'code_shadowing_mounts': mounts, 'base_image': base,
              'dependency_image': None, 'candidate_image': None,
              'live_checks': 'not_performed',
              'scope': 'Image/hash/dependency gate only. Database, routes, credentials and worker health require separate verification.'}
    baseline_gate = base['image_checks_passed']
    if dependency or candidate:
        parent_metadata = inspect_image(identifier)
        if parent_metadata['image_id'] != identifier:
            raise ProbeError('The live image identity changed during inspection.')
    if dependency:
        dependency_metadata = inspect_image(dependency)
        result['dependency_image'] = probe_descendant(dependency_metadata, parent_metadata, BASE_HASHES)
        baseline_gate = (all(item['matches'] for item in base['files'])
                         and base['dependencies']['psycopg2']['available'] is True)
        result['live_baseline_code_and_postgres_passed'] = baseline_gate
        result['dependency_note'] = ('Only the live baseline encryption dependency is delegated to the explicit staged image. '
                                     'Live code, PostgreSQL import, healthy state and code-mount checks remain required.')
    if candidate:
        root = Path(__file__).resolve().parents[2]
        metadata = inspect_image(candidate)
        result['candidate_image'] = probe_descendant(
            metadata, dependency_metadata if dependency else parent_metadata, candidate_hashes(root))
    passed = (baseline_gate and raw.get('running') is True
              and raw.get('restarting') is False and not mounts
              and (not dependency or (raw.get('health') == 'healthy'
                   and result['dependency_image']['image_checks_passed']))
              and (result['candidate_image'] is None or result['candidate_image']['image_checks_passed']))
    result['image_gate'] = 'passed' if passed else 'blocked'
    if mounts:
        result['mount_note'] = 'Existing mounts replace release code paths. Resolve their effect in the observed Compose configuration before deployment.'
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', required=True, type=container_name,
                        help='Explicit observed account-service container name or ID; never inferred.')
    parser.add_argument('--candidate-image', type=image_reference,
                        help='Optional already-built local image; verified against source files in this checkout.')
    parser.add_argument('--dependency-image', type=image_reference,
                        help='Explicit staged dependency base; must descend the exact live image and preserve its runtime.')
    args = parser.parse_args()
    try:
        result = preflight(args.container, args.candidate_image, args.dependency_image)
    except ProbeError as error:
        print(json.dumps({'image_gate': 'blocked', 'error': str(error), 'live_checks': 'not_performed'}, indent=2))
        return 2
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if result['image_gate'] == 'passed' else 2


if __name__ == '__main__':
    raise SystemExit(main())
