"""Copilot account memory, authoritative context and confirmed atomic actions."""
import json
import re
from datetime import timedelta
from decimal import Decimal
import pelanggan_meja as meja


def ensure_copilot_schema(conn, postgres):
    from pelanggan_hasil import ensure_hasil_schema
    ensure_hasil_schema(conn, postgres)
    cur = conn.cursor()
    cur.execute('''CREATE TABLE IF NOT EXISTS copilot_memory (
        akaun_id TEXT PRIMARY KEY, data_json TEXT NOT NULL, versi INTEGER NOT NULL)''')
    cur.execute('''CREATE TABLE IF NOT EXISTS copilot_action_receipt (
        cadangan_id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, hasil_json TEXT NOT NULL)''')
    cur.execute('''CREATE TABLE IF NOT EXISTS copilot_voice_usage (
        akaun_id TEXT NOT NULL, hari TEXT NOT NULL, jumlah INTEGER NOT NULL,
        PRIMARY KEY (akaun_id, hari))''')


def memory(conn, postgres, owner):
    cur = conn.cursor()
    cur.execute(f'SELECT data_json, versi FROM copilot_memory WHERE akaun_id = {meja._ph(postgres)}', (owner,))
    row = cur.fetchone()
    return {'ok': True, 'ingatan': json.loads(row[0]) if row else {}, 'versi': row[1] if row else 0}


def save_memory(conn, postgres, owner, payload):
    fields = {'produk': 2000, 'pelanggan': 500, 'lokasi': 240, 'saluran': 500, 'bajet': 120, 'matlamat': 500}
    raw = payload.get('ingatan')
    version = meja._versi(payload.get('versi'), 0)
    if not isinstance(raw, dict) or set(raw) - set(fields) or version is None:
        return 400, {'error': 'Butiran ingatan tidak sah.'}
    clean = {}
    for key, limit in fields.items():
        value = raw.get(key, '')
        if not isinstance(value, str) or len(value) > limit:
            return 400, {'error': 'Butiran ingatan terlalu panjang atau tidak sah.'}
        clean[key] = value.strip()
    meja._kunci_akaun(conn, postgres, owner)
    current = memory(conn, postgres, owner)
    if current['versi'] != version:
        return 409, {**current, 'ok': False, 'error': 'Ingatan sudah berubah. Muat semula sebelum menyimpan.'}
    ph = meja._ph(postgres)
    conn.cursor().execute(f'''INSERT INTO copilot_memory (akaun_id, data_json, versi)
        VALUES ({ph}, {ph}, {ph}) ON CONFLICT (akaun_id)
        DO UPDATE SET data_json = EXCLUDED.data_json, versi = EXCLUDED.versi''',
        (owner, json.dumps(clean, ensure_ascii=False), version + 1))
    return 200, {'ok': True, 'ingatan': clean, 'versi': version + 1}


