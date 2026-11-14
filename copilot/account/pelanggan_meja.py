"""Matlamat, pelan, angka dan import draf. Akaun pelanggan sahaja. Bukan meja dagangan."""
import hashlib
import json
import re
import secrets
import time
from datetime import date, datetime, timedelta, timezone

KL = timezone(timedelta(hours=8))
LABEL_ANGKA = 'Dimasukkan sendiri. Bukan rekod kewangan yang disahkan.'
KEY_RE = re.compile(r'^[A-Za-z0-9_-]{16,64}$')
DATE_RE = re.compile(r'^\d{4}-\d{2}-\d{2}$')
MONEY_RE = re.compile(r'^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$')
COUNT_RE = re.compile(r'^(?:0|[1-9]\d{0,9})$')
TUGAS_LALUAN = re.compile(r'^/api/akaun/pelan/tugasan/([a-f0-9]{32})$')
ANGKA_LALUAN = re.compile(r'^/api/akaun/angka/([a-f0-9]{32})$')
LAJUR_AKAUN = (
    ('matlamat', 'TEXT'),
    ('matlamat_versi', 'INTEGER'),
    ('mesej_versi', 'INTEGER'),
)


class Tolak(Exception):
    def __init__(self, status, body):
        self.status = status
        self.body = body


def ensure_meja_schema(conn, postgres):
    for nama, jenis in LAJUR_AKAUN:
        if not _ada_lajur(conn, postgres, nama):
            conn.cursor().execute(f'ALTER TABLE pelanggan_akaun ADD COLUMN {nama} {jenis}')
    cur = conn.cursor()
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS pelanggan_pelan (
            id TEXT PRIMARY KEY,
            akaun_id TEXT NOT NULL,
            minggu_mula TEXT NOT NULL,
            versi INTEGER NOT NULL,
            dicipta TEXT NOT NULL,
            dikemas TEXT NOT NULL,
            UNIQUE (akaun_id, minggu_mula)
        )
        '''
    )
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS pelanggan_tugas (
            id TEXT PRIMARY KEY,
            pelan_id TEXT NOT NULL,
            akaun_id TEXT NOT NULL,
            teks TEXT NOT NULL,
            siap INTEGER NOT NULL,
            susunan INTEGER NOT NULL,
            cap_teks TEXT NOT NULL,
            dicipta TEXT NOT NULL,
            UNIQUE (pelan_id, cap_teks)
        )
        '''
    )
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS pelanggan_angka (
            id TEXT PRIMARY KEY,
            akaun_id TEXT NOT NULL,
            tarikh TEXT NOT NULL,
            jenis TEXT NOT NULL,
            nilai TEXT NOT NULL,
            unit TEXT NOT NULL,
            status TEXT NOT NULL,
            sumber TEXT NOT NULL,
            nota TEXT NOT NULL,
            versi INTEGER NOT NULL,
            dicipta TEXT NOT NULL,
            dikemas TEXT NOT NULL
        )
        '''
    )
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS pelanggan_import (
            id TEXT PRIMARY KEY,
            akaun_id TEXT NOT NULL,
            jenis TEXT NOT NULL,
            kunci_idem TEXT NOT NULL,
            cap TEXT NOT NULL,
            status TEXT NOT NULL,
            hasil_json TEXT NOT NULL,
            dicipta TEXT NOT NULL,
            UNIQUE (akaun_id, jenis, kunci_idem)
        )
        '''
    )
    cur.execute('CREATE INDEX IF NOT EXISTS pelanggan_tugas_akaun ON pelanggan_tugas (akaun_id, pelan_id)')
    cur.execute('CREATE INDEX IF NOT EXISTS pelanggan_angka_tarikh ON pelanggan_angka (akaun_id, tarikh)')


def dispatch_meja(method, path, conn, postgres, akaun_id, payload, stamp, query, idempotency_key):
    soal = _soal(query)
    if method == 'GET' and path == '/api/akaun/matlamat':
        return _baca_matlamat(conn, postgres, akaun_id)
    if method == 'PUT' and path == '/api/akaun/matlamat':
        return _simpan_matlamat(conn, postgres, akaun_id, payload)
    if method == 'GET' and path == '/api/akaun/pelan':
        return _baca_pelan_http(conn, postgres, akaun_id, soal)
    if method == 'POST' and path == '/api/akaun/pelan/tugasan':
        return _dalam(conn, postgres, lambda: _tambah_tugas(conn, postgres, akaun_id, payload, stamp))
    padanan = TUGAS_LALUAN.match(path)
    if padanan and method == 'PATCH':
        return _dalam(conn, postgres, lambda: _kemas_tugas(conn, postgres, akaun_id, padanan.group(1), payload, stamp))
    if padanan and method == 'DELETE':
        return _dalam(conn, postgres, lambda: _padam_tugas(conn, postgres, akaun_id, padanan.group(1), payload, stamp))
    if method == 'GET' and path == '/api/akaun/angka':
        return _senarai_angka(conn, postgres, akaun_id, soal)
    if method == 'POST' and path == '/api/akaun/angka':
        return _dalam(conn, postgres, lambda: _tambah_angka(conn, postgres, akaun_id, payload, stamp, 'manual'))
    angka = ANGKA_LALUAN.match(path)
    if angka and method == 'PATCH':
        return _dalam(conn, postgres, lambda: _kemas_angka(conn, postgres, akaun_id, angka.group(1), payload, stamp))
    if angka and method == 'DELETE':
        return _dalam(conn, postgres, lambda: _tarik_angka(conn, postgres, akaun_id, angka.group(1), payload, stamp))
    if method == 'POST' and path == '/api/akaun/import':
        return _dalam(conn, postgres, lambda: _import(conn, postgres, akaun_id, payload, stamp, idempotency_key))
    return 404, {'error': 'Endpoint tidak ditemui.'}


