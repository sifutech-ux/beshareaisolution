import json
import os
import sqlite3
import tempfile
import threading
import unittest
from datetime import timedelta
from pathlib import Path

import pelanggan_akaun as akaun
import pelanggan_meja as meja

STAMP = 1_700_000_000
ISNIN = '2023-11-13'
HARI = '2023-11-15'
KUNCI = 'import-ujian-0001'
PROFIL = {
    'nama': 'Kedai Siti',
    'jualan': 'Kuih raya kepada jiran taman',
    'peringkat': 'jual',
    'mesej': [{'dari': 'penasihat', 'teks': 'Baik.', 'langkah': ['Satu', 'Dua', 'Tiga']}],
}


class MejaCases:
    postgres = False

    def setUp(self):
        akaun._HITS.clear()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = str(Path(self.tmp.name) / 'akaun.db')
        self.secret = 'rahsia-ujian'
        self.hari = meja.hari_ini(STAMP).isoformat()

    def connect(self):
        return sqlite3.connect(self.path)

    def call(self, method, path, body=None, token='', query=None, kunci=None, origin='https://beshareaisolution.com'):
        raw = b'' if body is None else json.dumps(body).encode()
        header = f'Bearer {token}' if token else ''
        return akaun.dispatch(
            method, path, origin, header, raw, self.connect, self.postgres, self.secret, '1.1.1.1',
            now=STAMP, query=query, idempotency_key=kunci,
        )

    def daftar(self, email='siti@kedai.my'):
        status, body = self.call('POST', '/api/akaun/daftar', {'email': email, 'password': 'kata-rahsia'})
        self.assertEqual(status, 200, body)
        return body

    def profil(self, token, nama='Kedai Siti'):
        profil = dict(PROFIL)
        profil['nama'] = nama
        profil['mesej'] = [{'dari': 'penasihat', 'teks': f'Rahsia {nama}'}]
        status, body = self.call('POST', '/api/akaun/penasihat', {'penasihat': profil}, token=token)
        self.assertEqual(status, 200, body)
        return body

    def kira(self, jadual, akaun_id=None):
        conn = self.connect()
        if akaun_id:
            cur = conn.execute(f'SELECT COUNT(*) FROM {jadual} WHERE akaun_id = ?', (akaun_id,))
        else:
            cur = conn.execute(f'SELECT COUNT(*) FROM {jadual}')
        nombor = cur.fetchone()[0]
        conn.close()
        return nombor

    def test_migrasi_idempoten_mengekalkan_mesej_dan_jadual_lain(self):
        conn = self.connect()
        conn.execute(
            '''CREATE TABLE pelanggan_akaun (
                id TEXT PRIMARY KEY, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
                nama_perniagaan TEXT NOT NULL DEFAULT '', jualan TEXT NOT NULL DEFAULT '',
                peringkat TEXT NOT NULL DEFAULT '', mesej TEXT NOT NULL DEFAULT '[]',
                created_at TEXT NOT NULL)'''
        )
        mesej = '[{"dari":"penasihat","teks":"Jangan tulis semula"}]'
        conn.execute(
            'INSERT INTO pelanggan_akaun VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ('akaun-lama', 'lama@kedai.my', 'scrypt$hash', 'Lama', 'Kuih untuk jiran taman', 'jual', mesej, '2020-01-01T00:00:00Z'),
        )
        conn.execute('CREATE TABLE portfolio_records (id INTEGER PRIMARY KEY, nota TEXT)')
        conn.execute('INSERT INTO portfolio_records (nota) VALUES (?)', ('jangan sentuh',))
        conn.commit()
        conn.close()
        self.daftar('baharu@kedai.my')
        self.daftar('kedua@kedai.my')
        conn = self.connect()
        lajur = [row[1] for row in conn.execute('PRAGMA table_info(pelanggan_akaun)')]
        self.assertEqual(lajur.count('matlamat'), 1)
        self.assertEqual(lajur.count('matlamat_versi'), 1)
        self.assertEqual(lajur.count('mesej_versi'), 1)
        tersimpan = conn.execute('SELECT mesej FROM pelanggan_akaun WHERE id = ?', ('akaun-lama',)).fetchone()[0]
        self.assertEqual(tersimpan, mesej)
        self.assertEqual(conn.execute('SELECT nota FROM portfolio_records').fetchone()[0], 'jangan sentuh')
        conn.close()
        src = Path(__file__).resolve().parents[1].joinpath('account/pelanggan_meja.py').read_text()
        self.assertNotIn('hata_trade', src)
        self.assertNotIn('portfolio_records', src)
        akar = Path(__file__).resolve().parents[1] / 'account'
        pelayan = (akar / 'akaun_pelayan.py').read_text()
        self.assertIn("Access-Control-Allow-Headers', 'Content-Type, Authorization, Idempotency-Key'", pelayan)
        self.assertIn('def do_PUT(', pelayan)
        self.assertIn('def do_PATCH(', pelayan)
        self.assertIn('def do_DELETE(', pelayan)

    def test_saya_akaun_id_dan_versi_pilihan(self):
        akaun_baharu = self.daftar()
        token = akaun_baharu['token']
        status, saya = self.call('GET', '/api/akaun/saya', token=token)
        self.assertEqual(status, 200)
        self.assertEqual(saya['akaun_id'], token.split('.')[0])
        self.assertEqual(saya['mesej_versi'], 1)
        self.assertIsNone(saya['penasihat'])
        self.assertEqual(saya['email'], 'siti@kedai.my')
        self.assertTrue({'ok', 'email', 'penasihat'} <= set(saya))
        pertama = self.profil(token)
        self.assertEqual(pertama['mesej_versi'], 2)
        status, konflik = self.call('POST', '/api/akaun/penasihat', {
            'versi': 1,
            'penasihat': dict(PROFIL, mesej=[{'dari': 'klien', 'teks': 'Salinan lama'}]),
        }, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(konflik['ralat'], 'konflik')
        self.assertEqual(konflik['penasihat']['mesej'][0]['teks'], 'Rahsia Kedai Siti')
        status, segar = self.call('GET', '/api/akaun/saya', token=token)
        self.assertEqual(segar['penasihat']['mesej'][0]['teks'], 'Rahsia Kedai Siti')
        status, baharu = self.call('POST', '/api/akaun/penasihat', {
            'versi': segar['mesej_versi'],
            'penasihat': PROFIL,
        }, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(baharu['mesej_versi'], segar['mesej_versi'] + 1)

    def test_matlamat_dan_konflik(self):
        token = self.daftar()['token']
        status, kosong = self.call('GET', '/api/akaun/matlamat', token=token)
        self.assertEqual(kosong, {'ok': True, 'matlamat': None, 'versi': 0})
        status, pendek = self.call('PUT', '/api/akaun/matlamat', {'matlamat': 'A', 'versi': 0}, token=token)
        self.assertEqual(status, 400)
        status, tulis = self.call('PUT', '/api/akaun/matlamat', {
            'matlamat': 'Hantar 20 kotak setiap minggu', 'versi': 0, 'akaun_id': 'bukan-saya',
        }, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(tulis['versi'], 1)
        self.assertNotIn(self.secret, json.dumps(tulis))
        status, lapuk = self.call('PUT', '/api/akaun/matlamat', {
            'matlamat': 'Sasaran lain yang cukup panjang', 'versi': 0,
        }, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(lapuk['matlamat'], 'Hantar 20 kotak setiap minggu')
        status, padam = self.call('PUT', '/api/akaun/matlamat', {'matlamat': '  ', 'versi': 1}, token=token)
        self.assertEqual(status, 200)
        self.assertIsNone(padam['matlamat'])
        hasil = []

        def tulis(teks):
            status_tulis, badan = self.call('PUT', '/api/akaun/matlamat', {'matlamat': teks, 'versi': 2}, token=token)
            hasil.append((status_tulis, badan.get('ralat')))

        benang = [
            threading.Thread(target=tulis, args=('Matlamat serentak yang pertama',)),
            threading.Thread(target=tulis, args=('Matlamat serentak yang kedua',)),
        ]
        for item in benang:
            item.start()
        for item in benang:
            item.join()
        self.assertEqual(sorted(item[0] for item in hasil), [200, 409])
        self.assertEqual(self.call('GET', '/api/akaun/matlamat', token=token)[1]['versi'], 3)

    def test_pelan_isnin_had_dan_pendua(self):
        token = self.daftar()['token']
        akaun_id = token.split('.')[0]
        status, _salah = self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': '2023-11-14'})
        self.assertEqual(status, 400)
        status, kosong = self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': ISNIN})
        self.assertEqual(kosong['pelan'], None)
        self.assertEqual(kosong['versi'], 0)
        status, pertama = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 0, 'teks': 'Kemaskan harga',
        }, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(pertama['pelan']['versi'], 1)
        self.assertEqual(len(pertama['pelan']['tugasan']), 1)
        status, ulang = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 1, 'teks': '  kemaskan   harga ',
        }, token=token)
        self.assertEqual(status, 200)
        self.assertTrue(ulang['sudah_ada'])
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 1)
        self.assertEqual(ulang['pelan']['versi'], 1)
        versi = 1
        for nombor in range(29):
            status, body = self.call('POST', '/api/akaun/pelan/tugasan', {
                'minggu_mula': ISNIN, 'versi': versi, 'teks': f'Tugasan nombor {nombor + 2}',
            }, token=token)
            self.assertEqual(status, 200, body)
            versi = body['pelan']['versi']
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 30)
        status, penuh = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': versi, 'teks': 'Tugasan lebihan',
        }, token=token)
        self.assertEqual(status, 400)
        self.assertEqual(penuh['ralat'], 'had_tugas')
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 30)

    def test_tugas_konflik_dan_padam(self):
        token = self.daftar()['token']
        _, pelan = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 0, 'teks': 'Hubungi pelanggan',
        }, token=token)
        tugas_id = pelan['pelan']['tugasan'][0]['id']
        status, siap = self.call('PATCH', f'/api/akaun/pelan/tugasan/{tugas_id}', {
            'versi': 1, 'siap': True,
        }, token=token)
        self.assertEqual(status, 200)
        self.assertTrue(siap['pelan']['tugasan'][0]['siap'])
        status, lapuk = self.call('DELETE', f'/api/akaun/pelan/tugasan/{tugas_id}', {'versi': 1}, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(len(lapuk['pelan']['tugasan']), 1)
        status, padam = self.call('DELETE', f'/api/akaun/pelan/tugasan/{tugas_id}', {'versi': 2}, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(padam['pelan']['tugasan'], [])
        self.assertEqual(padam['pelan']['versi'], 3)

    def test_pelan_versi_konsisten(self):
        token = self.daftar()['token']
        akaun_id = token.split('.')[0]
        status, kosong = self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': ISNIN})
        self.assertEqual((status, kosong['pelan'], kosong['versi']), (200, None, 0))
        status, cipta = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 0, 'teks': 'Tulis harga',
        }, token=token)
        self.assertEqual(cipta['pelan']['versi'], 1)
        self.assertEqual(self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': ISNIN})[1]['versi'], 1)
        status, lapuk = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 0, 'teks': 'Jangan tambah',
        }, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 1)
        self.assertEqual(self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': ISNIN})[1]['versi'], 1)
        tugas_id = cipta['pelan']['tugasan'][0]['id']
        status, pendua = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 1, 'teks': 'tulis harga',
        }, token=token)
        self.assertTrue(pendua['sudah_ada'])
        self.assertEqual(pendua['pelan']['versi'], 1)
        status, siap = self.call('PATCH', f'/api/akaun/pelan/tugasan/{tugas_id}', {'versi': 1, 'siap': True}, token=token)
        self.assertEqual(siap['pelan']['versi'], 2)
        status, lapuk_siap = self.call('PATCH', f'/api/akaun/pelan/tugasan/{tugas_id}', {'versi': 1, 'siap': False}, token=token)
        self.assertEqual(status, 409)
        self.assertTrue(self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': ISNIN})[1]['pelan']['tugasan'][0]['siap'])
        status, lapuk_padam = self.call('DELETE', f'/api/akaun/pelan/tugasan/{tugas_id}', {'versi': 1}, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 1)
        status, import_sama = self.call('POST', '/api/akaun/import', {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': ISNIN, 'versi': 2,
            'tugasan': [{'teks': 'Tulis harga', 'siap': True}],
        }, token=token, kunci='import-versi-00001')
        self.assertEqual(status, 200)
        self.assertTrue(import_sama['sudah_ada'])
        self.assertEqual(import_sama['pelan']['versi'], 2)
        status, import_baru = self.call('POST', '/api/akaun/import', {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': ISNIN, 'versi': 2,
            'tindakan': 'tambah_baharu',
            'tugasan': [{'teks': 'Tulis harga', 'siap': True}, {'teks': 'Hantar sampel', 'siap': False}],
        }, token=token, kunci='import-versi-00002')
        self.assertEqual(status, 200, import_baru)
        self.assertEqual(import_baru['pelan']['versi'], 3)
        self.assertEqual(len(import_baru['pelan']['tugasan']), 2)
        minggu_baru = '2023-11-20'
        status, import_awal = self.call('POST', '/api/akaun/import', {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': minggu_baru, 'versi': 0,
            'tugasan': [{'teks': 'Minggu baharu', 'siap': False}],
        }, token=token, kunci='import-versi-00003')
        self.assertEqual(import_awal['pelan']['versi'], 1)
        self.assertEqual(self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': minggu_baru})[1]['versi'], 1)

    def test_asing_semua_laluan(self):
        pertama = self.daftar('a@kedai.my')
        kedua = self.daftar('b@kedai.my')
        token_a = pertama['token']
        token_b = kedua['token']
        self.call('PUT', '/api/akaun/matlamat', {'matlamat': 'Matlamat kedai A cukup', 'versi': 0}, token=token_a)
        _, pelan = self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 0, 'teks': 'Rahsia tugasan A',
        }, token=token_a)
        tugas_id = pelan['pelan']['id'] and pelan['pelan']['tugasan'][0]['id']
        _, angka = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'hasil', 'nilai': '10.00', 'unit': 'MYR',
        }, token=token_a)
        rekod_id = angka['rekod']['id']
        self.call('POST', '/api/akaun/import', {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': '2023-11-20', 'versi': 0,
            'tugasan': [{'teks': 'Import rahsia A', 'siap': False}],
        }, token=token_a, kunci=KUNCI)
        status, matlamat_b = self.call('GET', '/api/akaun/matlamat', token=token_b)
        self.assertIsNone(matlamat_b['matlamat'])
        self.call('PUT', '/api/akaun/matlamat', {
            'matlamat': 'Matlamat kedai B cukup', 'versi': 0, 'akaun_id': token_a.split('.')[0],
        }, token=token_b)
        status, matlamat_a = self.call('GET', '/api/akaun/matlamat', token=token_a)
        self.assertEqual(matlamat_a['matlamat'], 'Matlamat kedai A cukup')
        status, pelan_b = self.call('GET', '/api/akaun/pelan', token=token_b, query={'minggu': ISNIN})
        self.assertIsNone(pelan_b['pelan'])
        status, _patch = self.call('PATCH', f'/api/akaun/pelan/tugasan/{tugas_id}', {'versi': 1, 'siap': True}, token=token_b)
        self.assertEqual(status, 404)
        status, _padam = self.call('DELETE', f'/api/akaun/pelan/tugasan/{tugas_id}', {'versi': 1}, token=token_b)
        self.assertEqual(status, 404)
        status, senarai = self.call('GET', '/api/akaun/angka', token=token_b)
        self.assertEqual(senarai['rekod'], [])
        status, _angka = self.call('PATCH', f'/api/akaun/angka/{rekod_id}', {
            'versi': 1, 'nilai': '99.00', 'jenis': 'hasil', 'unit': 'MYR', 'tarikh': HARI,
        }, token=token_b)
        self.assertEqual(status, 404)
        status, _tarik = self.call('DELETE', f'/api/akaun/angka/{rekod_id}', {'versi': 1}, token=token_b)
        self.assertEqual(status, 404)
        status, import_b = self.call('GET', '/api/akaun/pelan', token=token_b, query={'minggu': '2023-11-20'})
        self.assertIsNone(import_b['pelan'])
        masih = self.call('GET', '/api/akaun/pelan', token=token_a, query={'minggu': ISNIN})[1]
        self.assertEqual(masih['pelan']['tugasan'][0]['teks'], 'Rahsia tugasan A')
        self.assertFalse(masih['pelan']['tugasan'][0]['siap'])

    def test_import_persetujuan_ulang_dan_rollback(self):
        token = self.daftar()['token']
        akaun_id = token.split('.')[0]
        status, _tiada = self.call('POST', '/api/akaun/import', {
            'persetujuan': False, 'jenis': 'pelan', 'minggu_mula': ISNIN, 'tugasan': [{'teks': 'Jangan simpan', 'siap': False}],
        }, token=token, kunci=KUNCI)
        self.assertEqual(status, 400)
        self.assertEqual(self.kira('pelanggan_tugas'), 0)
        self.assertEqual(self.kira('pelanggan_import'), 0)
        badan = {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': ISNIN, 'versi': 0,
            'tugasan': [{'teks': 'Kemaskan harga', 'siap': False}],
        }
        status, pertama = self.call('POST', '/api/akaun/import', badan, token=token, kunci=KUNCI)
        self.assertEqual(status, 200, pertama)
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 1)
        status, ulang = self.call('POST', '/api/akaun/import', badan, token=token, kunci=KUNCI)
        self.assertEqual(status, 200)
        self.assertEqual(ulang, pertama)
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 1)
        lain = dict(badan, tugasan=[{'teks': 'Tugasan berbeza', 'siap': False}])
        status, konflik = self.call('POST', '/api/akaun/import', lain, token=token, kunci=KUNCI)
        self.assertEqual(status, 409)
        self.assertEqual(konflik['ralat'], 'kunci_berbeza')
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 1)
        for nombor in range(28):
            versi = self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': ISNIN})[1]['pelan']['versi']
            self.call('POST', '/api/akaun/pelan/tugasan', {
                'minggu_mula': ISNIN, 'versi': versi, 'teks': f'Tambahan {nombor}',
            }, token=token)
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 29)
        status, had = self.call('POST', '/api/akaun/import', {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': ISNIN,
            'versi': self.call('GET', '/api/akaun/pelan', token=token, query={'minggu': ISNIN})[1]['pelan']['versi'],
            'tindakan': 'tambah_baharu',
            'tugasan': [{'teks': 'Lebih satu', 'siap': False}, {'teks': 'Lebih dua', 'siap': True}],
        }, token=token, kunci='import-ujian-0002')
        self.assertEqual(status, 400)
        self.assertEqual(had['ralat'], 'had_tugas')
        self.assertEqual(self.kira('pelanggan_tugas', akaun_id), 29)
        self.assertEqual(self.kira('pelanggan_import', akaun_id), 1)

    def test_import_konflik_pelan(self):
        token = self.daftar()['token']
        self.call('POST', '/api/akaun/pelan/tugasan', {
            'minggu_mula': ISNIN, 'versi': 0, 'teks': 'Tugasan pelayan',
        }, token=token)
        status, konflik = self.call('POST', '/api/akaun/import', {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': ISNIN, 'versi': 1,
            'tugasan': [{'teks': 'Draf telefon', 'siap': False}],
        }, token=token, kunci=KUNCI)
        self.assertEqual(status, 409)
        self.assertEqual(konflik['ralat'], 'konflik')
        self.assertIn('Tugasan pelayan', konflik['pelayan'])
        self.assertIn('Draf telefon', konflik['draf'])
        self.assertEqual(self.kira('pelanggan_import'), 0)
        status, tambah = self.call('POST', '/api/akaun/import', {
            'persetujuan': True, 'jenis': 'pelan', 'minggu_mula': ISNIN, 'versi': 1,
            'tindakan': 'tambah_baharu',
            'tugasan': [{'teks': 'Draf telefon', 'siap': False}],
        }, token=token, kunci=KUNCI)
        self.assertEqual(status, 200, tambah)
        teks = [item['teks'] for item in tambah['pelan']['tugasan']]
        self.assertEqual(teks, ['Tugasan pelayan', 'Draf telefon'])

    def test_angka_pengesahan_dan_label(self):
        token = self.daftar()['token']
        sah_wang = ('0', '0.5', '0.50', '10', '120.5', '120.50', '9999999999.99')
        for nilai in sah_wang:
            status, badan = self.call('POST', '/api/akaun/angka', {
                'tarikh': HARI, 'jenis': 'kos', 'nilai': nilai, 'unit': 'MYR', 'sahkan_pendua': True,
            }, token=token)
            self.assertEqual(status, 200, nilai)
            self.assertRegex(badan['rekod']['nilai'], r'^(?:0|[1-9]\d{0,9})\.\d{2}$')
        for nilai in ('01.00', '-1.00', '1.234', '1e2', '', '10.', '.50', '10000000000.00', 120.5):
            status, _rosak = self.call('POST', '/api/akaun/angka', {
                'tarikh': HARI, 'jenis': 'hasil', 'nilai': nilai, 'unit': 'MYR',
            }, token=token)
            self.assertEqual(status, 400, nilai)
        for jenis, unit in (('hasil', 'MYR'), ('kos', 'MYR'), ('lain', 'MYR'), ('lain', 'unit')):
            nilai = '4' if unit == 'unit' else '4.00'
            status, _pasangan = self.call('POST', '/api/akaun/angka', {
                'tarikh': HARI, 'jenis': jenis, 'nilai': nilai, 'unit': unit,
            }, token=token)
            self.assertEqual(status, 200, (jenis, unit))
        for jenis, unit in (('hasil', 'unit'), ('kos', 'unit'), ('hasil', 'rm'), ('lain', 'kg')):
            status, pasangan = self.call('POST', '/api/akaun/angka', {
                'tarikh': HARI, 'jenis': jenis, 'nilai': '4', 'unit': unit,
            }, token=token)
            self.assertEqual(status, 400, (jenis, unit))
            self.assertIn(pasangan['ralat'], {'unit_tidak_sah', 'bentuk'})
        status, _teruk = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'hasil', 'nilai': 'unit', 'unit': 'unit',
        }, token=token)
        self.assertEqual(status, 400)
        self.assertEqual(_teruk['ralat'], 'unit_tidak_sah')
        status, _apung = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'hasil', 'nilai': 120.5, 'unit': 'MYR',
        }, token=token)
        self.assertEqual(status, 400)
        esok = (meja.hari_ini(STAMP) + timedelta(days=1)).isoformat()
        status, _esok = self.call('POST', '/api/akaun/angka', {
            'tarikh': esok, 'jenis': 'hasil', 'nilai': '10.00', 'unit': 'MYR',
        }, token=token)
        self.assertEqual(status, 400)
        status, hasil = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'hasil', 'nilai': '120.5', 'unit': 'MYR',
        }, token=token)
        self.assertEqual(status, 200, hasil)
        self.assertEqual(hasil['rekod']['nilai'], '120.50')
        self.assertEqual(hasil['rekod']['status'], 'dimasukkan_pengguna')
        self.assertEqual(hasil['rekod']['sumber'], 'manual')
        self.assertTrue(hasil['bukan_rekod_kewangan'])
        self.assertIn('Bukan rekod kewangan', hasil['label'])
        status, pendua = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'hasil', 'nilai': '120.50', 'unit': 'MYR',
        }, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(pendua['ralat'], 'pendua')
        self.assertTrue(pendua['bukan_rekod_kewangan'])
        status, sah = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'hasil', 'nilai': '120.50', 'unit': 'MYR', 'sahkan_pendua': True,
        }, token=token)
        self.assertEqual(status, 200)
        self.assertNotEqual(sah['rekod']['id'], hasil['rekod']['id'])
        self.assertEqual(sah['rekod']['versi'], 1)
        status, kemas = self.call('PATCH', f"/api/akaun/angka/{hasil['rekod']['id']}", {
            'versi': 1, 'tarikh': HARI, 'jenis': 'hasil', 'nilai': '88.00', 'unit': 'MYR',
        }, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(kemas['rekod']['versi'], 2)
        self.assertEqual(kemas['rekod']['nilai'], '88.00')
        status, lapuk_angka = self.call('PATCH', f"/api/akaun/angka/{hasil['rekod']['id']}", {
            'versi': 1, 'tarikh': HARI, 'jenis': 'hasil', 'nilai': '1.00', 'unit': 'MYR',
        }, token=token)
        self.assertEqual(status, 409)
        self.assertEqual(lapuk_angka['ralat'], 'konflik')
        masih = self.call('GET', '/api/akaun/angka', token=token, query={'dari': HARI, 'hingga': HARI})[1]
        self.assertTrue(any(item['id'] == hasil['rekod']['id'] and item['nilai'] == '88.00' for item in masih['rekod']))
        status, kiraan = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'lain', 'nilai': '3', 'unit': 'unit',
        }, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(kiraan['rekod']['nilai'], '3')
        for rekod_id, versi in ((hasil['rekod']['id'], 2), (sah['rekod']['id'], 1)):
            status, tarik = self.call('DELETE', f'/api/akaun/angka/{rekod_id}', {'versi': versi}, token=token)
            self.assertEqual(status, 200, tarik)
            self.assertEqual(tarik['rekod']['status'], 'ditarik_pengguna')
        status, senarai = self.call('GET', '/api/akaun/angka', token=token, query={'dari': HARI, 'hingga': HARI})
        self.assertTrue(senarai['bukan_rekod_kewangan'])
        self.assertFalse(any(item['id'] in {hasil['rekod']['id'], sah['rekod']['id']} for item in senarai['rekod']))
        self.assertTrue(all(item['status'] == 'dimasukkan_pengguna' for item in senarai['rekod']))
        conn = self.connect()
        cur = conn.cursor()
        tanda = '%s' if self.postgres else '?'
        cur.execute(f'SELECT status FROM pelanggan_angka WHERE id = {tanda}', (hasil['rekod']['id'],))
        self.assertEqual(cur.fetchone()[0], 'ditarik_pengguna')
        conn.close()
        status, ganti = self.call('POST', '/api/akaun/angka', {
            'tarikh': HARI, 'jenis': 'hasil', 'nilai': '120.50', 'unit': 'MYR',
        }, token=token)
        self.assertEqual(status, 200, ganti)


