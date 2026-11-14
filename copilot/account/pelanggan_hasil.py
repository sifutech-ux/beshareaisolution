"""Saved work and weekly reviews. Owner-scoped, versioned, no external side effects."""
import re
from datetime import timedelta
import pelanggan_meja as meja

PREFIX = '/api/akaun/copilot/hasil'
ID = re.compile(r'^[a-f0-9]{32}$')
CHANNELS = {'WhatsApp', 'Facebook', 'Instagram', 'TikTok', 'Lain'}
STATUSES = {'draf', 'digunakan', 'arkib'}


def ensure_hasil_schema(conn, postgres):
    conn.cursor().execute('''CREATE TABLE IF NOT EXISTS copilot_work (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, jenis TEXT NOT NULL,
        tajuk TEXT NOT NULL, saluran TEXT NOT NULL, status TEXT NOT NULL,
        kandungan TEXT NOT NULL, versi INTEGER NOT NULL, dicipta TEXT NOT NULL, dikemas TEXT NOT NULL)''')
    conn.cursor().execute('CREATE INDEX IF NOT EXISTS copilot_work_owner ON copilot_work (akaun_id, dikemas)')
    conn.cursor().execute('''CREATE TABLE IF NOT EXISTS copilot_review (
        akaun_id TEXT NOT NULL, minggu TEXT NOT NULL, dibuat TEXT NOT NULL,
        hasil TEXT NOT NULL, halangan TEXT NOT NULL, tugasan_id TEXT NOT NULL,
        versi INTEGER NOT NULL, dikemas TEXT NOT NULL, PRIMARY KEY (akaun_id, minggu))''')


def _row(cursor):
    value = cursor.fetchone()
    return dict(zip([d[0] for d in cursor.description], value)) if value else None


def _text(value, limit, required=False):
    if not isinstance(value, str) or len(value) > limit or '\x00' in value:
        raise meja.Tolak(400, {'ok': False, 'error': 'Teks terlalu panjang atau tidak sah.'})
    value = value.strip()
    if required and not value:
        raise meja.Tolak(400, {'ok': False, 'error': 'Lengkapkan maklumat yang diperlukan.'})
    return value


def _version(value):
    if type(value) is not int or value < 0:
        raise meja.Tolak(400, {'ok': False, 'error': 'Versi simpanan tidak sah.'})
    return value


def _draft(conn, postgres, owner, key):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT id,jenis,tajuk,saluran,status,kandungan,versi,dicipta,dikemas FROM copilot_work WHERE id={ph} AND akaun_id={ph}', (key, owner))
    return _row(cur)


def _save_draft(conn, postgres, owner, key, payload, stamp):
    if set(payload) != {'versi', 'jenis', 'tajuk', 'saluran', 'status', 'kandungan'}:
        raise meja.Tolak(400, {'ok': False, 'error': 'Bentuk draf tidak sah.'})
    version = _version(payload.get('versi'))
    data = {k: _text(payload.get(k), limit, True) for k, limit in [('tajuk', 100), ('kandungan', 8000)]}
    if any(not isinstance(payload.get(k), str) for k in ('jenis','saluran','status')) or payload.get('jenis') not in {'pemasaran', 'suara'} or payload.get('saluran') not in CHANNELS or payload.get('status') not in STATUSES:
        raise meja.Tolak(400, {'ok': False, 'error': 'Jenis, saluran atau status tidak sah.'})
    data.update({k: payload[k] for k in ('jenis', 'saluran', 'status')})
    meja._kunci_akaun(conn, postgres, owner)
    current = _draft(conn, postgres, owner, key)
    ph = meja._ph(postgres)
    if current:
        # Retrying an acknowledged or ambiguous save is harmless.
        if all(current[k] == v for k, v in data.items()) and version in {current['versi'], current['versi'] - 1}:
            return 200, {'ok': True, 'draf': current, 'main_semula': True}
        if current['versi'] != version:
            return 409, {'ok': False, 'error': 'Draf telah berubah pada peranti lain. Semak salinan akaun dahulu.', 'draf': current}
        conn.cursor().execute(f'''UPDATE copilot_work SET jenis={ph},tajuk={ph},saluran={ph},status={ph},kandungan={ph},versi=versi+1,dikemas={ph}
            WHERE id={ph} AND akaun_id={ph}''', (data['jenis'],data['tajuk'],data['saluran'],data['status'],data['kandungan'],meja._iso(stamp),key,owner))
    else:
        if version != 0:
            return 404, {'ok': False, 'error': 'Draf tidak ditemui dalam akaun ini.'}
        cur = conn.cursor()
        cur.execute(f'SELECT COUNT(*) FROM copilot_work WHERE akaun_id={ph}', (owner,))
        if cur.fetchone()[0] >= 200:
            return 409, {'ok': False, 'error': 'Koleksi sudah mempunyai 200 draf. Sunting draf sedia ada.'}
        # A client cannot claim another owner's identifier, including on first save.
        cur.execute(f'SELECT id FROM copilot_work WHERE id={ph}', (key,))
        if cur.fetchone():
            return 409, {'ok': False, 'error': 'Pengenal draf telah digunakan. Buka draf baharu.'}
        cur.execute(f'''INSERT INTO copilot_work (id,akaun_id,jenis,tajuk,saluran,status,kandungan,versi,dicipta,dikemas)
            VALUES ({','.join([ph]*10)})''', (key,owner,data['jenis'],data['tajuk'],data['saluran'],data['status'],data['kandungan'],1,meja._iso(stamp),meja._iso(stamp)))
    return 200, {'ok': True, 'draf': _draft(conn, postgres, owner, key)}