def hari_ini(stamp):
    return datetime.fromtimestamp(int(stamp), KL).date()


def _ph(postgres):
    return '%s' if postgres else '?'


def _iso(stamp):
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(int(stamp)))


def _soal(query):
    if not isinstance(query, dict):
        return {}
    hasil = {}
    for kunci, nilai in query.items():
        if isinstance(nilai, (list, tuple)):
            if nilai:
                hasil[kunci] = nilai[-1]
        elif nilai is not None:
            hasil[kunci] = nilai
    return hasil


def _ada_lajur(conn, postgres, nama):
    cur = conn.cursor()
    if postgres:
        cur.execute(
            '''SELECT 1 FROM information_schema.columns
               WHERE table_name = %s AND column_name = %s''',
            ('pelanggan_akaun', nama),
        )
        return cur.fetchone() is not None
    cur.execute('PRAGMA table_info(pelanggan_akaun)')
    return any(row[1] == nama for row in cur.fetchall())


def _begin(conn, postgres):
    if postgres:
        if conn.get_transaction_status() == 0:
            conn.cursor().execute('BEGIN')
        return
    conn.execute('BEGIN IMMEDIATE')


def _dalam(conn, postgres, kerja):
    _begin(conn, postgres)
    try:
        status, body = kerja()
    except Tolak as tolak:
        conn.rollback()
        return tolak.status, tolak.body
    except Exception:
        conn.rollback()
        raise
    if status >= 400:
        conn.rollback()
        return status, body
    conn.commit()
    return status, body


def _kunci_akaun(conn, postgres, akaun_id):
    ph = _ph(postgres)
    conn.cursor().execute(f'UPDATE pelanggan_akaun SET email = email WHERE id = {ph}', (akaun_id,))


def _ralat(kod, mesej, **lebih):
    badan = {'ok': False, 'ralat': kod, 'error': mesej}
    badan.update(lebih)
    return badan


def _teks(nilai, bawah, atas):
    if not isinstance(nilai, str):
        return None
    bersih = ' '.join(nilai.replace('\n', ' ').split())
    if len(bersih) < bawah or len(bersih) > atas:
        return None
    return bersih


def _cap_teks(teks):
    return ' '.join(teks.casefold().split())


def _tarikh(nilai):
    if not isinstance(nilai, str) or not DATE_RE.match(nilai):
        return None
    try:
        hari = date.fromisoformat(nilai)
    except ValueError:
        return None
    if hari.year < 2000 or hari.year > 2100:
        return None
    return hari


def _hari_teks(hari):
    return hari.isoformat()


def _isnin(hari):
    return hari.weekday() == 0


def _versi(nilai, kosong):
    if isinstance(nilai, bool) or not isinstance(nilai, int):
        return None
    if nilai < kosong:
        return None
    return nilai


def _canon_wang(nilai):
    if not isinstance(nilai, str) or not MONEY_RE.match(nilai):
        return None
    penuh, _, sen = nilai.partition('.')
    return f'{penuh}.{(sen + "00")[:2]}'


def _canon_unit(nilai):
    if isinstance(nilai, str) and COUNT_RE.match(nilai):
        return nilai
    return None


def _pasangan(jenis, unit, nilai):
    if jenis in {'hasil', 'kos', 'lain'} and unit == 'MYR':
        return _canon_wang(nilai), None
    if jenis == 'lain' and unit == 'unit':
        return _canon_unit(nilai), None
    if jenis in {'hasil', 'kos', 'lain'} and unit in {'MYR', 'unit'}:
        return None, 'unit_tidak_sah'
    return None, 'bentuk'


def _nota(nilai):
    if nilai is None:
        return ''
    if not isinstance(nilai, str):
        return None
    bersih = ' '.join(nilai.replace('\n', ' ').split())
    if len(bersih) > 240:
        return None
    return bersih


def _label_angka(badan):
    badan['bukan_rekod_kewangan'] = True
    badan['label'] = LABEL_ANGKA
    return badan


def _baris_angka(row):
    return {
        'id': row[0],
        'tarikh': row[1],
        'jenis': row[2],
        'nilai': row[3],
        'unit': row[4],
        'status': row[5],
        'sumber': row[6],
        'nota': row[7],
        'versi': row[8],
    }


