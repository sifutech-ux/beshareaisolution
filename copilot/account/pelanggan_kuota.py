"""Kuota Copilot dan penasihat awam. Jadual tambahan. Bukan meja dagangan.

Jaminan keras ialah bilangan kemasukan yang di-commit, bukan bil pembekal.
Setiap perubahan status ialah UPDATE bersyarat. Penambah dan penolak kaunter
hanya berlaku selepas rowcount status itu sama dengan satu.

Jika proses mati selepas status sending dan sebelum muktamad, unit harian
kekal terpakai dan tiada panggilan model automatik. Token pembekal yang tidak
dipulangkan disimpan sebagai NULL, bukan sifar.

Yang tidak dapat dijamin: kematian proses selepas pembekal menerima permintaan
tetapi sebelum baris muktamad di-commit. Unit kemasukan kekal, manakala bil
pembekal mungkin sudah atau belum wujud. SQLite satu fail tidak selamat untuk
beberapa proses pelayan. Kunci baris PostgreSQL ialah jaminan rentas proses.
"""
import hashlib
import hmac
import json
import os
import re
import secrets
import time

PUBLIC_SCOPE = 'public'
ROUTE_COPILOT = 'copilot_nasihat'
ROUTE_PUBLIC = 'penasihat_awam'
POLICY_ID = 'utama'
LEASE_SECONDS = 90
SKEW_SECONDS = 60
KEY_RE = re.compile(r'^[A-Za-z0-9_-]{16,64}$')
NONCE_RE = KEY_RE

DEFAULT_POLICY = {
    'copilot_daily_account': 20,
    'copilot_monthly_account': 200,
    'copilot_daily_global': 100,
    'copilot_inflight_account': 1,
    'copilot_inflight_global': 3,
    'public_daily': 20,
    'public_inflight': 2,
    'shared_daily': 100,
    'shared_inflight': 3,
    'circuit_open': 0,
}


def kuta_secret():
    return (os.getenv('COPILOT_KUTA_SECRET') or '').strip()


