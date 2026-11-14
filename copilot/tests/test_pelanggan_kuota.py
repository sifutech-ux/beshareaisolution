import calendar
import hashlib
import hmac
import json
import os
import sqlite3
import tempfile
import threading
import time
import unittest
from pathlib import Path

import pelanggan_akaun as akaun
import pelanggan_kuota as kuota

STAMP = 1_700_000_000
KUTA = 'kuta-ujian'
AKAUN_SECRET = 'rahsia-ujian'
GILIRAN = [{'dari': 'klien', 'teks': 'Macam mana nak mula?'}]
VEKTOR_BADAN = (
    b'{"route":"copilot_nasihat","idempotency_key":"idem-copilot-0001",'
    b'"giliran":[{"dari":"klien","teks":"Macam mana nak mula?"}]}'
)
VEKTOR_TANDATANGAN = '7e287c1afa32c967f45fa149021f229dd1e1eeaed4899ce037233137365f669b'
REZAB = '/api/akaun/copilot/kuota/rezab'
HANTAR = '/api/akaun/copilot/kuota/hantar'
MUKTAMAD = '/api/akaun/copilot/kuota/muktamad'


def sign(method, path, body, masa, nonce, secret=KUTA):
    digest = hashlib.sha256(body).hexdigest()
    signed = f'{masa}.{nonce}.{method.upper()}.{path}.{digest}'.encode()
    signature = hmac.new(secret.encode(), signed, hashlib.sha256).hexdigest()
    return {'masa': str(masa), 'nonce': nonce, 'tandatangan': signature}