def _baca_matlamat(conn, postgres, akaun_id):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT matlamat, matlamat_versi FROM pelanggan_akaun WHERE id = {ph}',
        (akaun_id,),
    )
    row = cur.fetchone()
    if not row:
        return 401, {'error': 'Sila masuk akaun dahulu.'}
    versi = row[1] if isinstance(row[1], int) else 0
    return 200, {'ok': True, 'matlamat': row[0] or None, 'versi': versi}


def _simpan_matlamat(conn, postgres, akaun_id, payload):
    if not isinstance(payload, dict) or 'matlamat' not in payload or 'versi' not in payload:
        return 400, _ralat('bentuk', 'Permintaan tidak sah.')
    mentah = payload.get('matlamat')
    if isinstance(mentah, str) and ' '.join(mentah.replace('\n', ' ').split()) == '':
        matlamat = None
    else:
        matlamat = _teks(mentah, 2, 240)
        if matlamat is None:
            return 400, _ralat('bentuk', 'Matlamat perlu 2 hingga 240 aksara, atau kosong untuk memadam.')
    versi = _versi(payload.get('versi'), 0)
    if versi is None:
        return 400, _ralat('bentuk', 'Permintaan tidak sah.')
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE pelanggan_akaun SET matlamat = {ph}, matlamat_versi = {ph}
            WHERE id = {ph} AND COALESCE(matlamat_versi, 0) = {ph}''',
        (matlamat, versi + 1, akaun_id, versi),
    )
    if cur.rowcount == 1:
        conn.commit()
        return 200, {'ok': True, 'matlamat': matlamat, 'versi': versi + 1}
    cur.execute(
        f'SELECT id, matlamat, matlamat_versi FROM pelanggan_akaun WHERE id = {ph}',
        (akaun_id,),
    )
    ada = cur.fetchone()
    conn.rollback()
    if not ada:
        return 401, {'error': 'Sila masuk akaun dahulu.'}
    semasa = ada[2] if isinstance(ada[2], int) else 0
    return 409, _ralat(
        'konflik', 'Salinan ini sudah berubah. Muat semula.',
        matlamat=ada[1] or None, versi=semasa,
    )


def _pelan_penuh(conn, postgres, akaun_id, pelan_id):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''SELECT id, minggu_mula, versi FROM pelanggan_pelan
            WHERE id = {ph} AND akaun_id = {ph}''',
        (pelan_id, akaun_id),
    )
    pelan = cur.fetchone()
    if not pelan:
        return None
    cur.execute(
        f'''SELECT id, teks, siap, susunan FROM pelanggan_tugas
            WHERE pelan_id = {ph} AND akaun_id = {ph}
            ORDER BY susunan, id''',
        (pelan_id, akaun_id),
    )
    tugasan = [
        {'id': row[0], 'teks': row[1], 'siap': bool(row[2]), 'susunan': row[3]}
        for row in cur.fetchall()
    ]
    return {'id': pelan[0], 'minggu_mula': pelan[1], 'versi': pelan[2], 'tugasan': tugasan}


def _cari_pelan(conn, postgres, akaun_id, minggu):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT id, versi FROM pelanggan_pelan WHERE akaun_id = {ph} AND minggu_mula = {ph}',
        (akaun_id, minggu),
    )
    return cur.fetchone()


def _baca_pelan_http(conn, postgres, akaun_id, soal):
    """Pelan yang belum wujud bermula pada versi 0. Setiap tulisan menaikkan versi tepat satu.
    Teks pendua dan import yang sudah ada tidak menulis, jadi versi tidak berubah.
    Versi lapuk memulangkan 409 tanpa mengubah baris."""
    hari = _tarikh(soal.get('minggu'))
    if not hari or not _isnin(hari):
        return 400, _ralat('bentuk', 'Guna hari Isnin bagi minggu ini.')
    pelan = _cari_pelan(conn, postgres, akaun_id, _hari_teks(hari))
    if not pelan:
        return 200, {'ok': True, 'pelan': None, 'versi': 0}
    penuh = _pelan_penuh(conn, postgres, akaun_id, pelan[0])
    return 200, {'ok': True, 'pelan': penuh, 'versi': penuh['versi']}


