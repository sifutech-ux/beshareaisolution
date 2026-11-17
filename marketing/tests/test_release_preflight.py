"""Release gate behavior only; every Docker interaction is synthetic."""
import argparse
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / 'bin' / 'release-preflight.py'
SPEC = importlib.util.spec_from_file_location('release_preflight', SCRIPT)
preflight = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(preflight)
IMAGE = 'sha256:' + 'a' * 64
DEPENDENCY_IMAGE = 'sha256:' + 'c' * 64
CANDIDATE_IMAGE = 'sha256:' + 'd' * 64
BASE_LAYER = 'sha256:' + 'e' * 64
DEPENDENCY_LAYER = 'sha256:' + 'f' * 64
CANDIDATE_LAYER = 'sha256:' + '1' * 64
PROBE_NAME = 'beshare-marketing-probe-' + 'b' * 24


def probe_result(expected):
    return {'python_version': '3.12.8', 'python_executable': '/do/not/relay/arbitrary/path',
            'files': [{'path': name, 'actual_sha256': digest} for name, digest in expected.items()],
            'dependencies': {'cryptography': {'available': True, 'version': '44.0.0'},
                             'psycopg2': {'available': True, 'version': '2.9.10 (dt dec pq3 ext lo64)'}},
            'fernet_roundtrip': True, 'unexpected_secret': 'never-display-this'}


def image_metadata(identifier, layers):
    return {'image_id': identifier, 'rootfs_layers': layers, 'user': 'nobody',
            'working_dir': '/app', 'entrypoint': ['/app/entrypoint.sh'],
            'cmd': ['private-runtime-argument'], 'os': 'linux', 'architecture': 'amd64'}


def staged_probe(identifier, expected):
    raw = probe_result(expected)
    if identifier == IMAGE:
        raw['dependencies']['cryptography'] = {'available': False, 'version': None}
        raw['fernet_roundtrip'] = False
    return {'image_id': identifier, **preflight.clean_probe(raw, expected)}


