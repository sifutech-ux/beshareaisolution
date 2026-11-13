"""Akaun pelanggan BeShare. Jadual dan laluan ini tidak membaca desk dagangan."""
import hashlib
import hmac
import json
import os
import re
import secrets
import time
import urllib.parse

ALLOWED_ORIGINS = {
    'https://beshareaisolution.com',
    'https://www.beshareaisolution.com',
    'http://127.0.0.1:8000',
    'http://localhost:8000',
    'http://127.0.0.1:8766',
    'http://localhost:8766',
}
STAGES = {'mula', 'jual', 'kembang'}
SESSION_SECONDS = 30 * 24 * 3600
EMAIL_RE = re.compile(r'^[^@\s]{1,64}@[^@\s]{1,80}\.[^@\s]{2,24}$')
_HITS = {}
_DUMMY = None


def account_secret():
    """Copilot bearer key. Empty makes dispatch return 503.

    AKAUN_SECRET must be non-empty and distinct from TELEGRAM_BOT_TOKEN.
    The bot token is not a signing fallback.
    """
    akaun = (os.getenv('AKAUN_SECRET') or '').strip()
    if not akaun:
        return ''
    bot = (os.getenv('TELEGRAM_BOT_TOKEN') or '').strip()
    if bot and _same_secret(akaun, bot):
        return ''
    return akaun


def _same_secret(left, right):
    if len(left) != len(right):
        return False
    return hmac.compare_digest(left, right)


def _hos_tidak_awam(host):
    if host in {'localhost', '::1', 'beshare-ipo-bot.onrender.com'} or host.endswith('.localhost'):
        return True
    if ':' in host:
        return True
    parts = host.split('.')
    if len(parts) == 4 and all(part.isdigit() for part in parts):
        return True
    return False


def _asal_https(origin):
    if not isinstance(origin, str) or not origin or '*' in origin or any(char.isspace() for char in origin):
        return False
    parsed = urllib.parse.urlsplit(origin)
    if parsed.scheme != 'https' or parsed.username or parsed.password or parsed.query or parsed.fragment:
        return False
    if parsed.port not in (None, 443):
        return False
    if parsed.path not in ('', '/'):
        return False
    host = parsed.hostname or ''
    if not host or _hos_tidak_awam(host):
        return False
    return origin == f'https://{host}'


def asal_tambahan():
    """Asal tepat dari ACCOUNT_CORS_ORIGINS. Senarai rosak ditolak seluruhnya."""
    raw = os.getenv('ACCOUNT_CORS_ORIGINS') or ''
    if not raw.strip():
        return set()
    items = [part.strip() for part in raw.split(',')]
    if any(not item or not _asal_https(item) for item in items):
        return None
    return set(items)


def cors_origin(origin):
    if origin in ALLOWED_ORIGINS:
        return origin
    extra = asal_tambahan()
    if extra and origin in extra:
        return origin
    return None


def ensure_schema(conn, postgres):
    from pelanggan_kuota import ensure_kuota_schema
    from pelanggan_meja import ensure_meja_schema
    conn.cursor().execute(
        '''
        CREATE TABLE IF NOT EXISTS pelanggan_akaun (
            id TEXT PRIMARY KEY,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            nama_perniagaan TEXT NOT NULL DEFAULT '',
            jualan TEXT NOT NULL DEFAULT '',
            peringkat TEXT NOT NULL DEFAULT '',
            mesej TEXT NOT NULL DEFAULT '[]',
            created_at TEXT NOT NULL
        )
        '''
    )
    from pelanggan_bil import schema as billing_schema
    billing_schema(conn)
    ensure_kuota_schema(conn, postgres)
    ensure_meja_schema(conn, postgres)
    from pelanggan_copilot import ensure_copilot_schema
    ensure_copilot_schema(conn, postgres)


def _placeholder(postgres):
    return '%s' if postgres else '?'


def _hash_password(password, salt=None):
    salt = salt or secrets.token_hex(16)
    digest = hashlib.scrypt(password.encode('utf-8'), salt=bytes.fromhex(salt), n=2**14, r=8, p=1, dklen=32)
    return f'scrypt${salt}${digest.hex()}'


def _dummy_hash():
    global _DUMMY
    if _DUMMY is None:
        _DUMMY = _hash_password('bukan-akaun-beshare')
    return _DUMMY