def _tambah_tugas(conn, postgres, akaun_id, payload, stamp):
    if not isinstance(payload, dict):
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    hari = _tarikh(payload.get('minggu_mula'))
    if not hari or not _isnin(hari):
        raise Tolak(400, _ralat('bentuk', 'Guna hari Isnin bagi minggu ini.'))
    teks = _teks(payload.get('teks'), 2, 160)
    versi = _versi(payload.get('versi'), 0)
    if not teks or versi is None:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    _kunci_akaun(conn, postgres, akaun_id)
    minggu = _hari_teks(hari)
    pelan = _cari_pelan(conn, postgres, akaun_id, minggu)
    cap = _cap_teks(teks)
    if not pelan:
        if versi != 0:
            raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.', versi=0, pelayan=[]))
        return _cipta_pelan(conn, postgres, akaun_id, minggu, teks, cap, stamp)
    pelan_id, semasa = pelan
    if semasa != versi:
        penuh = _pelan_penuh(conn, postgres, akaun_id, pelan_id)
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.', versi=semasa, pelayan=penuh['tugasan']))
    ada = _tugas_cap(conn, postgres, akaun_id, pelan_id, cap)
    if ada:
        return 200, {'ok': True, 'sudah_ada': True, 'pelan': _pelan_penuh(conn, postgres, akaun_id, pelan_id)}
    if _kira_tugas(conn, postgres, akaun_id, pelan_id) >= 30:
        raise Tolak(400, _ralat('had_tugas', 'Pelan minggu ini sudah mempunyai 30 tugasan.'))
    _naik_pelan(conn, postgres, akaun_id, pelan_id, versi, stamp)
    _masuk_tugas(conn, postgres, akaun_id, pelan_id, teks, cap, False, stamp)
    return 200, {'ok': True, 'sudah_ada': False, 'pelan': _pelan_penuh(conn, postgres, akaun_id, pelan_id)}


def _cipta_pelan(conn, postgres, akaun_id, minggu, teks, cap, stamp):
    ph = _ph(postgres)
    pelan_id = secrets.token_hex(16)
    masa = _iso(stamp)
    conn.cursor().execute(
        f'''INSERT INTO pelanggan_pelan (id, akaun_id, minggu_mula, versi, dicipta, dikemas)
            VALUES ({ph}, {ph}, {ph}, 1, {ph}, {ph})''',
        (pelan_id, akaun_id, minggu, masa, masa),
    )
    _masuk_tugas(conn, postgres, akaun_id, pelan_id, teks, cap, False, stamp)
    return 200, {'ok': True, 'sudah_ada': False, 'pelan': _pelan_penuh(conn, postgres, akaun_id, pelan_id)}


def _tugas_cap(conn, postgres, akaun_id, pelan_id, cap):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT id FROM pelanggan_tugas WHERE pelan_id = {ph} AND akaun_id = {ph} AND cap_teks = {ph}',
        (pelan_id, akaun_id, cap),
    )
    return cur.fetchone()


def _kira_tugas(conn, postgres, akaun_id, pelan_id):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT COUNT(*) FROM pelanggan_tugas WHERE pelan_id = {ph} AND akaun_id = {ph}',
        (pelan_id, akaun_id),
    )
    return int(cur.fetchone()[0])


