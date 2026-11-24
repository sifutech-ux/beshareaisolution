"""Meaningful isolation, write-conflict and publishing-boundary checks."""
import concurrent.futures
import json
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from urllib.parse import urlsplit

import pelanggan_marketing as marketing
import pelanggan_meja as meja
from test_pelanggan_kuota import KuotaCases


STAMP = 1_700_000_000
FUTURE = meja._iso(STAMP + 600)
OWNER = 'owner-a'
OTHER = 'owner-b'
CAMPAIGN_ID = 'a' * 32
OTHER_CAMPAIGN = 'e' * 32
POST_ID = 'b' * 32
SOURCE_ID = 'c' * 32
RESULT_ID = 'd' * 32
CAMPAIGN = {'version': 0, 'title': 'Tawaran hujung minggu', 'product': 'Kopi',
            'offer': 'Pakej dua cawan', 'audience': 'Jiran kedai', 'goal': 'leads',
            'budget_total': '500.5', 'destination_url': 'https://example.com/tempahan',
            'start_date': '2026-11-17', 'end_date': '2026-11-24',
            'channels': ['instagram', 'facebook'], 'status': 'draft'}
POST = {'version': 0, 'campaign_id': CAMPAIGN_ID, 'platform': 'facebook',
        'format': 'text', 'body': 'Tawaran kopi minggu ini.', 'media_url': '',
        'scheduled_at': FUTURE, 'status': 'planned'}
SOURCE = {'version': 0, 'campaign_id': CAMPAIGN_ID, 'title': 'Contoh demonstrasi',
          'url': 'https://example.com/reference', 'observation': 'Produk ditunjukkan awal.',
          'hypothesis': 'Uji demonstrasi dalam pembukaan.', 'source_type': 'reference'}
RESULT = {'version': 0, 'campaign_id': CAMPAIGN_ID, 'date': '2023-11-14',
          'platform': 'facebook', 'spend': '10.5', 'revenue': '80',
          'impressions': 100, 'clicks': 5, 'enquiries': 2, 'qualified_leads': 1,
          'orders': 1, 'note': 'Catatan pemilik.'}