def _verify_password(password, stored):
    try:
        kind, salt, expected = str(stored).split('$', 2)
        if kind != 'scrypt':
            return False
        digest = hashlib.scrypt(password.encode('utf-8'), salt=bytes.fromhex(salt), n=2**14, r=8, p=1, dklen=32)
        return hmac.compare_digest(digest.hex(), expected)
    except (TypeError, ValueError):
        return False


def issue_token(user_id, secret, now=None):
    exp = int(now if now is not None else time.time()) + SESSION_SECONDS
    payload = f'{user_id}.{exp}'
    sig = hmac.new(str(secret).encode('utf-8'), payload.encode('utf-8'), hashlib.sha256).hexdigest()
    return f'{payload}.{sig}'


def read_token(token, secret, now=None):
    claims = read_session(token, secret, now)
    if not claims:
        return None
    return claims['akaun_id']


def read_session(token, secret, now=None):
    """Sahkan token sedia ada. Pulangkan id dan exp, atau None. Jangan dedahkan rahsia."""
    if not token or not secret or str(token).count('.') != 2:
        return None
    user_id, exp_raw, sig = str(token).split('.')
    payload = f'{user_id}.{exp_raw}'
    expected = hmac.new(str(secret).encode('utf-8'), payload.encode('utf-8'), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, sig):
        return None
    try:
        exp = int(exp_raw)
    except ValueError:
        return None
    if exp < int(now if now is not None else time.time()):
        return None
    if not user_id or len(user_id) > 64:
        return None
    return {'akaun_id': user_id, 'exp': exp}


def _clean_email(value):
    email = str(value or '').strip().lower()
    if not EMAIL_RE.match(email):
        return None
    return email


def _clean_password(value):
    if not isinstance(value, str):
        return None
    if len(value) < 8 or len(value) > 72:
        return None
    return value


def _clean_profile(data):
    if not isinstance(data, dict):
        return None
    nama = str(data.get('nama') or '').replace('\n', ' ')
    nama = ' '.join(nama.split())[:80]
    jualan = str(data.get('jualan') or '').replace('\n', ' ')
    jualan = ' '.join(jualan.split())[:240]
    peringkat = data.get('peringkat')
    if len(nama) < 2 or len(jualan) < 8 or peringkat not in STAGES:
        return None
    mesej = []
    for item in data.get('mesej') or []:
        if not isinstance(item, dict):
            continue
        dari = item.get('dari')
        teks = str(item.get('teks') or '').strip()[:4000]
        if dari not in {'klien', 'penasihat'} or not teks:
            continue
        bersih = {'dari': dari, 'teks': teks}
        langkah = item.get('langkah')
        if isinstance(langkah, list):
            baris = [' '.join(str(line).split())[:240] for line in langkah if str(line).strip()][:5]
            if baris:
                bersih['langkah'] = baris
        if item.get('format') in {'ringkas', 'pelan', 'pemasaran', 'analisis', 'tindakan'}:
            bersih['format'] = item['format']
        if isinstance(item.get('cadangan_id'), str) and re.fullmatch('[a-f0-9]{32}', item['cadangan_id']):
            bersih['cadangan_id'] = item['cadangan_id']
        mesej.append(bersih)
    if not mesej:
        return None
    return {'nama': nama, 'jualan': jualan, 'peringkat': peringkat, 'mesej': mesej[-40:]}


def _bearer(header):
    raw = str(header or '')
    if raw.lower().startswith('bearer '):
        return raw[7:].strip()
    return ''


def _too_many(ip, now):
    stamp = int(now)
    hits = [item for item in _HITS.get(ip, []) if stamp - item < 60]
    if len(hits) >= 8:
        _HITS[ip] = hits
        return True
    hits.append(stamp)
    _HITS[ip] = hits
    return False


def _unique(error):
    text = f'{type(error).__name__} {error}'.lower()
    return 'unique' in text or 'integrity' in text


def _ringkas_konteks(nama, jualan, peringkat):
    """Konteks perniagaan minimum untuk Copilot. Tiada mesej, e-mel, atau hash."""
    nama = ' '.join(str(nama or '').replace('\n', ' ').split())[:80]
    jualan = ' '.join(str(jualan or '').replace('\n', ' ').split())[:240]
    if len(nama) < 2 or len(jualan) < 8 or peringkat not in STAGES:
        return None
    return {'nama': nama, 'jualan': jualan, 'peringkat': peringkat}