def _naik_pelan(conn, postgres, akaun_id, pelan_id, versi, stamp):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE pelanggan_pelan SET versi = {ph}, dikemas = {ph}
            WHERE id = {ph} AND akaun_id = {ph} AND versi = {ph}''',
        (versi + 1, _iso(stamp), pelan_id, akaun_id, versi),
    )
    if cur.rowcount != 1:
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.'))


def _masuk_tugas(conn, postgres, akaun_id, pelan_id, teks, cap, siap, stamp):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT COALESCE(MAX(susunan), -1) FROM pelanggan_tugas WHERE pelan_id = {ph} AND akaun_id = {ph}',
        (pelan_id, akaun_id),
    )
    susunan = int(cur.fetchone()[0]) + 1
    cur.execute(
        f'''INSERT INTO pelanggan_tugas
            (id, pelan_id, akaun_id, teks, siap, susunan, cap_teks, dicipta)
            VALUES ({ph}, {ph}, {ph}, {ph}, {ph}, {ph}, {ph}, {ph})''',
        (secrets.token_hex(16), pelan_id, akaun_id, teks, 1 if siap else 0, susunan, cap, _iso(stamp)),
    )


def _milik_tugas(conn, postgres, akaun_id, tugas_id):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT pelan_id FROM pelanggan_tugas WHERE id = {ph} AND akaun_id = {ph}',
        (tugas_id, akaun_id),
    )
    row = cur.fetchone()
    if row:
        return row[0]
    cur.execute(f'SELECT id FROM pelanggan_tugas WHERE id = {ph}', (tugas_id,))
    if cur.fetchone():
        raise Tolak(404, {'error': 'Endpoint tidak ditemui.'})
    raise Tolak(404, {'error': 'Endpoint tidak ditemui.'})


def _versi_pelan(conn, postgres, akaun_id, pelan_id):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT versi FROM pelanggan_pelan WHERE id = {ph} AND akaun_id = {ph}',
        (pelan_id, akaun_id),
    )
    row = cur.fetchone()
    if not row:
        raise Tolak(404, {'error': 'Endpoint tidak ditemui.'})
    return row[0]


def _kemas_tugas(conn, postgres, akaun_id, tugas_id, payload, stamp):
    if not isinstance(payload, dict) or not isinstance(payload.get('siap'), bool):
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    versi = _versi(payload.get('versi'), 1)
    if versi is None:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    _kunci_akaun(conn, postgres, akaun_id)
    pelan_id = _milik_tugas(conn, postgres, akaun_id, tugas_id)
    if _versi_pelan(conn, postgres, akaun_id, pelan_id) != versi:
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.', pelan=_pelan_penuh(conn, postgres, akaun_id, pelan_id)))
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'UPDATE pelanggan_tugas SET siap = {ph} WHERE id = {ph} AND akaun_id = {ph}',
        (1 if payload['siap'] else 0, tugas_id, akaun_id),
    )
    _naik_pelan(conn, postgres, akaun_id, pelan_id, versi, stamp)
    return 200, {'ok': True, 'pelan': _pelan_penuh(conn, postgres, akaun_id, pelan_id)}


def _padam_tugas(conn, postgres, akaun_id, tugas_id, payload, stamp):
    if not isinstance(payload, dict):
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    versi = _versi(payload.get('versi'), 1)
    if versi is None:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    _kunci_akaun(conn, postgres, akaun_id)
    pelan_id = _milik_tugas(conn, postgres, akaun_id, tugas_id)
    if _versi_pelan(conn, postgres, akaun_id, pelan_id) != versi:
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.', pelan=_pelan_penuh(conn, postgres, akaun_id, pelan_id)))
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'DELETE FROM pelanggan_tugas WHERE id = {ph} AND akaun_id = {ph}',
        (tugas_id, akaun_id),
    )
    if cur.rowcount != 1:
        raise Tolak(404, {'error': 'Endpoint tidak ditemui.'})
    _naik_pelan(conn, postgres, akaun_id, pelan_id, versi, stamp)
    return 200, {'ok': True, 'pelan': _pelan_penuh(conn, postgres, akaun_id, pelan_id)}


def _rekod_angka(payload, stamp, semasa=None):
    if not isinstance(payload, dict):
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    jenis = payload.get('jenis', semasa['jenis'] if semasa else None)
    unit = payload.get('unit', semasa['unit'] if semasa else None)
    nilai = payload.get('nilai', semasa['nilai'] if semasa else None)
    tarikh_mentah = payload.get('tarikh', semasa['tarikh'] if semasa else None)
    if 'jenis' in payload or 'unit' in payload or 'nilai' in payload or 'tarikh' in payload or semasa is None:
        if jenis not in {'hasil', 'kos', 'lain'} or unit not in {'MYR', 'unit'}:
            raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    canon, kod = _pasangan(jenis, unit, nilai if isinstance(nilai, str) else '')
    if kod == 'unit_tidak_sah':
        raise Tolak(400, _ralat('unit_tidak_sah', 'Jenis dan unit ini tidak boleh digandingkan.'))
    if not canon:
        raise Tolak(400, _ralat('nilai', 'Nilai perlu nombor perpuluhan yang tepat, atau bilangan bulat.'))
    hari = _tarikh(tarikh_mentah)
    if not hari:
        raise Tolak(400, _ralat('bentuk', 'Tarikh tidak sah.'))
    if hari < date(2000, 1, 1) or hari > hari_ini(stamp):
        raise Tolak(400, _ralat('tarikh', 'Tarikh perlu dari 2000-01-01 hingga hari ini di Malaysia.'))
    nota = _nota(payload.get('nota', semasa['nota'] if semasa else ''))
    if nota is None:
        raise Tolak(400, _ralat('bentuk', 'Nota terlalu panjang.'))
    return {
        'tarikh': _hari_teks(hari),
        'jenis': jenis,
        'nilai': canon,
        'unit': unit,
        'nota': nota,
    }


def _pendua_angka(conn, postgres, akaun_id, rekod, kecualikan=None):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''SELECT id, tarikh, jenis, nilai, unit, status, sumber, nota, versi
            FROM pelanggan_angka
            WHERE akaun_id = {ph} AND tarikh = {ph} AND jenis = {ph} AND unit = {ph}
              AND nilai = {ph} AND status = 'dimasukkan_pengguna' ''',
        (akaun_id, rekod['tarikh'], rekod['jenis'], rekod['unit'], rekod['nilai']),
    )
    for row in cur.fetchall():
        if kecualikan and row[0] == kecualikan:
            continue
        return _baris_angka(row)
    return None


def _tambah_angka(conn, postgres, akaun_id, payload, stamp, sumber):
    rekod = _rekod_angka(payload, stamp)
    if payload.get('sahkan_pendua') not in {None, False, True}:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    _kunci_akaun(conn, postgres, akaun_id)
    ada = _pendua_angka(conn, postgres, akaun_id, rekod)
    if ada and payload.get('sahkan_pendua') is not True:
        raise Tolak(409, _label_angka(_ralat('pendua', 'Rekod yang sama sudah ada.', rekod_sedia=ada)))
    row = _masuk_angka(conn, postgres, akaun_id, rekod, sumber, stamp)
    return 200, _label_angka({'ok': True, 'rekod': row})


