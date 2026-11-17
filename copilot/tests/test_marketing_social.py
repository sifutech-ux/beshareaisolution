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
            return {'access_token': TOKEN, 'expires_in': 60 * 86400}
        if url.endswith('/me/permissions'):
            return {'data': [{'permission': s, 'status': 'granted'} for s in set(social.SCOPES['facebook'] + social.SCOPES['instagram'])]}
        if url.endswith('/me/accounts'):
            return {'data': [{'id': '1001', 'name': 'Page Satu', 'access_token': TOKEN,
                              'tasks': ['CREATE_CONTENT'],
                              'instagram_business_account': {'id': '2001', 'username': 'kedai.satu'}}]}
        raise AssertionError('Unexpected endpoint')

    def connection(self, platform='facebook', owner=OWNER, account='1001'):
        conn = self.connect()
        try:
            candidate = {'platform': platform, 'account_id': account, 'account_name': 'Kedai',
                         'page_id': '1001' if platform != 'threads' else '',
                         'token_cipher': social._encrypt(TOKEN), 'token_expires_at': NOW + 60 * 86400,
                         'scopes_json': json.dumps(social.SCOPES[platform])}
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
            if url.endswith('/oauth/access_token'):
                return {'access_token': 'app-token' if params.get('grant_type') == 'client_credentials' else 'short-token', 'user_id': '3001'}
            if url.endswith('/access_token'):
                return {'access_token': TOKEN, 'expires_in': 60 * 86400}
            if url.endswith('/debug_token'):
                self.assertEqual(token, 'app-token')
                return {'data': {'app_id': '67890', 'user_id': '3001', 'is_valid': True,
                                 'expires_at': NOW + 60 * 86400, 'scopes': social.SCOPES['threads']}}
            return {'id': '3001', 'username': 'kedai.threads'}
        status, data = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, transport=transport)
        self.assertEqual(status, 200, data)
        self.assertFalse(data['selection_required'])
        self.assertEqual(data['account']['account_id'], '3001')
        self.assertTrue(all('graph.threads.com' in url for _, url in seen))
        self.assertNotIn(TOKEN, json.dumps(data))

    def test_threads_missing_publishing_grant_cannot_become_connected(self):
        state = self.begin('threads')
        def transport(method, url, params, token):
            if url.endswith('/oauth/access_token'):
                return {'access_token': 'app-token' if params.get('grant_type') == 'client_credentials' else 'short-token', 'user_id': '3001'}
            if url.endswith('/access_token'):
                return {'access_token': TOKEN, 'expires_in': 60 * 86400}
            if url.endswith('/debug_token'):
                return {'data': {'app_id': '67890', 'user_id': '3001', 'is_valid': True,
                                 'expires_at': NOW + 60 * 86400, 'scopes': ['threads_basic']}}
            self.fail('Denied publish scope must fail before profile saving')
        status, data = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, transport=transport)
        self.assertEqual(status, 409, data)
        self.assertEqual(data['code'], 'authorization')
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
