"""Signed callback, grant isolation and publication races. No provider requests."""
import base64
import concurrent.futures
import hashlib
import hmac
import http.client
import json
import os
import sqlite3
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer
from pathlib import Path
from unittest.mock import Mock, patch
from urllib.parse import urlencode

from cryptography.fernet import Fernet

import akaun_pelayan
import marketing_lifecycle as lifecycle
import marketing_social as social
import marketing_worker as worker
import pelanggan_marketing as marketing
import pelanggan_meja as meja
import test_marketing_social as fixtures
from test_marketing import _postgres_test_connection, _reset_postgres_fixture

NOW, OWNER, OTHER, CAMPAIGN, POST, TOKEN = (
    fixtures.NOW, fixtures.OWNER, fixtures.OTHER, fixtures.CAMPAIGN, fixtures.POST, fixtures.TOKEN)
FORM = 'application/x-www-form-urlencoded'
STATUS_URL = 'https://akaun.example.test' + lifecycle.STATUS_PATH


def signed_form(payload=None, secret='synthetic-meta-secret'):
    payload = payload if payload is not None else {'algorithm': 'HMAC-SHA256', 'user_id': '5001', 'issued_at': NOW - 86400}
    encoded = base64.urlsafe_b64encode(json.dumps(payload, separators=(',', ':')).encode()).rstrip(b'=')
    signature = base64.urlsafe_b64encode(hmac.new(secret.encode(), encoded, hashlib.sha256).digest()).rstrip(b'=')
    return urlencode({'signed_request': (signature + b'.' + encoded).decode()}).encode()