def _masuk_angka(conn, postgres, akaun_id, rekod, sumber, stamp):
    ph = _ph(postgres)
    rekod_id = secrets.token_hex(16)
    masa = _iso(stamp)
    conn.cursor().execute(
        f'''INSERT INTO pelanggan_angka
            (id, akaun_id, tarikh, jenis, nilai, unit, status, sumber, nota, versi, dicipta, dikemas)
            VALUES ({ph}, {ph}, {ph}, {ph}, {ph}, {ph}, 'dimasukkan_pengguna', {ph}, {ph}, 1, {ph}, {ph})''',
        (rekod_id, akaun_id, rekod['tarikh'], rekod['jenis'], rekod['nilai'], rekod['unit'], sumber, rekod['nota'], masa, masa),
    )
    return {
        'id': rekod_id,
        'tarikh': rekod['tarikh'],
        'jenis': rekod['jenis'],
        'nilai': rekod['nilai'],
        'unit': rekod['unit'],
        'status': 'dimasukkan_pengguna',
        'sumber': sumber,
        'nota': rekod['nota'],
        'versi': 1,
    }


def _baca_angka_satu(conn, postgres, akaun_id, rekod_id):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''SELECT id, tarikh, jenis, nilai, unit, status, sumber, nota, versi
            FROM pelanggan_angka WHERE id = {ph} AND akaun_id = {ph}''',
        (rekod_id, akaun_id),
    )
    row = cur.fetchone()
    if row:
        return _baris_angka(row)
    cur.execute(f'SELECT akaun_id FROM pelanggan_angka WHERE id = {ph}', (rekod_id,))
    if cur.fetchone():
        raise Tolak(404, {'error': 'Endpoint tidak ditemui.'})
    raise Tolak(404, {'error': 'Endpoint tidak ditemui.'})


def _kemas_angka(conn, postgres, akaun_id, rekod_id, payload, stamp):
    if not isinstance(payload, dict):
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    versi = _versi(payload.get('versi'), 1)
    if versi is None:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    _kunci_akaun(conn, postgres, akaun_id)
    semasa = _baca_angka_satu(conn, postgres, akaun_id, rekod_id)
    if semasa['versi'] != versi or semasa['status'] != 'dimasukkan_pengguna':
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.', rekod=semasa))
    rekod = _rekod_angka(payload, stamp, semasa)
    if payload.get('sahkan_pendua') not in {None, False, True}:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    ada = _pendua_angka(conn, postgres, akaun_id, rekod, kecualikan=rekod_id)
    if ada and payload.get('sahkan_pendua') is not True:
        raise Tolak(409, _label_angka(_ralat('pendua', 'Rekod yang sama sudah ada.', rekod_sedia=ada)))
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE pelanggan_angka
            SET tarikh = {ph}, jenis = {ph}, nilai = {ph}, unit = {ph}, nota = {ph},
                versi = {ph}, dikemas = {ph}
            WHERE id = {ph} AND akaun_id = {ph} AND versi = {ph} AND status = 'dimasukkan_pengguna' ''',
        (rekod['tarikh'], rekod['jenis'], rekod['nilai'], rekod['unit'], rekod['nota'], versi + 1, _iso(stamp), rekod_id, akaun_id, versi),
    )
    if cur.rowcount != 1:
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.'))
    baharu = dict(semasa)
    baharu.update(rekod)
    baharu['versi'] = versi + 1
    return 200, _label_angka({'ok': True, 'rekod': baharu})


