import json
import sqlite3
import unittest
from unittest.mock import patch

import marketing_telegram_discovery as discovery


def provider_response(rows, urls):
    return {'output': [
        {'type': 'web_search_call', 'status': 'completed'},
        {'type': 'message', 'content': [{
            'type': 'output_text',
            'text': json.dumps({'results': rows}),
            'annotations': [{'type': 'url_citation', 'url': url} for url in urls],
        }]},
    ]}


class TelegramDiscoveryTests(unittest.TestCase):
    def test_only_cited_public_handles_and_same_community_rules(self):
        row = {'name': 'Puchong', 'url': 'https://t.me/PuchongArea', 'reason': 'Penduduk Puchong',
               'rule': 'allowed', 'rule_evidence': 'Promosi dibenarkan',
               'rule_source': 'https://t.me/OtherPlace/1'}
        rows = [row, row, {**row, 'url': 'https://t.me/Uncited'},
                {**row, 'url': 'https://t.me/+private'},
                {**row, 'url': 'https://t.me.invalid/PuchongArea'}]
        response = provider_response(rows, ['https://t.me/s/PuchongArea/12', row['rule_source']])
        results = discovery.parse_results(response)
        self.assertEqual([item['handle'] for item in results], ['@puchongarea'])
        self.assertEqual(results[0]['rule'], 'Belum dapat disahkan')
        self.assertFalse(results[0]['rule_source'])
        self.assertEqual(results[0]['sources'], ['https://t.me/s/PuchongArea/12'])

    def test_quota_is_per_account_and_resets_at_malaysia_midnight(self):
        profile = {'business': 'Makanan sejuk beku', 'location': 'Puchong',
                   'audience': '', 'language': ''}
        with sqlite3.connect(':memory:') as conn, patch.dict(
            discovery.os.environ, {'MARKETING_TELEGRAM_SEARCH_OPENAI_KEY': 'synthetic'}
        ), patch.object(discovery, '_search', return_value=[]) as search:
            conn.execute('CREATE TABLE marketing_telegram_search_limits '
                         '(akaun_id TEXT PRIMARY KEY, day INTEGER, hits INTEGER)')
            for _ in range(5):
                self.assertEqual(discovery.discover(conn, False, 'a', profile, 57599)[0], 200)
            self.assertEqual(discovery.discover(conn, False, 'a', profile, 57599)[0], 429)
            self.assertEqual(discovery.discover(conn, False, 'b', profile, 57599)[0], 200)
            self.assertEqual(discovery.discover(conn, False, 'a', profile, 57600)[0], 200)
            self.assertEqual(search.call_count, 7)
