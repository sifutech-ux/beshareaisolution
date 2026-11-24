"""Social integration tests use fake transport only. No requests leave this suite."""
import hashlib
import json
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.parse import parse_qs, urlsplit

from cryptography.fernet import Fernet

import marketing_social as social
import marketing_worker as worker
import pelanggan_marketing as marketing
import pelanggan_meja as meja


NOW = 1_900_000_000
OWNER = 'a' * 32
OTHER = 'b' * 32
CAMPAIGN = 'c' * 32
POST = 'd' * 32
TOKEN = 'provider-token-never-in-client'
USER_TOKEN = 'synthetic-user-access'
APP_TOKEN = 'synthetic-app-access'


class MarketingSocialTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'social.db')
        self.env = patch.dict(os.environ, {
            'MARKETING_TOKEN_KEY': Fernet.generate_key().decode(),
            'MARKETING_META_APP_ID': '12345', 'MARKETING_META_APP_SECRET': 'secret-meta',
            'MARKETING_THREADS_APP_ID': '67890', 'MARKETING_THREADS_APP_SECRET': 'secret-threads',
            'MARKETING_OAUTH_REDIRECT_URI': 'https://beshareaisolution.com/marketing/connect.html',
            'MARKETING_META_API_VERSION': 'v27.0', 'MARKETING_THREADS_API_VERSION': 'v1.0',
            'MARKETING_PUBLISH_ENABLED': '1',
        })
        self.env.start()
        self.addCleanup(self.env.stop)
        conn = self.connect()
        conn.execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT)')
        conn.executemany('INSERT INTO pelanggan_akaun VALUES (?,?)', [(OWNER, 'a@example.test'), (OTHER, 'b@example.test')])
        from access_fixture import grant_existing
        grant_existing(conn, postgres=False)
        marketing.ensure_marketing_schema(conn, False)
        social.ensure_social_schema(conn, False)
        conn.execute('INSERT INTO marketing_campaigns VALUES (?,?,?,?,?,?,?)',
                     (CAMPAIGN, OWNER, '{}', 1, 'active', meja._iso(NOW), meja._iso(NOW)))
        conn.commit()
        conn.close()

    def connect(self):
        return sqlite3.connect(self.path, timeout=10)

    def call(self, method, path, payload=None, owner=OWNER, now=NOW, transport=None):
        conn = self.connect()
        try:
            return social.dispatch_social(method, social.PREFIX + path, conn, False, owner,
                                          payload if payload is not None else {}, now, transport)
        finally:
            conn.close()

    def begin(self, platform='facebook', owner=OWNER, now=NOW):
        status, body = self.call('POST', '/' + platform + '/begin', owner=owner, now=now)
        self.assertEqual(status, 200, body)
        return parse_qs(urlsplit(body['authorization_url']).query)['state'][0]

    def meta_transport(self, method, url, params, token):
        if url.endswith('/oauth/access_token'):
            return {'access_token': APP_TOKEN if params.get('grant_type') == 'client_credentials' else USER_TOKEN,
                    'expires_in': 60 * 86400}
        if url.endswith('/debug_token'):
            page = params.get('input_token') == TOKEN
            self.assertEqual(token, APP_TOKEN)
            return {'data': {'app_id': '12345', 'user_id': '5001', 'is_valid': True,
                             'type': 'PAGE' if page else 'USER', **({'profile_id': '1001'} if page else {}),
                             'expires_at': NOW + 60 * 86400,
                             'scopes': sorted(set(social.SCOPES['facebook'] + social.SCOPES['instagram']))}}
        if url.endswith('/me'):
            return {'id': '1001' if token == TOKEN else '5001'}
        if url.endswith('/me/permissions'):
            return {'data': [{'permission': s, 'status': 'granted'} for s in set(social.SCOPES['facebook'] + social.SCOPES['instagram'])]}
        if url.endswith('/me/accounts'):
            return {'data': [{'id': '1001', 'name': 'Page Satu', 'access_token': TOKEN,
                              'tasks': ['CREATE_CONTENT'],
                              'instagram_business_account': {'id': '2001', 'username': 'kedai.satu'}}]}
        raise AssertionError('Unexpected endpoint')

    def threads_transport(self, method, url, params, token):
        if url.endswith('/oauth/access_token'):
            self.assertEqual(params['client_id'], '67890')
            self.assertEqual(params['client_secret'], 'secret-threads')
            return {'access_token': APP_TOKEN if params.get('grant_type') == 'client_credentials' else USER_TOKEN,
                    'user_id': '3001'}
        if url.endswith('/access_token'):
            return {'access_token': TOKEN, 'expires_in': 60 * 86400}
        if url.endswith('/debug_token'):
            self.assertEqual(token, APP_TOKEN)
            self.assertEqual(params['input_token'], TOKEN)
            # Documented Threads shape: app binding is enforced by the endpoint,
            # not by an app_id field in its response.
            return {'data': {'type': 'USER', 'application': 'Test Threads App',
                             'user_id': '3001', 'is_valid': True, 'issued_at': NOW,
                             'expires_at': NOW + 60 * 86400, 'data_access_expires_at': 0,
                             'scopes': list(social.SCOPES['threads'])}}
        if url.endswith('/me'):
            self.assertEqual(token, TOKEN)
            return {'id': '3001', 'username': 'kedai.threads'}
        raise AssertionError('Unexpected endpoint')

    def connection(self, platform='facebook', owner=OWNER, account='1001'):
        conn = self.connect()
        try:
            candidate = {'platform': platform, 'account_id': account, 'account_name': 'Kedai',
                         'page_id': '1001' if platform != 'threads' else '',
                         'token_cipher': social._encrypt(TOKEN), 'token_expires_at': NOW + 60 * 86400,
                         'scopes_json': json.dumps(social.SCOPES[platform]),
                         'provider_app_id': '67890' if platform == 'threads' else '12345',
                         'provider_user_id': '3001' if platform == 'threads' else '5001',
                         'authorization_started_at': NOW}
            social._save_connection(conn, False, owner, candidate, NOW)
            conn.commit()
        finally:
            conn.close()

    def queued_post(self, platform='facebook', form='text', key=POST, owner=OWNER, when=NOW,
                    media='', body='Promosi yang telah diluluskan'):
        self.connection(platform, owner, '2001' if platform == 'instagram' else '1001')
        conn = self.connect()
        data = {'campaign_id': CAMPAIGN, 'platform': platform, 'format': form, 'body': body, 'media_url': media}
        conn.execute('''INSERT INTO marketing_posts
                        (id,akaun_id,campaign_id,data_json,version,status,scheduled_at,created_at,updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?)''',
                     (key, owner, CAMPAIGN, json.dumps(data), 1, 'queued', meja._iso(when), meja._iso(NOW), meja._iso(NOW)))
        ready, reason = social.check_schedule(conn, False, owner, {'id': key, **data}, meja._iso(when), NOW)
        self.assertTrue(ready, reason)
        conn.commit()
        conn.close()

    def row(self, key=POST):
        conn = self.connect()
        try:
            cur = conn.execute('SELECT * FROM marketing_posts WHERE id=?', (key,))
            return social._row(cur)
        finally:
            conn.close()

    def test_configuration_fails_closed_without_key(self):
        with patch.dict(os.environ, {'MARKETING_TOKEN_KEY': ''}):
            status, body = self.call('POST', '/facebook/begin')
            self.assertEqual(status, 503)
            status, data = self.call('GET', '')
            self.assertFalse(data['connections'][0]['configured'])
            self.assertFalse(data['connections'][0]['publishable'])
        self.assertNotIn('secret-meta', json.dumps(body))

    def test_oauth_state_is_hashed_bound_to_owner_expiring_and_single_use(self):
        state = self.begin()
        conn = self.connect()
        stored = conn.execute('SELECT state_hash FROM marketing_oauth_states').fetchone()[0]
        conn.close()
        self.assertEqual(stored, hashlib.sha256(state.encode()).hexdigest())
        status, _ = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, owner=OTHER,
                              transport=lambda *_: self.fail('Foreign owner must not call provider'))
        self.assertEqual(status, 409)
        status, body = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, transport=self.meta_transport)
        self.assertEqual(status, 200, body)
        status, _ = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                              transport=lambda *_: self.fail('Replayed state must not call provider'))
        self.assertEqual(status, 409)
        expired = self.begin(now=NOW + 61)
        status, _ = self.call('POST', '/complete', {'code': 'validcode', 'state': expired}, now=NOW + 700,
                              transport=lambda *_: self.fail('Expired state must not call provider'))
        self.assertEqual(status, 409)

    def test_candidates_are_encrypted_owner_scoped_and_not_tokens_in_response(self):
        state = self.begin('instagram')
        status, body = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, transport=self.meta_transport)
        self.assertEqual(status, 200, body)
        self.assertEqual(body['candidates'][0]['name'], 'kedai.satu')
        self.assertNotIn(TOKEN, json.dumps(body))
        key = body['candidates'][0]['id']
        conn = self.connect()
        cipher = conn.execute('SELECT token_cipher FROM marketing_oauth_candidates').fetchone()[0]
        conn.close()
        self.assertNotIn(TOKEN, cipher)
        self.assertEqual(social._decrypt(cipher), TOKEN)
        status, _ = self.call('POST', '/instagram/select', {'candidate_id': key}, owner=OTHER)
        self.assertEqual(status, 404)
        status, selected = self.call('POST', '/instagram/select', {'candidate_id': key})
        self.assertEqual(status, 200, selected)
        self.assertNotIn(TOKEN, json.dumps(selected))
        _, visible = self.call('GET', '')
        _, hidden = self.call('GET', '', owner=OTHER)
        self.assertEqual(visible['connections'][1]['accounts'][0]['account_id'], '2001')
        self.assertEqual(hidden['connections'][1]['accounts'], [])
        self.assertNotIn('token_cipher', json.dumps(visible))

    def test_threads_exchanges_server_side_and_connects_one_account(self):
        state = self.begin('threads')
        seen = []
        def transport(method, url, params, token):
            seen.append((method, url))
            return self.threads_transport(method, url, params, token)
        status, data = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, transport=transport)
        self.assertEqual(status, 200, data)
        self.assertFalse(data['selection_required'])
        self.assertEqual(data['account']['account_id'], '3001')
        self.assertTrue(all('graph.threads.com' in url for _, url in seen))
        self.assertNotIn(TOKEN, json.dumps(data))
        conn = self.connect()
        self.assertEqual(conn.execute('SELECT provider_app_id,provider_user_id FROM marketing_connections').fetchall(),
                         [('67890', '3001')])
        conn.close()

    def test_threads_debugger_failures_cannot_save_connections(self):
        cases = ('missing_data', 'malformed_data', 'missing_validity', 'invalid_token',
                 'non_boolean_validity', 'missing_user', 'wrong_user', 'cross_app_error')
        for index, case in enumerate(cases):
            with self.subTest(case=case):
                stamp = NOW + index * 61
                state = self.begin('threads', now=stamp)
                seen = []
                def transport(method, url, params, token):
                    seen.append(url)
                    data = self.threads_transport(method, url, params, token)
                    if url.endswith('/debug_token'):
                        if case == 'missing_data':
                            return {}
                        if case == 'malformed_data':
                            return {'data': []}
                        if case == 'missing_validity':
                            del data['data']['is_valid']
                        elif case == 'invalid_token':
                            data['data']['is_valid'] = False
                        elif case == 'non_boolean_validity':
                            data['data']['is_valid'] = 1
                        elif case == 'missing_user':
                            del data['data']['user_id']
                        elif case == 'wrong_user':
                            data['data']['user_id'] = '9999'
                        elif case == 'cross_app_error':
                            return {'error': {'message': 'The App_id in the input_token did not match the Viewing App'}}
                    return data
                status, body = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                                         now=stamp, transport=transport)
                self.assertEqual(status, 502 if case == 'cross_app_error' else 409, body)
                self.assertEqual(body['code'], 'provider' if case == 'cross_app_error' else 'authorization')
                self.assertTrue(seen[-1].endswith('/me' if case in {'missing_user', 'wrong_user'} else '/debug_token'))
                conn = self.connect()
                self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_connections').fetchone()[0], 0)
                self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_oauth_candidates').fetchone()[0], 0)
                conn.close()
                for private_value in (TOKEN, USER_TOKEN, APP_TOKEN, 'secret-threads', 'The App_id'):
                    self.assertNotIn(private_value, json.dumps(body))

    def test_meta_grant_identity_and_page_token_must_both_be_verified(self):
        cases = ('empty_tasks', 'manage_only', 'wrong_app', 'wrong_user', 'missing_scope',
                 'wrong_page', 'expired_page', 'missing_page_scope')
        for index, case in enumerate(cases):
            with self.subTest(case=case):
                stamp = NOW + index * 61
                state = self.begin(now=stamp)
                def transport(method, url, params, token):
                    data = self.meta_transport(method, url, params, token)
                    page = params.get('input_token') == TOKEN
                    if url.endswith('/me/accounts'):
                        if case == 'empty_tasks':
                            data['data'][0]['tasks'] = []
                        elif case == 'manage_only':
                            data['data'][0]['tasks'] = ['MANAGE']
                    if url.endswith('/debug_token'):
                        if not page and case == 'wrong_app':
                            data['data']['app_id'] = '99999'
                        if not page and case == 'wrong_user':
                            data['data']['user_id'] = '9999'
                        if not page and case == 'missing_scope':
                            data['data']['scopes'] = ['pages_show_list']
                        if page and case == 'wrong_page':
                            data['data']['profile_id'] = '9999'
                        if page and case == 'expired_page':
                            data['data']['expires_at'] = NOW - 1
                        if page and case == 'missing_page_scope':
                            data['data']['scopes'] = ['pages_show_list']
                    return data
                status, body = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                                          now=stamp, transport=transport)
                self.assertEqual(status, 409, body)
                self.assertNotIn(TOKEN, json.dumps(body))
        conn = self.connect()
        self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_connections').fetchone()[0], 0)
        self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_oauth_candidates').fetchone()[0], 0)
        conn.close()

    def test_meta_page_lifetime_uses_its_own_verified_bounds(self):
        day = 86400
        # Debugger bounds are (token expiry, data-access expiry), relative to the
        # callback; an explicit zero is retained as provider metadata.
        cases = (
            ('omitted', {}, (0, 60 * day), (0, 70 * day), 70 * day),
            ('zero_integer', {'expires_in': 0}, (20 * day, 0), (0, 70 * day), 70 * day),
            ('zero_string', {'expires_in': '0'}, (0, 60 * day), (15 * day, 0), 15 * day),
            ('page_data_bound', {}, (0, 60 * day), (0, 10 * day), 10 * day),
            ('local_cap', {}, (0, 0), (0, 0), 90 * day),
            ('one_second', {'expires_in': 1}, (60 * day, 0), (60 * day, 0), 60 * day),
            ('positive_string', {'expires_in': '7200'}, (60 * day, 0), (60 * day, 0), 60 * day),
            ('short_user_nonexpiring_page', {'expires_in': 3600}, (3600, 0), (0, 0), 90 * day),
            ('short_user_page_data_bound', {'expires_in': 3600}, (3600, 0), (0, 70 * day), 70 * day),
            ('positive_debug_bound', {'expires_in': 60 * day}, (20 * day, 0), (10 * day, 0), 10 * day),
        )
        for platform_index, platform in enumerate(('facebook', 'instagram')):
            for index, (case, exchange, user_bounds, page_bounds, expected) in enumerate(cases):
                with self.subTest(platform=platform, case=case):
                    stamp = NOW + (platform_index * len(cases) + index) * 61
                    state = self.begin(platform, now=stamp)
                    def transport(method, url, params, token):
                        data = self.meta_transport(method, url, params, token)
                        if params.get('grant_type') == 'fb_exchange_token':
                            data.pop('expires_in')
                            data.update(exchange)
                        if url.endswith('/debug_token'):
                            bounds = page_bounds if params.get('input_token') == TOKEN else user_bounds
                            for field, seconds in zip(('expires_at', 'data_access_expires_at'), bounds):
                                data['data'][field] = stamp + seconds if seconds else 0
                        return data
                    status, body = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                                             now=stamp, transport=transport)
                    self.assertEqual(status, 200, body)
                    candidate = body['candidates'][0]['id']
                    conn = self.connect()
                    self.assertEqual(conn.execute('SELECT token_expires_at FROM marketing_oauth_candidates WHERE id=?',
                                                  (candidate,)).fetchone()[0], stamp + expected)
                    conn.close()
                    status, body = self.call('POST', '/' + platform + '/select', {'candidate_id': candidate}, now=stamp)
                    self.assertEqual(status, 200, body)
                    conn = self.connect()
                    try:
                        self.assertEqual(conn.execute('SELECT token_expires_at FROM marketing_connections WHERE platform=?',
                                                      (platform,)).fetchone()[0], stamp + expected)
                        # The workspace must remain publishable beyond a short
                        # USER lifetime and expire at the retained PAGE bound.
                        with patch.object(social.time, 'time', return_value=stamp + 7201):
                            connection = next(c for c in social.get_connections(conn, False, OWNER)
                                              if c['platform'] == platform)
                        self.assertEqual(connection['status'], 'connected')
                        self.assertTrue(connection['publishable'])
                        with patch.object(social.time, 'time', return_value=stamp + expected):
                            connection = next(c for c in social.get_connections(conn, False, OWNER)
                                              if c['platform'] == platform)
                        self.assertEqual(connection['status'], 'expired')
                        self.assertFalse(connection['publishable'])
                    finally:
                        conn.close()
                    self.assertNotIn(TOKEN, json.dumps(body))

    def test_meta_missing_malformed_or_expired_lifetime_evidence_saves_nothing(self):
        missing = object()
        cases = [
            ('missing_user_expiry', {}, 'USER', 'expires_at', missing),
            ('missing_page_expiry', {}, 'PAGE', 'expires_at', missing),
            ('positive_missing_page_expiry', {'expires_in': 3600}, 'PAGE', 'expires_at', missing),
            ('zero_missing_user', {'expires_in': 0}, 'USER', 'expires_at', missing),
            ('zero_missing_page', {'expires_in': '0'}, 'PAGE', 'expires_at', missing),
            ('malformed_user_expiry', {}, 'USER', 'expires_at', True),
            ('malformed_page_data_expiry', {}, 'PAGE', 'data_access_expires_at', 'invalid'),
            ('expired_user', {}, 'USER', 'expires_at', NOW - 1),
            ('expired_page', {'expires_in': 0}, 'PAGE', 'expires_at', NOW - 1),
            ('expired_user_data', {}, 'USER', 'data_access_expires_at', NOW - 1),
            ('expired_page_data', {'expires_in': '0'}, 'PAGE', 'data_access_expires_at', NOW - 1),
            ('positive_expired_user', {'expires_in': 3600}, 'USER', 'expires_at', NOW - 1),
            ('positive_expired_user_data', {'expires_in': 3600}, 'USER', 'data_access_expires_at', NOW - 1),
            ('positive_expired_page', {'expires_in': 3600}, 'PAGE', 'expires_at', NOW - 1),
            ('positive_malformed_page_expiry', {'expires_in': 3600}, 'PAGE', 'expires_at', True),
            ('positive_expired_page_data', {'expires_in': 3600}, 'PAGE', 'data_access_expires_at', NOW - 1),
        ]
        for value in (None, False, True, -1, '-1', 1.5, '1.5', '', 'invalid', [], {}):
            cases.append(('invalid_exchange_' + repr(value), {'expires_in': value}, None, None, None))
        for platform_index, platform in enumerate(('facebook', 'instagram')):
            for index, (case, exchange, kind, field, value) in enumerate(cases):
                with self.subTest(platform=platform, case=case):
                    stamp = NOW + (platform_index * len(cases) + index) * 61
                    state = self.begin(platform, now=stamp)
                    def transport(method, url, params, token):
                        data = self.meta_transport(method, url, params, token)
                        if params.get('grant_type') == 'fb_exchange_token':
                            data.pop('expires_in')
                            data.update(exchange)
                        if url.endswith('/debug_token') and data['data']['type'] == kind:
                            if value is missing:
                                data['data'].pop(field)
                            else:
                                data['data'][field] = value
                        return data
                    status, body = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                                             now=stamp, transport=transport)
                    self.assertEqual(status, 409, body)
                    self.assertEqual(body['code'], 'authorization')
                    conn = self.connect()
                    self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_oauth_candidates').fetchone()[0], 0)
                    self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_connections').fetchone()[0], 0)
                    conn.close()
                    self.assertNotIn(TOKEN, json.dumps(body))

    def test_verified_authorizer_is_saved_on_candidates_connections_and_bindings(self):
        state = self.begin()
        status, data = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, transport=self.meta_transport)
        self.assertEqual(status, 200, data)
        conn = self.connect()
        self.assertEqual(conn.execute('SELECT provider_app_id,provider_user_id FROM marketing_oauth_candidates').fetchall(),
                         [('12345', '5001')])
        conn.close()
        self.assertEqual(self.call('POST', '/facebook/select', {'candidate_id': data['candidates'][0]['id']})[0], 200)
        self.queued_post()
        conn = self.connect()
        for table in ('marketing_connections', 'marketing_post_bindings'):
            self.assertEqual(conn.execute(f'SELECT provider_app_id,provider_user_id FROM {table}').fetchall(),
                             [('12345', '5001')])
        conn.close()

    def test_page_tokens_need_actions_while_user_token_proves_page_discovery(self):
        for index, platform in enumerate(('facebook', 'instagram')):
            with self.subTest(platform=platform):
                stamp = NOW + 61 * index
                state = self.begin(platform, now=stamp)
                def transport(method, url, params, token):
                    data = self.meta_transport(method, url, params, token)
                    if url.endswith('/debug_token') and params.get('input_token') == TOKEN:
                        data['data']['scopes'].remove('pages_show_list')
                    return data
                status, data = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                                          now=stamp, transport=transport)
                self.assertEqual(status, 200, data)
                status, data = self.call('POST', '/' + platform + '/select',
                                          {'candidate_id': data['candidates'][0]['id']}, now=stamp)
                self.assertEqual(status, 200, data)
                conn = self.connect()
                scopes = conn.execute('SELECT scopes_json FROM marketing_connections WHERE platform=?', (platform,)).fetchone()[0]
                self.assertEqual(set(json.loads(scopes)), set(social.SCOPES[platform]))
                ready, reason = social.check_schedule(conn, False, OWNER, {
                    'id': ('1' if platform == 'facebook' else '2') * 32, 'platform': platform,
                    'format': 'image', 'body': 'Kandungan pelanggan', 'media_url': 'https://example.test/image.jpg'},
                    meja._iso(stamp + 120), stamp)
                self.assertTrue(ready, reason)
                conn.commit()
                conn.close()

    def test_threads_missing_publishing_grant_cannot_become_connected(self):
        state = self.begin('threads')
        seen = []
        def transport(method, url, params, token):
            seen.append(url)
            data = self.threads_transport(method, url, params, token)
            if url.endswith('/debug_token'):
                data['data']['scopes'] = ['threads_basic']
            return data
        status, data = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, transport=transport)
        self.assertEqual(status, 409, data)
        self.assertEqual(data['code'], 'authorization')
        self.assertTrue(seen[-1].endswith('/debug_token'))
        conn = self.connect()
        self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_connections').fetchone()[0], 0)
        conn.close()
        self.assertNotIn(TOKEN, json.dumps(data))

    def test_provider_destination_is_fixed_and_errors_redacted(self):
        for url in ['https://evil.test/me', 'https://graph.facebook.com@evil.test/me',
                    'http://graph.facebook.com/me', 'https://graph.facebook.com/me?access_token=bad']:
            with self.assertRaises(social.SocialError):
                social.provider_call('GET', url, transport=lambda *_: self.fail('Must not request disallowed URL'))
        with self.assertRaises(social.SocialError) as caught:
            social.provider_call('POST', 'https://graph.facebook.com/v27.0/1001/feed',
                                 transport=lambda *_: {'error': {'code': 190, 'message': TOKEN}})
        self.assertNotIn(TOKEN, str(caught.exception))
        self.assertEqual(caught.exception.kind, 'authorization')

    def test_disconnect_removes_cipher_and_pending_states(self):
        self.connection()
        self.begin()
        status, _ = self.call('DELETE', '/facebook')
        self.assertEqual(status, 200)
        conn = self.connect()
        self.assertEqual(conn.execute('SELECT token_cipher,status FROM marketing_connections').fetchone(), ('', 'disconnected'))
        self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_oauth_states').fetchone()[0], 0)
        conn.close()

    def test_worker_publishes_once_and_records_provider_id(self):
        self.queued_post()
        calls = []
        def transport(method, url, params, token):
            calls.append(url)
            self.assertEqual(token, TOKEN)
            return {'id': '1001_9001'}
        first = worker.run_once(self.connect, False, transport, NOW)
        second = worker.run_once(self.connect, False, transport, NOW + 1)
        self.assertEqual(first['published'], 1)
        self.assertEqual(second['processed'], 0)
        self.assertEqual(len(calls), 1)
        self.assertEqual(self.row()['provider_post_id'], '1001_9001')
        self.assertEqual(self.row()['version'], 3)

    def test_future_posts_and_disabled_worker_never_call_provider(self):
        self.queued_post(when=NOW + 1000)
        forbidden = lambda *_: self.fail('Must not publish')
        self.assertEqual(worker.run_once(self.connect, False, forbidden, NOW)['processed'], 0)
        with patch.dict(os.environ, {'MARKETING_PUBLISH_ENABLED': '0'}):
            self.assertFalse(worker.run_once(self.connect, False, forbidden, NOW + 1001)['enabled'])

    def test_reselecting_account_does_not_redirect_a_queued_post(self):
        self.queued_post()
        self.connection(account='9999')
        result = worker.run_once(self.connect, False, lambda *_: self.fail('Wrong target must not publish'), NOW)
        self.assertEqual(result['failed'], 1)
        self.assertEqual(self.row()['status'], 'failed')

    def test_ambiguous_post_response_is_never_retried(self):
        self.queued_post()
        calls = []
        def lost(*args):
            calls.append(args[1])
            raise TimeoutError(TOKEN)
        result = worker.run_once(self.connect, False, lost, NOW)
        self.assertEqual(result['uncertain'], 1)
        self.assertEqual(self.row()['status'], 'uncertain')
        worker.run_once(self.connect, False, lost, NOW + 1000)
        self.assertEqual(len(calls), 1)
        self.assertNotIn(TOKEN, self.row()['last_error'])

    def test_success_without_id_is_uncertain_not_editable_failed(self):
        self.queued_post()
        result = worker.run_once(self.connect, False, lambda *_: {'success': True}, NOW)
        self.assertEqual(result['uncertain'], 1)
        self.assertEqual(self.row()['status'], 'uncertain')

    def test_crashed_request_marker_is_recovered_without_posting_again(self):
        self.queued_post()
        conn = self.connect()
        job = worker._claim(conn, False, 'dead-worker', NOW)
        worker._advance_record(conn, False, job, 'dead-worker', NOW, 'requesting_facebook')
        conn.close()
        result = worker.run_once(self.connect, False, lambda *_: self.fail('Must not replay ambiguous request'), NOW + 121)
        self.assertEqual(result['uncertain'], 1)
        self.assertEqual(self.row()['status'], 'uncertain')

    def test_lease_prevents_second_worker_claim(self):
        self.queued_post()
        one, two = self.connect(), self.connect()
        try:
            job = worker._claim(one, False, 'one', NOW)
            self.assertIsNotNone(job)
            self.assertIsNone(worker._claim(two, False, 'two', NOW))
        finally:
            one.close()
            two.close()

    def test_rate_limit_retries_are_bounded_and_explicit(self):
        self.queued_post()
        calls = []
        def limited(*args):
            calls.append(args[1])
            return {'error': {'code': 4, 'message': 'secret-provider-detail'}}
        for at in (NOW, NOW + 60, NOW + 180, NOW + 420):
            worker.run_once(self.connect, False, limited, at)
        self.assertEqual(len(calls), 4)
        self.assertEqual(self.row()['status'], 'failed')
        self.assertNotIn('secret-provider-detail', self.row()['last_error'])
        worker.run_once(self.connect, False, limited, NOW + 1000)
        self.assertEqual(len(calls), 4)

    def test_instagram_container_polls_then_publishes_once(self):
        self.queued_post('instagram', 'image', media='https://beshareaisolution.com/media/photo.jpg')
        calls = []
        def transport(method, url, params, token):
            calls.append((method, url))
            if url.endswith('/media'):
                return {'id': '4001'}
            if method == 'GET':
                return {'status_code': 'FINISHED'}
            return {'id': '5001'}
        first = worker.run_once(self.connect, False, transport, NOW)
        self.assertEqual(first['processing'], 1)
        self.assertEqual(self.row()['status'], 'publishing')
        self.assertEqual(worker.run_once(self.connect, False, transport, NOW + 15)['published'], 1)
        worker.run_once(self.connect, False, transport, NOW + 100)
        self.assertEqual(len([c for c in calls if c[0] == 'POST']), 2)
        self.assertEqual(self.row()['provider_post_id'], '5001')

    def test_facebook_video_accepted_then_poll_auth_failure_keeps_id_uncertain(self):
        self.queued_post('facebook', 'video', media='https://beshareaisolution.com/media/clip.mp4')
        def transport(method, url, params, token):
            return {'id': '8001'} if method == 'POST' else {'error': {'code': 190}}
        self.assertEqual(worker.run_once(self.connect, False, transport, NOW)['processing'], 1)
        self.assertEqual(worker.run_once(self.connect, False, transport, NOW + 30)['uncertain'], 1)
        row = self.row()
        self.assertEqual(row['provider_post_id'], '8001')
        self.assertEqual(row['status'], 'uncertain')

    def test_archived_campaign_cancels_before_external_write(self):
        self.queued_post()
        conn = self.connect()
        conn.execute("UPDATE marketing_campaigns SET status='archived' WHERE id=?", (CAMPAIGN,))
        conn.commit()
        conn.close()
        result = worker.run_once(self.connect, False, lambda *_: self.fail('Closed campaign must not publish'), NOW)
        self.assertEqual(result['cancelled'], 1)
        self.assertEqual(self.row()['status'], 'cancelled')

    def test_callback_scrubs_code_before_any_external_resource_and_has_csp(self):
        html = Path(__file__).resolve().parents[2].joinpath('marketing/connect.html').read_text()
        self.assertLess(html.index('history.replaceState'), html.index('src="../akaun-asal.js"'))
        self.assertIn('name="referrer" content="no-referrer"', html)
        self.assertNotIn('OAUTH_BOOTSTRAP_HASH', html)
        js = Path(__file__).resolve().parents[2].joinpath('marketing/connect.js').read_text()
        self.assertNotIn('localStorage.setItem', js)
        self.assertNotIn('innerHTML', js)


if __name__ == '__main__':
    unittest.main()