def _tarik_angka(conn, postgres, akaun_id, rekod_id, payload, stamp):
    if not isinstance(payload, dict):
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    versi = _versi(payload.get('versi'), 1)
    if versi is None:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    _kunci_akaun(conn, postgres, akaun_id)
    semasa = _baca_angka_satu(conn, postgres, akaun_id, rekod_id)
    if semasa['versi'] != versi or semasa['status'] != 'dimasukkan_pengguna':
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.', rekod=semasa))
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE pelanggan_angka
            SET status = 'ditarik_pengguna', versi = {ph}, dikemas = {ph}
            WHERE id = {ph} AND akaun_id = {ph} AND versi = {ph} AND status = 'dimasukkan_pengguna' ''',
        (versi + 1, _iso(stamp), rekod_id, akaun_id, versi),
    )
    if cur.rowcount != 1:
        raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.'))
    semasa['status'] = 'ditarik_pengguna'
    semasa['versi'] = versi + 1
    return 200, _label_angka({'ok': True, 'rekod': semasa})


def _senarai_angka(conn, postgres, akaun_id, soal):
    mula = _tarikh(soal.get('dari')) if soal.get('dari') else None
    tamat = _tarikh(soal.get('hingga')) if soal.get('hingga') else None
    if soal.get('dari') and not mula:
        return 400, _ralat('bentuk', 'Tarikh tidak sah.')
    if soal.get('hingga') and not tamat:
        return 400, _ralat('bentuk', 'Tarikh tidak sah.')
    if mula and tamat and mula > tamat:
        return 400, _ralat('bentuk', 'Julat tarikh tidak sah.')
    ph = _ph(postgres)
    syarat = f"akaun_id = {ph} AND status = 'dimasukkan_pengguna'"
    args = [akaun_id]
    if mula:
        syarat += f' AND tarikh >= {ph}'
        args.append(_hari_teks(mula))
    if tamat:
        syarat += f' AND tarikh <= {ph}'
        args.append(_hari_teks(tamat))
    cur = conn.cursor()
    cur.execute(
        f'''SELECT id, tarikh, jenis, nilai, unit, status, sumber, nota, versi
            FROM pelanggan_angka WHERE {syarat}
            ORDER BY tarikh, id LIMIT 200''',
        tuple(args),
    )
    return 200, _label_angka({'ok': True, 'rekod': [_baris_angka(row) for row in cur.fetchall()]})


def _kunci_import(header, payload):
    badan = payload.get('kunci_idem') if isinstance(payload, dict) else None
    kepala = header.strip() if isinstance(header, str) and header.strip() else None
    if badan is not None and not isinstance(badan, str):
        return None
    if kepala and badan and kepala != badan:
        return None
    kunci = kepala or badan
    if not isinstance(kunci, str) or not KEY_RE.match(kunci):
        return None
    return kunci


def _cap(badan):
    mentah = json.dumps(badan, ensure_ascii=False, separators=(',', ':'), sort_keys=True)
    return hashlib.sha256(mentah.encode('utf-8')).hexdigest()


def _baca_resit(conn, postgres, akaun_id, jenis, kunci):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''SELECT cap, hasil_json FROM pelanggan_import
            WHERE akaun_id = {ph} AND jenis = {ph} AND kunci_idem = {ph}''',
        (akaun_id, jenis, kunci),
    )
    row = cur.fetchone()
    if not row:
        return None
    return {'cap': row[0], 'hasil': json.loads(row[1])}


def _simpan_resit(conn, postgres, akaun_id, jenis, kunci, cap, hasil, stamp):
    ph = _ph(postgres)
    conn.cursor().execute(
        f'''INSERT INTO pelanggan_import
            (id, akaun_id, jenis, kunci_idem, cap, status, hasil_json, dicipta)
            VALUES ({ph}, {ph}, {ph}, {ph}, {ph}, 'selesai', {ph}, {ph})''',
        (secrets.token_hex(16), akaun_id, jenis, kunci, cap, json.dumps(hasil, ensure_ascii=False), _iso(stamp)),
    )


def _import(conn, postgres, akaun_id, payload, stamp, idempotency_key):
    if not isinstance(payload, dict) or payload.get('persetujuan') is not True:
        raise Tolak(400, _ralat('persetujuan', 'Import memerlukan persetujuan.'))
    jenis = payload.get('jenis')
    if jenis not in {'pelan', 'angka'}:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    kunci = _kunci_import(idempotency_key, payload)
    if not kunci:
        raise Tolak(400, _ralat('bentuk', 'Kunci idempotensi tidak sah.'))
    if jenis == 'pelan':
        cap = _cap_import_pelan(payload)
    else:
        cap = _cap_import_angka(payload, stamp)
    _kunci_akaun(conn, postgres, akaun_id)
    ada = _baca_resit(conn, postgres, akaun_id, jenis, kunci)
    if ada:
        if ada['cap'] == cap:
            return 200, ada['hasil']
        raise Tolak(409, _ralat('kunci_berbeza', 'Kunci permintaan ini sudah digunakan.'))
    if jenis == 'pelan':
        hasil = _tulis_import_pelan(conn, postgres, akaun_id, payload, stamp)
    else:
        hasil = _tulis_import_angka(conn, postgres, akaun_id, payload, stamp)
    _simpan_resit(conn, postgres, akaun_id, jenis, kunci, cap, hasil, stamp)
    return 200, hasil


def _tugasan_import(payload):
    mentah = payload.get('tugasan')
    if not isinstance(mentah, list) or not mentah or len(mentah) > 30:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    bersih = []
    dilihat = set()
    for item in mentah:
        if not isinstance(item, dict) or not isinstance(item.get('siap'), bool):
            raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
        teks = _teks(item.get('teks'), 2, 160)
        if not teks:
            raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
        cap = _cap_teks(teks)
        if cap in dilihat:
            continue
        dilihat.add(cap)
        bersih.append({'teks': teks, 'siap': item['siap'], 'cap': cap})
    if not bersih:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    return bersih


def _rangka_pelan(payload):
    hari = _tarikh(payload.get('minggu_mula'))
    if not hari or not _isnin(hari):
        raise Tolak(400, _ralat('bentuk', 'Guna hari Isnin bagi minggu ini.'))
    tindakan = payload.get('tindakan') or ''
    if tindakan not in {'', 'tambah_baharu'}:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    tugasan = _tugasan_import(payload)
    versi = _versi(payload.get('versi', 0), 0)
    if versi is None:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    minggu = _hari_teks(hari)
    cap = _cap({
        'jenis': 'pelan',
        'minggu_mula': minggu,
        'tindakan': tindakan,
        'versi': versi,
        'tugasan': [{'siap': item['siap'], 'teks': item['cap']} for item in tugasan],
    })
    return cap, minggu, tindakan, tugasan, versi