class MarketingTests(unittest.TestCase):
    postgres = False

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'marketing.db')
        conn = self.connect()
        cur = conn.cursor()
        cur.execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT NOT NULL)')
        cur.executemany('INSERT INTO pelanggan_akaun VALUES (' + ','.join([meja._ph(self.postgres)] * 2) + ')',
                        [(OWNER, 'a@example.com'), (OTHER, 'b@example.com')])
        marketing.ensure_marketing_schema(conn, self.postgres)
        conn.commit()
        conn.close()
        self.provider_patch = patch.object(marketing, '_social', return_value=None)
        self.provider = self.provider_patch.start()
        self.addCleanup(self.provider_patch.stop)

    def connect(self):
        return sqlite3.connect(self.path, timeout=10)

    def call(self, method, suffix, payload=None, owner=OWNER, stamp=STAMP):
        conn = self.connect()
        try:
            return marketing.dispatch_marketing(method, marketing.PREFIX + suffix, conn,
                                                self.postgres, owner, payload, stamp)
        finally:
            conn.close()

    def seed_campaign(self, key=CAMPAIGN_ID, owner=OWNER, **updates):
        code, result = self.call('PUT', '/campaigns/' + key, {**CAMPAIGN, **updates}, owner)
        self.assertEqual(code, 200, result)
        return result['campaign']

    def seed_post(self, **updates):
        self.seed_campaign()
        code, result = self.call('PUT', '/posts/' + POST_ID, {**POST, **updates})
        self.assertEqual(code, 200, result)
        return result['post']

    def rows(self, sql, values=()):
        conn = self.connect()
        try:
            cur = conn.cursor()
            cur.execute(sql.replace('?', '%s') if self.postgres else sql, values)
            return cur.fetchall()
        finally:
            conn.close()

    def execute(self, sql, values=()):
        conn = self.connect()
        conn.cursor().execute(sql.replace('?', '%s') if self.postgres else sql, values)
        conn.commit()
        conn.close()

    def ready_provider(self):
        self.provider.return_value = SimpleNamespace(
            check_schedule=lambda *args: (True, ''),
            get_connections=lambda *args: [{'provider': 'facebook', 'status': 'connected'}],
            capabilities=lambda *args: {'auto_publish': True, 'worker_enabled': True})

    def test_owner_isolation_auth_and_cross_owner_identifiers(self):
        self.assertEqual(self.call('GET', '/workspace', owner='missing')[0], 401)
        self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, CAMPAIGN, owner='missing')[0], 401)
        self.seed_campaign()
        self.seed_campaign(OTHER_CAMPAIGN, OTHER)
        specimens = [('campaigns', 'campaign', CAMPAIGN_ID, CAMPAIGN),
                     ('posts', 'post', POST_ID, POST),
                     ('sources', 'source', SOURCE_ID, SOURCE),
                     ('results', 'result', RESULT_ID, RESULT)]
        for plural, singular, key, payload in specimens:
            with self.subTest(resource=plural):
                self.assertEqual(self.call('PUT', '/' + plural + '/' + key, payload)[0], 200)
                code, response = self.call('GET', '/' + plural + '/' + key, owner=OTHER)
                self.assertEqual(code, 404)
                self.assertNotIn(singular, response)
                foreign = {**payload, 'campaign_id': OTHER_CAMPAIGN} if plural != 'campaigns' else payload
                code, response = self.call('PUT', '/' + plural + '/' + key, foreign, OTHER)
                self.assertEqual(code, 404, response)
                self.assertNotIn('current', response)
        workspace = self.call('GET', '/workspace', owner=OTHER)[1]
        self.assertEqual([item['id'] for item in workspace['campaigns']], [OTHER_CAMPAIGN])
        self.assertEqual(workspace['posts'], [])
        self.assertEqual(workspace['sources'], [])
        self.assertEqual(workspace['results'], [])
        self.assertFalse(workspace['capabilities']['auto_publish'])
        self.assertNotIn('akaun_id', json.dumps(workspace))

    def test_cross_owner_campaign_reference_rejected_for_every_child(self):
        self.seed_campaign()
        for resource, key, payload in [('posts', POST_ID, POST), ('sources', SOURCE_ID, SOURCE), ('results', RESULT_ID, RESULT)]:
            self.assertEqual(self.call('PUT', '/' + resource + '/' + key, payload, OTHER)[0], 404)
        manual = {**SOURCE, 'campaign_id': '', 'source_type': 'manual', 'url': ''}
        self.assertEqual(self.call('PUT', '/sources/' + SOURCE_ID, manual, OTHER)[0], 200)

    def test_published_promos_collect_private_enquiries_and_count_confirmed_orders(self):
        from access_fixture import grant_existing
        from marketing_promos import PUBLIC, public
        self.seed_campaign()
        with self.connect() as db:
            grant_existing(db, postgres=self.postgres)
        endpoint = '/promos/' + CAMPAIGN_ID
        detail = dict(revision=0, published=False, business='Kopi Aurora', area='Ampang',
                      title='Pakej pejabat', offer='Kopi untuk 20 orang', cta='Minta sebut harga')
        lead = dict(id='9' * 32, name='Visitor', contact='sample@example.test', note='Friday',
                    source='google', consent=True)

        def visitor(method='GET', suffix='', **payload):
            db = self.connect()
            try:
                return public(method, PUBLIC + CAMPAIGN_ID + suffix, db, self.postgres,
                              payload, STAMP, 'synthetic-address', 'test-key')
            finally:
                db.close()

        self.assertEqual(self.call('PUT', endpoint, detail)[0], 200)
        self.assertEqual(visitor()[0], 404)
        self.assertEqual(visitor('POST', '/enquiries', **lead)[0], 404)
        detail.update(revision=1, published=True)
        self.assertEqual(self.call('PUT', endpoint, detail)[0], 200)
        self.assertEqual(self.call('PUT', endpoint, {**detail, 'title': 'stale'})[0], 409)
        self.assertEqual(set(visitor()[1]['page']), {'business','area','title','offer','cta','image'})
        for bad in ({'consent':False}, {'contact':'abc'}, {'source':'invalid'}):
            self.assertEqual(visitor('POST','/enquiries', **{**lead, **bad})[0], 400)
        self.assertEqual(visitor('POST', '/enquiries', **lead), (201, {'ok':True}))
        self.assertEqual(visitor('POST', '/enquiries', **lead), (200, {'ok':True}))
        self.assertEqual(visitor('GET','/enquiries')[0], 404)
        self.assertEqual(self.call('GET',endpoint+'/enquiries',owner=OTHER)[0], 404)
        status_url = endpoint+'/enquiries/'+lead['id']
        change = {'previous':'baru','status':'tempahan'}
        self.assertEqual(self.call('PUT',status_url,change,OTHER)[0], 404)
        self.assertEqual(self.call('PUT',status_url,change)[0], 200)
        self.assertEqual(self.call('PUT',status_url,{**change,'status':'dihubungi'})[0], 409)
        inbox = self.call('GET', endpoint+'/enquiries')[1]
        self.assertEqual(inbox['totals'], [{'source':'google','enquiries':1,'orders':1}])
        self.assertEqual(inbox['leads'][0]['contact'], lead['contact'])
        self.assertNotIn('sender', inbox['leads'][0])
        self.execute("UPDATE pelanggan_akses SET state='suspended' WHERE owner=?", (OWNER,))
        self.assertEqual(visitor()[0], 404)
        self.assertEqual(visitor('POST', '/enquiries', **lead)[0], 404)
        self.execute("UPDATE pelanggan_akses SET state='free' WHERE owner=?", (OWNER,))
        self.assertEqual(self.call('PUT', endpoint, {**detail,'revision':2,'published':False})[0], 200)
        self.assertEqual(visitor()[0], 404)

    def test_canonical_retry_and_lost_update_protection(self):
        current = self.seed_campaign()
        self.assertEqual(current['budget_total'], '500.50')
        self.assertEqual(current['channels'], ['facebook', 'instagram'])
        retry = self.call('PUT', '/campaigns/' + CAMPAIGN_ID,
                          {**CAMPAIGN, 'budget_total': '500.50', 'channels': ['facebook', 'instagram']})
        self.assertEqual(retry[0], 200)
        self.assertTrue(retry[1]['replayed'])
        self.assertEqual(retry[1]['campaign']['version'], 1)
        update = {**CAMPAIGN, 'version': 1, 'title': 'Tawaran baharu'}
        self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, update)[1]['campaign']['version'], 2)
        code, conflict = self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**CAMPAIGN, 'version': 1, 'title': 'Salinan lama'})
        self.assertEqual(code, 409)
        self.assertEqual(conflict['current']['title'], 'Tawaran baharu')
        self.assertTrue(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, update)[1]['replayed'])
        self.assertTrue(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**update, 'version': 2})[1]['replayed'])
        self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**update, 'version': 0})[0], 409)
        self.assertEqual(self.rows('SELECT COUNT(*) FROM marketing_campaigns')[0][0], 1)

    def test_two_devices_have_one_winner_and_identical_retries_only_write_once(self):
        self.seed_campaign()
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            codes = list(pool.map(lambda title: self.call('PUT', '/campaigns/' + CAMPAIGN_ID,
                                 {**CAMPAIGN, 'version': 1, 'title': title})[0], ['Peranti A', 'Peranti B']))
        self.assertEqual(sorted(codes), [200, 409])
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            codes = list(pool.map(lambda _: self.call('PUT', '/sources/' + SOURCE_ID, SOURCE)[0], range(2)))
        self.assertEqual(codes, [200, 200])
        self.assertEqual(self.call('GET', '/sources/' + SOURCE_ID)[1]['source']['version'], 1)

    def test_forbidden_state_owner_and_invalid_payload_shapes(self):
        for body in (None, [], 'bad', {**CAMPAIGN, 'akaun_id': OTHER}, {**CAMPAIGN, 'access_token': 'secret'}):
            self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, body)[0], 400)
        for value in (True, -1, '0', 0.0, 2_147_483_647):
            self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**CAMPAIGN, 'version': value})[0], 400)
        for field, value in [('title', ''), ('title', 'a' * 161), ('goal', []), ('channels', ['unknown']),
                             ('channels', 'facebook'), ('status', 'published'), ('product', '\x00')]:
            self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**CAMPAIGN, field: value})[0], 400)
        self.seed_campaign()
        for state in ('queued', 'publishing', 'published', 'failed', 'uncertain', 'cancelled'):
            self.assertEqual(self.call('PUT', '/posts/' + POST_ID, {**POST, 'status': state})[0], 400)
        self.assertEqual(self.call('PUT', '/posts/' + POST_ID, {**POST, 'provider_post_id': 'forged'})[0], 400)
        self.assertEqual(self.call('PUT', '/results/' + RESULT_ID, {**RESULT, 'origin': 'meta_api'})[0], 400)
        self.assertEqual(self.call('PUT', '/campaigns/not-an-id', CAMPAIGN)[0], 404)
        self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID + '/extra', CAMPAIGN)[0], 404)

    def test_urls_money_dates_and_counts_are_validated(self):
        bad_urls = ['http://example.com', 'javascript:alert(1)', 'data:text/html,hi',
                    'https://user:pass@example.com', 'https://localhost/path', 'https://127.0.0.1',
                    'https://[::1]/', 'https://10.0.0.1', 'https://host.internal',
                    'https://example.com:invalid', 'https://exam ple.com', 'https://example.com\\@localhost']
        for url in bad_urls:
            with self.subTest(url=url):
                self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**CAMPAIGN, 'destination_url': url})[0], 400)
        for money in ('', '-1', '1e2', 'NaN', 'Infinity', '01', '1.001', '10000000000', 10.5, True):
            self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**CAMPAIGN, 'budget_total': money})[0], 400)
        for dates in [('2026-02-30', ''), ('2026-1-1', ''), ('2026-11-20', '2026-11-19')]:
            self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID,
                                      {**CAMPAIGN, 'start_date': dates[0], 'end_date': dates[1]})[0], 400)
        self.seed_campaign(budget_total='0', destination_url='', start_date='', end_date='')
        for count in (-1, True, '10', 2.5, 1_000_000_001):
            self.assertEqual(self.call('PUT', '/results/' + RESULT_ID, {**RESULT, 'clicks': count})[0], 400)
        for timestamp in ('2026-11-17', '2026-11-17T20:00:00+08:00', '2026-02-30T20:00:00Z'):
            self.assertEqual(self.call('PUT', '/posts/' + POST_ID, {**POST, 'scheduled_at': timestamp})[0], 400)
        code, result = self.call('PUT', '/posts/' + POST_ID, {**POST, 'scheduled_at': FUTURE.replace('Z', '+00:00')})
        self.assertEqual(code, 200)
        self.assertEqual(result['post']['scheduled_at'], FUTURE)
        code, result = self.call('PUT', '/posts/' + POST_ID,
                                 {**POST, 'version': 1, 'scheduled_at': FUTURE.replace('Z', '.000Z')})
        self.assertEqual(code, 200)
        self.assertTrue(result['replayed'])

    def test_daily_results_are_unique_canonical_and_honestly_labelled(self):
        self.seed_campaign()
        code, result = self.call('PUT', '/results/' + RESULT_ID, RESULT)
        self.assertEqual(code, 200, result)
        self.assertEqual(result['result']['spend'], '10.50')
        self.assertEqual(result['result']['revenue'], '80.00')
        self.assertEqual(result['result']['origin'], 'user_reported')
        self.assertTrue(self.call('PUT', '/results/' + RESULT_ID, RESULT)[1]['replayed'])
        duplicate_id = 'f' * 32
        code, result = self.call('PUT', '/results/' + duplicate_id, RESULT)
        self.assertEqual(code, 409)
        self.assertEqual(result['code'], 'duplicate_result')
        self.assertEqual(result['existing_id'], RESULT_ID)
        other_day = {**RESULT, 'date': '2023-11-13'}
        self.assertEqual(self.call('PUT', '/results/' + duplicate_id, other_day)[0], 200)
        self.assertEqual(self.call('PUT', '/results/' + duplicate_id, {**RESULT, 'version': 1})[0], 409)
        self.assertEqual(self.call('GET', '/results/' + duplicate_id)[1]['result']['date'], '2023-11-13')
        self.seed_campaign(OTHER_CAMPAIGN)
        self.assertEqual(self.call('PUT', '/results/' + '1' * 32, {**RESULT, 'campaign_id': OTHER_CAMPAIGN})[0], 200)
        self.assertEqual(len(self.call('GET', '/results')[1]['results']), 3)

    def test_drafts_persist_without_connections_but_schedule_fails_closed(self):
        self.seed_post()
        approval = {'version': 1, 'approved': True, 'scheduled_at': FUTURE}
        code, result = self.call('POST', '/posts/' + POST_ID + '/schedule', approval)
        self.assertEqual(code, 409, result)
        self.assertEqual(result['code'], 'connection_not_ready')
        post = self.call('GET', '/posts/' + POST_ID)[1]['post']
        self.assertEqual((post['version'], post['status']), (1, 'planned'))
        self.ready_provider()
        self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/schedule', {**approval, 'approved': False})[0], 400)
        self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/schedule', {**approval, 'scheduled_at': meja._iso(STAMP)})[0], 400)
        self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/schedule', approval, OTHER)[0], 404)

    def test_scheduling_approval_is_retry_safe_cancel_is_versioned_and_queue_immutable(self):
        self.seed_post()
        self.ready_provider()
        approval = {'version': 1, 'approved': True, 'scheduled_at': FUTURE}
        code, result = self.call('POST', '/posts/' + POST_ID + '/schedule', approval)
        self.assertEqual(code, 200, result)
        self.assertEqual((result['post']['status'], result['post']['version']), ('queued', 2))
        self.assertTrue(self.call('POST', '/posts/' + POST_ID + '/schedule', approval)[1]['replayed'])
        self.assertEqual(self.call('PUT', '/posts/' + POST_ID, {**POST, 'version': 2, 'body': 'Unapproved change'})[0], 409)
        self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/cancel', {'version': 1})[0], 409)
        cancelled = self.call('POST', '/posts/' + POST_ID + '/cancel', {'version': 2})[1]
        self.assertEqual((cancelled['post']['status'], cancelled['post']['version']), ('cancelled', 3))
        self.assertTrue(self.call('DELETE', '/posts/' + POST_ID, {'version': 2})[1]['replayed'])
        self.assertEqual(self.call('PUT', '/posts/' + POST_ID, {**POST, 'version': 3, 'status': 'draft'})[0], 200)
        stored = json.loads(self.rows('SELECT data_json FROM marketing_posts WHERE id=?', (POST_ID,))[0][0])
        self.assertNotIn('status', stored)
        self.assertNotIn('scheduled_at', stored)

    def test_publishing_published_and_uncertain_are_never_requeued_or_cancelled(self):
        self.seed_post()
        self.ready_provider()
        for state in ('publishing', 'published', 'uncertain'):
            self.execute('UPDATE marketing_posts SET status=?,version=5,attempt_id=? WHERE id=?', (state, 'private-attempt', POST_ID))
            code, _ = self.call('POST', '/posts/' + POST_ID + '/schedule',
                                {'version': 5, 'approved': True, 'scheduled_at': meja._iso(STAMP + 900)})
            self.assertEqual(code, 409)
            self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/cancel', {'version': 5})[0], 409)
            self.assertEqual(self.call('PUT', '/posts/' + POST_ID, {**POST, 'version': 5})[0], 409)
            item = self.call('GET', '/posts/' + POST_ID)[1]['post']
            self.assertEqual(item['status'], state)
            self.assertNotIn('attempt_id', item)

    def test_schedule_requires_content_and_open_campaign(self):
        self.seed_post(body='')
        self.ready_provider()
        approval = {'version': 1, 'approved': True, 'scheduled_at': FUTURE}
        self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/schedule', approval)[0], 400)
        self.assertEqual(self.call('PUT', '/posts/' + POST_ID, {**POST, 'version': 1, 'format': 'image', 'body': ''})[0], 200)
        self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/schedule', {**approval, 'version': 2})[0], 400)
        self.call('PUT', '/posts/' + POST_ID, {**POST, 'version': 2})
        self.call('PUT', '/campaigns/' + CAMPAIGN_ID, {**CAMPAIGN, 'version': 1, 'status': 'archived'})
        code, result = self.call('POST', '/posts/' + POST_ID + '/schedule', {**approval, 'version': 3})
        self.assertEqual(code, 409)
        self.assertEqual(result['code'], 'campaign_closed')

    def test_failed_post_with_known_provider_identity_cannot_be_recreated(self):
        self.seed_post()
        self.execute("UPDATE marketing_posts SET status='failed',version=5,provider_post_id='123456' WHERE id=?", (POST_ID,))
        code, result = self.call('PUT', '/posts/' + POST_ID, {**POST, 'version': 5, 'status': 'draft'})
        self.assertEqual(code, 409)
        self.assertEqual(result['code'], 'post_locked')
        self.assertEqual(result['current']['provider_post_id'], '123456')
        self.assertEqual(self.call('POST', '/posts/' + POST_ID + '/schedule',
                                  {'version': 5, 'approved': True, 'scheduled_at': FUTURE})[0], 409)

    def test_readiness_failure_rolls_back_connection_binding_and_preserves_draft(self):
        self.seed_post()
        self.execute('CREATE TABLE test_binding (post_id TEXT PRIMARY KEY)')
        def fail_after_pin(conn, postgres, owner, post, scheduled_at, stamp):
            conn.cursor().execute('INSERT INTO test_binding VALUES (' + meja._ph(postgres) + ')', (post['id'],))
            return False, 'Sambungan belum disahkan.'
        self.provider.return_value = SimpleNamespace(check_schedule=fail_after_pin)
        code, _ = self.call('POST', '/posts/' + POST_ID + '/schedule',
                            {'version': 1, 'approved': True, 'scheduled_at': FUTURE})
        self.assertEqual(code, 409)
        self.assertEqual(self.rows('SELECT * FROM test_binding'), [])
        self.assertEqual(self.call('GET', '/posts/' + POST_ID)[1]['post']['status'], 'planned')

    def test_capacity_includes_archived_and_schema_is_repeatable(self):
        conn = self.connect()
        marketing.ensure_marketing_schema(conn, self.postgres)
        rows = [(format(index, '032x'), OWNER, json.dumps({'title': 'Arkib'}), 1, 'archived', '2023', '2023') for index in range(200)]
        conn.cursor().executemany('INSERT INTO marketing_campaigns VALUES (' + ','.join([meja._ph(self.postgres)] * 7) + ')', rows)
        conn.commit()
        marketing.ensure_marketing_schema(conn, self.postgres)
        conn.commit()
        conn.close()
        code, result = self.call('PUT', '/campaigns/' + CAMPAIGN_ID, CAMPAIGN)
        self.assertEqual(code, 409)
        self.assertEqual(result['code'], 'capacity')
        self.assertEqual(self.rows('SELECT COUNT(*) FROM marketing_campaigns')[0][0], 200)