class LifecycleCases:
    postgres = False
    meta_transport = fixtures.MarketingSocialTests.meta_transport
    begin = fixtures.MarketingSocialTests.begin

    def setUp(self):
        env = patch.dict(os.environ, {
            'MARKETING_TOKEN_KEY': Fernet.generate_key().decode(),
            'MARKETING_META_APP_ID': '12345', 'MARKETING_META_APP_SECRET': 'synthetic-meta-secret',
            'MARKETING_THREADS_APP_ID': '67890', 'MARKETING_THREADS_APP_SECRET': 'synthetic-threads-secret',
            'MARKETING_META_API_VERSION': 'v27.0', 'MARKETING_THREADS_API_VERSION': 'v1.0',
            'MARKETING_META_LOGIN_CONFIG_ID': '',
            'MARKETING_OAUTH_REDIRECT_URI': 'https://example.test/marketing/connect.html',
            'MARKETING_DATA_DELETION_STATUS_URL': STATUS_URL, 'MARKETING_PUBLISH_ENABLED': '1',
        })
        env.start()
        self.addCleanup(env.stop)
        network = patch('urllib.request.OpenerDirector.open', side_effect=AssertionError('Provider network forbidden'))
        self.network = network.start()
        self.addCleanup(network.stop)
        self.addCleanup(self.network.assert_not_called)
        if self.postgres:
            _reset_postgres_fixture(self.connect)
        else:
            tmp = tempfile.TemporaryDirectory()
            self.addCleanup(tmp.cleanup)
            self.path = str(Path(tmp.name) / 'lifecycle.db')
        conn = self.connect()
        cur = conn.cursor()
        ph = meja._ph(self.postgres)
        cur.execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT NOT NULL)')
        cur.executemany(f'INSERT INTO pelanggan_akaun VALUES ({ph},{ph})',
                        [(OWNER, 'a@example.test'), (OTHER, 'b@example.test'), ('e' * 32, 'c@example.test')])
        marketing.ensure_marketing_schema(conn, self.postgres)
        social.ensure_social_schema(conn, self.postgres)
        cur.execute(f'INSERT INTO marketing_campaigns VALUES ({",".join([ph] * 7)})',
                    (CAMPAIGN, OWNER, '{"title":"Kempen pemilik"}', 1, 'active', meja._iso(NOW), meja._iso(NOW)))
        conn.commit()
        conn.close()

    def connect(self):
        return sqlite3.connect(self.path, timeout=10)

    def query(self, sql, params=()):
        conn = self.connect()
        try:
            cur = conn.cursor()
            cur.execute(sql.replace('?', '%s') if self.postgres else sql, params)
            return cur.fetchall()
        finally:
            conn.close()

    def execute(self, sql, params=()):
        conn = self.connect()
        try:
            conn.cursor().execute(sql.replace('?', '%s') if self.postgres else sql, params)
            conn.commit()
        finally:
            conn.close()

    def call(self, method, suffix, payload=None, owner=OWNER, now=NOW, transport=None):
        conn = self.connect()
        try:
            return social.dispatch_social(method, social.PREFIX + suffix, conn, self.postgres, owner,
                                          payload if payload is not None else {}, now, transport)
        finally:
            conn.close()

    def callback(self, body=None, action='data-deletion', provider='meta', now=NOW + 1):
        return lifecycle.dispatch_provider('POST', lifecycle.PREFIX + '/' + provider + '/' + action,
                                           signed_form() if body is None else body, FORM, '',
                                           self.connect, self.postgres, now)

    def connection(self, platform='facebook', owner=OWNER, user_id='5001', app_id=None, now=NOW):
        conn = self.connect()
        candidate = {'platform': platform, 'account_id': '3001' if platform == 'threads' else '1001',
                     'account_name': 'Nama daripada provider', 'page_id': '' if platform == 'threads' else '1001',
                     'token_cipher': social._encrypt(TOKEN), 'token_expires_at': NOW + 86400,
                     'scopes_json': json.dumps(social.SCOPES[platform]),
                     'provider_app_id': app_id or ('67890' if platform == 'threads' else '12345'),
                     'provider_user_id': user_id, 'authorization_started_at': now}
        try:
            result = social._save_connection(conn, self.postgres, owner, candidate, now)
            conn.commit()
            return result
        finally:
            conn.close()

    def post(self, key=POST, status='queued', provider_id=''):
        data = {'campaign_id': CAMPAIGN, 'platform': 'facebook', 'format': 'text',
                'body': 'Teks ditulis pelanggan', 'media_url': ''}
        self.execute('''INSERT INTO marketing_posts
                        (id,akaun_id,campaign_id,data_json,version,status,scheduled_at,provider_post_id,created_at,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?)''',
                     (key, OWNER, CAMPAIGN, json.dumps(data), 1, status, meja._iso(NOW), provider_id,
                      meja._iso(NOW), meja._iso(NOW)))
        conn = self.connect()
        try:
            ok, reason = social.check_schedule(conn, self.postgres, OWNER, {'id': key, **data}, meja._iso(NOW), NOW)
            self.assertTrue(ok, reason)
            conn.commit()
        finally:
            conn.close()

    def connect_oauth(self, now=NOW + 2):
        state = self.begin(now=now)
        status, data = self.call('POST', '/complete', {'code': 'valid-code', 'state': state},
                                 now=now, transport=self.meta_transport)
        self.assertEqual(status, 200, data)
        status, data = self.call('POST', '/facebook/select', {'candidate_id': data['candidates'][0]['id']}, now=now)
        self.assertEqual(status, 200, data)
        return data

    def test_forged_malformed_and_unsupported_callbacks_never_open_database(self):
        forbidden = Mock(side_effect=AssertionError('Unverified callback opened DB'))
        bad = [signed_form(secret='different-app-secret'), signed_form() + b'&signed_request=other',
               b'signed_request=%ZZ', signed_form({'algorithm': 'none', 'user_id': '5001'}),
               signed_form({'user_id': True}), b'x' * (lifecycle.MAX_BODY + 1)]
        for raw in bad:
            status, data = lifecycle.dispatch_provider('POST', lifecycle.PREFIX + '/meta/data-deletion',
                                                       raw, FORM, '', forbidden, self.postgres, NOW)
            self.assertIn(status, (400, 403))
            self.assertNotIn('5001', json.dumps(data))
        for method, path, raw, query, expected in [
                ('POST', '/threads/deauthorize', signed_form(secret='synthetic-threads-secret'), '', 503),
                ('GET', '/meta/deauthorize', b'', '', 405),
                ('GET', '/deletion-status', b'', 'code=short', 400)]:
            status, _ = lifecycle.dispatch_provider(method, lifecycle.PREFIX + path, raw, FORM, query,
                                                    forbidden, self.postgres, NOW)
            self.assertEqual(status, expected)
        forbidden.assert_not_called()

    def test_missing_or_untrusted_status_url_rejects_before_changes(self):
        self.connection()
        forbidden = Mock(side_effect=AssertionError('Invalid callback configuration opened DB'))
        for url in ['', 'http://example.test/status', 'https://example.test/elsewhere', STATUS_URL + '?x=1']:
            with patch.dict(os.environ, {'MARKETING_DATA_DELETION_STATUS_URL': url}):
                status, _ = lifecycle.dispatch_provider('POST', lifecycle.PREFIX + '/meta/data-deletion',
                                                        signed_form(), FORM, '', forbidden, self.postgres, NOW)
                self.assertEqual(status, 503)
        forbidden.assert_not_called()
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_connections'), [(1,)])

    def test_deletion_scopes_by_app_and_authorizer_and_preserves_authored_records(self):
        self.connection()
        self.connection('instagram')
        self.connection(owner=OTHER)
        self.connection('instagram', OTHER, user_id='9999')
        self.connection('threads', OTHER, user_id='5001')
        self.connection(owner='e' * 32, app_id='99999')
        self.post()
        self.post('f' * 32, 'published', '1001_9876')
        self.post('1' * 32, 'failed', '1001_8765')
        self.post('2' * 32, 'publishing')
        self.execute('''INSERT INTO marketing_publish_attempts
                        (id,post_id,akaun_id,stage,container_id,provider_post_id,available_at,lease_until,worker_id,created_at,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)''',
                     ('3' * 32, '2' * 32, OWNER, 'requesting_facebook', '123', '456', NOW, NOW + 120,
                      'synthetic-worker', meja._iso(NOW), meja._iso(NOW)))
        state = self.begin(now=NOW)
        status, _ = self.call('POST', '/complete', {'code': 'valid-code', 'state': state}, transport=self.meta_transport)
        self.assertEqual(status, 200)
        authored = self.query('SELECT id,data_json FROM marketing_posts ORDER BY id')
        campaigns = self.query('SELECT * FROM marketing_campaigns')
        owners = self.query('SELECT * FROM pelanggan_akaun ORDER BY id')
        status, result = self.callback()
        self.assertEqual(status, 200, result)
        self.assertRegex(result['confirmation_code'], r'^[a-f0-9]{64}$')
        self.assertEqual(result['url'], STATUS_URL + '?code=' + result['confirmation_code'])
        self.assertNotIn('5001', json.dumps(result))
        self.assertEqual(self.query('SELECT akaun_id,platform FROM marketing_connections ORDER BY akaun_id,platform'),
                         [(OTHER, 'instagram'), (OTHER, 'threads'), ('e' * 32, 'facebook')])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_oauth_candidates'), [(0,)])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_oauth_states'), [(0,)])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_post_bindings'), [(0,)])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_publish_attempts'), [(0,)])
        self.assertEqual(self.query('SELECT status FROM marketing_posts ORDER BY id'),
                         [('uncertain',), ('uncertain',), ('cancelled',), ('published',)])
        self.assertEqual(self.query("SELECT COUNT(*) FROM marketing_posts WHERE provider_post_id<>'' OR attempt_id<>''"), [(0,)])
        self.assertEqual(self.query('SELECT id,data_json FROM marketing_posts ORDER BY id'), authored)
        self.assertEqual(self.query('SELECT * FROM marketing_campaigns'), campaigns)
        self.assertEqual(self.query('SELECT * FROM pelanggan_akaun ORDER BY id'), owners)

    def test_retry_and_equivalent_payload_cannot_remove_a_later_reconnection(self):
        self.connection()
        status, first = self.callback()
        self.assertEqual(status, 200)
        self.connect_oauth(now=NOW + 2)
        reordered = signed_form({'issued_at': NOW - 86400, 'user_id': '5001', 'algorithm': 'HMAC-SHA256'})
        status, again = self.callback(reordered, now=NOW + 3)
        self.assertEqual((status, again), (200, first))
        self.assertEqual(self.query('SELECT status FROM marketing_connections'), [('connected',)])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_provider_receipts'), [(1,)])
        self.assertEqual(self.callback(action='deauthorize', now=NOW + 4), (200, {'ok': True}))
        self.assertEqual(self.query('SELECT status FROM marketing_connections'), [('connected',)])

    def test_old_binding_is_removed_without_deleting_a_different_authorizers_connection(self):
        self.connection()
        self.post()
        self.connection(user_id='9999')  # Same Page, a different authorizing person.
        self.assertEqual(self.callback()[0], 200)
        self.assertEqual(self.query('SELECT provider_user_id,status FROM marketing_connections'), [('9999', 'connected')])
        self.assertEqual(self.query('SELECT status FROM marketing_posts'), [('cancelled',)])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_post_bindings'), [(0,)])

    def test_deauthorization_purges_without_a_deletion_status_url(self):
        self.connection()
        with patch.dict(os.environ, {'MARKETING_DATA_DELETION_STATUS_URL': ''}):
            self.assertEqual(self.callback(action='deauthorize'), (200, {'ok': True}))
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_connections'), [(0,)])

    def test_legacy_connection_is_preserved_by_migration_but_requires_reauthorization(self):
        self.connection()
        self.execute("UPDATE marketing_connections SET provider_app_id='',provider_user_id='',authorization_started_at=0")
        before = self.query('SELECT token_cipher,account_name FROM marketing_connections')
        conn = self.connect()
        try:
            social.ensure_social_schema(conn, self.postgres)
            conn.commit()
        finally:
            conn.close()
        self.assertEqual(self.query('SELECT token_cipher,account_name FROM marketing_connections'), before)
        status, body = self.call('GET', '')
        self.assertEqual(status, 200)
        self.assertEqual(body['connections'][0]['status'], 'reconnect_required')
        self.assertFalse(body['connections'][0]['publishable'])

    def test_old_oauth_completion_cannot_restore_revoked_grant(self):
        state = self.begin()
        called = []
        def transport(method, url, params, token):
            if url.endswith('/me/accounts'):
                called.append(True)
                self.assertEqual(self.callback(now=NOW + 1)[0], 200)
            return self.meta_transport(method, url, params, token)
        status, _ = self.call('POST', '/complete', {'code': 'valid-code', 'state': state}, now=NOW,
                              transport=transport)
        self.assertEqual(status, 409)
        self.assertEqual(called, [True])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_oauth_candidates'), [(0,)])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_connections'), [(0,)])

    def test_revocation_watermark_never_moves_backwards(self):
        self.assertEqual(self.callback(signed_form({'user_id': '5001', 'issued_at': 2}), now=NOW + 200)[0], 200)
        self.assertEqual(self.callback(signed_form({'user_id': '5001', 'issued_at': 1}), now=NOW + 100)[0], 200)
        self.assertEqual(self.query('SELECT revoked_at FROM marketing_provider_subjects'), [(NOW + 200,)])
        state = self.begin(now=NOW + 150)
        status, _ = self.call('POST', '/complete', {'code': 'valid-code', 'state': state}, now=NOW + 150,
                              transport=self.meta_transport)
        self.assertEqual(status, 409)

    def test_simultaneous_retries_return_one_receipt(self):
        self.connection()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _index: self.callback(), range(2)))
        self.assertEqual(results[0], results[1])
        self.assertEqual(results[0][0], 200)
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_provider_receipts'), [(1,)])

    def test_callback_during_external_request_keeps_uncertain_without_provider_metadata(self):
        self.connection()
        self.post()
        calls = []
        def transport(*_args):
            calls.append(True)
            self.assertEqual(self.callback()[0], 200)
            return {'id': '1001_9001'}
        worker.run_once(self.connect, self.postgres, transport, NOW)
        self.assertEqual(calls, [True])
        self.assertEqual(self.query('SELECT status,provider_post_id FROM marketing_posts'), [('uncertain', '')])
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_publish_attempts'), [(0,)])
        self.assertEqual(worker.run_once(self.connect, self.postgres, lambda *_: self.fail('Must not retry'), NOW + 200)['processed'], 0)

    def test_revoked_old_request_cannot_poison_a_new_connection(self):
        old = self.connection()
        self.post()
        def transport(*_args):
            self.assertEqual(self.callback()[0], 200)
            new = self.connect_oauth(now=NOW + 2)
            self.assertNotEqual(new['account']['id'], old['id'])
            return {'error': {'code': 190}}
        worker.run_once(self.connect, self.postgres, transport, NOW)
        self.assertEqual(self.query('SELECT status FROM marketing_connections'), [('connected',)])
        self.assertEqual(self.query('SELECT status FROM marketing_posts'), [('uncertain',)])

    def test_old_token_failure_cannot_poison_an_ordinary_same_person_reconnect(self):
        old = self.connection()
        old_cipher = self.query('SELECT token_cipher FROM marketing_connections')[0][0]
        self.post()
        def transport(*_args):
            new = self.connect_oauth(now=NOW + 2)
            self.assertEqual(new['account']['id'], old['id'])
            self.assertNotEqual(self.query('SELECT token_cipher FROM marketing_connections')[0][0], old_cipher)
            return {'error': {'code': 190}}
        worker.run_once(self.connect, self.postgres, transport, NOW)
        self.assertEqual(self.query('SELECT status FROM marketing_connections'), [('connected',)])
        self.assertEqual(self.query('SELECT status FROM marketing_posts'), [('failed',)])

    def test_status_code_is_opaque_and_only_reports_local_completion(self):
        _, receipt = self.callback()
        for code, expected in [(receipt['confirmation_code'], 200), ('0' * 64, 404)]:
            status, body = lifecycle.dispatch_provider('GET', lifecycle.STATUS_PATH, b'', None, 'code=' + code,
                                                       self.connect, self.postgres, NOW)
            self.assertEqual(status, expected)
            self.assertNotIn('5001', json.dumps(body))
            self.assertNotIn('akaun_id', json.dumps(body))
            self.assertNotIn('5001', lifecycle.status_html(status).decode())