def _cap_import_pelan(payload):
    cap, _minggu, _tindakan, _tugasan, _versi = _rangka_pelan(payload)
    return cap


def _tulis_import_pelan(conn, postgres, akaun_id, payload, stamp):
    _cap_nilai, minggu, tindakan, tugasan, versi = _rangka_pelan(payload)
    pelan = _cari_pelan(conn, postgres, akaun_id, minggu)
    if not pelan:
        if versi != 0:
            raise Tolak(409, _ralat('konflik', 'Salinan ini sudah berubah. Muat semula.', versi=0, pelayan=[], draf=[item['teks'] for item in tugasan]))
        if len(tugasan) > 30:
            raise Tolak(400, _ralat('had_tugas', 'Pelan minggu ini sudah mempunyai 30 tugasan.'))
        pelan_id = secrets.token_hex(16)
        masa = _iso(stamp)
        ph = _ph(postgres)
        conn.cursor().execute(
            f'''INSERT INTO pelanggan_pelan (id, akaun_id, minggu_mula, versi, dicipta, dikemas)
                VALUES ({ph}, {ph}, {ph}, 1, {ph}, {ph})''',
            (pelan_id, akaun_id, minggu, masa, masa),
        )
        for item in tugasan:
            _masuk_tugas(conn, postgres, akaun_id, pelan_id, item['teks'], item['cap'], item['siap'], stamp)
        return {'ok': True, 'sudah_ada': False, 'pelan': _pelan_penuh(conn, postgres, akaun_id, pelan_id)}
    pelan_id, semasa = pelan
    if semasa != versi:
        penuh = _pelan_penuh(conn, postgres, akaun_id, pelan_id)
        raise Tolak(409, _ralat(
            'konflik', 'Salinan ini sudah berubah. Muat semula.',
            versi=semasa, pelayan=[item['teks'] for item in penuh['tugasan']], draf=[item['teks'] for item in tugasan],
        ))
    sedia = _pelan_penuh(conn, postgres, akaun_id, pelan_id)
    caps = { _cap_teks(item['teks']) for item in sedia['tugasan'] }
    baharu = [item for item in tugasan if item['cap'] not in caps]
    pelayan_lain = [item['teks'] for item in sedia['tugasan'] if _cap_teks(item['teks']) not in {row['cap'] for row in tugasan}]
    if not baharu:
        return {'ok': True, 'sudah_ada': True, 'pelan': sedia}
    if pelayan_lain or any(item['cap'] in caps for item in tugasan):
        if tindakan != 'tambah_baharu':
            raise Tolak(409, _ralat(
                'konflik', 'Pelan pelayan dan draf telefon tidak sama.',
                versi=semasa,
                pelayan=[item['teks'] for item in sedia['tugasan']],
                draf=[item['teks'] for item in tugasan],
            ))
    if _kira_tugas(conn, postgres, akaun_id, pelan_id) + len(baharu) > 30:
        raise Tolak(400, _ralat('had_tugas', 'Pelan minggu ini sudah mempunyai 30 tugasan.'))
    _naik_pelan(conn, postgres, akaun_id, pelan_id, versi, stamp)
    for item in baharu:
        _masuk_tugas(conn, postgres, akaun_id, pelan_id, item['teks'], item['cap'], item['siap'], stamp)
    return {'ok': True, 'sudah_ada': False, 'pelan': _pelan_penuh(conn, postgres, akaun_id, pelan_id)}


def _cap_import_angka(payload, stamp):
    rekod = _rekod_import(payload, stamp)
    return _cap({
        'jenis': 'angka',
        'sahkan_pendua': payload.get('sahkan_pendua') is True,
        'rekod': rekod,
    })


def _tulis_import_angka(conn, postgres, akaun_id, payload, stamp):
    rekod = _rekod_import(payload, stamp)
    if payload.get('sahkan_pendua') is not True:
        for item in rekod:
            ada = _pendua_angka(conn, postgres, akaun_id, item)
            if ada:
                raise Tolak(409, _label_angka(_ralat('pendua', 'Rekod yang sama sudah ada.', rekod_sedia=ada)))
    ditulis = [_masuk_angka(conn, postgres, akaun_id, item, 'import_draf', stamp) for item in rekod]
    return _label_angka({'ok': True, 'sudah_ada': False, 'rekod': ditulis})


def _rekod_import(payload, stamp):
    mentah = payload.get('rekod')
    if not isinstance(mentah, list) or not mentah or len(mentah) > 30:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    if payload.get('sahkan_pendua') not in {None, False, True}:
        raise Tolak(400, _ralat('bentuk', 'Permintaan tidak sah.'))
    return [_rekod_angka(item, stamp) for item in mentah]