class ReleasePreflightTests(unittest.TestCase):
    def test_explicit_selection_and_cleanup_reject_unsafe_identifiers(self):
        self.assertEqual(preflight.container_name('observed-account-1'), 'observed-account-1')
        self.assertEqual(preflight.image_reference('registry.example.com/account:release'),
                         'registry.example.com/account:release')
        for value in ('', '--all', 'two containers', '../account', 'account;id'):
            with self.subTest(value=value), self.assertRaises(argparse.ArgumentTypeError):
                preflight.container_name(value)
        with self.assertRaises(preflight.ProbeError):
            preflight.remove_probe('observed-account-1')

    def test_hash_dependency_and_encryption_mismatches_block_gate(self):
        expected = preflight.BASE_HASHES
        good = probe_result(expected)
        cleaned = preflight.clean_probe(good, expected)
        self.assertTrue(cleaned['image_checks_passed'])
        self.assertNotIn('never-display-this', json.dumps(cleaned))
        self.assertNotIn('python_executable', cleaned)
        changed = probe_result(expected)
        changed['files'][0]['actual_sha256'] = '0' * 64
        self.assertFalse(preflight.clean_probe(changed, expected)['image_checks_passed'])
        changed = probe_result(expected)
        changed['dependencies']['cryptography']['available'] = False
        self.assertFalse(preflight.clean_probe(changed, expected)['image_checks_passed'])
        changed = probe_result(expected)
        changed['fernet_roundtrip'] = False
        self.assertFalse(preflight.clean_probe(changed, expected)['image_checks_passed'])
        changed = probe_result(expected)
        changed['files'].pop()
        with self.assertRaises(preflight.ProbeError):
            preflight.clean_probe(changed, expected)

    def test_code_mounts_block_but_separate_data_mount_does_not(self):
        metadata = {'image_id': IMAGE, 'running': True, 'restarting': False,
                    'mounts': [{'destination': '/app/data', 'type': 'volume', 'read_write': True}]}
        with patch.object(preflight, 'command_json', return_value=metadata), \
                patch.object(preflight, 'probe_image', return_value={'image_checks_passed': True}):
            self.assertEqual(preflight.preflight('observed-account-1')['image_gate'], 'passed')
            metadata['mounts'].append({'destination': '/app', 'type': 'bind', 'read_write': False})
            result = preflight.preflight('observed-account-1')
            self.assertEqual(result['image_gate'], 'blocked')
            self.assertEqual(result['code_shadowing_mounts'][0]['destination'], '/app')
        self.assertEqual(preflight.shadowing_mounts([
            {'destination': '/app/marketing_worker.py', 'type': 'bind', 'read_write': True}])[0]['destination'],
                         '/app/marketing_worker.py')
        self.assertNotIn('Config.Env', preflight.CONTAINER_FORMAT)
        self.assertNotIn('Health.Log', preflight.CONTAINER_FORMAT)

    def test_image_probe_uses_immutable_id_and_isolated_arguments(self):
        with patch.object(preflight.secrets, 'token_hex', return_value='b' * 24), \
                patch.object(preflight, 'command_json', return_value=probe_result(preflight.BASE_HASHES)) as command, \
                patch.object(preflight.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, '', '')) as cleanup:
            self.assertTrue(preflight.probe_image(IMAGE, preflight.BASE_HASHES)['image_checks_passed'])
            arguments = command.call_args.args[0]
            for flag in ('--pull=never', '--network=none', '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges'):
                self.assertIn(flag, arguments)
            self.assertIn(IMAGE, arguments)
            self.assertNotIn('--volume', arguments)
            self.assertNotIn('--publish', arguments)
            self.assertEqual(arguments[arguments.index('--name') + 1], PROBE_NAME)
            cleanup.assert_called_once()
            self.assertEqual(cleanup.call_args.args[0], ['docker', 'rm', '-f', '--', PROBE_NAME])

    def test_timeout_cleans_up_only_its_named_probe_without_exposing_output(self):
        observed = []
        def docker(arguments, **kwargs):
            observed.append(arguments)
            if arguments[:2] == ['docker', 'run']:
                raise subprocess.TimeoutExpired(arguments, kwargs['timeout'], output='private-data')
            self.assertEqual(arguments, ['docker', 'rm', '-f', '--', PROBE_NAME])
            return subprocess.CompletedProcess(arguments, 0, '', '')
        with patch.object(preflight.secrets, 'token_hex', return_value='b' * 24), \
                patch.object(preflight.subprocess, 'run', side_effect=docker):
            with self.assertRaises(preflight.ProbeError) as raised:
                preflight.probe_image(IMAGE, preflight.BASE_HASHES)
        self.assertNotIn('private-data', str(raised.exception))
        self.assertEqual(len(observed), 2)
        self.assertEqual(observed[1][-1], observed[0][observed[0].index('--name') + 1])

    def test_cleanup_failure_is_reported_without_raw_daemon_error(self):
        with patch.object(preflight.secrets, 'token_hex', return_value='b' * 24), \
                patch.object(preflight, 'command_json', return_value=probe_result(preflight.BASE_HASHES)), \
                patch.object(preflight.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1, '', 'private-daemon-details')):
            with self.assertRaises(preflight.ProbeError) as raised:
                preflight.probe_image(IMAGE, preflight.BASE_HASHES)
        self.assertIn(PROBE_NAME, str(raised.exception))
        self.assertNotIn('private-daemon-details', str(raised.exception))

    def test_explicit_dependency_can_supply_crypto_while_default_stays_blocked(self):
        container = {'image_id': IMAGE, 'running': True, 'restarting': False, 'health': 'healthy', 'mounts': []}
        metadata = {IMAGE: image_metadata(IMAGE, [BASE_LAYER]),
                    'dependencies': image_metadata(DEPENDENCY_IMAGE, [BASE_LAYER, DEPENDENCY_LAYER])}
        with patch.object(preflight, 'command_json', return_value=container), \
                patch.object(preflight, 'inspect_image', side_effect=metadata.__getitem__), \
                patch.object(preflight, 'probe_image', side_effect=staged_probe):
            self.assertEqual(preflight.preflight('observed-account-1')['image_gate'], 'blocked')
            result = preflight.preflight('observed-account-1', dependency='dependencies')
        self.assertEqual(result['image_gate'], 'passed')
        self.assertFalse(result['base_image']['image_checks_passed'])
        self.assertTrue(result['live_baseline_code_and_postgres_passed'])
        self.assertTrue(result['dependency_image']['image_checks_passed'])
        self.assertNotIn('private-runtime-argument', json.dumps(result))

    def test_dependency_must_descend_live_layers_and_preserve_runtime(self):
        container = {'image_id': IMAGE, 'running': True, 'restarting': False, 'health': 'healthy', 'mounts': []}
        variants = [('rootfs_layers', [DEPENDENCY_LAYER]), ('rootfs_layers', [BASE_LAYER]),
                    ('user', 'root'), ('working_dir', '/other'),
                    ('entrypoint', ['/other/entrypoint']), ('cmd', ['other-private-argument'])]
        for field, value in variants:
            with self.subTest(field=field, value=value):
                dependency = image_metadata(DEPENDENCY_IMAGE, [BASE_LAYER, DEPENDENCY_LAYER])
                dependency[field] = value
                metadata = {IMAGE: image_metadata(IMAGE, [BASE_LAYER]), 'dependencies': dependency}
                with patch.object(preflight, 'command_json', return_value=container), \
                        patch.object(preflight, 'inspect_image', side_effect=metadata.__getitem__), \
                        patch.object(preflight, 'probe_image', side_effect=staged_probe) as probe:
                    result = preflight.preflight('observed-account-1', dependency='dependencies')
                self.assertEqual(result['image_gate'], 'blocked')
                self.assertIn('probe_skipped', result['dependency_image'])
                self.assertEqual(probe.call_count, 1)
                self.assertNotIn('other-private-argument', json.dumps(result))

    def test_dependency_does_not_bypass_live_code_postgres_health_or_mounts(self):
        metadata = {IMAGE: image_metadata(IMAGE, [BASE_LAYER]),
                    'dependencies': image_metadata(DEPENDENCY_IMAGE, [BASE_LAYER, DEPENDENCY_LAYER])}
        for failure in ('code', 'postgres', 'health', 'mount'):
            with self.subTest(failure=failure):
                container = {'image_id': IMAGE, 'running': True, 'restarting': False, 'health': 'healthy', 'mounts': []}
                if failure == 'health':
                    container['health'] = 'unhealthy'
                if failure == 'mount':
                    container['mounts'] = [{'type': 'bind', 'destination': '/app', 'read_write': False}]
                def probe(identifier, expected):
                    result = staged_probe(identifier, expected)
                    if identifier == IMAGE:
                        if failure == 'code':
                            result['files'][0]['matches'] = False
                        if failure == 'postgres':
                            result['dependencies']['psycopg2']['available'] = False
                    return result
                with patch.object(preflight, 'command_json', return_value=container), \
                        patch.object(preflight, 'inspect_image', side_effect=metadata.__getitem__), \
                        patch.object(preflight, 'probe_image', side_effect=probe):
                    result = preflight.preflight('observed-account-1', dependency='dependencies')
                self.assertEqual(result['image_gate'], 'blocked')

    def test_candidate_must_descend_dependency_and_preserve_runtime(self):
        container = {'image_id': IMAGE, 'running': True, 'restarting': False, 'health': 'healthy', 'mounts': []}
        metadata = {IMAGE: image_metadata(IMAGE, [BASE_LAYER]),
                    'dependencies': image_metadata(DEPENDENCY_IMAGE, [BASE_LAYER, DEPENDENCY_LAYER]),
                    'candidate': image_metadata(CANDIDATE_IMAGE, [BASE_LAYER, DEPENDENCY_LAYER, CANDIDATE_LAYER])}
        with patch.object(preflight, 'command_json', return_value=container), \
                patch.object(preflight, 'inspect_image', side_effect=metadata.__getitem__), \
                patch.object(preflight, 'probe_image', side_effect=staged_probe):
            result = preflight.preflight('observed-account-1', candidate='candidate', dependency='dependencies')
            self.assertEqual(result['image_gate'], 'passed')
            self.assertEqual(result['candidate_image']['parent_image_id'], DEPENDENCY_IMAGE)
            metadata['candidate']['rootfs_layers'] = [BASE_LAYER, CANDIDATE_LAYER]
            self.assertEqual(preflight.preflight('observed-account-1', 'candidate', 'dependencies')['image_gate'], 'blocked')
            metadata['candidate']['rootfs_layers'] = [BASE_LAYER, DEPENDENCY_LAYER, CANDIDATE_LAYER]
            metadata['candidate']['cmd'] = ['different']
            self.assertEqual(preflight.preflight('observed-account-1', 'candidate', 'dependencies')['image_gate'], 'blocked')

    def test_direct_candidate_must_descend_live_image_and_preserve_runtime(self):
        container = {'image_id': IMAGE, 'running': True, 'restarting': False, 'health': 'healthy', 'mounts': []}
        metadata = {IMAGE: image_metadata(IMAGE, [BASE_LAYER]),
                    'candidate': image_metadata(CANDIDATE_IMAGE, [BASE_LAYER, CANDIDATE_LAYER])}
        def probe(identifier, expected):
            return {'image_id': identifier, **preflight.clean_probe(probe_result(expected), expected)}
        with patch.object(preflight, 'command_json', return_value=container), \
                patch.object(preflight, 'inspect_image', side_effect=metadata.__getitem__), \
                patch.object(preflight, 'probe_image', side_effect=probe) as run_probe:
            result = preflight.preflight('observed-account-1', candidate='candidate')
            self.assertEqual(result['image_gate'], 'passed')
            self.assertEqual(result['candidate_image']['parent_image_id'], IMAGE)
            for field, value in (('rootfs_layers', [CANDIDATE_LAYER]), ('cmd', ['unexpected-command'])):
                with self.subTest(field=field):
                    metadata['candidate'] = image_metadata(CANDIDATE_IMAGE, [BASE_LAYER, CANDIDATE_LAYER])
                    metadata['candidate'][field] = value
                    run_probe.reset_mock()
                    result = preflight.preflight('observed-account-1', candidate='candidate')
                    self.assertEqual(result['image_gate'], 'blocked')
                    self.assertIn('probe_skipped', result['candidate_image'])
                    self.assertEqual(run_probe.call_count, 1)


if __name__ == '__main__':
    unittest.main()