def context(conn, postgres, owner, stamp):
    today = meja.hari_ini(stamp)
    monday = today - timedelta(days=today.weekday())
    start = today - timedelta(days=90)
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT tarikh, jenis, nilai, unit, nota FROM pelanggan_angka
        WHERE akaun_id = {ph} AND status = 'dimasukkan_pengguna'
        AND tarikh >= {ph} AND tarikh <= {ph} ORDER BY tarikh DESC, id''',
        (owner, start.isoformat(), today.isoformat()))
    rows = cur.fetchall()
    def totals(begin, end):
        subset = [r for r in rows if begin <= r[0] <= end and r[3] == 'MYR']
        hasil = [Decimal(r[2]) for r in subset if r[1] == 'hasil']
        kos = [Decimal(r[2]) for r in subset if r[1] == 'kos']
        return {'dari': begin, 'hingga': end, 'bilangan_hasil': len(hasil), 'bilangan_kos': len(kos),
                'hasil': format(sum(hasil), '.2f') if hasil else None,
                'kos': format(sum(kos), '.2f') if kos else None,
                'beza_rekod': format(sum(hasil) - sum(kos), '.2f') if hasil and kos else None}
    _, plan = meja._baca_pelan_http(conn, postgres, owner, {'minggu': monday.isoformat()})
    _, goal = meja._baca_matlamat(conn, postgres, owner)
    from pelanggan_hasil import recent_reviews
    reviews = recent_reviews(conn, postgres, owner)
    return {'semakan_mingguan': reviews, 'nota_semakan': 'Catatan hasil oleh pengguna. Perubahan jualan tidak membuktikan sebab atau keberkesanan sesuatu tindakan.', 'ok': True, 'hari': today.isoformat(), 'minggu_mula': monday.isoformat(),
            'ingatan': memory(conn, postgres, owner), 'matlamat': goal.get('matlamat'), 'pelan': plan.get('pelan'),
            'angka': {'minggu': totals(monday.isoformat(), today.isoformat()),
                      'minggu_lalu': totals((monday-timedelta(days=7)).isoformat(), (monday-timedelta(days=1)).isoformat()),
                      'bulan': totals(today.replace(day=1).isoformat(), today.isoformat()),
                      'tempoh': totals(start.isoformat(), today.isoformat()),
                      'rekod_terkini': [dict(zip(('tarikh', 'jenis', 'nilai', 'unit', 'nota'), r)) for r in rows[:50]],
                      'bilangan_rekod': len(rows), 'senarai_dipotong': len(rows) > 50,
                      'nota': 'Rekod dimasukkan pengguna. Data mungkin tidak lengkap. Beza rekod bukan untung bersih.'}}


def validate_actions(value):
    if not isinstance(value, list) or len(value) > 5:
        raise ValueError('cadangan')
    for item in value:
        if not isinstance(item, dict) or item.get('jenis') not in {'angka', 'tugas'}:
            raise ValueError('jenis')
        if set(item) != {'jenis', 'teks', 'tarikh', 'kategori', 'nilai'}:
            raise ValueError('bentuk')
        if not isinstance(item['teks'], str) or not 2 <= len(item['teks']) <= 160:
            raise ValueError('teks')
        if not meja._tarikh(item['tarikh']):
            raise ValueError('tarikh')
        if item['jenis'] == 'angka':
            if item['kategori'] not in {'hasil', 'kos'} or meja._canon_wang(item['nilai']) is None:
                raise ValueError('nilai')
        elif item['kategori'] != '' or item['nilai'] != '':
            raise ValueError('tugas')
    return value


def actions(conn, postgres, owner, payload, stamp):
    key = payload.get('cadangan_id')
    if payload.get('sahkan') is not True or not isinstance(key, str) or not re.fullmatch('[a-f0-9]{32}', key):
        return 400, {'error': 'Sahkan cadangan dahulu.'}
    ph = meja._ph(postgres)
    meja._kunci_akaun(conn, postgres, owner)
    cur = conn.cursor()
    cur.execute(f'SELECT hasil_json FROM copilot_action_receipt WHERE cadangan_id = {ph} AND akaun_id = {ph}', (key, owner))
    saved = cur.fetchone()
    if saved:
        return 200, {**json.loads(saved[0]), 'main_semula': True}
    cur.execute(f'''SELECT reply_json FROM copilot_usage_reservation
        WHERE id = {ph} AND owner_scope = {ph} AND route = 'copilot_nasihat' AND status = 'settled' ''', (key, owner))
    row = cur.fetchone()
    if not row:
        return 404, {'error': 'Cadangan tidak ditemui dalam akaun ini.'}
    reply = json.loads(row[0])
    proposals = validate_actions(reply.get('cadangan', []))
    if not proposals:
        return 400, {'error': 'Tiada tindakan dalam cadangan ini.'}
    # Never execute a proposal whose relevant account data has changed since generation.
    basis = reply.get('asas', {})
    current = context(conn, postgres, owner, stamp)
    if basis.get('minggu_mula') != current['minggu_mula'] or basis.get('ingatan_versi') != current['ingatan']['versi']:
        return 409, {'error': 'Maklumat sudah berubah. Minta cadangan baharu.'}
    expected = basis.get('pelan_versi')
    actual = current['pelan']['versi'] if current['pelan'] else 0
    if any(p['jenis'] == 'tugas' for p in proposals) and actual != expected:
        return 409, {'error': 'Pelan sudah berubah. Minta cadangan baharu.'}
    result = []
    for proposal in proposals:
        if proposal['jenis'] == 'angka':
            _, item = meja._tambah_angka(conn, postgres, owner, {
                'tarikh': proposal['tarikh'], 'jenis': proposal['kategori'], 'nilai': proposal['nilai'],
                'unit': 'MYR', 'nota': proposal['teks']}, stamp, 'copilot_disahkan')
            result.append({'jenis': 'angka', 'rekod': item['rekod']})
        else:
            if proposal['tarikh'] != current['minggu_mula']:
                raise meja.Tolak(409, {'error': 'Minggu cadangan sudah berlalu. Minta pelan baharu.'})
            _, item = meja._tambah_tugas(conn, postgres, owner, {
                'minggu_mula': proposal['tarikh'], 'teks': proposal['teks'], 'versi': actual}, stamp)
            actual = item['pelan']['versi']
            result.append({'jenis': 'tugas', 'pelan': item['pelan']})
    body = {'ok': True, 'hasil': result, 'cadangan_id': key}
    cur.execute(f'INSERT INTO copilot_action_receipt (cadangan_id, akaun_id, hasil_json) VALUES ({ph}, {ph}, {ph})',
                (key, owner, json.dumps(body)))
    return 200, body


def reserve_voice(conn, postgres, owner, stamp):
    meja._kunci_akaun(conn, postgres, owner)
    ph = meja._ph(postgres)
    day = meja.hari_ini(stamp).isoformat()
    cur = conn.cursor()
    cur.execute(f'SELECT jumlah FROM copilot_voice_usage WHERE akaun_id = {ph} AND hari = {ph}', (owner, day))
    row = cur.fetchone()
    if row and row[0] >= 10:
        return 429, {'error': 'Had 10 sesi suara hari ini telah digunakan.'}
    cur.execute(f'''INSERT INTO copilot_voice_usage (akaun_id, hari, jumlah) VALUES ({ph}, {ph}, 1)
        ON CONFLICT (akaun_id, hari) DO UPDATE SET jumlah = copilot_voice_usage.jumlah + 1''', (owner, day))
    return 200, {'ok': True}


def dispatch_copilot(method, path, conn, postgres, owner, payload, stamp):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT id FROM pelanggan_akaun WHERE id = {ph}', (owner,))
    if not cur.fetchone():
        return 401, {'error': 'Sila masuk akaun dahulu.'}
    if path.startswith('/api/akaun/copilot/hasil/'):
        from pelanggan_hasil import dispatch_hasil
        return dispatch_hasil(method, path, conn, postgres, owner, payload, stamp)
    if method == 'GET' and re.fullmatch(r'/api/akaun/copilot/tindakan/[a-f0-9]{32}', path):
        key = path.rsplit('/', 1)[1]
        cur.execute(f'''SELECT reply_json FROM copilot_usage_reservation WHERE id = {ph}
            AND owner_scope = {ph} AND route = 'copilot_nasihat' AND status = 'settled' ''', (key, owner))
        row = cur.fetchone()
        if not row:
            return 404, {'error': 'Cadangan tidak ditemui.'}
        cur.execute(f'SELECT hasil_json FROM copilot_action_receipt WHERE cadangan_id = {ph} AND akaun_id = {ph}', (key, owner))
        receipt = cur.fetchone()
        return 200, {'ok': True, 'cadangan': json.loads(row[0]).get('cadangan', []), 'disimpan': bool(receipt)}
    if method == 'GET' and path == '/api/akaun/copilot/ingatan':
        return 200, memory(conn, postgres, owner)
    if method == 'GET' and path == '/api/akaun/copilot/konteks':
        return 200, context(conn, postgres, owner, stamp)
    work = None
    if method == 'PUT' and path == '/api/akaun/copilot/ingatan':
        work = lambda: save_memory(conn, postgres, owner, payload)
    if method == 'POST' and path == '/api/akaun/copilot/tindakan':
        work = lambda: actions(conn, postgres, owner, payload, stamp)
    if method == 'POST' and path == '/api/akaun/copilot/suara/rezab':
        work = lambda: reserve_voice(conn, postgres, owner, stamp)
    if work:
        return meja._dalam(conn, postgres, work)
    return 404, {'error': 'Endpoint tidak ditemui.'}