def _review(conn, postgres, owner, week):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT minggu,dibuat,hasil,halangan,tugasan_id,versi,dikemas FROM copilot_review
        WHERE akaun_id={ph} AND minggu={ph}''', (owner, week))
    return _row(cur)


def recent_reviews(conn, postgres, owner, limit=4):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT minggu,dibuat,hasil,halangan,tugasan_id,versi,dikemas FROM copilot_review
        WHERE akaun_id={ph} ORDER BY minggu DESC LIMIT {int(limit)}''', (owner,))
    keys = [d[0] for d in cur.description]
    return [dict(zip(keys, row)) for row in cur.fetchall()]


def _save_review(conn, postgres, owner, week, payload, stamp):
    if set(payload) != {'versi', 'dibuat', 'hasil', 'halangan', 'tugasan_id'}:
        raise meja.Tolak(400, {'ok': False, 'error': 'Bentuk semakan tidak sah.'})
    day = meja._tarikh(week)
    today = meja.hari_ini(stamp)
    if not day or day.weekday() != 0 or day > today or day < today - timedelta(days=90):
        raise meja.Tolak(400, {'ok': False, 'error': 'Pilih minggu semasa atau minggu dalam 90 hari lepas.'})
    version = _version(payload.get('versi'))
    data = {k: _text(payload.get(k), 1500, k == 'dibuat') for k in ('dibuat', 'hasil', 'halangan')}
    task = payload.get('tugasan_id')
    if not isinstance(task, str) or (task and not ID.fullmatch(task)):
        raise meja.Tolak(400, {'ok': False, 'error': 'Tugasan tidak sah.'})
    data['tugasan_id'] = task
    meja._kunci_akaun(conn, postgres, owner)
    ph = meja._ph(postgres)
    if task:
        cur = conn.cursor()
        cur.execute(f'''SELECT t.id FROM pelanggan_tugas t JOIN pelanggan_pelan p ON p.id=t.pelan_id
            WHERE t.id={ph} AND t.akaun_id={ph} AND p.akaun_id={ph} AND p.minggu_mula={ph}''', (task,owner,owner,week))
        if not cur.fetchone():
            return 400, {'ok': False, 'error': 'Tugasan mesti milik akaun dan minggu yang dipilih.'}
    current = _review(conn, postgres, owner, week)
    actual = current['versi'] if current else 0
    if current and all(current[k] == v for k, v in data.items()) and version in {actual, actual - 1}:
        return 200, {'ok': True, 'semakan': current, 'main_semula': True}
    if actual != version:
        return 409, {'ok': False, 'error': 'Semakan telah berubah. Draf anda masih ada; muat salinan akaun untuk membandingkannya.', 'semakan': current}
    conn.cursor().execute(f'''INSERT INTO copilot_review (akaun_id,minggu,dibuat,hasil,halangan,tugasan_id,versi,dikemas)
        VALUES ({','.join([ph]*8)}) ON CONFLICT (akaun_id,minggu) DO UPDATE SET dibuat=EXCLUDED.dibuat,
        hasil=EXCLUDED.hasil,halangan=EXCLUDED.halangan,tugasan_id=EXCLUDED.tugasan_id,versi=EXCLUDED.versi,dikemas=EXCLUDED.dikemas''',
        (owner,week,data['dibuat'],data['hasil'],data['halangan'],task,actual+1,meja._iso(stamp)))
    return 200, {'ok': True, 'semakan': _review(conn, postgres, owner, week)}


def dispatch_hasil(method, path, conn, postgres, owner, payload, stamp):
    ph = meja._ph(postgres)
    if method == 'GET' and path == PREFIX + '/draf':
        cur = conn.cursor()
        cur.execute(f'''SELECT id,jenis,tajuk,saluran,status,versi,dikemas,SUBSTR(kandungan,1,160) AS ringkasan
            FROM copilot_work WHERE akaun_id={ph} ORDER BY dikemas DESC,id LIMIT 200''', (owner,))
        keys = [d[0] for d in cur.description]
        return 200, {'ok': True, 'draf': [dict(zip(keys,row)) for row in cur.fetchall()]}
    if method == 'GET' and path == PREFIX + '/semakan':
        return 200, {'ok': True, 'semakan': recent_reviews(conn,postgres,owner,14)}
    prefix = PREFIX + '/draf/'
    if path.startswith(prefix) and ID.fullmatch(path[len(prefix):]):
        key = path[len(prefix):]
        if method == 'GET':
            item = _draft(conn,postgres,owner,key)
            return (200, {'ok': True, 'draf': item}) if item else (404, {'ok': False, 'error': 'Draf tidak ditemui dalam akaun ini.'})
        if method == 'PUT':
            return meja._dalam(conn,postgres,lambda: _save_draft(conn,postgres,owner,key,payload,stamp))
    prefix = PREFIX + '/semakan/'
    if method == 'PUT' and path.startswith(prefix):
        return meja._dalam(conn,postgres,lambda: _save_review(conn,postgres,owner,path[len(prefix):],payload,stamp))
    return 404, {'ok': False, 'error': 'Endpoint tidak ditemui.'}