def ensure_kuota_schema(conn, postgres):
    cur = conn.cursor()
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS copilot_quota_policy (
            id TEXT PRIMARY KEY,
            copilot_daily_account INTEGER NOT NULL,
            copilot_monthly_account INTEGER NOT NULL,
            copilot_daily_global INTEGER NOT NULL,
            copilot_inflight_account INTEGER NOT NULL,
            copilot_inflight_global INTEGER NOT NULL,
            public_daily INTEGER NOT NULL,
            public_inflight INTEGER NOT NULL,
            shared_daily INTEGER NOT NULL,
            shared_inflight INTEGER NOT NULL,
            circuit_open INTEGER NOT NULL,
            updated_at TEXT NOT NULL
        )
        '''
    )
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS copilot_usage_counter (
            scope TEXT NOT NULL,
            period TEXT NOT NULL,
            kind TEXT NOT NULL,
            amount INTEGER NOT NULL,
            PRIMARY KEY (scope, period, kind)
        )
        '''
    )
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS copilot_usage_reservation (
            id TEXT PRIMARY KEY,
            owner_scope TEXT NOT NULL,
            route TEXT NOT NULL,
            idempotency_key TEXT NOT NULL,
            fingerprint TEXT NOT NULL,
            status TEXT NOT NULL,
            day_period TEXT NOT NULL,
            month_period TEXT NOT NULL,
            model TEXT,
            prompt_tokens INTEGER,
            output_tokens INTEGER,
            reply_json TEXT,
            created_at TEXT NOT NULL,
            sending_at TEXT,
            expires_at TEXT NOT NULL,
            settled_at TEXT,
            UNIQUE (route, owner_scope, idempotency_key)
        )
        '''
    )
    cur.execute(
        '''
        CREATE TABLE IF NOT EXISTS copilot_service_nonce (
            nonce TEXT PRIMARY KEY,
            seen_at TEXT NOT NULL
        )
        '''
    )
    cur.execute(
        'CREATE INDEX IF NOT EXISTS copilot_rezab_tamat ON copilot_usage_reservation (status, expires_at)'
    )
    ph = '%s' if postgres else '?'
    cur.execute('SELECT id FROM copilot_quota_policy WHERE id = ' + ph, (POLICY_ID,))
    if cur.fetchone():
        return
    now = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    cols = ['id', *DEFAULT_POLICY.keys(), 'updated_at']
    marks = ', '.join([ph] * len(cols))
    cur.execute(
        f"INSERT INTO copilot_quota_policy ({', '.join(cols)}) VALUES ({marks})",
        (POLICY_ID, *DEFAULT_POLICY.values(), now),
    )


def cap_jari(route, owner, nama, jualan, peringkat, giliran):
    payload = {
        'giliran': giliran,
        'jualan': jualan,
        'nama': nama,
        'owner': owner,
        'peringkat': peringkat,
        'route': route,
    }
    raw = json.dumps(payload, ensure_ascii=False, separators=(',', ':'), sort_keys=True)
    return hashlib.sha256(raw.encode('utf-8')).hexdigest()


def sahkan_kuta(method, path, body, headers, secret, now):
    if not secret or not isinstance(headers, dict):
        return False
    masa_raw = str(headers.get('masa') or '')
    nonce = str(headers.get('nonce') or '')
    sig = str(headers.get('tandatangan') or '').lower()
    if not masa_raw.isdigit() or not NONCE_RE.match(nonce) or not re.fullmatch(r'[0-9a-f]{64}', sig):
        return False
    masa = int(masa_raw)
    if abs(int(now) - masa) > SKEW_SECONDS:
        return False
    digest = hashlib.sha256(body or b'').hexdigest()
    signed = f'{masa}.{nonce}.{method.upper()}.{path}.{digest}'.encode('utf-8')
    expected = hmac.new(str(secret).encode('utf-8'), signed, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, sig)


def handle_kuota(method, path, authorization, body, conn, postgres, account_secret, stamp, kuta_headers, read_session):
    if method != 'POST':
        return 404, {'ok': False, 'ralat': 'tiada', 'error': 'Endpoint tidak ditemui.'}
    secret = kuta_secret()
    if not secret:
        return 503, {'ok': False, 'ralat': 'kuota_tidak_sedia', 'error': 'Akaun tidak dapat dihubungi. Cuba semula.'}
    if not sahkan_kuta(method, path, body, kuta_headers or {}, secret, stamp):
        return 401, {'ok': False, 'ralat': 'kuta', 'error': 'Permintaan ini tidak diterima.'}
    try:
        payload = json.loads(body.decode('utf-8') or '{}')
    except (UnicodeError, json.JSONDecodeError, AttributeError):
        return 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Permintaan tidak sah.'}
    if not isinstance(payload, dict):
        return 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Permintaan tidak sah.'}
    try:
        _begin(conn, postgres)
        _buang_nonce_lama(conn, postgres, stamp)
        if not _simpan_nonce(conn, postgres, str((kuta_headers or {}).get('nonce')), stamp):
            conn.rollback()
            return 401, {'ok': False, 'ralat': 'nonce', 'error': 'Permintaan ini tidak diterima.'}
        _pulih(conn, postgres, stamp)
        if path == '/api/akaun/copilot/kuota/rezab':
            return _rezab(conn, postgres, payload, authorization, account_secret, stamp, read_session)
        if path == '/api/akaun/copilot/kuota/hantar':
            return _hantar(conn, postgres, payload, authorization, account_secret, stamp, read_session)
        if path == '/api/akaun/copilot/kuota/muktamad':
            return _muktamad(conn, postgres, payload, authorization, account_secret, stamp, read_session)
        conn.commit()
        return 404, {'ok': False, 'ralat': 'tiada', 'error': 'Endpoint tidak ditemui.'}
    except Exception:
        conn.rollback()
        return 503, {'ok': False, 'ralat': 'kuota_tidak_sedia', 'error': 'Akaun tidak dapat dihubungi. Cuba semula.'}


def _begin(conn, postgres):
    if postgres:
        if conn.get_transaction_status() == 0:
            conn.cursor().execute('BEGIN')
        return
    conn.execute('PRAGMA busy_timeout = 5000')
    conn.execute('BEGIN IMMEDIATE')


def _ph(postgres):
    return '%s' if postgres else '?'


def _iso(stamp):
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(int(stamp)))


def _tempoh(stamp):
    hari = time.strftime('%Y-%m-%d', time.gmtime(int(stamp)))
    return hari, hari[:7]


def _buang_nonce_lama(conn, postgres, stamp):
    ph = _ph(postgres)
    conn.cursor().execute(
        f'DELETE FROM copilot_service_nonce WHERE seen_at < {ph}',
        (_iso(stamp - 600),),
    )


def _simpan_nonce(conn, postgres, nonce, stamp):
    ph = _ph(postgres)
    try:
        conn.cursor().execute(
            f'INSERT INTO copilot_service_nonce (nonce, seen_at) VALUES ({ph}, {ph})',
            (nonce, _iso(stamp)),
        )
        return True
    except Exception as error:
        text = f'{type(error).__name__} {error}'.lower()
        if 'unique' in text or 'integrity' in text:
            return False
        raise


def _dasar(conn, postgres):
    cur = conn.cursor()
    cur.execute(
        '''SELECT copilot_daily_account, copilot_monthly_account, copilot_daily_global,
                  copilot_inflight_account, copilot_inflight_global, public_daily, public_inflight,
                  shared_daily, shared_inflight, circuit_open
           FROM copilot_quota_policy WHERE id = ''' + _ph(postgres),
        (POLICY_ID,),
    )
    row = cur.fetchone()
    if not row:
        raise RuntimeError('dasar kuota tiada')
    keys = list(DEFAULT_POLICY.keys())
    return dict(zip(keys, row))


def _kaunter(route, owner, day, month):
    if route == ROUTE_COPILOT:
        return [
            ('*', day, 'shared_day', 'shared_daily'),
            ('*', 'live', 'shared_inflight', 'shared_inflight'),
            ('*', day, 'copilot_day', 'copilot_daily_global'),
            ('*', 'live', 'copilot_inflight', 'copilot_inflight_global'),
            (owner, month, 'copilot_month', 'copilot_monthly_account'),
            (owner, day, 'copilot_day', 'copilot_daily_account'),
            (owner, 'live', 'copilot_inflight', 'copilot_inflight_account'),
        ]
    return [
        ('*', day, 'shared_day', 'shared_daily'),
        ('*', 'live', 'shared_inflight', 'shared_inflight'),
        ('*', day, 'public_day', 'public_daily'),
        ('*', 'live', 'public_inflight', 'public_inflight'),
    ]


def _tambah(conn, postgres, scope, period, kind, cap):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''INSERT INTO copilot_usage_counter (scope, period, kind, amount)
            VALUES ({ph}, {ph}, {ph}, 0)
            ON CONFLICT (scope, period, kind) DO NOTHING''',
        (scope, period, kind),
    )
    cur.execute(
        f'''UPDATE copilot_usage_counter SET amount = amount + 1
            WHERE scope = {ph} AND period = {ph} AND kind = {ph} AND amount < {ph}''',
        (scope, period, kind, int(cap)),
    )
    return cur.rowcount == 1


