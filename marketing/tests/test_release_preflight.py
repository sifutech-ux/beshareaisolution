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
PROBE_NAME = 'beshare-marketing-probe-' + 'b' * 24


def probe_result(expected):
    return {'python_version': '3.12.8', 'python_executable': '/do/not/relay/arbitrary/path',
            'files': [{'path': name, 'actual_sha256': digest} for name, digest in expected.items()],
            'dependencies': {'cryptography': {'available': True, 'version': '44.0.0'},
                             'psycopg2': {'available': True, 'version': '2.9.10 (dt dec pq3 ext lo64)'}},
            'fernet_roundtrip': True, 'unexpected_secret': 'never-display-this'}


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


if __name__ == '__main__':
    unittest.main()
