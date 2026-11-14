import json
import sqlite3
import tempfile
import unittest
from pathlib import Path

import pelanggan_akaun as akaun


class AkaunTests(unittest.TestCase):
    def setUp(self):
        akaun._HITS.clear()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'akaun.db')
        self.secret = 'rahsia-ujian'

    def connect(self):
        return sqlite3.connect(self.path)

    def call(self, method, path, body=None, token='', origin='https://beshareaisolution.com', ip='1.1.1.1'):
        raw = b'' if body is None else json.dumps(body).encode()
        header = f'Bearer {token}' if token else ''
        return akaun.dispatch(method, path, origin, header, raw, self.connect, False, self.secret, ip, now=1_700_000_000)

    def test_daftar_masuk_dan_simpan_penasihat(self):
        status, body = self.call('POST', '/api/akaun/daftar', {'email': 'Siti@Kedai.my', 'password': 'kata-rahsia'})
        self.assertEqual(status, 200)
        self.assertEqual(body['email'], 'siti@kedai.my')
        token = body['token']
        status, body = self.call('GET', '/api/akaun/saya', token=token)
        self.assertEqual(status, 200)
        self.assertIsNone(body['penasihat'])
        profile = {
            'nama': 'Kedai Siti',
            'jualan': 'Kuih raya kepada jiran taman',
            'peringkat': 'jual',
            'mesej': [{'dari': 'penasihat', 'teks': 'Baik.', 'langkah': ['Satu', 'Dua', 'Tiga']}],
        }
        status, body = self.call('POST', '/api/akaun/penasihat', {'penasihat': profile}, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(body['penasihat']['nama'], 'Kedai Siti')
        status, lagi = self.call('POST', '/api/akaun/masuk', {'email': 'siti@kedai.my', 'password': 'kata-rahsia'})
        self.assertEqual(status, 200)
        status, body = self.call('GET', '/api/akaun/saya', token=lagi['token'])
        self.assertEqual(body['penasihat']['langkah'] if False else body['penasihat']['mesej'][0]['langkah'], ['Satu', 'Dua', 'Tiga'])

    def test_akaun_kedua_tidak_nampak_perniagaan_pertama(self):
        _, pertama = self.call('POST', '/api/akaun/daftar', {'email': 'a@kedai.my', 'password': 'kata-rahsia'})
        self.call('POST', '/api/akaun/penasihat', {'penasihat': {
            'nama': 'Kedai A', 'jualan': 'Nasi lemak untuk pekerja', 'peringkat': 'mula',
            'mesej': [{'dari': 'penasihat', 'teks': 'Rahsia A'}],
        }}, token=pertama['token'])
        _, kedua = self.call('POST', '/api/akaun/daftar', {'email': 'b@kedai.my', 'password': 'kata-rahsia'})
        status, body = self.call('GET', '/api/akaun/saya', token=kedua['token'])
        self.assertEqual(status, 200)
        self.assertIsNone(body['penasihat'])

    def test_kata_laluan_salah_dan_token_rosak(self):
        self.call('POST', '/api/akaun/daftar', {'email': 'a@kedai.my', 'password': 'kata-rahsia'})
        status, bad = self.call('POST', '/api/akaun/masuk', {'email': 'a@kedai.my', 'password': 'salah-sekali'})
        self.assertEqual(status, 401)
        self.assertIn('tidak tepat', bad['error'])
        status, missing = self.call('POST', '/api/akaun/masuk', {'email': 'tiada@kedai.my', 'password': 'kata-rahsia'})
        self.assertEqual(status, 401)
        self.assertEqual(bad['error'], missing['error'])
        status, _ = self.call('GET', '/api/akaun/saya', token='bukan.token.sah')
        self.assertEqual(status, 401)
        status, ulang = self.call('POST', '/api/akaun/daftar', {'email': 'a@kedai.my', 'password': 'kata-rahsia'})
        self.assertEqual(status, 409)

    def test_asal_lain_ditolak(self):
        status, body = self.call('POST', '/api/akaun/daftar', {'email': 'a@kedai.my', 'password': 'kata-rahsia'}, origin='https://contoh.evil')
        self.assertEqual(status, 403)
        self.assertNotIn('token', body)

    def test_desk_hata_tidak_terlibat(self):
        src = Path(__file__).resolve().parents[1].joinpath('account/pelanggan_akaun.py').read_text()
        self.assertNotIn('hata_trade', src)
        self.assertNotIn('moomoo', src.lower())
        self.assertNotIn('portfolio_records', src)

    def _profil(self, nama):
        return {
            'nama': nama,
            'jualan': f'{nama} menjual kuih kepada jiran',
            'peringkat': 'mula',
            'mesej': [{'dari': 'klien', 'teks': f'Rahsia dagangan {nama}'}],
        }

    def test_copilot_sesi_minimum_dan_pengasingan(self):
        _, pertama = self.call('POST', '/api/akaun/daftar', {'email': 'a@kedai.my', 'password': 'kata-rahsia'})
        self.call('POST', '/api/akaun/penasihat', {'penasihat': self._profil('Kedai Amanah')}, token=pertama['token'])
        _, kedua = self.call('POST', '/api/akaun/daftar', {'email': 'b@kedai.my', 'password': 'kata-rahsia'})
        self.call('POST', '/api/akaun/penasihat', {'penasihat': self._profil('Kedai Kedua')}, token=kedua['token'])
        status, body = self.call('GET', '/api/akaun/copilot/sesi', token=pertama['token'])
        self.assertEqual(status, 200)
        self.assertEqual(set(body), {'ok', 'akaun_id', 'exp', 'penasihat'})
        self.assertEqual(body['akaun_id'], pertama['token'].split('.')[0])
        self.assertEqual(set(body['penasihat']), {'nama', 'jualan', 'peringkat'})
        self.assertEqual(body['penasihat']['nama'], 'Kedai Amanah')
        teks = json.dumps(body)
        self.assertNotIn('Kedai Kedua', teks)
        self.assertNotIn('Rahsia dagangan', teks)
        self.assertNotIn('kata-rahsia', teks)
        self.assertNotIn('password', teks.lower())
        self.assertNotIn(self.secret, teks)
        self.assertNotIn('email', body)
        self.assertNotIn('mesej', body)
        status, lain = self.call('GET', '/api/akaun/copilot/sesi', token=kedua['token'])
        self.assertEqual(status, 200)
        self.assertEqual(lain['penasihat']['nama'], 'Kedai Kedua')
        self.assertNotEqual(lain['akaun_id'], body['akaun_id'])

    def test_copilot_sesi_token_ditolak(self):
        _, akaun_baharu = self.call('POST', '/api/akaun/daftar', {'email': 'a@kedai.my', 'password': 'kata-rahsia'})
        token = akaun_baharu['token']
        status, kosong = self.call('GET', '/api/akaun/copilot/sesi')
        self.assertEqual(status, 401)
        self.assertNotIn('akaun_id', kosong)
        user_id, exp, _sig = token.split('.')
        status, _rosak = self.call('GET', '/api/akaun/copilot/sesi', token=f'{user_id}.{exp}.{"ab" * 32}')
        self.assertEqual(status, 401)
        luput = akaun.issue_token(user_id, self.secret, now=1_700_000_000 - akaun.SESSION_SECONDS - 5)
        status, _tamat = self.call('GET', '/api/akaun/copilot/sesi', token=luput)
        self.assertEqual(status, 401)
        conn = self.connect()
        conn.execute('DELETE FROM pelanggan_akaun WHERE id = ?', (user_id,))
        conn.commit()
        conn.close()
        status, padam = self.call('GET', '/api/akaun/copilot/sesi', token=token)
        self.assertEqual(status, 401)
        self.assertEqual(padam['error'], 'Sila masuk akaun dahulu.')

    def test_copilot_sesi_pangkalan_data_gagal(self):
        def gagal():
            raise OSError('pangkalan data tutup')

        status, body = akaun.dispatch(
            'GET', '/api/akaun/copilot/sesi', 'https://beshareaisolution.com', 'Bearer ada.token.diuji',
            b'', gagal, False, self.secret, '1.1.1.1', now=1_700_000_000,
        )
        self.assertEqual(status, 503)
        self.assertNotIn('token', json.dumps(body))
        self.assertNotIn(self.secret, json.dumps(body))

    def test_copilot_sesi_tanpa_profil_dan_asal_jahat(self):
        _, akaun_baharu = self.call('POST', '/api/akaun/daftar', {'email': 'a@kedai.my', 'password': 'kata-rahsia'})
        status, body = self.call('GET', '/api/akaun/copilot/sesi', token=akaun_baharu['token'])
        self.assertEqual(status, 200)
        self.assertIsNone(body['penasihat'])
        status, ditolak = self.call(
            'GET', '/api/akaun/copilot/sesi', token=akaun_baharu['token'], origin='https://contoh.evil',
        )
        self.assertEqual(status, 403)
        self.assertNotIn('akaun_id', ditolak)

