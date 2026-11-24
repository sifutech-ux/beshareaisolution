"""Published campaign pages and merchant-owned enquiries."""
import hashlib
import hmac
import json
import re

import pelanggan_akses as access
import pelanggan_marketing as m
import pelanggan_meja as meja

PUBLIC = '/api/akaun/promosi/'
STATES = {'baru', 'dihubungi', 'tempahan'}
SOURCES = {'google', 'telegram', 'qr', 'facebook', 'instagram', 'threads', 'whatsapp', 'direct', 'other'}
MISSING = 404, {'ok': False, 'error': 'Halaman promosi tidak tersedia.'}


def schema(conn):
    cur = conn.cursor()
    cur.execute("""CREATE TABLE IF NOT EXISTS marketing_promos (
        campaign_id TEXT PRIMARY KEY REFERENCES marketing_campaigns(id) ON DELETE CASCADE,
        details TEXT NOT NULL, published INTEGER NOT NULL, revision INTEGER NOT NULL)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS marketing_enquiries (
        campaign_id TEXT NOT NULL REFERENCES marketing_promos(campaign_id) ON DELETE CASCADE,
        id TEXT NOT NULL, name TEXT NOT NULL, contact TEXT NOT NULL, note TEXT NOT NULL,
        source TEXT NOT NULL, status TEXT NOT NULL, submitted BIGINT NOT NULL, sender TEXT NOT NULL,
        PRIMARY KEY(campaign_id,id))""")
    cur.execute('CREATE INDEX IF NOT EXISTS enquiries_campaign_time ON marketing_enquiries(campaign_id,submitted,id)')
    cur.execute('CREATE INDEX IF NOT EXISTS enquiries_sender ON marketing_enquiries(campaign_id,sender,submitted)')


def read_page(conn, p, key):
    cur = conn.cursor()
    cur.execute(f'SELECT details,published,revision FROM marketing_promos WHERE campaign_id={p}', (key,))
    row = cur.fetchone()
    return {**json.loads(row[0]), 'published': bool(row[1]), 'revision': row[2]} if row else None


def save(conn, p, key, data):
    fields = {'business': 100, 'title': 120, 'area': 120, 'offer': 2000, 'cta': 50}
    m._payload(data, {*fields, 'image', 'published', 'revision'}, fields)
    details = {field: m._text(data[field], limit, True) for field, limit in fields.items()}
    details['image'] = m._url(data.get('image', ''))
    revision = m._version(data.get('revision'))
    if type(data.get('published')) is not bool:
        m._reject('Pilih status halaman.')
    published = data['published']
    current = read_page(conn, p, key)
    if current and all(current[k] == v for k, v in {**details, 'published': published}.items()):
        return 200, {'ok': True, 'page': current}
    if revision != (current['revision'] if current else 0):
        m._reject('Halaman sudah disunting. Muat semula untuk melihat versi terkini.', 'conflict', 409)
    raw = json.dumps(details, ensure_ascii=False)
    cur = conn.cursor()
    if current:
        cur.execute(f'UPDATE marketing_promos SET details={p},published={p},revision=revision+1 WHERE campaign_id={p}', (raw, int(published), key))
    else:
        cur.execute(f'INSERT INTO marketing_promos (details,published,campaign_id,revision) VALUES ({p},{p},{p},1)', (raw, int(published), key))
    return 200, {'ok': True, 'page': read_page(conn, p, key)}


def inbox(conn, p, key, cursor):
    cur = conn.cursor()
    params = [key]
    extra = ''
    if cursor:
        if not re.fullmatch(r'\d{1,13}-[a-f0-9]{32}', cursor):
            m._reject('Halaman senarai tidak sah.')
        ts, last = cursor.split('-')
        extra = f' AND (submitted<{p} OR (submitted={p} AND id<{p}))'
        params.extend([int(ts), int(ts), last])
    cur.execute(f"""SELECT id,name,contact,note,source,status,submitted FROM marketing_enquiries
        WHERE campaign_id={p}{extra} ORDER BY submitted DESC,id DESC LIMIT 41""", params)
    rows = [dict(zip([c[0] for c in cur.description], row)) for row in cur.fetchall()]
    cur.execute(f"""SELECT source,COUNT(*),SUM(CASE WHEN status='tempahan' THEN 1 ELSE 0 END)
        FROM marketing_enquiries WHERE campaign_id={p} GROUP BY source ORDER BY source""", (key,))
    totals = [{'source': s, 'enquiries': n, 'orders': orders} for s, n, orders in cur.fetchall()]
    after = f"{rows[39]['submitted']}-{rows[39]['id']}" if len(rows) > 40 else ''
    return 200, {'ok': True, 'leads': rows[:40], 'after': after, 'totals': totals}


