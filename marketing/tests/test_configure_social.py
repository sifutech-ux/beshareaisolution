"""Synthetic-only credential handoff tests. No network, Docker or real secrets."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch
import warnings
import base64

SPEC = importlib.util.spec_from_file_location('configure_social', Path(__file__).parents[1] / 'bin' / 'configure-social.py')
helper = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(helper)


class ConfigureSocialTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / 'marketing.env'
        self.args = ['--output', str(self.path), '--meta-app-id', '123', '--meta-login-config-id', '456', '--threads-app-id', '789']

    def run_helper(self, write=True, secrets=('synthetic-meta', 'synthetic-threads'), tty=True, extra=()):
        stdout, stderr = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr), \
                patch.object(helper.sys.stdin, 'isatty', return_value=tty), \
                patch.object(stderr, 'isatty', return_value=tty), \
                patch.object(helper.getpass, 'getpass', side_effect=secrets) as prompt:
            status = helper.main(self.args + (['--write'] if write else []) + list(extra))
        return status, stdout.getvalue() + stderr.getvalue(), prompt.call_count

    def test_default_plan_has_no_prompt_file_or_values(self):
        status, output, prompts = self.run_helper(write=False)
        self.assertEqual(status, 0)
        self.assertFalse(self.path.exists())
        self.assertEqual(prompts, 0)
        self.assertIn('MARKETING_TOKEN_KEY', output)
        self.assertNotIn(helper.REDIRECT, output)
        self.assertNotIn('\n123\n', output)

    def test_existing_file_and_symlink_are_never_overwritten_or_prompted(self):
        self.path.write_text('existing-sensitive-file')
        for symlink in (False, True):
            if symlink:
                self.path.unlink()
                target = Path(self.temp.name) / 'absent-target'
                self.path.symlink_to(target)
            status, output, prompts = self.run_helper()
            self.assertEqual(status, 1)
            self.assertEqual(prompts, 0)
            self.assertNotIn('existing-sensitive-file', output)
            if symlink:
                self.assertTrue(self.path.is_symlink())
                self.assertFalse(target.exists())
            else:
                self.assertEqual(self.path.read_text(), 'existing-sensitive-file')

    def test_no_terminal_no_prompt_no_file(self):
        status, _output, prompts = self.run_helper(tty=False)
        self.assertEqual(status, 1)
        self.assertEqual(prompts, 0)
        self.assertFalse(self.path.exists())

    def test_new_file_is_private_and_values_roundtrip_without_interpolation(self):
        secrets = (r'''synthetic $NAME ${NAME} # ' " \\ end''' + '\\', 'synthetic \\" and café')
        status, output, prompts = self.run_helper(secrets=secrets)
        self.assertEqual(status, 0)
        self.assertEqual(prompts, 2)
        self.assertEqual(stat.S_IMODE(self.path.stat().st_mode), 0o600)
        self.assertEqual(self.path.stat().st_uid, os.getuid())
        values = {}
        for line in self.path.read_text().splitlines():
            key, encoded = line.split('=', 1)
            # Independent standard-library quoted-literal decoder; $$ is Compose's literal dollar.
            values[key] = json.loads(encoded).replace('$$', '$')
        self.assertEqual(values['MARKETING_META_APP_SECRET'], secrets[0])
        self.assertEqual(values['MARKETING_THREADS_APP_SECRET'], secrets[1])
        self.assertEqual(values['MARKETING_PUBLISH_ENABLED'], '0')
        self.assertEqual(values['MARKETING_META_API_VERSION'], 'v27.0')
        self.assertEqual(values['MARKETING_THREADS_API_VERSION'], 'v1.0')
        self.assertEqual(values['MARKETING_DATA_DELETION_STATUS_URL'], helper.STATUS)
        self.assertEqual(len(base64.urlsafe_b64decode(values['MARKETING_TOKEN_KEY'])), 32)
        for value in (*secrets, values['MARKETING_TOKEN_KEY']):
            self.assertNotIn(value, output)

    def test_aborted_input_or_echo_warning_creates_no_file_and_does_not_echo(self):
        def fallback(_label):
            warnings.warn('synthetic-sensitive-warning', helper.getpass.GetPassWarning)
            self.fail('Echo fallback must never continue')
        for secrets in ([EOFError()], [KeyboardInterrupt()], fallback):
            status, output, _prompts = self.run_helper(secrets=secrets)
            self.assertEqual(status, 1)
            self.assertFalse(self.path.exists())
            self.assertNotIn('synthetic-sensitive-warning', output)

    def test_controls_and_invalid_urls_fail_before_any_file_is_created(self):
        for secret in ('line\nbreak', 'carriage\rreturn', 'tab\tvalue', 'nul\x00byte', '\x1bsecret', 'x' * 4097):
            status, output, _prompts = self.run_helper(secrets=[secret])
            self.assertEqual(status, 1)
            self.assertFalse(self.path.exists())
            self.assertNotIn(secret, output)
        for url in ('http://example.com/marketing/connect.html', 'https://127.0.0.1/marketing/connect.html',
                    'https://example.com/wrong', 'https://example.com/marketing/connect.html?code=x'):
            status, output, prompts = self.run_helper(extra=('--redirect-uri', url))
            self.assertEqual(status, 1)
            self.assertEqual(prompts, 0)
            self.assertFalse(self.path.exists())
            self.assertNotIn(url, output)

    def test_creation_race_is_exclusive_and_existing_content_survives(self):
        real_open = helper.os.open
        def racing_open(path, flags, *args, **kwargs):
            if flags & os.O_CREAT:
                self.path.write_text('other-writer')
            return real_open(path, flags, *args, **kwargs)
        with patch.object(helper.os, 'open', side_effect=racing_open):
            status, output, _prompts = self.run_helper()
        self.assertEqual(status, 1)
        self.assertEqual(self.path.read_text(), 'other-writer')
        self.assertNotIn('other-writer', output)

    def test_post_creation_error_retains_protected_file_without_raw_exception(self):
        with patch.object(helper.os, 'fsync', side_effect=OSError('synthetic-secret-in-error')):
            status, output, _prompts = self.run_helper()
        self.assertEqual(status, 1)
        self.assertTrue(self.path.exists())
        self.assertEqual(stat.S_IMODE(self.path.stat().st_mode), 0o600)
        self.assertIn('protected file may be incomplete', output)
        self.assertNotIn('synthetic-secret-in-error', output)


if __name__ == '__main__':
    unittest.main()
