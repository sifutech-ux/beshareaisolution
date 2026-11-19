"""Stored grant health: no publication, no credential output, no stale writes."""
import json
import os
import sqlite3
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from cryptography.fernet import Fernet

import marketing_social as social
import pelanggan_akaun as account
import pelanggan_marketing as marketing
import pelanggan_meja as meja
from test_marketing import _postgres_test_connection, _reset_postgres_fixture


OWNER, OTHER = 'health-owner', 'health-other'
TOKEN, APP_TOKEN = 'synthetic-private-page-token', 'synthetic-private-app-token'
SECRET = 'synthetic-account-secret'


class ConnectionHealthTests(unittest.TestCase):
    postgres = False

    def setUp(self):
        self.now = int(time.time())
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.path = str(Path(tmp.name) / 'health.db')
        env = patch.dict(os.environ, {
            'MARKETING_TOKEN_KEY': Fernet.generate_key().decode(),
            'MARKETING_META_APP_ID': '12345', 'MARKETING_META_APP_SECRET': 'synthetic-meta-secret',
            'MARKETING_THREADS_APP_ID': '67890', 'MARKETING_THREADS_APP_SECRET': 'synthetic-threads-secret',
            'MARKETING_META_API_VERSION': 'v27.0', 'MARKETING_THREADS_API_VERSION': 'v1.0',
            'MARKETING_OAUTH_REDIRECT_URI': 'https://beshareaisolution.com/marketing/connect.html',
            'MARKETING_PUBLISH_ENABLED': '1', 'AKAUN_SECRET': SECRET,
        })
        env.start()
        self.addCleanup(env.stop)
        network = patch('urllib.request.OpenerDirector.open', side_effect=AssertionError('Live network forbidden'))
        self.network = network.start()
        self.addCleanup(network.stop)
        self.addCleanup(self.network.assert_not_called)
        if self.postgres:
            _reset_postgres_fixture(self.connect)
        conn = self.connect()
        ph = meja._ph(self.postgres)
        conn.cursor().execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT NOT NULL)')
        conn.cursor().executemany(f'INSERT INTO pelanggan_akaun VALUES ({ph},{ph})',
                                 [(OWNER, 'owner@example.com'), (OTHER, 'other@example.com')])
        marketing.ensure_marketing_schema(conn, self.postgres)
        social.ensure_social_schema(conn, self.postgres)
        conn.commit()
        conn.close()
        self.calls = []

    def connect(self):
        return sqlite3.connect(self.path, timeout=10)

    def rows(self, sql, args=()):
        conn = self.connect()
        try:
            cur = conn.cursor()
            cur.execute(sql.replace('?', '%s') if self.postgres else sql, args)
            result = cur.fetchall() if cur.description else []
            conn.commit()
            return result
        finally:
            conn.close()

    def connection(self, platform='facebook', owner=OWNER, token=TOKEN, account_id=None):
        conn = self.connect()
        try:
            candidate = {'platform': platform, 'account_id': account_id or ('2001' if platform == 'instagram' else '3001' if platform == 'threads' else '1001'),
                'account_name': 'Saved business account', 'page_id': '' if platform == 'threads' else '1001',
                'token_cipher': social._encrypt(token), 'token_expires_at': self.now + 60 * 86400,
                'scopes_json': json.dumps(social.SCOPES[platform]),
                'provider_app_id': '67890' if platform == 'threads' else '12345',
                'provider_user_id': '3001' if platform == 'threads' else '5001',
                'authorization_started_at': self.now}
            result = social._save_connection(conn, self.postgres, owner, candidate, self.now)
            conn.commit()
            return result
        finally:
            conn.close()

    def debug(self, platform='facebook', **extra):
        result = {'is_valid': True, 'type': 'USER' if platform == 'threads' else 'PAGE',
            'user_id': '3001' if platform == 'threads' else '5001',
            'expires_at': 0 if platform != 'threads' else self.now + 3600,
            'data_access_expires_at': 0,
            'scopes': [scope for scope in social.SCOPES[platform] if scope != 'pages_show_list']}
        if platform != 'threads':
            result.update(app_id='12345', profile_id='1001')
        return {**result, **extra}

    def transport(self, debug, callback=None):
        def request(method, url, params, token):
            self.calls.append((method, url))
            self.assertEqual(method, 'GET')
            if url.endswith('/oauth/access_token'):
                self.assertEqual(params['grant_type'], 'client_credentials')
                return {'access_token': APP_TOKEN}
            self.assertTrue(url.endswith('/debug_token'))
            self.assertEqual(token, APP_TOKEN)
            if callback:
                callback()
            if isinstance(debug, Exception):
                raise debug
            return {'data': debug}
        return request

    def check(self, platform='facebook', debug=None, transport=None, owner=OWNER, payload=None):
        conn = self.connect()
        try:
            return social.dispatch_social('POST', social.PREFIX + '/' + platform + '/check', conn,
                self.postgres, owner, {} if payload is None else payload, self.now,
                transport or self.transport(self.debug(platform) if debug is None else debug))
        finally:
            conn.close()

    def test_valid_page_token_does_not_require_user_discovery_scope_or_change_grant(self):
        self.connection()
        before = self.rows('SELECT * FROM marketing_connections')
        code, result = self.check()
        self.assertEqual(code, 200, result)
        self.assertEqual(result['health_check']['status'], 'valid')
        self.assertEqual(result['connection']['status'], 'connected')
        self.assertTrue(result['connection']['publishable'])
        self.assertEqual(self.rows('SELECT * FROM marketing_connections'), before)
        self.assertEqual(len(self.calls), 2)

    def test_definitive_invalid_token_overrides_future_expiry_and_keeps_account_name(self):
        self.connection()
        code, result = self.check(debug=self.debug(is_valid=False, scopes=['public_profile'], error={'message': TOKEN}))
        self.assertEqual(code, 200)
        self.assertEqual(result['health_check']['status'], 'invalid')
        self.assertEqual(result['connection']['status'], 'reconnect_required')
        self.assertFalse(result['connection']['publishable'])
        self.assertEqual(result['connection']['accounts'][0]['name'], 'Saved business account')
        self.assertEqual(result['health_check']['missing_permissions'], ['pages_manage_posts', 'pages_read_engagement'])
        self.assertEqual(self.rows('SELECT status FROM marketing_connections'), [('reconnect_required',)])
        serialized = json.dumps(result)
        for secret in (TOKEN, APP_TOKEN, 'synthetic-meta-secret', 'token_cipher'):
            self.assertNotIn(secret, serialized)

    def test_instagram_missing_page_read_permission_is_not_publishable(self):
        self.connection('instagram')
        code, result = self.check('instagram', self.debug('instagram', scopes=['instagram_basic', 'instagram_content_publish']))
        self.assertEqual(code, 200)
        self.assertEqual(result['health_check']['code'], 'permissions_missing')
        self.assertEqual(result['health_check']['missing_permissions'], ['pages_read_engagement'])
        self.assertEqual(result['connection']['status'], 'reconnect_required')

    def test_documented_threads_response_without_app_id_is_valid(self):
        self.connection('threads')
        code, result = self.check('threads')
        self.assertEqual(code, 200, result)
        self.assertEqual(result['health_check']['status'], 'valid')
        self.assertTrue(all('graph.threads.com/' in url for _method, url in self.calls))

    def test_transient_malformed_and_debugger_auth_errors_preserve_saved_status(self):
        self.connection()
        before = self.rows('SELECT * FROM marketing_connections')
        samples = [TimeoutError(TOKEN), {'is_valid': 'false'}, {'scopes': []},
                   self.debug(scopes=None), self.debug(expires_at=True),
                   self.debug(app_id='malformed'), self.debug(profile_id={'unexpected': TOKEN})]
        for sample in samples:
            with self.subTest(sample=type(sample).__name__):
                code, result = self.check(debug=sample)
                self.assertEqual(code, 200, result)
                self.assertEqual(result['health_check']['status'], 'unknown')
                self.assertEqual(self.rows('SELECT * FROM marketing_connections'), before)
                self.assertNotIn(TOKEN, json.dumps(result))
        def invalid_app(method, url, params, token):
            return {'error': {'code': 190, 'message': APP_TOKEN}}
        code, result = self.check(transport=invalid_app)
        self.assertEqual((code, result['health_check']['status']), (200, 'unknown'))
        self.assertEqual(self.rows('SELECT * FROM marketing_connections'), before)
        self.assertNotIn(APP_TOKEN, json.dumps(result))

    def test_expired_provider_evidence_requires_reconnection(self):
        self.connection()
        code, result = self.check(debug=self.debug(expires_at=self.now - 1))
        self.assertEqual(code, 200)
        self.assertEqual(result['health_check']['code'], 'grant_expired')
        self.assertEqual(result['connection']['status'], 'reconnect_required')

    def test_wrong_page_or_authorizer_is_definitive_and_owner_scoped(self):
        self.connection(owner=OTHER)
        for extra in ({'profile_id': '9999'}, {'user_id': '9999'}, {'app_id': '9999'}, {'type': 'USER'}):
            with self.subTest(extra=extra):
                self.connection()
                code, result = self.check(debug=self.debug(**extra))
                self.assertEqual((code, result['health_check']['code']), (200, 'grant_identity_changed'))
                self.assertEqual(self.rows('SELECT status FROM marketing_connections WHERE akaun_id=?', (OTHER,)), [('connected',)])

    def test_old_invalid_result_cannot_poison_new_oauth_grant(self):
        self.connection()
        changed = []
        def replace():
            self.connection(token='new-private-grant', account_id='9999')
            changed.append(True)
        code, result = self.check(transport=self.transport(self.debug(is_valid=False), replace))
        self.assertEqual((code, result['health_check']['code']), (200, 'connection_changed'))
        self.assertEqual(result['health_check']['status'], 'unknown')
        self.assertEqual(result['connection']['status'], 'connected')
        self.assertEqual(result['connection']['accounts'][0]['account_id'], '9999')
        self.assertEqual(self.rows('SELECT status FROM marketing_connections'), [('connected',)])
        self.assertTrue(changed)

    def test_old_valid_result_does_not_restore_disconnected_grant(self):
        self.connection()
        def disconnect():
            conn = self.connect()
            try:
                social._disconnect(conn, self.postgres, OWNER, 'facebook', self.now + 1)
            finally:
                conn.close()
        code, result = self.check(transport=self.transport(self.debug(), disconnect))
        self.assertEqual((code, result['health_check']['code']), (200, 'connection_changed'))
        self.assertEqual(result['connection']['status'], 'not_connected')
        self.assertEqual(self.rows('SELECT status,token_cipher FROM marketing_connections'), [('disconnected', '')])

    def test_check_does_not_reconnect_previously_invalidated_grant(self):
        self.connection()
        self.rows("UPDATE marketing_connections SET status='reconnect_required'")
        code, result = self.check()
        self.assertEqual((code, result['health_check']['code']), (200, 'reconnection_required'))
        self.assertEqual(result['connection']['status'], 'reconnect_required')

    def test_check_never_edits_posts_or_publish_attempts(self):
        self.connection()
        self.rows('''INSERT INTO marketing_posts
            (id,akaun_id,campaign_id,data_json,version,status,scheduled_at,provider_post_id,last_error,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)''', ('a' * 32, OWNER, 'b' * 32, '{}', 3, 'published', '', '1001_55', '', 'before', 'before'))
        before = self.rows('SELECT * FROM marketing_posts')
        self.assertEqual(self.check(debug=self.debug(is_valid=False))[0], 200)
        self.assertEqual(self.rows('SELECT * FROM marketing_posts'), before)
        self.assertEqual(self.rows('SELECT * FROM marketing_publish_attempts'), [])

    def test_owner_auth_and_request_shape_are_enforced(self):
        self.connection()
        self.assertEqual(self.check(owner=OTHER)[0], 404)
        self.assertEqual(self.check(payload={'access_token': 'do-not-accept'})[0], 400)
        self.assertEqual(self.calls, [])
        code, result = account.dispatch('POST', social.PREFIX + '/facebook/check', None, '', b'{}',
            self.connect, self.postgres, SECRET, 'test-ip', self.now)
        self.assertEqual(code, 401, result)
        self.assertEqual(self.calls, [])

    def test_health_capability_is_exposed_without_claiming_worker_health(self):
        capabilities = social.capabilities()
        self.assertTrue(capabilities['connection_health_checks'])
        self.assertEqual(capabilities['worker_health'], 'not_checked')


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not enabled')
class PostgresConnectionHealthTests(ConnectionHealthTests):
    postgres = True
    connect = _postgres_test_connection


if __name__ == '__main__':
    unittest.main()