def _row_profile(row):
    if not row or not row[0]:
        return None
    try:
        mesej = json.loads(row[3] or '[]')
    except json.JSONDecodeError:
        mesej = []
    profile = _clean_profile({
        'nama': row[0],
        'jualan': row[1],
        'peringkat': row[2],
        'mesej': mesej,
    })
    return profile


def dispatch(method, path, origin, authorization, body, connect, postgres, secret, ip, now=None, kuta_headers=None, query=None, idempotency_key=None):
    if origin and not cors_origin(origin):
        return 403, {'error': 'Permintaan ini tidak diterima.'}
    if not secret:
        return 503, {'error': 'Akaun belum sedia pada pelayan.'}
    stamp = time.time() if now is None else now
    if method == 'OPTIONS':
        return 204, None
    if method == 'POST' and path in {'/api/akaun/daftar', '/api/akaun/masuk'} and _too_many(ip or 'tempatan', stamp):
        return 429, {'error': 'Terlalu banyak percubaan. Tunggu seminit.'}
    try:
        payload = json.loads(body.decode('utf-8') or '{}') if method != 'GET' else {}
    except (UnicodeError, json.JSONDecodeError, AttributeError):
        return 400, {'error': 'Permintaan tidak sah.'}
    if not isinstance(payload, dict):
        return 400, {'error': 'Permintaan tidak sah.'}
    try:
        conn = connect()
    except Exception:
        return 503, {'error': 'Akaun tidak dapat dihubungi. Cuba semula.'}
    ph = _placeholder(postgres)
    try:
        ensure_schema(conn, postgres)
        conn.commit()
        if path.startswith('/api/akaun/copilot/kuota/'):
            from pelanggan_kuota import handle_kuota
            return handle_kuota(
                method, path, authorization, body, conn, postgres, secret, stamp, kuta_headers, read_session,
            )
        if method == 'POST' and path == '/api/akaun/daftar':
            return _daftar(conn, ph, payload, secret, stamp)
        if method == 'POST' and path == '/api/akaun/masuk':
            return _masuk(conn, ph, payload, secret, stamp)
        user_id = read_token(_bearer(authorization), secret, stamp)
        if not user_id:
            return 401, {'error': 'Sila masuk akaun dahulu.'}
        if path == '/api/akaun/bil' or path.startswith('/api/akaun/bil/'):
            from pelanggan_bil import dispatch_billing
            return dispatch_billing(method, path, conn, postgres, user_id, stamp)
        if method == 'GET' and path == '/api/akaun/saya':
            return _saya(conn, ph, user_id)
        if method == 'GET' and path == '/api/akaun/copilot/sesi':
            return _copilot_sesi(conn, ph, read_session(_bearer(authorization), secret, stamp))
        if path.startswith('/api/akaun/copilot/'):
            from pelanggan_copilot import dispatch_copilot
            return dispatch_copilot(method, path, conn, postgres, user_id, payload, stamp)
        if method == 'POST' and path == '/api/akaun/penasihat':
            return _simpan(conn, ph, user_id, payload)
        if path.startswith('/api/akaun/matlamat') or path.startswith('/api/akaun/pelan') or path.startswith('/api/akaun/angka') or path == '/api/akaun/import':
            from pelanggan_meja import dispatch_meja
            return dispatch_meja(
                method, path, conn, postgres, user_id, payload, stamp, query, idempotency_key,
            )
        return 404, {'error': 'Endpoint tidak ditemui.'}
    except Exception:
        return 503, {'error': 'Akaun tidak dapat dihubungi. Cuba semula.'}
    finally:
        conn.close()


def _daftar(conn, ph, payload, secret, now):
    email = _clean_email(payload.get('email'))
    password = _clean_password(payload.get('password'))
    if not email or not password:
        return 400, {'error': 'Guna e-mel yang sah dan kata laluan sekurang-kurangnya 8 aksara.'}
    user_id = secrets.token_hex(16)
    try:
        conn.cursor().execute(
            f'''INSERT INTO pelanggan_akaun
                (id, email, password_hash, nama_perniagaan, jualan, peringkat, mesej, created_at)
                VALUES ({ph}, {ph}, {ph}, '', '', '', '[]', {ph})''',
            (user_id, email, _hash_password(password), time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(now))),
        )
        conn.commit()
    except Exception as error:
        conn.rollback()
        if _unique(error):
            return 409, {'error': 'E-mel ini sudah didaftar. Sila masuk.'}
        raise
    return 200, {'ok': True, 'token': issue_token(user_id, secret, now), 'email': email}