def _tolak(conn, postgres, scope, period, kind):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE copilot_usage_counter SET amount = amount - 1
            WHERE scope = {ph} AND period = {ph} AND kind = {ph} AND amount > 0''',
        (scope, period, kind),
    )
    return cur.rowcount == 1


def _kunci(conn, postgres, scope, period, kind):
    ph = _ph(postgres)
    conn.cursor().execute(
        f'''UPDATE copilot_usage_counter SET amount = amount
            WHERE scope = {ph} AND period = {ph} AND kind = {ph}''',
        (scope, period, kind),
    )


def _tertib(route, owner, day, month):
    return sorted(_kaunter(route, owner, day, month))


def _turunkan(conn, postgres, route, owner, day, month, inflight_sahaja=False):
    for scope, period, kind, _cap in _tertib(route, owner, day, month):
        if inflight_sahaja and not str(kind).endswith('inflight'):
            _kunci(conn, postgres, scope, period, kind)
            continue
        _tolak(conn, postgres, scope, period, kind)


def _pulih(conn, postgres, stamp):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''SELECT id, owner_scope, route, status, day_period, month_period
            FROM copilot_usage_reservation
            WHERE status IN ('reserved', 'sending') AND expires_at < {ph}''',
        (_iso(stamp),),
    )
    rows = sorted(cur.fetchall(), key=lambda row: row[0])
    tugas = []
    for rezab_id, owner, route, status, day, month in rows:
        if status == 'reserved':
            cur.execute(
                f'''UPDATE copilot_usage_reservation SET status = 'released'
                    WHERE id = {ph} AND status = 'reserved' ''',
                (rezab_id,),
            )
            if cur.rowcount != 1:
                continue
            tugas.append((route, owner, day, month, False))
            continue
        cur.execute(
            f'''UPDATE copilot_usage_reservation SET status = 'uncertain'
                WHERE id = {ph} AND status = 'sending' ''',
            (rezab_id,),
        )
        if cur.rowcount != 1:
            continue
        tugas.append((route, owner, day, month, True))
    for route, owner, day, month, inflight_sahaja in tugas:
        _turunkan(conn, postgres, route, owner, day, month, inflight_sahaja=inflight_sahaja)