def dispatch(method, suffix, conn, postgres, owner, data):
    parts = suffix.split('/')
    if not m.ID.fullmatch(parts[0]):
        return MISSING
    key = parts[0]
    p = meja._ph(postgres)

    def run():
        if method in {'PUT', 'DELETE'}:
            meja._kunci_akaun(conn, postgres, owner)
        m._owned_campaign(conn, postgres, owner, key)
        if len(parts) == 1:
            if method == 'GET':
                return 200, {'ok': True, 'page': read_page(conn, p, key)}
            if method == 'PUT':
                return save(conn, p, key, data)
        if len(parts) >= 2 and parts[1] == 'enquiries':
            if method == 'GET' and len(parts) in (2, 3):
                return inbox(conn, p, key, parts[2] if len(parts) == 3 else '')
            if method == 'PUT' and len(parts) == 3 and m.ID.fullmatch(parts[2]):
                old = m._choice(data.get('previous'), STATES)
                new = m._choice(data.get('status'), STATES)
                cur = conn.cursor()
                cur.execute(f'UPDATE marketing_enquiries SET status={p} WHERE campaign_id={p} AND id={p} AND status IN ({p},{p})',
                            (new, key, parts[2], old, new))
                if cur.rowcount != 1:
                    m._reject('Rekod telah berubah. Muat semula senarai.', 'conflict', 409)
                return 200, {'ok': True}
        return MISSING
    return meja._dalam(conn, postgres, run)


def public(method, path, conn, postgres, data, stamp, ip, secret):
    parts = path[len(PUBLIC):].split('/')
    key = parts[0]
    is_submit = method == 'POST' and parts[1:] == ['enquiries']
    if not m.ID.fullmatch(key) or not (is_submit or method == 'GET' and len(parts) == 1):
        return MISSING
    p = meja._ph(postgres)

    def run():
        cur = conn.cursor()
        lock = ' FOR UPDATE OF pg' if is_submit and postgres else ''
        cur.execute(f"""SELECT pg.details,c.akaun_id FROM marketing_promos pg
            JOIN marketing_campaigns c ON c.id=pg.campaign_id WHERE pg.campaign_id={p}
            AND pg.published=1 AND c.status<>'archived'{lock}""", (key,))
        row = cur.fetchone()
        if not row or not access.allowed(conn, p, row[1], 'marketing', stamp):
            return MISSING
        if not is_submit:
            return 200, {'ok': True, 'page': json.loads(row[0])}
        name = m._text(data.get('name'), 100, True)
        contact = m._text(data.get('contact'), 160, True)
        if not (re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', contact) or
                re.fullmatch(r'\+?[0-9 ()-]{7,24}', contact) and len(re.sub(r'\D', '', contact)) >= 7):
            m._reject('Masukkan nombor telefon atau e-mel yang sah.')
        note = m._text(data.get('note', ''), 1000)
        rid = m._identifier(data.get('id'))
        if data.get('consent') is not True or data.get('website'):
            m._reject('Semak maklumat dan kebenaran untuk dihubungi.')
        source = m._choice(data.get('source', 'direct'), SOURCES)
        cur.execute(f'SELECT 1 FROM marketing_enquiries WHERE campaign_id={p} AND id={p}', (key, rid))
        if cur.fetchone():
            return 200, {'ok': True}
        day = int(stamp) // 86400
        sender = hmac.new(secret.encode(), f'{day}:{key}:{ip}'.encode(), hashlib.sha256).hexdigest()
        cur.execute(f'SELECT COUNT(*) FROM marketing_enquiries WHERE campaign_id={p} AND sender={p} AND submitted>{p}',
                    (key, sender, int(stamp) - 3600))
        if cur.fetchone()[0] >= 8:
            m._reject('Terlalu banyak pertanyaan. Cuba lagi kemudian.', 'rate_limited', 429)
        cur.execute(f"""INSERT INTO marketing_enquiries (campaign_id,id,name,contact,note,source,status,submitted,sender)
            VALUES ({','.join([p]*9)})""", (key, rid, name, contact, note, source, 'baru', int(stamp), sender))
        return 201, {'ok': True}
    if is_submit:
        return meja._dalam(conn, postgres, run)
    return run()
