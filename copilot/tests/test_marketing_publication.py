"""Publication receipts prove destination identity, never public visibility.

All provider requests are mocked; no publication or external request is made.
"""
import json
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from cryptography.fernet import Fernet

import marketing_lifecycle as lifecycle
import marketing_publication as publication
import marketing_social as social
import pelanggan_marketing as marketing
import pelanggan_meja as meja
from test_marketing import _postgres_test_connection, _reset_postgres_fixture
from test_pelanggan_kuota import KuotaCases, STAMP


NOW = 1_900_000_000
OWNER, OTHER, CAMPAIGN, POST = ('a' * 32, 'b' * 32, 'c' * 32, 'd' * 32)
TOKEN = 'synthetic-receipt-provider-token'
POST_IDS = {'facebook': '1001_9001', 'instagram': '9002', 'threads': '9003'}
ACCOUNT_IDS = {'facebook': '1001', 'instagram': '2001', 'threads': '3001'}
POST_URLS = {'facebook': 'https://www.facebook.com/61500000000001/posts/9001/',
             'instagram': 'https://www.instagram.com/p/TestPost/',
             'threads': 'https://www.threads.com/@kedai/post/TestPost'}


class PublicationTests(unittest.TestCase):
    postgres = False

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'publication.db')
        env = patch.dict(os.environ, {
            'MARKETING_TOKEN_KEY': Fernet.generate_key().decode(),
            'MARKETING_META_APP_ID': '12345', 'MARKETING_META_APP_SECRET': 'synthetic-meta',
            'MARKETING_THREADS_APP_ID': '67890', 'MARKETING_THREADS_APP_SECRET': 'synthetic-threads',
            'MARKETING_OAUTH_REDIRECT_URI': 'https://example.test/marketing/connect.html',
            'MARKETING_META_API_VERSION': 'v27.0', 'MARKETING_THREADS_API_VERSION': 'v1.0',
            'MARKETING_PUBLISH_ENABLED': '1', 'MARKETING_META_LOGIN_CONFIG_ID': '',
        })
        env.start()
        self.addCleanup(env.stop)
        network = patch('urllib.request.OpenerDirector.open', side_effect=AssertionError('Live provider network forbidden'))
        self.no_network = network.start()
        self.addCleanup(network.stop)
        self.addCleanup(self.no_network.assert_not_called)
        conn = self.connect()
        cur = conn.cursor()
        cur.execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT)')
        ph = meja._ph(self.postgres)
        cur.executemany(f'INSERT INTO pelanggan_akaun VALUES ({ph},{ph})',
                        [(OWNER, 'a@example.test'), (OTHER, 'b@example.test')])
        marketing.ensure_marketing_schema(conn, self.postgres)
        social.ensure_social_schema(conn, self.postgres)
        marketing.ensure_marketing_schema(conn, self.postgres)
        conn.commit()
        conn.close()
        self.requests = []

    def connect(self):
        return sqlite3.connect(self.path, timeout=10)

    def execute(self, sql, params=()):
        conn = self.connect()
        try:
            cur = conn.cursor()
            cur.execute(sql.replace('?', '%s') if self.postgres else sql, params)
            result = cur.fetchall() if cur.description else []
            conn.commit()
            return result
        finally:
            conn.close()

    def seed(self, platform='facebook', status='published', form=None, provider_id=None):
        conn = self.connect()
        account = ACCOUNT_IDS[platform]
        candidate = {'platform': platform, 'account_id': account, 'account_name': 'Nama lama',
                     'page_id': '1001' if platform != 'threads' else '',
                     'token_cipher': social._encrypt(TOKEN), 'token_expires_at': NOW + 86400,
                     'scopes_json': json.dumps(social.SCOPES[platform]),
                     'provider_app_id': '67890' if platform == 'threads' else '12345',
                     'provider_user_id': '3001' if platform == 'threads' else '5001',
                     'authorization_started_at': NOW - 60}
        social._save_connection(conn, self.postgres, OWNER, candidate, NOW)
        form = form or ('image' if platform == 'instagram' else 'text')
        data = {'campaign_id': CAMPAIGN, 'platform': platform,
                'format': form,
                'body': 'Teks yang sudah diterbitkan',
                'media_url': 'https://example.test/test.jpg' if form != 'text' else ''}
        ph = meja._ph(self.postgres)
        conn.cursor().execute(f'''INSERT INTO marketing_posts
            (id,akaun_id,campaign_id,data_json,version,status,scheduled_at,provider_post_id,last_error,created_at,updated_at)
            VALUES ({','.join([ph] * 11)})''',
            (POST, OWNER, CAMPAIGN, json.dumps(data), 4, status, meja._iso(NOW),
             (provider_id or POST_IDS[platform]) if status == 'published' else '', '', meja._iso(NOW), meja._iso(NOW)))
        ok, reason = social.check_schedule(conn, self.postgres, OWNER, {'id': POST, **data}, meja._iso(NOW), NOW)
        self.assertTrue(ok, reason)
        conn.commit()
        conn.close()

    def transport(self, method, url, params, token):
        self.assertEqual(method, 'GET', 'Resolving a receipt must never publish')
        self.assertEqual(token, TOKEN)
        self.requests.append((method, url, params['fields']))
        key = url.rsplit('/', 1)[-1]
        for platform, provider_id in POST_IDS.items():
            if key == provider_id:
                owner = {'id': ACCOUNT_IDS[platform]}
                return {'id': key, 'from' if platform == 'facebook' else 'owner': owner,
                        'permalink_url' if platform == 'facebook' else 'permalink': POST_URLS[platform]}
        if key == '1001':
            return {'id': key, 'name': 'Page Asal', 'link': 'https://www.facebook.com/profile.php?id=61500000000001'}
        if key == '2001':
            return {'id': key, 'username': 'kedai.instagram'}
        if key == 'me':
            return {'id': '3001', 'username': 'kedai.threads'}
        self.fail('Unexpected provider read')

    def call(self, method='POST', suffix=None, payload=None, owner=OWNER, transport=None):
        conn = self.connect()
        original_call = social.provider_call
        def request(method, url, params=None, token=None, _transport=None, **options):
            return original_call(method, url, params, token, transport or self.transport, **options)
        try:
            with patch.object(social, 'provider_call', side_effect=request):
                return marketing.dispatch_marketing(method, marketing.PREFIX + (suffix or '/posts/' + POST + '/publication'),
                                                    conn, self.postgres, owner, {} if payload is None else payload, NOW + 10)
        finally:
            conn.close()

    def post_state(self):
        return self.execute('SELECT status,provider_post_id,version,last_error FROM marketing_posts WHERE id=?', (POST,))

    def test_old_published_post_resolves_canonical_page_and_is_cached_in_read_list_workspace(self):
        self.seed()
        before = self.post_state()
        status, result = self.call()
        self.assertEqual(status, 200, result)
        receipt = result['publication']
        self.assertEqual(receipt, {'platform': 'facebook', 'account_id': '1001', 'account_name': 'Page Asal',
                                  'profile_url': 'https://www.facebook.com/profile.php?id=61500000000001',
                                  'post_url': POST_URLS['facebook'], 'resolved_at': meja._iso(NOW + 10)})
        self.assertEqual(len(self.requests), 2)
        self.assertEqual(self.call()[1]['publication'], receipt)
        self.assertEqual(self.call('GET', '/posts/' + POST)[1]['post']['publication'], receipt)
        self.assertEqual(self.call('GET', '/posts')[1]['posts'][0]['publication'], receipt)
        workspace = self.call('GET', '/workspace')[1]
        self.assertTrue(workspace['capabilities']['publication_receipts'])
        self.assertEqual(workspace['posts'][0]['publication'], receipt)
        self.assertEqual(len(self.requests), 2, 'Cached/list reads must not call a provider')
        self.assertEqual(self.post_state(), before)
        for private in (TOKEN, 'token_cipher', 'provider_user_id', 'provider_app_id', 'akaun_id'):
            self.assertNotIn(private, json.dumps(result))

    def test_foreign_owner_never_reads_provider_or_cached_receipt(self):
        self.seed()
        self.assertEqual(self.call(owner=OTHER)[0], 404)
        self.assertEqual(self.requests, [])
        self.call()
        self.assertEqual(self.call(owner=OTHER)[0], 404)
        self.assertEqual(self.call('GET', '/posts/' + POST, owner=OTHER)[0], 404)
        self.assertEqual(self.call('GET', '/posts', owner=OTHER)[1]['posts'], [])
        self.assertEqual(len(self.requests), 2)

    def test_facebook_image_with_page_post_id_uses_post_permalink(self):
        self.seed(form='image')
        status, body = self.call()
        self.assertEqual(status, 200, body)
        self.assertEqual(self.requests[0][2], 'id,from,permalink_url')
        self.assertEqual(body['publication']['post_url'], POST_URLS['facebook'])

    def test_facebook_photo_id_fallback_uses_provider_photo_link(self):
        self.seed(form='image', provider_id='9004')
        before = self.post_state()
        photo_link = 'https://www.facebook.com/photo.php?fbid=9004&set=a.8001&type=3'
        def photo(method, url, params, token):
            if url.endswith('/9004'):
                self.assertEqual(method, 'GET')
                self.assertEqual(params['fields'], 'id,from,link')
                self.assertEqual(token, TOKEN)
                return {'id': '9004', 'from': {'id': '1001'}, 'link': photo_link}
            return self.transport(method, url, params, token)
        status, body = self.call(transport=photo)
        self.assertEqual(status, 200, body)
        self.assertEqual(body['publication']['post_url'], photo_link)
        self.assertEqual(body['publication']['account_id'], '1001')
        self.assertEqual(self.post_state(), before)

    def test_facebook_video_preserves_provider_absolute_or_relative_permalink(self):
        self.seed(form='video', provider_id='9005')
        before = self.post_state()
        relative = '/PageAsal/videos/9005/'
        absolute = 'https://www.facebook.com' + relative
        for value in (absolute, relative):
            with self.subTest(value=value):
                self.execute('DELETE FROM marketing_publications')
                def video(method, url, params, token):
                    if url.endswith('/9005'):
                        self.assertEqual(method, 'GET')
                        self.assertEqual(params['fields'], 'id,from,permalink_url')
                        self.assertEqual(token, TOKEN)
                        return {'id': '9005', 'from': {'id': '1001'}, 'permalink_url': value}
                    return self.transport(method, url, params, token)
                status, body = self.call(transport=video)
                self.assertEqual(status, 200, body)
                self.assertEqual(body['publication']['post_url'], absolute)
                self.assertEqual(self.post_state(), before)

    def test_facebook_video_missing_or_unsafe_permalink_never_guesses_or_republishes(self):
        self.seed(form='video', provider_id='9005')
        before = self.post_state()
        responses = [
            {'id': '9005', 'from': {'id': '1001'}},
            {'id': '9005', 'from': {'id': '1001'}, 'permalink_url': '//evil.test/video'},
            {'id': '9005', 'from': {'id': '1001'}, 'permalink_url': '/%2fexample.test/video'},
            {'id': '9005', 'from': {'id': '1001'}, 'permalink_url': '/%6c.php?u=https://evil.test/'},
            {'error': {'code': 100, 'message': 'Synthetic field unavailable'}},
        ]
        for result in responses:
            def video(method, url, params, token):
                self.assertEqual(method, 'GET')
                return result if url.endswith('/9005') else self.transport(method, url, params, token)
            status, body = self.call(transport=video)
            self.assertGreaterEqual(status, 400, body)
            self.assertNotIn('publication', body)
            self.assertNotIn('Synthetic field unavailable', json.dumps(body))
            self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])
            self.assertEqual(self.post_state(), before)

    def test_unpublished_states_cannot_resolve_or_mutate_a_post(self):
        self.seed(status='draft')
        for state in ('draft', 'planned', 'queued', 'publishing', 'failed', 'uncertain', 'cancelled'):
            self.execute('UPDATE marketing_posts SET status=? WHERE id=?', (state, POST))
            before = self.post_state()
            self.assertEqual(self.call()[0], 409)
            self.assertEqual(self.post_state(), before)
        self.assertEqual(self.requests, [])

    def test_client_cannot_supply_publication_metadata(self):
        self.seed()
        for payload in ({'post_url': POST_URLS['facebook']}, {'account_id': '1001'}, {'approved': True}, [], 'bad'):
            self.assertEqual(self.call(payload=payload)[0], 400)
        self.assertEqual(self.requests, [])

    def test_changed_connection_before_resolution_fails_closed(self):
        self.seed()
        self.execute("UPDATE marketing_connections SET account_id='9999',account_name='Other Page'")
        before = self.post_state()
        self.assertEqual(self.call()[0], 409)
        self.assertEqual(self.requests, [])
        self.assertEqual(self.post_state(), before)
        self.assertIsNone(self.call('GET', '/posts/' + POST)[1]['post']['publication'])

    def test_cached_destination_survives_changed_or_disconnected_connection(self):
        self.seed()
        receipt = self.call()[1]['publication']
        self.execute("UPDATE marketing_connections SET account_id='9999',account_name='Other Page',status='disconnected',token_cipher=''")
        self.assertEqual(self.call()[1]['publication'], receipt)
        self.assertEqual(self.call('GET', '/posts/' + POST)[1]['post']['publication'], receipt)
        self.assertEqual(len(self.requests), 2)

    def test_provider_post_owner_and_profile_identity_must_match_original_binding(self):
        self.seed()
        before = self.post_state()
        for mismatch in ('post_id', 'owner', 'profile_id'):
            def wrong(method, url, params, token):
                result = self.transport(method, url, params, token)
                if url.endswith('/1001_9001'):
                    if mismatch == 'post_id':
                        result['id'] = '1001_9999'
                    if mismatch == 'owner':
                        result['from'] = {'id': '9999'}
                elif mismatch == 'profile_id':
                    result['id'] = '9999'
                return result
            self.assertEqual(self.call(transport=wrong)[0], 502)
            self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])
            self.assertEqual(self.post_state(), before)

    def test_untrusted_provider_links_are_rejected(self):
        self.seed()
        invalid = ['http://www.facebook.com/1001/posts/9001', 'javascript:alert(1)',
                   'https://facebook.com.evil.test/post', 'https://www.facebook.com@evil.test/post',
                   'https://evil.test@www.facebook.com/post', 'https://www.facebook.com:444/post',
                   'https://www.facebook.com./post', 'https://www.facebook.com\\@evil.test/post',
                   'https://www.facebook.com/post\n', 'https://www.facebook.com/post#token',
                   'https://www.facebook.com/l.php?u=https://evil.test/',
                   'https://www.facebook.com/post?access_token=secret', '//www.facebook.com/post',
                   'https://www.facebook.com/post?state=secret', 'https://www.facebook.com/post?state=',
                   'https://www.facebook.com/post?%73tate=secret',
                   'https://www.facebook.com/%6c.php', 'https://www.facebook.com/%256c.php',
                   'https://www.facebook.com/safe/%2e%2e/l.php',
                   'https://www.instagram.com/p/TestPost/']
        for value in invalid:
            with self.subTest(url=value):
                def bad(method, url, params, token):
                    result = self.transport(method, url, params, token)
                    if 'permalink_url' in result:
                        result['permalink_url'] = value
                    return result
                self.assertEqual(self.call(transport=bad)[0], 502)
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])

    def test_invalid_profile_link_cannot_be_cached(self):
        self.seed()
        def bad(method, url, params, token):
            result = self.transport(method, url, params, token)
            if 'link' in result:
                result['link'] = 'https://evil.test/1001'
            return result
        self.assertEqual(self.call(transport=bad)[0], 502)
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])

    def test_timeout_or_provider_rejection_never_republishes_or_changes_status(self):
        self.seed()
        before = self.post_state()
        def timeout(method, *_args):
            self.assertEqual(method, 'GET')
            raise TimeoutError('secret must not escape')
        for transport in (timeout, lambda *_args: {'error': {'code': 190, 'message': 'secret must not escape'}}):
            status, body = self.call(transport=transport)
            self.assertIn(status, (409, 502))
            self.assertNotIn('secret must not escape', json.dumps(body))
            self.assertEqual(self.post_state(), before)
            self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])
            self.assertEqual(self.execute('SELECT status FROM marketing_connections'), [('connected',)])

    def test_binding_change_during_provider_read_does_not_cache_wrong_destination(self):
        self.seed()
        before = self.post_state()
        def changed(method, url, params, token):
            result = self.transport(method, url, params, token)
            if url.endswith('/1001'):
                self.execute("UPDATE marketing_connections SET account_id='9999'")
            return result
        self.assertEqual(self.call(transport=changed)[0], 409)
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])
        self.assertEqual(self.post_state(), before)

    def test_cached_receipt_is_bound_to_provider_id(self):
        self.seed()
        self.call()
        self.execute("UPDATE marketing_posts SET provider_post_id='1001_9999' WHERE id=?", (POST,))
        self.assertIsNone(self.call('GET', '/posts/' + POST)[1]['post']['publication'])
        self.assertEqual(self.execute('SELECT provider_post_id FROM marketing_publications'), [('1001_9001',)])

    def test_instagram_uses_provider_permalink_and_verified_media_owner(self):
        self.seed('instagram')
        status, result = self.call()
        self.assertEqual(status, 200, result)
        self.assertEqual(result['publication']['post_url'], POST_URLS['instagram'])
        self.assertEqual(result['publication']['account_id'], '2001')
        self.assertEqual(result['publication']['account_name'], 'kedai.instagram')
        self.assertEqual(result['publication']['profile_url'], '')

    def test_threads_uses_owner_and_me_identity_with_no_guessed_profile_link(self):
        self.seed('threads')
        status, result = self.call()
        self.assertEqual(status, 200, result)
        self.assertEqual(result['publication']['post_url'], POST_URLS['threads'])
        self.assertEqual(result['publication']['account_id'], '3001')
        self.assertEqual(result['publication']['profile_url'], '')

    def test_missing_media_owner_fails_closed(self):
        self.seed('instagram')
        def missing(method, url, params, token):
            result = self.transport(method, url, params, token)
            result.pop('owner', None)
            return result
        self.assertEqual(self.call(transport=missing)[0], 502)
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])

    def test_provider_data_deletion_removes_cached_receipt(self):
        self.seed()
        self.call()
        conn = self.connect()
        try:
            status, _ = lifecycle.apply_callback(conn, self.postgres, 'meta', '12345', '5001', 'e' * 64, NOW + 20)
            self.assertEqual(status, 200)
        finally:
            conn.close()
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_publications'), [(0,)])
        self.assertIsNone(self.call('GET', '/posts/' + POST)[1]['post']['publication'])

    def test_threads_review_copy_is_operator_configured_and_nonblocking(self):
        self.seed('threads')
        for value, expected in (('', 'testing'), ('testing', 'testing'), ('invalid', 'testing'), ('approved', 'approved')):
            with patch.dict(os.environ, {'MARKETING_THREADS_CUSTOMER_ACCESS': value}):
                capabilities = self.call('GET', '/workspace')[1]['capabilities']
                self.assertEqual(capabilities['threads_customer_access'], expected)
                self.assertTrue(capabilities['publishing_enabled'])
                threads = next(p for p in capabilities['providers'] if p['platform'] == 'threads')
                self.assertTrue(threads['connectable'])
                self.assertTrue(threads['publishable'])


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not enabled')
class PostgresPublicationTests(PublicationTests):
    postgres = True
    connect = _postgres_test_connection

    def setUp(self):
        _reset_postgres_fixture(self.connect)
        super().setUp()