def _giliran(value):
    if not isinstance(value, list):
        return None
    turns = []
    for item in value:
        if not isinstance(item, dict):
            return None
        dari = item.get('dari')
        teks = item.get('teks')
        if dari not in {'klien', 'penasihat'} or not isinstance(teks, str):
            return None
        clean = ' '.join(teks.split())[:4000]
        if not clean:
            return None
        turns.append({'dari': dari, 'teks': clean})
    return turns[-8:]


def _teks(value, limit):
    if not isinstance(value, str):
        return ''
    return ' '.join(value.replace('\n', ' ').split())[:limit]


def _siap(conn, status, body):
    conn.commit()
    return status, body


def _save(conn, name):
    conn.cursor().execute(f'SAVEPOINT {name}')


def _undo(conn, name):
    conn.cursor().execute(f'ROLLBACK TO SAVEPOINT {name}')


def _unik(error):
    text = f'{type(error).__name__} {error}'.lower()
    return 'unique' in text or 'integrity' in text


def _angka(value):
    if isinstance(value, bool) or not isinstance(value, int):
        return None
    if value < 0 or value > 2_000_000:
        return None
    return value


def _model(value):
    if not isinstance(value, str):
        return None
    clean = ''.join(ch for ch in value if ch.isalnum() or ch in '.-_')[:80]
    return clean or None