class KuotaCases:
    postgres = False

    def setUp(self):
        self._old_kuta = os.environ.get('COPILOT_KUTA_SECRET')
        os.environ['COPILOT_KUTA_SECRET'] = KUTA
        akaun._HITS.clear()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'akaun.db')
        self.n = 0
        self.addCleanup(self._restore_secret)

    def _restore_secret(self):
        if self._old_kuta is None:
            os.environ.pop('COPILOT_KUTA_SECRET', None)
        else:
            os.environ['COPILOT_KUTA_SECRET'] = self._old_kuta

    def connect(self):
        return sqlite3.connect(self.path, timeout=5)

    def dispatch_raw(self, method, path, raw, token='', stamp=STAMP, kuta=None, origin=None):
        header = f'Bearer {token}' if token else ''
        return akaun.dispatch(
            method, path, origin, header, raw, self.connect, self.postgres, AKAUN_SECRET, '1.1.1.1',
            now=stamp, kuta_headers=kuta,
        )

    def kuota(self, path, body, token='', stamp=STAMP, nonce=None, secret=KUTA, method='POST', sign_path=None, sign_body=None, masa=None):
        raw = json.dumps(body, separators=(',', ':')).encode() if sign_body is None else sign_body
        sent = raw if sign_body is None else json.dumps(body, separators=(',', ':')).encode()
        self.n += 1
        nonce = nonce or f'nonce-ujian-{self.n:04d}-x'
        kuta = sign(method, sign_path or path, raw, int(STAMP if masa is None else masa), nonce, secret)
        return self.dispatch_raw(method, path, sent, token, stamp, kuta)

    def q(self, query, args=()):
        conn = self.connect()
        text = query if not self.postgres else query.replace('?', '%s')
        cur = conn.cursor()
        cur.execute(text, args)
        if query.lstrip().upper().startswith('SELECT'):
            rows = cur.fetchall()
            conn.close()
            return rows
        conn.commit()
        conn.close()
        return None

    def profil(self, email, nama='Kedai Amanah'):
        status, body = self.dispatch_raw(
            'POST', '/api/akaun/daftar',
            json.dumps({'email': email, 'password': 'kata-rahsia'}).encode(),
        )
        self.assertEqual(status, 200, body)
        token = body['token']
        status, saved = self.dispatch_raw(
            'POST', '/api/akaun/penasihat',
            json.dumps({'penasihat': {
                'nama': nama,
                'jualan': 'Kuih kering untuk pejabat kecil',
                'peringkat': 'mula',
                'mesej': [{'dari': 'klien', 'teks': 'Mula jual'}],
            }}).encode(),
            token=token,
        )
        self.assertEqual(status, 200, saved)
        return token, token.split('.')[0]

    def copilot(self, token, key, giliran=None, stamp=STAMP, nonce=None, **extra):
        body = {'route': 'copilot_nasihat', 'idempotency_key': key, 'giliran': GILIRAN if giliran is None else giliran}
        body.update(extra)
        return self.kuota(REZAB, body, token=token, stamp=stamp, masa=stamp, nonce=nonce)

    def awam(self, key, nama='Kedai Awam', stamp=STAMP):
        return self.kuota(REZAB, {
            'route': 'penasihat_awam',
            'idempotency_key': key,
            'nama': nama,
            'jualan': 'Kuih kering untuk pejabat kecil',
            'peringkat': 'mula',
            'giliran': GILIRAN,
        }, stamp=stamp, masa=stamp)

    def amount(self, scope, period, kind):
        rows = self.q(
            'SELECT amount FROM copilot_usage_counter WHERE scope = ? AND period = ? AND kind = ?',
            (scope, period, kind),
        )
        return 0 if not rows else rows[0][0]

    def status_rezab(self, rezab_id):
        rows = self.q('SELECT status FROM copilot_usage_reservation WHERE id = ?', (rezab_id,))
        return rows[0][0]

    def hari(self, stamp=STAMP):
        return time.strftime('%Y-%m-%d', time.gmtime(stamp)), time.strftime('%Y-%m', time.gmtime(stamp))

    def test_dasar_awal_dan_jadual_tambahan(self):
        self.profil('a@kedai.my')
        rows = self.q(
            '''SELECT copilot_daily_account, copilot_monthly_account, copilot_daily_global,
                      copilot_inflight_account, copilot_inflight_global, public_daily, public_inflight,
                      shared_daily, shared_inflight, circuit_open
               FROM copilot_quota_policy WHERE id = ?''',
            ('utama',),
        )
        self.assertEqual(list(rows[0]), [20, 200, 100, 1, 3, 20, 2, 100, 3, 0])
        src = Path(__file__).resolve().parents[1].joinpath('account/pelanggan_kuota.py').read_text()
        self.assertIn('BEGIN IMMEDIATE', src)
        self.assertIn('compare_digest', src)
        self.assertNotIn('ALTER TABLE pelanggan_akaun', src)
        self.assertNotIn('hata_trade', src)
        self.assertNotIn('print(', src)
        pelayan = Path(__file__).resolve().parents[1].joinpath('account/akaun_pelayan.py').read_text()
        self.assertIn('X-Copilot-Masa', pelayan)
        self.assertIn('X-Copilot-Nonce', pelayan)
        self.assertIn('X-Copilot-Tandatangan', pelayan)
        self.assertIn("Access-Control-Allow-Headers', 'Content-Type, Authorization, Idempotency-Key'", pelayan)
        self.assertIn("Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS'", pelayan)

    def test_vektor_hmac_laluan_masa_dan_nonce(self):
        headers = sign('POST', REZAB, VEKTOR_BADAN, STAMP, 'nonce-ujian-000001')
        self.assertEqual(headers['tandatangan'], VEKTOR_TANDATANGAN)
        self.assertTrue(kuota.sahkan_kuta('POST', REZAB, VEKTOR_BADAN, headers, KUTA, STAMP))
        self.assertFalse(kuota.sahkan_kuta('GET', REZAB, VEKTOR_BADAN, headers, KUTA, STAMP))
        self.assertFalse(kuota.sahkan_kuta('POST', HANTAR, VEKTOR_BADAN, headers, KUTA, STAMP))
        self.assertFalse(kuota.sahkan_kuta('POST', REZAB, VEKTOR_BADAN + b' ', headers, KUTA, STAMP))
        self.assertFalse(kuota.sahkan_kuta('POST', REZAB, VEKTOR_BADAN, headers, KUTA, STAMP + 61))
        self.assertTrue(kuota.sahkan_kuta('POST', REZAB, VEKTOR_BADAN, headers, KUTA, STAMP + 60))
        self.assertFalse(kuota.sahkan_kuta('POST', REZAB, VEKTOR_BADAN, headers, AKAUN_SECRET, STAMP))
        rosak = dict(headers)
        rosak['tandatangan'] = 'ab' * 32
        self.assertFalse(kuota.sahkan_kuta('POST', REZAB, VEKTOR_BADAN, rosak, KUTA, STAMP))

        token, _user = self.profil('a@kedai.my')
        status, tolak = self.kuota(REZAB, {
            'route': 'copilot_nasihat', 'idempotency_key': 'idem-copilot-0001', 'giliran': GILIRAN,
        }, token=token, secret=AKAUN_SECRET)
        self.assertEqual(status, 401)
        self.assertEqual(tolak['ralat'], 'kuta')
        self.assertNotIn(KUTA, json.dumps(tolak))

        status, pertama = self.copilot(token, 'idem-copilot-0001', nonce='nonce-ulang-000001')
        self.assertEqual(status, 200, pertama)
        status, ulang = self.copilot(token, 'idem-copilot-0002', nonce='nonce-ulang-000001')
        self.assertEqual(status, 401)
        self.assertEqual(ulang['ralat'], 'nonce')
        self.assertEqual(self.amount(token.split('.')[0], self.hari()[0], 'copilot_day'), 1)

    def test_sempadan_harian_akaun_dan_pemadaman_nonce(self):
        token, user = self.profil('a@kedai.my')
        day, _month = self.hari()
        for n in range(20):
            status, body = self.copilot(token, f'idem-harian-{n:04d}')
            self.assertEqual(status, 200, body)
            self.assertEqual(self.kuota(HANTAR, {'rezab_id': body['rezab_id']}, token=token)[0], 200)
            self.assertEqual(self.kuota(MUKTAMAD, {'rezab_id': body['rezab_id'], 'hasil': 'provider_failed'}, token=token)[0], 200)
        status, penuh = self.copilot(token, 'idem-harian-0020', nonce='nonce-penuh-000020')
        self.assertEqual(status, 429)
        self.assertEqual(penuh['ralat'], 'had')
        self.assertEqual(self.amount(user, day, 'copilot_day'), 20)
        status, replay = self.copilot(token, 'idem-harian-0021', nonce='nonce-penuh-000020')
        self.assertEqual(status, 401)
        self.assertEqual(replay['ralat'], 'nonce')
        self.assertEqual(self.amount(user, day, 'copilot_day'), 20)
        self.assertGreaterEqual(min(row[0] for row in self.q('SELECT amount FROM copilot_usage_counter')), 0)

    def test_serentak_pada_sempadan_dalam_penerbangan(self):
        token, user = self.profil('a@kedai.my')
        results = []
        errors = []
        barrier = threading.Barrier(8)

        def worker(i):
            try:
                barrier.wait(10)
                status, _body = self.copilot(token, f'idem-terbang-{i:04d}', nonce=f'nonce-terbang-{i:04d}')
                results.append(status)
            except Exception as error:
                errors.append(repr(error))

        threads = [threading.Thread(target=worker, args=(i,)) for i in range(8)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join(15)
        self.assertEqual(errors, [])
        self.assertEqual(results.count(200), 1)
        self.assertEqual(results.count(429), 7)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 1)
        self.assertEqual(self.amount(user, self.hari()[0], 'copilot_day'), 1)

    def test_hantar_atom_dan_asing(self):
        token, user = self.profil('a@kedai.my')
        lain, _lain_id = self.profil('b@kedai.my', 'Kedai Kedua')
        status, reserved = self.copilot(token, 'idem-hantar-0001')
        self.assertEqual(status, 200)
        results = []
        barrier = threading.Barrier(2)

        def worker(i, bearer):
            barrier.wait(10)
            status_hantar, body = self.kuota(HANTAR, {'rezab_id': reserved['rezab_id']}, token=bearer, nonce=f'nonce-hantar-{i:04d}ab')
            results.append((status_hantar, body.get('ralat'), body.get('status')))

        threads = [
            threading.Thread(target=worker, args=(1, token)),
            threading.Thread(target=worker, args=(2, token)),
        ]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join(15)
        self.assertEqual(sorted(item[0] for item in results), [200, 409])
        self.assertEqual(self.status_rezab(reserved['rezab_id']), 'sending')
        status, asing = self.kuota(HANTAR, {'rezab_id': reserved['rezab_id']}, token=lain)
        self.assertEqual(status, 403)
        self.assertEqual(asing['ralat'], 'asing')
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 1)

    def test_idempotensi_cap_jari_dan_balasan_disimpan(self):
        token, user = self.profil('a@kedai.my')
        status, reserved = self.copilot(token, 'idem-capjari-0001', akaun_id='b' * 32, nama='Syarikat Palsu')
        self.assertEqual(status, 200, reserved)
        rows = self.q('SELECT owner_scope, fingerprint FROM copilot_usage_reservation WHERE id = ?', (reserved['rezab_id'],))
        self.assertEqual(rows[0][0], user)
        self.assertNotEqual(rows[0][0], 'b' * 32)
        dijangka = kuota.cap_jari(
            'copilot_nasihat', user, 'Kedai Amanah', 'Kuih kering untuk pejabat kecil', 'mula', GILIRAN,
        )
        self.assertEqual(rows[0][1], dijangka)
        status, sama = self.copilot(token, 'idem-capjari-0001', giliran=[{'dari': 'klien', 'teks': 'Macam   mana nak mula?'}])
        self.assertEqual(status, 409)
        self.assertEqual(sama['ralat'], 'menunggu')
        status, lain = self.copilot(token, 'idem-capjari-0001', giliran=[{'dari': 'klien', 'teks': 'Soalan yang lain sekali'}])
        self.assertEqual(status, 409)
        self.assertEqual(lain['ralat'], 'kunci_berbeza')
        self.assertEqual(self.amount(user, self.hari()[0], 'copilot_day'), 1)

        status, hantar = self.kuota(HANTAR, {'rezab_id': reserved['rezab_id']}, token=token)
        self.assertEqual(status, 200, hantar)
        status, akhir = self.kuota(MUKTAMAD, {
            'rezab_id': reserved['rezab_id'],
            'hasil': 'settled',
            'jawapan': 'Tulis satu ayat tawaran.',
            'langkah': ['Senaraikan sepuluh orang.', 'Mesej tiga orang.', 'Tetapkan cara bayar.'],
            'model': 'gemini-3.5-flash-lite',
            'prompt_tokens': True,
            'output_tokens': 12,
        }, token=token)
        self.assertEqual(status, 200, akhir)
        self.assertEqual(akhir['jawapan'], 'Tulis satu ayat tawaran.')
        self.assertIsNone(self.q('SELECT prompt_tokens FROM copilot_usage_reservation WHERE id = ?', (reserved['rezab_id'],))[0][0])
        self.assertEqual(self.q('SELECT output_tokens FROM copilot_usage_reservation WHERE id = ?', (reserved['rezab_id'],))[0][0], 12)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 0)
        status, ulang = self.kuota(MUKTAMAD, {
            'rezab_id': reserved['rezab_id'], 'hasil': 'settled', 'jawapan': 'Lain', 'langkah': ['a', 'b', 'c'],
        }, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(ulang.get('main_semula'), True)
        self.assertEqual(ulang['jawapan'], 'Tulis satu ayat tawaran.')
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 0)
        status, main = self.copilot(token, 'idem-capjari-0001')
        self.assertEqual(status, 200)
        self.assertEqual(main.get('main_semula'), True)
        self.assertEqual(main['langkah'][2], 'Tetapkan cara bayar.')
        self.assertEqual(self.amount(user, self.hari()[0], 'copilot_day'), 1)

    def test_pengasingan_akaun_dan_laluan(self):
        token_a, user_a = self.profil('a@kedai.my', 'Kedai Amanah')
        token_b, user_b = self.profil('b@kedai.my', 'Kedai Kedua')
        status, _a = self.copilot(token_a, 'idem-kongsi-00001')
        status_b, _b = self.copilot(token_b, 'idem-kongsi-00001')
        self.assertEqual(status, 200)
        self.assertEqual(status_b, 200)
        self.assertEqual(self.amount(user_a, self.hari()[0], 'copilot_day'), 1)
        self.assertEqual(self.amount(user_b, self.hari()[0], 'copilot_day'), 1)
        status, awam = self.awam('idem-kongsi-00001')
        self.assertEqual(status, 200, awam)
        owners = {row[0] for row in self.q('SELECT owner_scope FROM copilot_usage_reservation')}
        self.assertEqual(owners, {user_a, user_b, 'public'})

    def test_kuota_awam_dan_kongsi(self):
        token, _user = self.profil('a@kedai.my')
        lain, _lain = self.profil('b@kedai.my', 'Kedai Kedua')
        for n in range(2):
            status, body = self.awam(f'idem-awam-{n:06d}')
            self.assertEqual(status, 200, body)
        status, penuh = self.awam('idem-awam-000003')
        self.assertEqual(status, 429)
        self.assertEqual(self.amount('*', 'live', 'public_inflight'), 2)
        status, copilot = self.copilot(token, 'idem-copilot-kongsi')
        self.assertEqual(status, 200, copilot)
        self.assertEqual(self.amount('*', 'live', 'shared_inflight'), 3)
        status, lagi = self.copilot(lain, 'idem-copilot-lagi01')
        self.assertEqual(status, 429)
        self.assertEqual(self.amount('*', 'live', 'copilot_inflight'), 1)
        self.q('UPDATE copilot_quota_policy SET shared_inflight = ? WHERE id = ?', (9, 'utama'))
        ketiga, _ketiga = self.profil('c@kedai.my', 'Kedai Ketiga')
        keempat, _keempat = self.profil('d@kedai.my', 'Kedai Keempat')
        kelima, _kelima = self.profil('e@kedai.my', 'Kedai Kelima')
        self.assertEqual(self.copilot(ketiga, 'idem-copilot-ketiga')[0], 200)
        self.assertEqual(self.copilot(keempat, 'idem-copilot-keempat')[0], 200)
        self.assertEqual(self.copilot(kelima, 'idem-copilot-kelima')[0], 429)
        self.assertEqual(self.amount('*', 'live', 'copilot_inflight'), 3)
        self.q('UPDATE copilot_quota_policy SET public_daily = ?, shared_inflight = ? WHERE id = ?', (1, 3, 'utama'))
        self.q("UPDATE copilot_usage_reservation SET status = 'released'")
        self.q('DELETE FROM copilot_usage_counter')
        status, _pertama = self.awam('idem-awam-harian1')
        self.assertEqual(status, 200)
        status, _kedua = self.awam('idem-awam-harian2')
        self.assertEqual(status, 429)
        status, masih = self.copilot(token, 'idem-copilot-masih1')
        self.assertEqual(status, 200, masih)

    def test_pelepasan_ulang_dan_pemulihan_tamat(self):
        token, user = self.profil('a@kedai.my')
        day, _month = self.hari()
        status, reserved = self.copilot(token, 'idem-lepas-000001')
        self.assertEqual(status, 200)
        status, lepas = self.kuota(MUKTAMAD, {'rezab_id': reserved['rezab_id'], 'hasil': 'released'}, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(self.amount(user, day, 'copilot_day'), 0)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 0)
        status, ulang = self.kuota(MUKTAMAD, {'rezab_id': reserved['rezab_id'], 'hasil': 'released'}, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(ulang.get('main_semula'), True)
        self.assertEqual(self.amount(user, day, 'copilot_day'), 0)
        status, hidup = self.copilot(token, 'idem-lepas-000001')
        self.assertEqual(status, 200, hidup)
        self.assertEqual(hidup['rezab_id'], reserved['rezab_id'])
        self.assertEqual(self.amount(user, day, 'copilot_day'), 1)
        self.kuota(MUKTAMAD, {'rezab_id': hidup['rezab_id'], 'hasil': 'released'}, token=token)
        status, bez = self.copilot(token, 'idem-bez-lepas-0001', giliran=[{'dari': 'klien', 'teks': 'Teks lain yang sah'}])
        self.assertEqual(status, 200, bez)
        self.kuota(MUKTAMAD, {'rezab_id': bez['rezab_id'], 'hasil': 'released'}, token=token)
        status, konflik = self.copilot(token, 'idem-bez-lepas-0001')
        self.assertEqual(status, 409)
        self.assertEqual(konflik['ralat'], 'kunci_berbeza')

        status, reserved = self.copilot(token, 'idem-tamat-0000001')
        self.assertEqual(status, 200, reserved)
        self.q('UPDATE copilot_usage_counter SET amount = ? WHERE scope = ? AND period = ? AND kind = ?', (5, user, day, 'copilot_day'))
        results = []
        barrier = threading.Barrier(8)

        def worker(i):
            barrier.wait(10)
            status_i, _body = self.copilot(
                token, f'idem-pulih-{i:05d}', stamp=STAMP + 91, nonce=f'nonce-pulih-{i:05d}',
            )
            results.append(status_i)

        threads = [threading.Thread(target=worker, args=(i,)) for i in range(8)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join(15)
        self.assertEqual(self.status_rezab(reserved['rezab_id']), 'released')
        self.assertEqual(results.count(200), 1, results)
        self.assertEqual(self.amount(user, day, 'copilot_day'), 5)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 1)
        self.assertGreaterEqual(min(row[0] for row in self.q('SELECT amount FROM copilot_usage_counter')), 0)

    def test_sending_tamat_jadi_tidak_pasti_dan_unit_kekal(self):
        token, user = self.profil('a@kedai.my')
        day, _month = self.hari()
        status, reserved = self.copilot(token, 'idem-sending-0001')
        status, hantar = self.kuota(HANTAR, {'rezab_id': reserved['rezab_id']}, token=token)
        self.assertEqual(status, 200, hantar)
        status, lepas = self.kuota(MUKTAMAD, {'rezab_id': reserved['rezab_id'], 'hasil': 'released'}, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(self.status_rezab(reserved['rezab_id']), 'sending')
        status, baru = self.copilot(token, 'idem-sending-0002', stamp=STAMP + 91)
        self.assertEqual(status, 200, baru)
        self.assertEqual(self.status_rezab(reserved['rezab_id']), 'uncertain')
        self.assertEqual(self.amount(user, day, 'copilot_day'), 2)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 1)
        status, ulang = self.copilot(token, 'idem-sending-0001', stamp=STAMP + 91)
        self.assertEqual(status, 503)
        self.assertEqual(ulang['ralat'], 'tidak_pasti')
        self.assertEqual(self.amount(user, day, 'copilot_day'), 2)

    def test_gagal_pembekal_dan_tidak_pasti_sekali(self):
        token, user = self.profil('a@kedai.my')
        status, reserved = self.copilot(token, 'idem-gagal-0000001')
        self.kuota(HANTAR, {'rezab_id': reserved['rezab_id']}, token=token)
        status, gagal = self.kuota(MUKTAMAD, {'rezab_id': reserved['rezab_id'], 'hasil': 'provider_failed'}, token=token)
        self.assertEqual(status, 200, gagal)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 0)
        self.assertEqual(self.amount(user, self.hari()[0], 'copilot_day'), 1)
        status, lagi = self.kuota(MUKTAMAD, {'rezab_id': reserved['rezab_id'], 'hasil': 'provider_failed'}, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(lagi.get('main_semula'), True)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 0)
        status, reserved = self.copilot(token, 'idem-tidak-0000001')
        self.kuota(HANTAR, {'rezab_id': reserved['rezab_id']}, token=token)
        status, tidak = self.kuota(MUKTAMAD, {'rezab_id': reserved['rezab_id'], 'hasil': 'uncertain'}, token=token)
        self.assertEqual(status, 200, tidak)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), 0)
        self.assertEqual(self.amount(user, self.hari()[0], 'copilot_day'), 2)

    def test_litar_pangkalan_data_dan_bulan_utc(self):
        token, user = self.profil('a@kedai.my')
        status, reserved = self.copilot(token, 'idem-litar-0000001')
        self.kuota(HANTAR, {'rezab_id': reserved['rezab_id']}, token=token)
        self.kuota(MUKTAMAD, {
            'rezab_id': reserved['rezab_id'], 'hasil': 'settled',
            'jawapan': 'Jawapan disimpan.', 'langkah': ['Satu', 'Dua', 'Tiga'],
        }, token=token)
        self.q('UPDATE copilot_quota_policy SET circuit_open = ? WHERE id = ?', (1, 'utama'))
        status, tutup = self.copilot(token, 'idem-litar-0000002')
        self.assertEqual(status, 503)
        self.assertEqual(tutup['ralat'], 'litar')
        status, main = self.copilot(token, 'idem-litar-0000001')
        self.assertEqual(status, 200)
        self.assertEqual(main['jawapan'], 'Jawapan disimpan.')
        self.assertEqual(self.amount(user, self.hari()[0], 'copilot_day'), 1)

        def gagal():
            raise OSError('pangkalan data tutup')

        status, rosak = akaun.dispatch(
            'POST', REZAB, None, 'Bearer token', b'{}', gagal, self.postgres, AKAUN_SECRET, '1.1.1.1', now=STAMP,
        )
        self.assertEqual(status, 503)
        self.assertNotIn(KUTA, json.dumps(rosak))
        os.environ['COPILOT_KUTA_SECRET'] = ''
        status, kosong = self.copilot(token, 'idem-litar-0000003')
        self.assertEqual(status, 503)
        self.assertEqual(kosong['ralat'], 'kuota_tidak_sedia')
        os.environ['COPILOT_KUTA_SECRET'] = KUTA

        self.q('UPDATE copilot_quota_policy SET circuit_open = ?, copilot_monthly_account = ?, copilot_daily_account = ? WHERE id = ?', (0, 2, 1, 'utama'))
        self.q('DELETE FROM copilot_usage_counter')
        self.q('DELETE FROM copilot_usage_reservation')
        hari_1 = calendar.timegm((2026, 10, 12, 23, 59, 30))
        hari_2 = calendar.timegm((2026, 10, 13, 0, 0, 5))
        hari_3 = calendar.timegm((2026, 10, 14, 0, 0, 5))
        token = akaun.issue_token(user, AKAUN_SECRET, now=hari_1)
        status, pertama = self.copilot(token, 'idem-utc-hari-0001', stamp=hari_1)
        self.assertEqual(status, 200, pertama)
        self.assertEqual(self.kuota(HANTAR, {'rezab_id': pertama['rezab_id']}, token=token, stamp=hari_1, masa=hari_1)[0], 200)
        self.assertEqual(self.kuota(MUKTAMAD, {'rezab_id': pertama['rezab_id'], 'hasil': 'provider_failed'}, token=token, stamp=hari_1, masa=hari_1)[0], 200)
        self.assertEqual(self.copilot(token, 'idem-utc-hari-0001b', stamp=hari_1)[0], 429)
        status, kedua = self.copilot(token, 'idem-utc-hari-0002', stamp=hari_2)
        self.assertEqual(status, 200, kedua)
        self.assertEqual(self.kuota(HANTAR, {'rezab_id': kedua['rezab_id']}, token=token, stamp=hari_2, masa=hari_2)[0], 200)
        self.assertEqual(self.kuota(MUKTAMAD, {'rezab_id': kedua['rezab_id'], 'hasil': 'provider_failed'}, token=token, stamp=hari_2, masa=hari_2)[0], 200)
        self.assertEqual(self.copilot(token, 'idem-utc-hari-0003', stamp=hari_3)[0], 429)
        self.assertEqual(self.amount(user, '2026-10', 'copilot_month'), 2)
        tempoh = {row[0] for row in self.q("SELECT day_period FROM copilot_usage_reservation WHERE route = 'copilot_nasihat'")}
        self.assertEqual(tempoh, {'2026-10-12', '2026-10-13'})

    def test_tertib_kunci_kaunter_sama_dengan_kemasukan(self):
        owner = 'f' * 32
        day, month = '2026-10-12', '2026-10'
        jejak = []
        asal_tambah = kuota._tambah
        asal_tolak = kuota._tolak
        asal_kunci = kuota._kunci

        def rekod(jenis):
            def panggil(_conn, _postgres, scope, period, kind, *_lebih):
                jejak.append((jenis, scope, period, kind))
                return True
            return panggil

        kuota._tambah = rekod('naik')
        kuota._tolak = rekod('tolak')
        kuota._kunci = rekod('kunci')
        try:
            dasar = dict(kuota.DEFAULT_POLICY)
            self.assertTrue(kuota._naikkan(None, self.postgres, 'copilot_nasihat', owner, day, month, dasar))
            penuh = [(scope, period, kind) for scope, period, kind, _cap in kuota._tertib('copilot_nasihat', owner, day, month)]
            self.assertNotEqual(kuota._kaunter('copilot_nasihat', owner, day, month), kuota._tertib('copilot_nasihat', owner, day, month))
            self.assertEqual(jejak, [('naik', *item) for item in penuh])
            jejak.clear()
            kuota._turunkan(None, self.postgres, 'copilot_nasihat', owner, day, month)
            self.assertEqual(jejak, [('tolak', *item) for item in penuh])
            jejak.clear()
            kuota._turunkan(None, self.postgres, 'copilot_nasihat', owner, day, month, inflight_sahaja=True)
            dijangka = []
            for scope, period, kind in penuh:
                dijangka.append(('tolak' if kind.endswith('inflight') else 'kunci', scope, period, kind))
            self.assertEqual(jejak, dijangka)
            jejak.clear()
            kuota._turunkan(None, self.postgres, 'penasihat_awam', 'public', day, month)
            awam = [(scope, period, kind) for scope, period, kind, _cap in kuota._tertib('penasihat_awam', 'public', day, month)]
            self.assertEqual(jejak, [('tolak', *item) for item in awam])
            self.assertEqual(awam, sorted(awam))
        finally:
            kuota._tambah = asal_tambah
            kuota._tolak = asal_tolak
            kuota._kunci = asal_kunci

    def test_naik_turun_serentak_kekal_sekali(self):
        token, user = self.profil('kunci@kedai.my')
        day, month = self.hari()
        self.q(
            '''UPDATE copilot_quota_policy SET copilot_daily_account = ?, copilot_monthly_account = ?,
                      copilot_daily_global = ?, copilot_inflight_account = ?, copilot_inflight_global = ?,
                      shared_daily = ?, shared_inflight = ? WHERE id = ?''',
            (50, 50, 50, 50, 50, 50, 50, 'utama'),
        )
        reserved = []
        sending = []
        for i in range(4):
            status, body = self.copilot(token, f'idem-kunci-rezab-{i:04d}')
            self.assertEqual(status, 200, body)
            reserved.append(body['rezab_id'])
            status, body = self.copilot(token, f'idem-kunci-hantar-{i:04d}')
            self.assertEqual(status, 200, body)
            self.assertEqual(self.kuota(HANTAR, {'rezab_id': body['rezab_id']}, token=token)[0], 200)
            sending.append(body['rezab_id'])
        self.q(
            'UPDATE copilot_usage_reservation SET expires_at = ? WHERE id = ?',
            (kuota._iso(STAMP - 10), reserved[0]),
        )
        self.q(
            'UPDATE copilot_usage_reservation SET expires_at = ? WHERE id = ?',
            (kuota._iso(STAMP - 10), sending[0]),
        )
        results = []
        errors = []
        barrier = threading.Barrier(8)

        def simpan(status, body):
            results.append((status, body.get('ralat')))

        def kerja(fn):
            try:
                barrier.wait(10)
                fn()
            except Exception as error:
                errors.append(repr(error))

        threads = [
            threading.Thread(target=kerja, args=(lambda i=i: simpan(*self.copilot(
                token, f'idem-kunci-baru-{i:04d}', nonce=f'nonce-kunci-baru-{i:04d}',
            )),))
            for i in range(3)
        ]
        threads.append(threading.Thread(target=kerja, args=(lambda: simpan(*self.kuota(
            MUKTAMAD, {'rezab_id': reserved[1], 'hasil': 'released'}, token=token, nonce='nonce-kunci-lepas-0001',
        )),)))
        threads.append(threading.Thread(target=kerja, args=(lambda: simpan(*self.kuota(
            MUKTAMAD, {'rezab_id': sending[1], 'hasil': 'provider_failed'}, token=token, nonce='nonce-kunci-gagal-0001',
        )),)))
        threads.append(threading.Thread(target=kerja, args=(lambda: simpan(*self.kuota(
            MUKTAMAD, {'rezab_id': sending[2], 'hasil': 'uncertain'}, token=token, nonce='nonce-kunci-tidak-0001',
        )),)))
        threads.append(threading.Thread(target=kerja, args=(lambda: simpan(*self.copilot(
            token, 'idem-kunci-pulih-0001', nonce='nonce-kunci-pulih-0001',
        )),)))
        threads.append(threading.Thread(target=kerja, args=(lambda: simpan(*self.copilot(
            token, 'idem-kunci-pulih-0002', nonce='nonce-kunci-pulih-0002',
        )),)))
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join(20)
        self.assertEqual(errors, [])
        self.assertEqual([item for item in results if item[0] == 503], [])
        statuses = [
            row[0] for row in self.q(
                "SELECT status FROM copilot_usage_reservation WHERE owner_scope = ? AND route = 'copilot_nasihat'",
                (user,),
            )
        ]
        consumed = sum(status != 'released' for status in statuses)
        held = sum(status in {'reserved', 'sending'} for status in statuses)
        self.assertEqual(self.amount(user, day, 'copilot_day'), consumed)
        self.assertEqual(self.amount(user, month, 'copilot_month'), consumed)
        self.assertEqual(self.amount(user, 'live', 'copilot_inflight'), held)
        self.assertEqual(self.amount('*', day, 'copilot_day'), consumed)
        self.assertEqual(self.amount('*', day, 'shared_day'), consumed)
        self.assertEqual(self.amount('*', 'live', 'copilot_inflight'), held)
        self.assertEqual(self.amount('*', 'live', 'shared_inflight'), held)
        self.assertGreaterEqual(min(row[0] for row in self.q('SELECT amount FROM copilot_usage_counter')), 0)
        self.assertEqual(self.status_rezab(reserved[0]), 'released')
        self.assertEqual(self.status_rezab(sending[0]), 'uncertain')


class SqliteKuotaTests(KuotaCases, unittest.TestCase):
    postgres = False


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL tidak diuji dalam larian ini')
class PostgresKuotaTests(KuotaCases, unittest.TestCase):
    postgres = True

    def connect(self):
        import psycopg2
        url = os.environ.get('COPILOT_TEST_DATABASE_URL', '')
        if 'copilot_kuota_test' not in url or not any(host in url for host in ('127.0.0.1', 'localhost')):
            raise RuntimeError('sambungan ujian postgres ditolak')
        return psycopg2.connect(url)

    def setUp(self):
        super().setUp()
        conn = self.connect()
        cur = conn.cursor()
        for name in (
            'copilot_service_nonce', 'copilot_usage_reservation', 'copilot_usage_counter',
            'copilot_quota_policy', 'pelanggan_akaun',
        ):
            cur.execute(f'DROP TABLE IF EXISTS {name}')
        conn.commit()
        conn.close()


if __name__ == '__main__':
    unittest.main()