class PublicationRouteTests(unittest.TestCase):
    """Verify the new endpoint uses the real account/Copilot auth boundary."""
    postgres = False
    for helper in ('setUp', '_restore_secret', 'connect', 'dispatch_raw', 'q', 'profil'):
        locals()[helper] = getattr(KuotaCases, helper)

    def test_real_route_requires_valid_owner_token_and_allowed_origin(self):
        token, owner = self.profil('receipt-route@example.test')
        path = marketing.PREFIX + '/posts/' + POST + '/publication'
        with patch.object(publication, 'resolve', wraps=publication.resolve) as resolve, \
                patch.object(social, 'provider_call', side_effect=AssertionError('No provider read expected')):
            for bad in ('', 'forged.session.token'):
                self.assertEqual(self.dispatch_raw('POST', path, b'{}', bad)[0], 401)
            self.assertEqual(self.dispatch_raw('POST', path, b'{}', token,
                                               stamp=STAMP + 31 * 86400)[0], 401)
            self.assertEqual(self.dispatch_raw('POST', path, b'{}', token,
                                               origin='https://untrusted.example.test')[0], 403)
            resolve.assert_not_called()
            status, _ = self.dispatch_raw('POST', path, b'{}', token,
                                           origin='https://beshareaisolution.com')
            self.assertEqual(status, 404, 'An authorized missing post must reach owner-scoped lookup')
            self.assertEqual(resolve.call_args.args[2:5], (owner, POST, {}))

    def test_real_route_rejects_invalid_json_and_client_metadata(self):
        token, _ = self.profil('receipt-body@example.test')
        path = marketing.PREFIX + '/posts/' + POST + '/publication'
        with patch.object(social, 'provider_call', side_effect=AssertionError('No provider read expected')):
            for raw in (b'{', b'\xff', b'[]', b'null', b'{"post_url":"https://www.facebook.com/post"}'):
                self.assertEqual(self.dispatch_raw('POST', path, raw, token)[0], 400)

    def test_http_body_limit_rejects_before_read_and_keeps_publication_in_account_router(self):
        import akaun_pelayan as server
        handler = server.AkaunHandler.__new__(server.AkaunHandler)
        handler.path = marketing.PREFIX + '/posts/' + POST + '/publication'
        handler.rfile = Mock()
        with patch.object(server, '_tulis') as write, patch.object(server, 'dispatch') as dispatch:
            for length, expected in (('not-a-length', 400), ('-1', 413), ('500001', 413)):
                handler.headers = {'Content-Length': length}
                handler.do_POST()
                self.assertEqual(write.call_args.args[1], expected)
            handler.rfile.read.assert_not_called()
            dispatch.assert_not_called()
            handler.headers = {'Content-Length': '500000'}
            handler.client_address = ('127.0.0.1', 12345)
            handler.rfile.read.return_value = b'{}' + b' ' * 499998
            dispatch.return_value = (200, {'ok': True})
            with patch.object(server, 'account_secret', return_value='synthetic-account-secret'):
                handler.do_POST()
            handler.rfile.read.assert_called_once_with(500000)
            self.assertEqual(dispatch.call_args.args[:2], ('POST', handler.path))
            self.assertEqual(len(dispatch.call_args.args[4]), 500000)


if __name__ == '__main__':
    unittest.main()