def _rezab(conn, postgres, payload, authorization, account_secret, stamp, read_session):
    route = payload.get('route')
    key = payload.get('idempotency_key')
    if route not in {ROUTE_COPILOT, ROUTE_PUBLIC} or not isinstance(key, str) or not KEY_RE.match(key):
        return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Permintaan tidak sah.'})
    giliran = _giliran(payload.get('giliran'))
    if not giliran or giliran[-1]['dari'] != 'klien':
        return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Sila tulis mesej.'})
    if route == ROUTE_COPILOT:
        claims = read_session(_bearer(authorization), account_secret, stamp)
        if not claims:
            return _siap(conn, 401, {'ok': False, 'ralat': 'sesi', 'error': 'Sila masuk akaun dahulu.'})
        owner, nama, jualan, peringkat = _konteks_akaun(conn, postgres, claims['akaun_id'])
        if owner is None:
            return _siap(conn, 401, {'ok': False, 'ralat': 'sesi', 'error': 'Sila masuk akaun dahulu.'})
        if not nama:
            return _siap(conn, 422, {'ok': False, 'ralat': 'profil', 'error': 'Lengkapkan profil perniagaan dahulu.'})
    else:
        owner = PUBLIC_SCOPE
        nama = _teks(payload.get('nama'), 80)
        jualan = _teks(payload.get('jualan'), 240)
        peringkat = payload.get('peringkat')
        if len(nama) < 2 or len(jualan) < 8 or peringkat not in {'mula', 'jual', 'kembang'}:
            return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Sila lengkapkan butiran perniagaan dan tulis mesej.'})
    jari = cap_jari(route, owner, nama, jualan, peringkat, giliran)
    dasar = _dasar(conn, postgres)
    day, month = _tempoh(stamp)
    sedia = _cari(conn, postgres, route, owner, key)
    if sedia and not (sedia['status'] == 'released' and sedia['fingerprint'] == jari):
        return _sedia(conn, sedia, jari)
    if int(dasar['circuit_open']):
        return _siap(conn, 503, {'ok': False, 'ralat': 'litar', 'error': 'Nasihat ditutup sebentar.'})
    _save(conn, 'naik')
    try:
        if sedia:
            if not _hidupkan(conn, postgres, sedia['id'], day, month, stamp):
                _undo(conn, 'naik')
                return _siap(conn, 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'})
            rezab_id = sedia['id']
        else:
            rezab_id = secrets.token_hex(16)
        if not _naikkan(conn, postgres, route, owner, day, month, dasar):
            _undo(conn, 'naik')
            return _siap(conn, 429, {'ok': False, 'ralat': 'had', 'error': 'Kuota nasihat penuh. Cuba lagi kemudian.'})
        if not sedia:
            _masukkan(conn, postgres, rezab_id, owner, route, key, jari, day, month, stamp)
    except Exception as error:
        _undo(conn, 'naik')
        if _unik(error):
            return _siap(conn, 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'})
        raise
    return _siap(conn, 200, {
        'ok': True, 'rezab_id': rezab_id, 'status': 'reserved', 'tamat': _iso(stamp + LEASE_SECONDS),
    })


def _sedia(conn, row, jari):
    status = row['status']
    if row['fingerprint'] != jari:
        conn.commit()
        return 409, {'ok': False, 'ralat': 'kunci_berbeza', 'error': 'Kunci permintaan ini sudah digunakan.'}
    if status == 'settled':
        conn.commit()
        return 200, _main_semula(row)
    if status in {'sending', 'uncertain', 'provider_failed'}:
        conn.commit()
        return 503, {'ok': False, 'ralat': 'tidak_pasti', 'error': 'Keputusan permintaan ini belum pasti.'}
    conn.commit()
    return 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'}


def _hidupkan(conn, postgres, rezab_id, day, month, stamp):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE copilot_usage_reservation
            SET status = 'reserved', day_period = {ph}, month_period = {ph},
                created_at = {ph}, expires_at = {ph}, sending_at = NULL, settled_at = NULL,
                reply_json = NULL, model = NULL, prompt_tokens = NULL, output_tokens = NULL
            WHERE id = {ph} AND status = 'released' ''',
        (day, month, _iso(stamp), _iso(stamp + LEASE_SECONDS), rezab_id),
    )
    return cur.rowcount == 1


def _naikkan(conn, postgres, route, owner, day, month, dasar):
    baris = _tertib(route, owner, day, month)
    for scope, period, kind, cap_name in baris:
        if not _tambah(conn, postgres, scope, period, kind, dasar[cap_name]):
            return False
    return True


def _masukkan(conn, postgres, rezab_id, owner, route, key, jari, day, month, stamp):
    ph = _ph(postgres)
    conn.cursor().execute(
        f'''INSERT INTO copilot_usage_reservation
            (id, owner_scope, route, idempotency_key, fingerprint, status, day_period, month_period,
             created_at, expires_at)
            VALUES ({ph}, {ph}, {ph}, {ph}, {ph}, 'reserved', {ph}, {ph}, {ph}, {ph})''',
        (rezab_id, owner, route, key, jari, day, month, _iso(stamp), _iso(stamp + LEASE_SECONDS)),
    )


def _cari(conn, postgres, route, owner, key):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''SELECT id, fingerprint, status, expires_at, reply_json
            FROM copilot_usage_reservation
            WHERE route = {ph} AND owner_scope = {ph} AND idempotency_key = {ph}''',
        (route, owner, key),
    )
    row = cur.fetchone()
    if not row:
        return None
    return {
        'id': row[0],
        'fingerprint': row[1],
        'status': row[2],
        'expires_at': row[3],
        'reply_json': row[4],
    }


def _konteks_akaun(conn, postgres, akaun_id):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'SELECT nama_perniagaan, jualan, peringkat FROM pelanggan_akaun WHERE id = {ph}',
        (akaun_id,),
    )
    row = cur.fetchone()
    if not row:
        return None, None, None, None
    nama = _teks(row[0], 80)
    jualan = _teks(row[1], 240)
    peringkat = row[2]
    if len(nama) < 2 or len(jualan) < 8 or peringkat not in {'mula', 'jual', 'kembang'}:
        return akaun_id, None, None, None
    return akaun_id, nama, jualan, peringkat


