import json
import os
import unittest
from test_pelanggan_kuota import KuotaCases, STAMP, HANTAR, MUKTAMAD


class IntelligenceTests(unittest.TestCase):
    postgres = False
    for name in ('setUp', '_restore_secret', 'connect', 'dispatch_raw', 'kuota', 'q', 'profil', 'copilot'):
        locals()[name] = getattr(KuotaCases, name)

    def call(self, method, path, body=None, token=''):
        return self.dispatch_raw(method, path, json.dumps(body or {}).encode(), token)

    def propose(self, token, actions):
        _, context = self.call('GET', '/api/akaun/copilot/konteks', token=token)
        status, reserved = self.copilot(token, 'intelligence-test-0001')
        self.assertEqual(status, 200, reserved)
        key = reserved['rezab_id']
        status, data = self.kuota(HANTAR, {'rezab_id': key}, token)
        self.assertEqual(status, 200, data)
        status, data = self.kuota(MUKTAMAD, {'rezab_id': key, 'hasil': 'settled',
            'jawapan': 'Sila semak cadangan.\nBelum disimpan.', 'langkah': [], 'format': 'tindakan',
            'cadangan': actions, 'asas': {'minggu_mula': context['minggu_mula'], 'pelan_versi': context['pelan']['versi'] if context['pelan'] else 0,
                                        'ingatan_versi': context['ingatan']['versi']}}, token)
        self.assertEqual(status, 200, data)
        return key

    def entry(self, kind='hasil', value='350.00'):
        return {'jenis': 'angka', 'teks': 'Rekod ujian', 'tarikh': '2023-11-15', 'kategori': kind, 'nilai': value}

    def test_memory_account_scope_persistence_conflict_and_clear(self):
        token, _ = self.profil('a@example.com')
        other, _ = self.profil('b@example.com')
        path = '/api/akaun/copilot/ingatan'
        self.assertEqual(self.call('GET', path)[0], 401)
        status, body = self.call('PUT', path, {'versi': 0, 'ingatan': {'produk': 'Latte RM8'}}, token)
        self.assertEqual(status, 200, body)
        self.assertEqual(self.call('GET', path, token=token)[1]['ingatan']['produk'], 'Latte RM8')
        self.assertEqual(self.call('GET', path, token=other)[1]['ingatan'], {})
        self.assertEqual(self.call('PUT', path, {'versi': 0, 'ingatan': {}}, token)[0], 409)
        self.assertEqual(self.call('PUT', path, {'versi': 1, 'ingatan': {}}, token)[0], 200)
        self.assertEqual(self.call('GET', path, token=token)[1]['ingatan']['produk'], '')

    def test_numbers_decimal_null_units_and_withdrawn_records(self):
        token, _ = self.profil('a@example.com')
        other, _ = self.profil('b@example.com')
        self.assertIsNone(self.call('GET', '/api/akaun/copilot/konteks', token=token)[1]['angka']['minggu']['hasil'])
        for kind, value, unit in [('hasil', '0.10', 'MYR'), ('hasil', '0.20', 'MYR'), ('kos', '0.05', 'MYR'), ('lain', '100', 'unit')]:
            status, body = self.call('POST', '/api/akaun/angka', {'jenis': kind, 'nilai': value, 'tarikh': '2023-11-15', 'unit': unit}, token)
            self.assertEqual(status, 200, body)
        self.call('POST', '/api/akaun/angka', {'jenis': 'hasil', 'nilai': '999', 'tarikh': '2023-11-15', 'unit': 'MYR'}, other)
        data = self.call('GET', '/api/akaun/copilot/konteks', token=token)[1]
        self.assertEqual(data['angka']['minggu']['hasil'], '0.30')
        self.assertEqual(data['angka']['minggu']['beza_rekod'], '0.25')
        self.assertEqual(data['angka']['bilangan_rekod'], 4)

    def test_confirmation_only_atomic_idempotent_and_foreign_account_denied(self):
        token, _ = self.profil('a@example.com')
        other, _ = self.profil('b@example.com')
        key = self.propose(token, [self.entry(), self.entry('kos', '120.00')])
        self.assertEqual(self.q('SELECT COUNT(*) FROM pelanggan_angka')[0][0], 0)
        path = '/api/akaun/copilot/tindakan'
        self.assertEqual(self.call('POST', path, {'cadangan_id': key}, token)[0], 400)
        self.assertEqual(self.call('POST', path, {'cadangan_id': key, 'sahkan': True}, other)[0], 404)
        self.assertEqual(self.call('GET', path+'/'+key, token=other)[0], 404)
        status, body = self.call('POST', path, {'cadangan_id': key, 'sahkan': True, 'cadangan': [self.entry(value='99999')]}, token)
        self.assertEqual(status, 200, body)
        self.assertEqual(body['hasil'][0]['rekod']['nilai'], '350.00')
        self.assertTrue(self.call('POST', path, {'cadangan_id': key, 'sahkan': True}, token)[1]['main_semula'])
        self.assertEqual(self.q('SELECT COUNT(*) FROM pelanggan_angka')[0][0], 2)
        self.assertTrue(self.call('GET', path+'/'+key, token=token)[1]['disimpan'])

    def test_second_invalid_action_rolls_back_first(self):
        token, _ = self.profil('a@example.com')
        bad = self.entry('kos'); bad['tarikh'] = '2099-01-01'
        key = self.propose(token, [self.entry(), bad])
        status, _ = self.call('POST', '/api/akaun/copilot/tindakan', {'cadangan_id': key, 'sahkan': True}, token)
        self.assertEqual(status, 400)
        self.assertEqual(self.q('SELECT COUNT(*) FROM pelanggan_angka')[0][0], 0)
        self.assertEqual(self.q('SELECT COUNT(*) FROM copilot_action_receipt')[0][0], 0)

    def test_changed_memory_blocks_pending_proposal(self):
        token, _ = self.profil('a@example.com')
        key = self.propose(token, [self.entry()])
        self.call('PUT', '/api/akaun/copilot/ingatan', {'versi': 0, 'ingatan': {'bajet': 'RM200'}}, token)
        self.assertEqual(self.call('POST', '/api/akaun/copilot/tindakan', {'cadangan_id': key, 'sahkan': True}, token)[0], 409)

    def test_plan_batch_and_replay_persist_all_metadata(self):
        token, _ = self.profil('a@example.com')
        proposals = [{'jenis': 'tugas', 'teks': f'Hubungi pelanggan {n}', 'tarikh': '2023-11-13', 'kategori': '', 'nilai': ''} for n in range(2)]
        key = self.propose(token, proposals)
        status, replay = self.copilot(token, 'intelligence-test-0001')
        self.assertEqual(status, 200)
        self.assertEqual(replay['cadangan'], proposals)
        self.assertEqual(replay['langkah'], [])
        self.assertIn('\n', replay['jawapan'])
        status, data = self.call('POST', '/api/akaun/copilot/tindakan', {'cadangan_id': key, 'sahkan': True}, token)
        self.assertEqual(status, 200, data)
        self.assertEqual(self.q('SELECT COUNT(*) FROM pelanggan_tugas')[0][0], 2)

    def test_voice_daily_limit_is_persistent_per_account(self):
        token, _ = self.profil('a@example.com')
        other, _ = self.profil('b@example.com')
        path = '/api/akaun/copilot/suara/rezab'
        for _ in range(10):
            self.assertEqual(self.call('POST', path, token=token)[0], 200)
        self.assertEqual(self.call('POST', path, token=token)[0], 429)
        self.assertEqual(self.call('POST', path, token=other)[0], 200)

    def test_chat_preserves_multiline_and_proposal_pointer(self):
        token, _ = self.profil('a@example.com')
        key = self.propose(token, [self.entry()])
        profile = {'nama': 'Kedai Ujian', 'jualan': 'Kopi untuk pekerja pejabat', 'peringkat': 'jual',
                   'mesej': [{'dari': 'penasihat', 'teks': 'Tajuk\n\nPerenggan kedua', 'langkah': [], 'format': 'pemasaran', 'cadangan_id': key}]}
        self.assertEqual(self.call('POST', '/api/akaun/penasihat', {'penasihat': profile}, token)[0], 200)
        saved = self.call('GET', '/api/akaun/saya', token=token)[1]['penasihat']['mesej'][0]
        self.assertEqual(saved['teks'], profile['mesej'][0]['teks'])
        self.assertEqual(saved['cadangan_id'], key)

@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not configured')
class PostgresIntelligenceTests(IntelligenceTests):
    postgres = True

    def connect(self):
        from test_pelanggan_kuota import PostgresKuotaTests
        return PostgresKuotaTests.connect(self)

    def setUp(self):
        super().setUp()
        conn = self.connect()
        cur = conn.cursor()
        # This connection is guarded to the dedicated loopback test database.
        for name in ('copilot_memory', 'copilot_action_receipt', 'copilot_voice_usage',
                     'pelanggan_import', 'pelanggan_angka', 'pelanggan_tugas', 'pelanggan_pelan',
                     'copilot_service_nonce', 'copilot_usage_reservation', 'copilot_usage_counter',
                     'copilot_quota_policy', 'pelanggan_akaun'):
            cur.execute(f'DROP TABLE IF EXISTS {name}')
        conn.commit()
        conn.close()


if __name__ == '__main__':
    unittest.main()