def _masuk(conn, ph, payload, secret, now):
    email = _clean_email(payload.get('email'))
    password = payload.get('password') if isinstance(payload.get('password'), str) else ''
    row = None
    if email:
        cur = conn.cursor()
        cur.execute(f'SELECT id, password_hash FROM pelanggan_akaun WHERE email = {ph}', (email,))
        row = cur.fetchone()
    stored = row[1] if row else _dummy_hash()
    if not email or not password or not row or not _verify_password(password, stored):
        return 401, {'error': 'E-mel atau kata laluan tidak tepat.'}
    return 200, {'ok': True, 'token': issue_token(row[0], secret, now), 'email': email}


def _copilot_sesi(conn, ph, claims):
    if not claims:
        return 401, {'error': 'Sila masuk akaun dahulu.'}
    cur = conn.cursor()
    cur.execute(
        f'SELECT nama_perniagaan, jualan, peringkat FROM pelanggan_akaun WHERE id = {ph}',
        (claims['akaun_id'],),
    )
    row = cur.fetchone()
    if not row:
        return 401, {'error': 'Sila masuk akaun dahulu.'}
    return 200, {
        'ok': True,
        'akaun_id': claims['akaun_id'],
        'exp': claims['exp'],
        'penasihat': _ringkas_konteks(row[0], row[1], row[2]),
    }


def _saya(conn, ph, user_id):
    cur = conn.cursor()
    cur.execute(
        f'''SELECT email, nama_perniagaan, jualan, peringkat, mesej, mesej_versi
            FROM pelanggan_akaun WHERE id = {ph}''',
        (user_id,),
    )
    row = cur.fetchone()
    if not row:
        return 401, {'error': 'Sila masuk akaun dahulu.'}
    return 200, {
        'ok': True,
        'email': row[0],
        'akaun_id': user_id,
        'mesej_versi': row[5] if isinstance(row[5], int) else 1,
        'penasihat': _row_profile(row[1:5]),
    }


def _versi_mesej(value):
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        return False
    return value


def _simpan(conn, ph, user_id, payload):
    profile = _clean_profile(payload.get('penasihat') if isinstance(payload, dict) else None)
    if not profile:
        return 400, {'error': 'Lengkapkan nama, jualan, peringkat, dan sekurang-kurangnya satu mesej.'}
    versi = _versi_mesej(payload.get('versi') if isinstance(payload, dict) else None)
    if versi is False:
        return 400, {'error': 'Permintaan tidak sah.'}
    mesej = json.dumps(profile['mesej'], ensure_ascii=False)
    cur = conn.cursor()
    if versi is None:
        cur.execute(
            f'''UPDATE pelanggan_akaun
                SET nama_perniagaan = {ph}, jualan = {ph}, peringkat = {ph}, mesej = {ph},
                    mesej_versi = COALESCE(mesej_versi, 1) + 1
                WHERE id = {ph}''',
            (profile['nama'], profile['jualan'], profile['peringkat'], mesej, user_id),
        )
        if cur.rowcount != 1:
            return 401, {'error': 'Sila masuk akaun dahulu.'}
    else:
        cur.execute(
            f'''UPDATE pelanggan_akaun
                SET nama_perniagaan = {ph}, jualan = {ph}, peringkat = {ph}, mesej = {ph},
                    mesej_versi = {ph}
                WHERE id = {ph} AND COALESCE(mesej_versi, 1) = {ph}''',
            (profile['nama'], profile['jualan'], profile['peringkat'], mesej, versi + 1, user_id, versi),
        )
        if cur.rowcount != 1:
            cur.execute(
                f'SELECT nama_perniagaan, jualan, peringkat, mesej, mesej_versi FROM pelanggan_akaun WHERE id = {ph}',
                (user_id,),
            )
            ada = cur.fetchone()
            conn.rollback()
            if not ada:
                return 401, {'error': 'Sila masuk akaun dahulu.'}
            return 409, {
                'ok': False,
                'ralat': 'konflik',
                'error': 'Salinan ini sudah berubah. Muat semula.',
                'mesej_versi': ada[4] if isinstance(ada[4], int) else 1,
                'penasihat': _row_profile(ada),
            }
    cur.execute(f'SELECT COALESCE(mesej_versi, 1) FROM pelanggan_akaun WHERE id = {ph}', (user_id,))
    disimpan = cur.fetchone()
    conn.commit()
    return 200, {'ok': True, 'penasihat': profile, 'mesej_versi': disimpan[0]}