class MarketingRouteTests(unittest.TestCase):
    """Exercise the real account token parser, schema setup and Copilot router."""
    postgres = False
    for helper in ('setUp', '_restore_secret', 'connect', 'dispatch_raw', 'q', 'profil'):
        locals()[helper] = getattr(KuotaCases, helper)

    def call(self, method, suffix, payload=None, token=''):
        raw = json.dumps(payload if payload is not None else {}).encode()
        return self.dispatch_raw(method, marketing.PREFIX + suffix, raw, token)

    def test_signed_out_and_forged_token_cannot_read_or_mutate(self):
        for token in ('', 'forged.session.token'):
            self.assertEqual(self.call('GET', '/workspace', token=token)[0], 401)
            self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, CAMPAIGN, token)[0], 401)
        self.assertEqual(self.q('SELECT COUNT(*) FROM marketing_campaigns')[0][0], 0)

    def test_authenticated_workspace_stays_owner_scoped(self):
        token, owner = self.profil('marketing-a@example.com')
        other, _ = self.profil('marketing-b@example.com')
        self.assertEqual(self.call('PUT', '/campaigns/' + CAMPAIGN_ID, CAMPAIGN, token)[0], 200)
        self.assertEqual(self.call('PUT', '/posts/' + POST_ID, POST, token)[0], 200)
        self.assertEqual(self.call('PUT', '/sources/' + SOURCE_ID, SOURCE, token)[0], 200)
        self.assertEqual(self.call('PUT', '/results/' + RESULT_ID, RESULT, token)[0], 200)
        code, own = self.call('GET', '/workspace', token=token)
        self.assertEqual(code, 200, own)
        for key in ('campaigns', 'posts', 'sources', 'results'):
            self.assertEqual(len(own[key]), 1)
        code, foreign = self.call('GET', '/workspace', token=other)
        self.assertEqual(code, 200, foreign)
        for key in ('campaigns', 'posts', 'sources', 'results'):
            self.assertEqual(foreign[key], [])
        self.assertNotIn(owner, json.dumps(foreign))
        self.assertEqual(self.call('GET', '/campaigns/' + CAMPAIGN_ID, token=other)[0], 404)
        code, collision = self.call('PUT', '/campaigns/' + CAMPAIGN_ID, CAMPAIGN, other)
        self.assertEqual(code, 404, collision)
        self.assertNotIn('current', collision)
        self.assertNotIn('campaign', collision)

    def test_version_conflict_and_retry_survive_full_dispatch(self):
        token, _ = self.profil('marketing-a@example.com')
        path = '/campaigns/' + CAMPAIGN_ID
        self.assertEqual(self.call('PUT', path, CAMPAIGN, token)[1]['campaign']['version'], 1)
        retry = self.call('PUT', path, CAMPAIGN, token)
        self.assertEqual(retry[0], 200, retry)
        self.assertTrue(retry[1]['replayed'])
        update = {**CAMPAIGN, 'version': 1, 'title': 'Tawaran baharu daripada MacBook'}
        self.assertEqual(self.call('PUT', path, update, token)[1]['campaign']['version'], 2)
        code, conflict = self.call('PUT', path, {**CAMPAIGN, 'version': 1, 'title': 'Salinan telefon'}, token)
        self.assertEqual(code, 409)
        self.assertEqual(conflict['current']['title'], update['title'])
        self.assertEqual(self.q('SELECT version FROM marketing_campaigns WHERE id=?', (CAMPAIGN_ID,))[0][0], 2)


