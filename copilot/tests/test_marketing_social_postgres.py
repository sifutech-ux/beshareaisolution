"""Focused real PostgreSQL paths; every social provider request is mocked.

Opt in with COPILOT_TEST_POSTGRES=1 and the existing loopback-only
COPILOT_TEST_DATABASE_URL pointing to the disposable copilot_kuota_test DB.
"""
import hashlib
import json
import os
import unittest
from unittest.mock import patch

from cryptography.fernet import Fernet

import marketing_social as social
import marketing_worker as worker
import pelanggan_marketing as marketing
import pelanggan_meja as meja
import test_marketing_social as fixtures
from test_marketing import _postgres_test_connection, _reset_postgres_fixture


NOW, OWNER, OTHER = fixtures.NOW, fixtures.OWNER, fixtures.OTHER
CAMPAIGN, POST, TOKEN = fixtures.CAMPAIGN, fixtures.POST, fixtures.TOKEN


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not enabled')
class PostgresMarketingSocialTests(unittest.TestCase):
    connect = _postgres_test_connection
    begin = fixtures.MarketingSocialTests.begin
    meta_transport = fixtures.MarketingSocialTests.meta_transport

    def setUp(self):
        _reset_postgres_fixture(self.connect)
        env = patch.dict(os.environ, {
            'MARKETING_TOKEN_KEY': Fernet.generate_key().decode(),
            'MARKETING_META_APP_ID': '12345', 'MARKETING_META_APP_SECRET': 'synthetic-meta-secret',
            'MARKETING_META_API_VERSION': 'v27.0', 'MARKETING_META_LOGIN_CONFIG_ID': '',
            'MARKETING_OAUTH_REDIRECT_URI': 'https://beshareaisolution.com/marketing/connect.html',
            'MARKETING_PUBLISH_ENABLED': '1',
        })
        env.start()
        self.addCleanup(env.stop)
        network = patch('urllib.request.OpenerDirector.open',
                        side_effect=AssertionError('Live provider requests are forbidden in this fixture'))
        self.no_network = network.start()
        self.addCleanup(network.stop)
        self.addCleanup(self.no_network.assert_not_called)
        conn = self.connect()
        try:
            cur = conn.cursor()
            cur.execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT NOT NULL)')
            cur.executemany('INSERT INTO pelanggan_akaun VALUES (%s,%s)',
                            [(OWNER, 'a@example.test'), (OTHER, 'b@example.test')])
            marketing.ensure_marketing_schema(conn, True)
            social.ensure_social_schema(conn, True)
            cur.execute('INSERT INTO marketing_campaigns VALUES (%s,%s,%s,%s,%s,%s,%s)',
                        (CAMPAIGN, OWNER, '{}', 1, 'active', meja._iso(NOW), meja._iso(NOW)))
            conn.commit()
        finally:
            conn.close()

    def call(self, method, path, payload=None, owner=OWNER, now=NOW, transport=None):
        conn = self.connect()
        try:
            return social.dispatch_social(method, social.PREFIX + path, conn, True, owner,
                                          payload if payload is not None else {}, now, transport)
        finally:
            conn.close()

    def domain(self, method, path, payload=None, now=NOW):
        conn = self.connect()
        try:
            return marketing.dispatch_marketing(method, marketing.PREFIX + path, conn, True,
                                                OWNER, payload, now)
        finally:
            conn.close()

    def query(self, sql, params=()):
        conn = self.connect()
        try:
            cur = conn.cursor()
            cur.execute(sql, params)
            return cur.fetchall()
        finally:
            conn.close()

    def connected_page(self, check_owners=False):
        state = self.begin()
        if check_owners:
            stored = self.query('SELECT state_hash FROM marketing_oauth_states WHERE akaun_id=%s', (OWNER,))
            self.assertEqual(stored, [(hashlib.sha256(state.encode()).hexdigest(),)])
            status, _ = self.call('POST', '/complete', {'code': 'validcode', 'state': state}, owner=OTHER,
                                  transport=lambda *_: self.fail('Foreign owner must not call provider'))
            self.assertEqual(status, 409)
        status, completed = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                                      transport=self.meta_transport)
        self.assertEqual(status, 200, completed)
        self.assertTrue(completed['selection_required'])
        key = completed['candidates'][0]['id']
        if check_owners:
            status, _ = self.call('POST', '/facebook/select', {'candidate_id': key}, owner=OTHER)
            self.assertEqual(status, 404)
            status, _ = self.call('POST', '/complete', {'code': 'validcode', 'state': state},
                                  transport=lambda *_: self.fail('Consumed state must not call provider'))
            self.assertEqual(status, 409)
        status, selected = self.call('POST', '/facebook/select', {'candidate_id': key})
        self.assertEqual(status, 200, selected)
        self.assertEqual(selected['account']['account_id'], '1001')
        self.assertNotIn(TOKEN, json.dumps([completed, selected]))

    def queued_post(self):
        self.connected_page()
        scheduled = meja._iso(NOW + 60)
        payload = {'version': 0, 'campaign_id': CAMPAIGN, 'platform': 'facebook', 'format': 'text',
                   'body': 'Promosi yang telah diluluskan', 'media_url': '',
                   'status': 'planned', 'scheduled_at': scheduled}
        status, saved = self.domain('PUT', '/posts/' + POST, payload)
        self.assertEqual(status, 200, saved)
        status, queued = self.domain('POST', '/posts/' + POST + '/schedule', {
            'version': saved['post']['version'], 'approved': True, 'scheduled_at': scheduled})
        self.assertEqual(status, 200, queued)
        self.assertEqual(queued['post']['status'], 'queued')

    def test_postgres_oauth_state_and_selection_are_owner_bound(self):
        self.connected_page(check_owners=True)
        rows = self.query('SELECT akaun_id,account_id,token_cipher FROM marketing_connections')
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0][:2], (OWNER, '1001'))
        self.assertNotIn(TOKEN, rows[0][2])
        self.assertEqual(social._decrypt(rows[0][2]), TOKEN)
        self.assertEqual(self.query('SELECT COUNT(*) FROM marketing_oauth_candidates'), [(0,)])

    def test_postgres_queue_publishes_once_across_worker_ticks(self):
        self.queued_post()
        calls = []
        def transport(method, url, params, token):
            calls.append((method, url))
            self.assertEqual(token, TOKEN)
            self.assertTrue(url.endswith('/1001/feed'))
            return {'id': '1001_9001'}
        first = worker.run_once(self.connect, True, transport, NOW + 61)
        second = worker.run_once(self.connect, True, transport, NOW + 62)
        self.assertEqual(first['published'], 1)
        self.assertEqual(second['processed'], 0)
        self.assertEqual(len(calls), 1)
        self.assertEqual(self.query('SELECT status,provider_post_id,version FROM marketing_posts WHERE id=%s', (POST,)),
                         [('published', '1001_9001', 4)])
        self.assertEqual(self.query('SELECT stage,provider_post_id FROM marketing_publish_attempts WHERE post_id=%s', (POST,)),
                         [('published', '1001_9001')])

    def test_postgres_ambiguous_write_is_uncertain_and_cannot_be_requeued(self):
        self.queued_post()
        calls = []
        def transport(*args):
            calls.append(args[0])
            raise TimeoutError('Synthetic response loss after a possible accepted write')
        first = worker.run_once(self.connect, True, transport, NOW + 61)
        self.assertEqual(first['uncertain'], 1)
        row = self.query('SELECT status,version FROM marketing_posts WHERE id=%s', (POST,))[0]
        self.assertEqual(row, ('uncertain', 4))
        status, _ = self.domain('POST', '/posts/' + POST + '/schedule', {
            'version': row[1], 'approved': True, 'scheduled_at': meja._iso(NOW + 600)}, now=NOW + 62)
        self.assertEqual(status, 409)
        self.assertEqual(worker.run_once(self.connect, True, transport, NOW + 3600)['processed'], 0)
        self.assertEqual(calls, ['POST'])
        self.assertEqual(self.query('SELECT stage,COUNT(*) FROM marketing_publish_attempts WHERE post_id=%s GROUP BY stage', (POST,)),
                         [('uncertain', 1)])
        self.assertEqual(self.query('SELECT status FROM marketing_posts WHERE id=%s', (POST,)), [('uncertain',)])


if __name__ == '__main__':
    unittest.main()
