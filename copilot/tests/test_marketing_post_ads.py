"""Verify review, amount, ownership and deduplication without creating real ads."""
import json
import unittest
from datetime import datetime, timezone

import test_marketing_facebook_ads as ads_tests
import test_marketing_social as fixtures


class PostAdsTests(unittest.TestCase):
    connect = fixtures.MarketingSocialTests.connect
    connection = fixtures.MarketingSocialTests.connection
    queued_post = fixtures.MarketingSocialTests.queued_post
    ads = ads_tests.FacebookAdsTests.ads

    def setUp(self):
        fixtures.MarketingSocialTests.setUp(self)
        self.queued_post()
        self.connection('facebook_ads', account='123')
        with self.connect() as conn:
            conn.execute("UPDATE marketing_posts SET status='published',provider_post_id='1001_987'")
        self.body = {'account_id': '123', 'post_id': fixtures.POST, 'days': 3, 'budget': '60.09', 'country': 'MY'}
        self.page_phone = '+60 12-3456789'
        self.writes = []
        self.page_fields = []
        self.creative_target = None
        self.actual = {}
        self.reject = ''
        self.lose = ''

    def meta(self, method, url, params, token):
        endpoint = url.rsplit('/', 1)[-1]
        if method == 'GET':
            if endpoint == 'act_123':
                return {'account_id': '123', 'name': 'Akaun', 'currency': 'MYR', 'account_status': 1}
            if endpoint == '1001':
                self.page_fields.append(params['fields'])
                profile = {'id': '1001', 'name': 'Page'}
                if 'whatsapp_number' in params['fields'].split(','):
                    profile['whatsapp_number'] = self.page_phone
                return profile
            if endpoint == 'published_posts':
                return {'data': [{'id': '1001_987', 'attachments': {'data': [{'target': {'id': '651'}}]}}]}
            if endpoint == '1001_987':
                return {'id': endpoint, 'from': {'id': '1001'}, 'message': 'Menu', 'is_published': True,
                        'permalink_url': 'https://www.facebook.com/1001/posts/987'}
            if endpoint == '911':
                rows = [{'id': '912'}] if any(e[0] == 'adsets' for e in self.writes) else []
                return {'status': 'PAUSED', 'adsets': {'data': rows}}
            if endpoint == '913':
                action = json.loads(dict(self.writes)['adcreatives']['call_to_action'])
                if self.creative_target is not None:
                    action['value']['link'] = self.creative_target
                return {'call_to_action': action}
            if endpoint == '912':
                adset = dict(self.writes)['adsets']
                return dict(campaign_id='911', lifetime_budget='6009', destination_type=adset['destination_type'],
                            promoted_object=json.loads(adset.get('promoted_object', '{}')),
                            end_time=datetime.fromtimestamp(fixtures.NOW + 3*86400, timezone.utc).isoformat(),
                            targeting={'geo_locations': {'countries': ['MY']}},
                            ads={'data': [{'id': '914', 'creative': {'id': '913','object_story_id': '1001_987'}}]}) | self.actual
            raise AssertionError(endpoint)
        self.writes.append((endpoint, params))
        if self.reject == endpoint:
            return {'error': {'code': 100}}
        if self.lose == endpoint:
            raise TimeoutError()
        if endpoint == '911':
            return {'success': True}
        return {'id': {'campaigns': '911', 'adsets': '912', 'adcreatives': '913', 'ads': '914'}[endpoint]}

    def review(self):
        status, result = self.ads('post-preview', self.body, self.meta)
        self.assertEqual(status, 200, result)
        return {'account_id': '123', 'post_id': fixtures.POST, 'review_id': result['review_id'],
                'approve_spend': True, 'ordinary_ad': True}

    def test_preview_never_spends_and_approval_cannot_change_budget(self):
        payload = self.review()
        self.assertEqual(self.writes, [])
        for changes in ({'approve_spend': False}, {'ordinary_ad': False}, {'budget': '90'}, {'review_id': '0'*40}):
            self.assertGreaterEqual(self.ads('post-launch', payload | changes, self.meta)[0], 400)
        self.assertGreaterEqual(self.ads('post-launch', payload, self.meta, owner=fixtures.OTHER)[0], 400)
        self.assertEqual(self.writes, [])
        self.assertEqual(self.ads('post-launch', payload, self.meta)[0], 200)
        self.assertEqual([entry[0] for entry in self.writes], ['campaigns','adsets','adcreatives','ads','911'])
        self.assertEqual(self.writes[0][1]['status'], 'PAUSED')
        self.assertEqual(self.writes[1][1]['lifetime_budget'], 6009)
        self.assertNotIn('daily_budget', self.writes[1][1])
        self.assertEqual(self.writes[2][1]['object_story_id'], '1001_987')
        self.assertEqual(json.loads(self.writes[2][1]['call_to_action'])['value']['link'], 'https://wa.me/60123456789')
        self.assertEqual(self.writes[-1][1]['status'], 'ACTIVE')
        self.assertEqual(self.ads('post-launch', payload, self.meta)[0], 200)
        self.assertTrue(self.ads('post-preview', self.body, self.meta)[1]['already_submitted'])
        self.assertEqual(len(self.writes), 5)

    def test_changed_phone_budget_and_lost_approval(self):
        original = self.review()
        self.body['budget'] = '80'
        replacement = self.review()
        self.assertEqual(self.ads('post-launch', original, self.meta)[0], 409)
        self.page_phone = '+60123456780'
        self.assertEqual(self.ads('post-launch', replacement, self.meta)[0], 409)
        self.assertEqual(self.writes, [])
        self.body['budget'] = '60.09'
        self.page_phone = '+60123456789'
        payload = self.review()
        self.lose = '911'
        self.assertEqual(self.ads('post-launch', payload, self.meta)[1]['code'], 'check_meta')
        self.assertEqual(self.ads('post-preview', self.body, self.meta)[1]['code'], 'check_meta')
        self.assertEqual(self.ads('post-launch', payload, self.meta)[0], 409)
        self.assertEqual(len(self.writes), 5)

    def test_explicit_rejection_can_resume_and_mismatched_remote_cap_never_activates(self):
        self.reject = 'adcreatives'
        self.assertEqual(self.ads('post-launch', self.review(), self.meta)[0], 502)
        self.reject = ''
        self.actual = {'lifetime_budget': '9000'}
        self.assertEqual(self.ads('post-launch', self.review(), self.meta)[1]['code'], 'mismatch')
        self.assertEqual([e[0] for e in self.writes], ['campaigns','adsets','adcreatives','adcreatives','ads'])

    def test_messenger_without_whatsapp(self):
        self.page_phone = None
        self.body['destination'] = 'messenger'
        payload = self.review()
        self.assertEqual(self.ads('post-launch', payload, self.meta)[0], 200)
        self.assertEqual(set(self.page_fields), {'id,name'})
        writes = dict(self.writes)
        self.assertEqual(writes['campaigns']['objective'], 'OUTCOME_ENGAGEMENT')
        self.assertEqual(writes['adsets']['destination_type'], 'MESSENGER')
        self.assertEqual(json.loads(writes['adsets']['promoted_object']), {'page_id': '1001'})
        cta = json.loads(writes['adcreatives']['call_to_action'])
        self.assertEqual(cta, {'type': 'MESSAGE_PAGE', 'value': {'app_destination': 'MESSENGER', 'link': 'https://m.me/1001'}})

    def test_link_without_whatsapp_and_stale_review(self):
        self.page_phone = None
        self.body['destination'] = 'messenger'
        old = self.review()
        self.body.update(destination='link', destination_url='https://t.me/kedai')
        payload = self.review()
        self.assertEqual(self.ads('post-launch', old, self.meta)[0], 409)
        self.assertEqual(self.writes, [])
        self.assertEqual(self.ads('post-launch', payload, self.meta)[0], 200)
        self.assertEqual(set(self.page_fields), {'id,name'})
        writes = dict(self.writes)
        self.assertEqual(writes['campaigns']['objective'], 'OUTCOME_TRAFFIC')
        self.assertEqual(writes['adsets']['destination_type'], 'WEBSITE')
        self.assertEqual(writes['adsets']['optimization_goal'], 'LINK_CLICKS')
        self.assertNotIn('promoted_object', writes['adsets'])
        self.assertEqual(json.loads(writes['adcreatives']['call_to_action']),
                         {'type': 'LEARN_MORE', 'value': {'link': 'https://t.me/kedai'}})

    def test_invalid_or_changed_destination_never_activates(self):
        for extra in ({'destination': ''}, {'destination': []}, {'destination': 'link'},
                      {'destination': 'link', 'destination_url': 'tg://resolve?domain=kedai'},
                      {'destination': 'link', 'destination_url': 'https://user@contoh.my'},
                      {'destination_url': 'https://contoh.my'}):
            self.assertEqual(self.ads('post-preview', self.body | extra, self.meta)[0], 400)
        self.assertEqual(self.page_fields, [])
        self.body.update(destination='link', destination_url='https://contoh.my')
        self.creative_target = 'https://berbeza.my'
        self.assertEqual(self.ads('post-launch', self.review(), self.meta)[1]['code'], 'mismatch')
        self.assertNotIn('911', dict(self.writes))

    def test_existing_whatsapp_review_still_launches(self):
        payload = self.review()
        with self.connect() as conn:
            spec = json.loads(conn.execute('SELECT settings FROM marketing_post_ads').fetchone()[0])
            del spec['destination']
            conn.execute('UPDATE marketing_post_ads SET settings=?', (json.dumps(spec),))
        self.assertEqual(self.ads('post-launch', payload, self.meta)[0], 200)

    def test_missing_phone_and_unpublished_post_block_review(self):
        self.page_phone = None
        self.assertEqual(self.ads('post-preview', self.body, self.meta)[1]['code'], 'whatsapp')
        with self.connect() as conn:
            conn.execute("UPDATE marketing_posts SET status='draft'")
        self.assertEqual(self.ads('post-preview', self.body, self.meta)[0], 409)
        self.assertEqual(self.writes, [])

    def test_media_only_receipt_resolves_original_story_and_revocation_removes_draft(self):
        with self.connect() as conn:
            conn.execute("UPDATE marketing_posts SET provider_post_id='651',data_json=?",
                         (json.dumps({'platform': 'facebook', 'format': 'video', 'body': 'Menu',
                                      'media_url': 'https://example.test/menu.mp4'}),))
        self.review()
        with self.connect() as conn:
            spec = json.loads(conn.execute('SELECT settings FROM marketing_post_ads').fetchone()[0])
            self.assertEqual(spec['story_id'], '1001_987')
            import marketing_lifecycle
            marketing_lifecycle.apply_callback(conn, False, 'meta', '12345', '5001', 'media-approval', fixtures.NOW)
            self.assertEqual(conn.execute('SELECT count(*) FROM marketing_post_ads').fetchone()[0], 0)
        self.assertEqual(self.writes, [])
