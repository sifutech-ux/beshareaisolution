#!/usr/bin/env python3
"""Create a NEW private Compose env file; never configure or restart services."""
import argparse
import base64
import getpass
import ipaddress
import json
import os
from pathlib import Path
import re
import sys
from urllib.parse import urlsplit
import warnings

REDIRECT = 'https://beshareaisolution.com/marketing/connect.html'
STATUS_PATH = '/api/akaun/copilot/marketing/provider/deletion-status'
STATUS = 'https://akaun.beshareaisolution.com' + STATUS_PATH


class SafeError(Exception):
    pass


class Parser(argparse.ArgumentParser):
    def error(self, _message):
        self.exit(2, 'Invalid arguments. Use --help; do not pass secrets as arguments.\n')


def public_url(value, path):
    try:
        parsed = urlsplit(value)
        host = parsed.hostname or ''
        if (len(value) > 2048 or not value.isascii() or any(c.isspace() or ord(c) < 32 for c in value)
                or parsed.scheme != 'https' or parsed.username is not None or parsed.password is not None
                or parsed.port not in (None, 443) or parsed.path != path or parsed.query or parsed.fragment
                or len(host) > 253 or any(not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label) for label in host.split('.'))
                or '.' not in host or host.endswith(('.local', '.localhost', '.internal', '.test', '.invalid'))):
            raise ValueError()
        try:
            ipaddress.ip_address(host)
        except ValueError:
            if re.fullmatch(r'[0-9.]+', host): raise SafeError('Use a public HTTPS hostname for callback URLs.')
        else:
            raise SafeError('Use a public HTTPS hostname for callback URLs.')
    except (TypeError, ValueError):
        raise SafeError('Invalid callback URL; use the exact registered HTTPS path without a query or fragment.') from None
    return value


def destination(value):
    path = Path(value)
    if not path.is_absolute() or not value.isprintable() or '..' in path.parts:
        raise SafeError('--output must be an explicit absolute file path.')
    if os.path.lexists(path):
        raise SafeError('Destination already exists. No file or encryption key was replaced.')
    if path.parent.is_symlink() or not path.parent.is_dir():
        raise SafeError('Destination parent must be an existing directory, not a symbolic link.')
    return path


def prompt_secret(label):
    with warnings.catch_warnings():
        warnings.simplefilter('error', getpass.GetPassWarning)
        value = getpass.getpass(label)
    if not 1 <= len(value) <= 4096 or not value.isprintable() or value != value.strip():
        raise SafeError('Secret rejected: use 1–4096 printable characters without surrounding whitespace or control characters.')
    return value


def dotenv(value):
    # Compose: double-quote escapes, then $$ protects literal dollars from interpolation.
    return json.dumps(value, ensure_ascii=False).replace('$', '$$')


def main(argv=None):
    parser = Parser(description=__doc__)
    parser.add_argument('--write', action='store_true', help='Prompt privately and create a new file; otherwise show names only.')
    parser.add_argument('--output', required=True, help='Absolute path for a NEW owner-only file.')
    for arg in ('meta-app-id', 'meta-login-config-id', 'threads-app-id'):
        parser.add_argument('--' + arg, required=True, help='Public numeric identifier; never an app secret.')
    parser.add_argument('--redirect-uri', default=REDIRECT)
    parser.add_argument('--deletion-status-url', default=STATUS)
    args = parser.parse_args(argv)
    created = False
    try:
        path = destination(args.output)
        for value in (args.meta_app_id, args.meta_login_config_id, args.threads_app_id):
            if not re.fullmatch(r'[0-9]{1,40}', value): raise SafeError('App and configuration IDs must contain 1–40 digits.')
        values = {
            'MARKETING_META_APP_ID': args.meta_app_id,
            'MARKETING_META_APP_SECRET': '',
            'MARKETING_META_LOGIN_CONFIG_ID': args.meta_login_config_id,
            'MARKETING_META_API_VERSION': 'v27.0',
            'MARKETING_THREADS_APP_ID': args.threads_app_id,
            'MARKETING_THREADS_APP_SECRET': '',
            'MARKETING_THREADS_API_VERSION': 'v1.0',
            'MARKETING_OAUTH_REDIRECT_URI': public_url(args.redirect_uri, '/marketing/connect.html'),
            'MARKETING_DATA_DELETION_STATUS_URL': public_url(args.deletion_status_url, STATUS_PATH),
            'MARKETING_TOKEN_KEY': '',
            'MARKETING_PUBLISH_ENABLED': '0',
        }
        if args.write:
            if not sys.stdin.isatty() or not sys.stderr.isatty():
                raise SafeError('A real interactive terminal is required. No secret was requested or file created.')
            values['MARKETING_META_APP_SECRET'] = prompt_secret('Meta app secret (hidden): ')
            values['MARKETING_THREADS_APP_SECRET'] = prompt_secret('Threads app secret (hidden): ')
            values['MARKETING_TOKEN_KEY'] = base64.urlsafe_b64encode(os.urandom(32)).decode('ascii')
            content = ''.join(key + '=' + dotenv(value) + '\n' for key, value in values.items())
            parent = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                fd = os.open(path.name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
                created = True
                with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
                    os.fchmod(stream.fileno(), 0o600)
                    stream.write(content)
                    stream.flush()
                    os.fsync(stream.fileno())
                os.fsync(parent)
            finally:
                os.close(parent)
        print(('Created: ' if args.write else 'Plan only; no file created: ') + str(path))
        print('Variables:\n' + '\n'.join(values))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except (OSError, EOFError, KeyboardInterrupt, getpass.GetPassWarning):
        print('Setup did not complete. A protected file may be incomplete; review it locally before use.' if created else
              'Setup stopped. No new file was created; existing files were not changed.', file=sys.stderr)
    return 1


if __name__ == '__main__':
    raise SystemExit(main())
