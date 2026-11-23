"""Exercise the Ads flow against an isolated account DB and a synthetic Meta transport."""
import json
import os
import unittest
from unittest.mock import Mock, patch
from urllib.parse import parse_qs, urlsplit

import marketing_facebook_ads as ads
import marketing_lifecycle as lifecycle
import marketing_social as social
import pelanggan_marketing as marketing
import test_marketing_social as fixtures

OWNER, OTHER, NOW, PLAN = fixtures.OWNER, fixtures.OTHER, fixtures.NOW, fixtures.CAMPAIGN


class FacebookAdsTests(unittest.TestCase):
    setUp = fixtures.MarketingSocialTests.setUp
    connect = fixtures.MarketingSocialTests.connect
    call = fixtures.MarketingSocialTests.call
    begin = fixtures.MarketingSocialTests.begin
    connection = fixtures.MarketingSocialTests.connection

    def ads(self, action, payload, transport, owner=OWNER):
        conn = self.connect()
        try:
            original = social.provider_call
            def local(method, url, params=None, token=None, unused=None, **kwargs):
                return original(method, url, params, token, transport, **kwargs)
            with patch.object(social, 'provider_call', local):
                return marketing.dispatch_marketing('POST', marketing.PREFIX + '/facebook-ads/' + action,
                                                    conn, False, owner, payload, NOW)
        finally:
            conn.close()

    def grant(self):
        self.connection('facebook_ads', account='123')
        conn = self.connect()
        conn.execute('UPDATE marketing_campaigns SET data_json=? WHERE id=?', (json.dumps({'title': 'Produk ujian'}), PLAN))
        conn.commit()
        conn.close()

    @staticmethod
    def create_payload():
        return {'account_id': '123', 'plan_id': PLAN, 'version': 1, 'objective': 'OUTCOME_TRAFFIC', 'ordinary_ad': True}

    def test_ads_oauth_uses_verified_user_token_and_separate_selected_account(self):
        self.connection('facebook')
        with patch.dict(os.environ, MARKETING_META_LOGIN_CONFIG_ID='111', MARKETING_META_ADS_LOGIN_CONFIG_ID='222'):
            status, body = self.call('POST', '/facebook_ads/begin')
            query = parse_qs(urlsplit(body['authorization_url']).query)
            self.assertEqual(query['config_id'], ['222'])
        requested = []
        def meta(method, url, params, token):
            requested.append(url)
            if url.endswith('/debug_token'):
                return {'data': {'type': 'USER', 'is_valid': True, 'user_id': '5001', 'app_id': '12345',
                                 'expires_at': NOW + 86400, 'scopes': social.SCOPES[ads.PLATFORM]}}
            if url.endswith('/me/permissions'):
                return {'data': [{'permission': p, 'status': 'granted'} for p in social.SCOPES[ads.PLATFORM]]}
            if url.endswith('/me/adaccounts'):
                self.assertEqual(token, fixtures.USER_TOKEN)
                return {'data': [{'account_id': '123', 'name': 'Akaun iklan', 'account_status': 1},
                                 {'account_id': '456', 'name': 'Ditutup', 'account_status': 2}]}
            return fixtures.MarketingSocialTests.meta_transport(self, method, url, params, token)
        status, data = self.call('POST', '/complete', {'code': 'example', 'state': query['state'][0]}, transport=meta)
        self.assertEqual(status, 200, data)
        self.assertEqual(len(data['candidates']), 1)
        self.assertNotIn(fixtures.USER_TOKEN, json.dumps(data))
        choice = {'candidate_id': data['candidates'][0]['id']}
        self.assertEqual(self.call('POST', '/facebook_ads/select', choice, owner=OTHER)[0], 404)
        self.assertEqual(self.call('POST', '/facebook_ads/select', choice)[0], 200)
        self.assertFalse(any(url.endswith('/me/accounts') for url in requested))
        conn = self.connect()
        saved = dict(conn.execute('SELECT platform,token_cipher FROM marketing_connections'))
        self.assertEqual(social._decrypt(saved['facebook_ads']), fixtures.USER_TOKEN)
        self.assertEqual(social._decrypt(saved['facebook']), fixtures.TOKEN)
        conn.close()

    def test_permissions_are_independent_and_missing_ads_grant_is_rejected(self):
        with patch.dict(os.environ, MARKETING_META_ADS_LOGIN_CONFIG_ID='', MARKETING_META_LOGIN_CONFIG_ID=''):
            for platform in ('facebook', 'facebook_ads'):
                _, data = self.call('POST', '/' + platform + '/begin')
                query = parse_qs(urlsplit(data['authorization_url']).query)
                self.assertEqual(query['scope'][0].split(','), social.SCOPES[platform])
        result = self.call('POST', '/complete', {'state': query['state'][0], 'code': 'example'},
                           transport=lambda *args: fixtures.MarketingSocialTests.meta_transport(self, *args))
        self.assertEqual(result[0], 409)

    def test_create_only_paused_once_and_revoke_grant(self):
        self.grant()
        body = self.create_payload()
        transport = Mock(return_value={'id': '901'})
        self.assertEqual(self.ads('campaigns', {**body, 'status': 'ACTIVE'}, transport)[0], 400)
        self.assertEqual(self.ads('campaigns', {**body, 'ordinary_ad': False}, transport)[0], 400)
        self.assertEqual(self.ads('campaigns', {**body, 'version': 8}, transport)[0], 409)
        self.assertEqual(self.ads('campaigns', {**body, 'plan_id': '0' * 32}, transport)[0], 404)
        self.assertEqual(self.ads('campaigns', body, transport, OTHER)[0], 409)
        self.assertEqual(self.ads('campaigns', {**body, 'account_id': '456'}, transport)[0], 409)
        transport.assert_not_called()
        self.assertEqual(self.ads('campaigns', body, transport), (200, {'ok': True, 'existing': False, 'campaign_id': '901'}))
        self.assertTrue(self.ads('campaigns', body, transport)[1]['existing'])
        method, url, params, token = transport.call_args.args
        self.assertEqual((method, url.rsplit('/', 2)[-2:], params['name'], params['status']), ('POST', ['act_123', 'campaigns'], 'Produk ujian', 'PAUSED'))
        self.assertFalse(any('budget' in k and k != 'is_adset_budget_sharing_enabled' for k in params))
        transport.assert_called_once()
        conn = self.connect()
        self.assertEqual(lifecycle.apply_callback(conn, False, 'meta', '12345', '5001', 'testreceipt', NOW + 1)[0], 200)
        self.assertEqual(conn.execute('SELECT COUNT(*) FROM marketing_ads_creations').fetchone()[0], 0)
        conn.close()
        self.assertEqual(self.ads('campaigns', body, transport)[0], 409)
        transport.assert_called_once()

    def test_lost_response_never_replays_and_explicit_rejection_can_retry(self):
        self.grant()
        transport = Mock(side_effect=[{'error': {'code': 100}}, TimeoutError('lost')])
        body = self.create_payload()
        self.assertEqual(self.ads('campaigns', body, transport)[0], 502)
        self.assertEqual(self.ads('campaigns', body, transport)[1]['code'], 'check_meta')
        self.assertEqual(self.ads('campaigns', body, transport)[1]['code'], 'check_meta')
        self.assertEqual(transport.call_count, 2)

    def test_reports_keep_currency_and_empty_metrics_and_expose_only_cursor(self):
        self.grant()
        body = {'account_id': '123', 'period': 'last_7d', 'after': 'from-first-page'}
        transport = Mock(return_value={'data': [
            {'id': '500', 'name': 'Active', 'effective_status': 'ACTIVE', 'insights': {
                'data': [{'account_currency': 'USD', 'spend': '10.55', 'clicks': '0', 'impressions': '45'}]}},
            {'id': '501', 'name': 'Paused', 'effective_status': 'PAUSED'}],
            'paging': {'next': 'private-provider-url', 'cursors': {'after': 'next-page'}}})
        self.assertEqual(self.ads('report', body, transport, OTHER)[0], 409)
        self.assertEqual(self.ads('report', {**body, 'period': 'invalid'}, transport)[0], 400)
        status, data = self.ads('report', body, transport)
        self.assertEqual(status, 200)
        self.assertEqual(data['after'], 'next-page')
        self.assertEqual(data['campaigns'][0]['metrics']['account_currency'], 'USD')
        self.assertIsNone(data['campaigns'][1]['metrics'])
        self.assertNotIn('private-provider-url', json.dumps(data))
        method, url, params, token = transport.call_args.args
        self.assertEqual((method, params['after']), ('GET', 'from-first-page'))
        self.assertIn('insights.date_preset(last_7d)', params['fields'])
        self.assertTrue(url.endswith('/act_123/campaigns'))
        self.assertEqual(self.call('DELETE', '/facebook_ads')[0], 200)
        self.assertEqual(self.ads('report', body, transport)[0], 409)
        transport.assert_called_once()