def _postgres_test_connection(_self):
    """Destructive fixture setup is restricted to one named loopback database."""
    import psycopg2
    url = os.environ.get('COPILOT_TEST_DATABASE_URL', '')
    parsed = urlsplit(url)
    if (parsed.scheme not in {'postgres', 'postgresql'}
            or parsed.hostname not in {'localhost', '127.0.0.1', '::1'}
            or parsed.path != '/copilot_kuota_test' or parsed.query or parsed.fragment):
        raise RuntimeError('Marketing PostgreSQL test connection rejected')
    return psycopg2.connect(url)


def _reset_postgres_fixture(connect):
    conn = connect()
    cur = conn.cursor()
    cur.execute("SELECT tablename FROM pg_tables WHERE schemaname='public'")
    for (name,) in cur.fetchall():
        if name.startswith(('pelanggan_', 'copilot_', 'marketing_')) or name == 'test_binding':
            cur.execute('DROP TABLE "' + name.replace('"', '""') + '" CASCADE')
    conn.commit()
    conn.close()


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not enabled')
class PostgresMarketingTests(MarketingTests):
    postgres = True
    connect = _postgres_test_connection

    def setUp(self):
        _reset_postgres_fixture(self.connect)
        super().setUp()


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not enabled')
class PostgresMarketingRouteTests(MarketingRouteTests):
    postgres = True
    connect = _postgres_test_connection

    def setUp(self):
        _reset_postgres_fixture(self.connect)
        super().setUp()


if __name__ == '__main__':
    unittest.main()