def _pemilik(conn, postgres, rezab_id, authorization, account_secret, stamp, read_session):
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''SELECT id, owner_scope, route, status, day_period, month_period, fingerprint, reply_json
            FROM copilot_usage_reservation WHERE id = {ph}''',
        (rezab_id,),
    )
    row = cur.fetchone()
    if not row:
        return None
    item = {
        'id': row[0],
        'owner_scope': row[1],
        'route': row[2],
        'status': row[3],
        'day_period': row[4],
        'month_period': row[5],
        'fingerprint': row[6],
        'reply_json': row[7],
    }
    if item['owner_scope'] == PUBLIC_SCOPE:
        return item
    claims = read_session(_bearer(authorization), account_secret, stamp)
    if not claims or claims['akaun_id'] != item['owner_scope']:
        return False
    return item


def _hantar(conn, postgres, payload, authorization, account_secret, stamp, read_session):
    rezab_id = payload.get('rezab_id')
    if not isinstance(rezab_id, str) or not rezab_id:
        return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Permintaan tidak sah.'})
    item = _pemilik(conn, postgres, rezab_id, authorization, account_secret, stamp, read_session)
    if item is False:
        return _siap(conn, 403, {'ok': False, 'ralat': 'asing', 'error': 'Permintaan ini tidak diterima.'})
    if item is None:
        return _siap(conn, 404, {'ok': False, 'ralat': 'tiada', 'error': 'Endpoint tidak ditemui.'})
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE copilot_usage_reservation
            SET status = 'sending', sending_at = {ph}, expires_at = {ph}
            WHERE id = {ph} AND status = 'reserved' ''',
        (_iso(stamp), _iso(stamp + LEASE_SECONDS), rezab_id),
    )
    if cur.rowcount != 1:
        return _siap(conn, 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'})
    return _siap(conn, 200, {'ok': True, 'rezab_id': rezab_id, 'status': 'sending'})


def _muktamad(conn, postgres, payload, authorization, account_secret, stamp, read_session):
    rezab_id = payload.get('rezab_id')
    hasil = payload.get('hasil')
    if not isinstance(rezab_id, str) or hasil not in {'settled', 'provider_failed', 'released', 'uncertain'}:
        return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Permintaan tidak sah.'})
    item = _pemilik(conn, postgres, rezab_id, authorization, account_secret, stamp, read_session)
    if item is False:
        return _siap(conn, 403, {'ok': False, 'ralat': 'asing', 'error': 'Permintaan ini tidak diterima.'})
    if item is None:
        return _siap(conn, 404, {'ok': False, 'ralat': 'tiada', 'error': 'Endpoint tidak ditemui.'})
    if item['status'] == hasil:
        body = {'ok': True, 'rezab_id': rezab_id, 'status': item['status'], 'main_semula': True}
        if item['status'] == 'settled' and item.get('reply_json'):
            body.update(json.loads(item['reply_json']))
        return _siap(conn, 200, body)
    if hasil == 'released':
        return _lepaskan(conn, postgres, item, stamp)
    if item['status'] != 'sending':
        return _siap(conn, 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'})
    reply = None
    model = _model(payload.get('model'))
    prompt_tokens = _angka(payload.get('prompt_tokens'))
    output_tokens = _angka(payload.get('output_tokens'))
    if hasil == 'settled':
        langkah = payload.get('langkah')
        jawapan = payload.get('jawapan')
        if not isinstance(jawapan, str) or not jawapan.strip() or not isinstance(langkah, list) or len(langkah) > 5:
            return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Permintaan tidak sah.'})
        if not all(isinstance(line, str) and line.strip() for line in langkah):
            return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk', 'error': 'Permintaan tidak sah.'})
        from pelanggan_copilot import validate_actions
        try:
            cadangan = validate_actions(payload.get('cadangan', []))
        except (ValueError, TypeError):
            return _siap(conn, 400, {'ok': False, 'ralat': 'bentuk'})
        reply = json.dumps({'jawapan': jawapan.strip()[:4000], 'langkah': [line.strip()[:240] for line in langkah],
            'format': payload.get('format', 'pelan'), 'cadangan': cadangan, 'asas': payload.get('asas', {})}, ensure_ascii=False)
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE copilot_usage_reservation
            SET status = {ph}, model = {ph}, prompt_tokens = {ph}, output_tokens = {ph},
                reply_json = {ph}, settled_at = {ph}
            WHERE id = {ph} AND status = 'sending' ''',
        (hasil, model, prompt_tokens, output_tokens, reply, _iso(stamp), rezab_id),
    )
    if cur.rowcount != 1:
        return _siap(conn, 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'})
    _turunkan(
        conn, postgres, item['route'], item['owner_scope'], item['day_period'], item['month_period'],
        inflight_sahaja=True,
    )
    body = {'ok': True, 'rezab_id': rezab_id, 'status': hasil}
    if reply:
        body.update(json.loads(reply))
    return _siap(conn, 200, body)


def _lepaskan(conn, postgres, item, stamp):
    if item['status'] != 'reserved':
        return _siap(conn, 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'})
    ph = _ph(postgres)
    cur = conn.cursor()
    cur.execute(
        f'''UPDATE copilot_usage_reservation SET status = 'released', settled_at = {ph}
            WHERE id = {ph} AND status = 'reserved' ''',
        (_iso(stamp), item['id']),
    )
    if cur.rowcount != 1:
        return _siap(conn, 409, {'ok': False, 'ralat': 'menunggu', 'error': 'Permintaan ini masih diproses.'})
    _turunkan(conn, postgres, item['route'], item['owner_scope'], item['day_period'], item['month_period'])
    return _siap(conn, 200, {'ok': True, 'rezab_id': item['id'], 'status': 'released'})


def _main_semula(row):
    body = {'ok': True, 'rezab_id': row['id'], 'status': 'settled', 'main_semula': True}
    if row.get('reply_json'):
        data = json.loads(row['reply_json'])
        body['jawapan'] = data.get('jawapan')
        body['langkah'] = data.get('langkah')
        for key in ('format', 'cadangan', 'asas'):
            if key in data:
                body[key] = data[key]
    return body


def _bearer(header):
    raw = str(header or '')
    if raw.lower().startswith('bearer '):
        return raw[7:].strip()
    return ''