class MarketingLifecycleTests(LifecycleCases, unittest.TestCase):
    def test_http_callbacks_bypass_bearer_but_require_signature_and_bound_body(self):
        server = ThreadingHTTPServer(('127.0.0.1', 0), akaun_pelayan.ikat(self.connect, False))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        def request(method, path, body=None, headers=None):
            client = http.client.HTTPConnection('127.0.0.1', server.server_address[1], timeout=5)
            try:
                client.request(method, path, body, headers or {})
                response = client.getresponse()
                return response.status, dict(response.getheaders()), response.read()
            finally:
                client.close()
        with patch('akaun_pelayan.account_secret', side_effect=AssertionError('Provider callbacks must not require account bearer')):
            path = lifecycle.PREFIX + '/meta/data-deletion'
            status, _, _ = request('POST', path, signed_form(secret='wrong-secret'), {'Content-Type': FORM})
            self.assertEqual(status, 403)
            status, _, _ = request('POST', path, b'x' * (lifecycle.MAX_BODY + 1), {'Content-Type': FORM})
            self.assertEqual(status, 413)
            status, _, raw = request('POST', path, signed_form(), {'Content-Type': FORM})
            self.assertEqual(status, 200)
            code = json.loads(raw)['confirmation_code']
            status, headers, raw = request('GET', lifecycle.STATUS_PATH + '?code=' + code)
            self.assertEqual(status, 200)
            self.assertEqual(headers['Content-Type'], 'text/html; charset=utf-8')
            self.assertEqual(headers['Cache-Control'], 'no-store')
            self.assertEqual(headers['Referrer-Policy'], 'no-referrer')
            self.assertIn("default-src 'none'", headers['Content-Security-Policy'])
            self.assertNotIn(b'5001', raw)


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not enabled')
class PostgresMarketingLifecycleTests(LifecycleCases, unittest.TestCase):
    postgres = True
    connect = _postgres_test_connection


if __name__ == '__main__':
    unittest.main()