class SqliteMejaTests(MejaCases, unittest.TestCase):
    postgres = False


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL tidak diuji dalam larian ini')
class PostgresMejaTests(MejaCases, unittest.TestCase):
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
            'pelanggan_import', 'pelanggan_angka', 'pelanggan_tugas', 'pelanggan_pelan',
            'copilot_service_nonce', 'copilot_usage_reservation', 'copilot_usage_counter',
            'copilot_quota_policy', 'pelanggan_akaun',
        ):
            cur.execute(f'DROP TABLE IF EXISTS {name}')
        conn.commit()
        conn.close()

    def kira(self, jadual, akaun_id=None):
        conn = self.connect()
        cur = conn.cursor()
        if akaun_id:
            cur.execute(f'SELECT COUNT(*) FROM {jadual} WHERE akaun_id = %s', (akaun_id,))
        else:
            cur.execute(f'SELECT COUNT(*) FROM {jadual}')
        nombor = cur.fetchone()[0]
        conn.close()
        return nombor

    def test_migrasi_idempoten_mengekalkan_mesej_dan_jadual_lain(self):
        self.daftar('baharu@kedai.my')
        self.daftar('kedua@kedai.my')
        conn = self.connect()
        cur = conn.cursor()
        cur.execute(
            '''SELECT column_name FROM information_schema.columns
               WHERE table_name = 'pelanggan_akaun' AND column_name IN ('matlamat', 'matlamat_versi', 'mesej_versi')'''
        )
        self.assertEqual(len(cur.fetchall()), 3)
        cur.execute('SELECT 1 FROM information_schema.tables WHERE table_name = %s', ('pelanggan_pelan',))
        self.assertIsNotNone(cur.fetchone())
        conn.close()


if __name__ == '__main__':
    unittest.main()

