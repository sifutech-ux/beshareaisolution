"""Product access, operator decisions and one-time account recovery."""
import datetime
import hashlib
import json
import re
import secrets
import urllib.request

PRODUCTS = ('copilot', 'marketing')
DENIED = (402, {'code': 'akses_diperlukan', 'error': 'Akses produk ini belum aktif. Semak bayaran atau hubungi BeShare.'})
VERIFY_URL = 'https://beshareaisolution.com/akaun/sahkan-operasi.php'


def schema(conn):
    cur = conn.cursor()
    cur.execute("""CREATE TABLE IF NOT EXISTS pelanggan_akses (
        owner TEXT NOT NULL REFERENCES pelanggan_akaun(id), product TEXT NOT NULL,
        state TEXT NOT NULL, until_at BIGINT NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 1,
        PRIMARY KEY(owner,product))""")
    cur.execute("""CREATE TABLE IF NOT EXISTS pelanggan_akses_audit (
        id TEXT PRIMARY KEY, owner TEXT NOT NULL, product TEXT NOT NULL, state TEXT NOT NULL,
        until_at BIGINT NOT NULL, actor TEXT NOT NULL, receipt TEXT NOT NULL, at BIGINT NOT NULL)""")
    cur.execute("""CREATE TABLE IF NOT EXISTS pelanggan_pulih (
        owner TEXT PRIMARY KEY REFERENCES pelanggan_akaun(id), digest TEXT UNIQUE,
        expires BIGINT NOT NULL, revoked BIGINT NOT NULL DEFAULT 0)""")


def pending(cur, ph, owner):
    for product in PRODUCTS:
        cur.execute(f"INSERT INTO pelanggan_akses(owner,product,state) VALUES ({ph},{ph},'pending')",
                    (owner, product))


def allowed(conn, ph, owner, product, now):
    cur = conn.cursor()
    cur.execute(f'SELECT state,until_at FROM pelanggan_akses WHERE owner={ph} AND product={ph}', (owner, product))
    row = cur.fetchone()
    return bool(row and (row[0] == 'free' or row[0] == 'paid' and row[1] > int(now)))



def denial(conn, ph, owner, product):
    cur = conn.cursor()
    cur.execute(f'SELECT state FROM pelanggan_akses WHERE owner={ph} AND product={ph}', (owner,product))
    row = cur.fetchone()
    return 402, {**DENIED[1], 'state': 'suspended' if row and row[0]=='suspended' else 'pending'}


def current(conn, ph, claims, lifetime):
    if not claims:
        return None
    cur = conn.cursor()
    cur.execute(f'SELECT revoked FROM pelanggan_pulih WHERE owner={ph}', (claims['akaun_id'],))
    row = cur.fetchone()
    return None if row and claims['exp'] - lifetime < row[0] else claims


def verified(ticket, body):
    if not isinstance(ticket, str) or not re.fullmatch(r'[0-9a-f]{64}', ticket):
        return False
    data = json.dumps({'ticket': ticket, 'hash': hashlib.sha256(body).hexdigest()}).encode()
    req = urllib.request.Request(VERIFY_URL, data=data, headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=5) as result:
        return result.geturl() == VERIFY_URL and result.read(100) == b'{"ok":true}'


def operator(conn, ph, data, body, auth, now):
    if not auth or not auth.startswith('Ticket ') or not verified(auth[7:], body):
        return 403, {'error': 'Pengesahan diperlukan.'}
    cur = conn.cursor()
    action = data.get('operation')
    if action == 'recover':
        cur.execute(f'SELECT id,email FROM pelanggan_akaun WHERE email={ph}', (data['email'],))
        row = cur.fetchone()
        if not row:
            return 200, {'ok': True}
        token = secrets.token_hex(32)
        cur.execute(f"""INSERT INTO pelanggan_pulih(owner,digest,expires) VALUES ({ph},{ph},{ph})
            ON CONFLICT(owner) DO UPDATE SET digest=excluded.digest,expires=excluded.expires""",
                    (row[0], hashlib.sha256(token.encode()).hexdigest(), int(now)+1800))
        conn.commit()
        return 200, {'ok': True, 'token': token, 'email': row[1]}
    if not isinstance(data.get('actor'), int) or data['actor'] < 1:
        return 403, {'error': 'Pentadbir diperlukan.'}
    if action == 'list':
        page = max(1, min(100000, int(data.get('page', 1))))
        term = '%'+str(data.get('q', '')).lower()[:120]+'%'
        cur.execute(f"""SELECT id,email,created_at FROM pelanggan_akaun WHERE LOWER(email) LIKE {ph}
            ORDER BY created_at DESC,id LIMIT 26 OFFSET {ph}""", (term, (page-1)*25))
        rows = cur.fetchall()
        users = []
        for owner, email, date in rows[:25]:
            cur.execute(f'SELECT product,state,until_at,revision FROM pelanggan_akses WHERE owner={ph}', (owner,))
            grants = {p: {'state': s, 'until': u, 'revision': v} for p,s,u,v in cur.fetchall()}
            users.append({'id': owner, 'email': email, 'date': date, 'grants': grants})
        return 200, {'ok': True, 'users': users, 'next': len(rows)>25}
    if action != 'change':
        return 400, {'error': 'Tindakan tidak sah.'}
    owner, product, state = (data.get(k) for k in ('id','product','state'))
    revision = data.get('revision')
    if product not in PRODUCTS or state not in ('paid','free','pending','suspended') or type(revision) is not int:
        return 400, {'error': 'Pilihan akses tidak sah.'}
    receipt, until = str(data.get('receipt','')).strip(), 0
    if state == 'paid':
        try:
            d = datetime.date.fromisoformat(data['until']) + datetime.timedelta(days=1)
            until = int(datetime.datetime.combine(d, datetime.time(), datetime.timezone(datetime.timedelta(hours=8))).timestamp())
            if until <= now or not re.fullmatch(r'in_[A-Za-z0-9]+', receipt): raise ValueError()
        except (KeyError, ValueError, TypeError, OverflowError):
            return 400, {'error': 'Masukkan ID invois Stripe dan tarikh akhir bayaran yang sah.'}
    cur.execute(f'SELECT 1 FROM pelanggan_akaun WHERE id={ph}', (owner,))
    if not cur.fetchone():
        return 404, {'error':'Akaun tidak ditemui.'}
    if revision == 0:
        cur.execute(f"""INSERT INTO pelanggan_akses(owner,product,state,until_at)
            VALUES ({ph},{ph},{ph},{ph}) ON CONFLICT(owner,product) DO NOTHING""", (owner,product,state,until))
    else:
        cur.execute(f"""UPDATE pelanggan_akses SET state={ph},until_at={ph},revision=revision+1
            WHERE owner={ph} AND product={ph} AND revision={ph}""", (state,until,owner,product,revision))
    if cur.rowcount != 1:
        conn.rollback()
        return 409, {'error':'Rekod telah berubah. Muat semula.'}
    cur.execute('INSERT INTO pelanggan_akses_audit VALUES ('+','.join([ph]*8)+')',
                (secrets.token_hex(16),owner,product,state,until,str(data['actor']),receipt if until else '',int(now)))
    conn.commit()
    return 200, {'ok':True}


def reset(conn, ph, data, now, clean, hash_password):
    token, pwd = data.get('token'), clean(data.get('password'))
    if not pwd or not isinstance(token,str) or not re.fullmatch(r'[0-9a-f]{64}', token):
        return 400, {'error':'Semak pautan dan kata laluan baharu.'}
    digest = hashlib.sha256(token.encode()).hexdigest()
    cur = conn.cursor()
    cur.execute(f"""UPDATE pelanggan_pulih SET digest=NULL,expires=0,revoked={ph}
        WHERE digest={ph} AND expires>{ph} RETURNING owner""", (int(now)+1,digest,int(now)))
    row = cur.fetchone()
    if not row:
        conn.rollback()
        return 400, {'error':'Pautan telah digunakan atau tamat. Minta pautan baharu.'}
    cur.execute(f'UPDATE pelanggan_akaun SET password_hash={ph} WHERE id={ph}', (hash_password(pwd),row[0]))
    conn.commit()
    return 200, {'ok':True}


def worker_condition(alias, now):
    # Alias is a code constant, and the cutoff is always an integer.
    if alias not in ('p', 'j'):
        raise ValueError('unknown table')
    return (f"EXISTS (SELECT 1 FROM pelanggan_akses g WHERE g.owner={alias}.akaun_id "
            f"AND g.product='marketing' AND (g.state='free' OR (g.state='paid' AND g.until_at>{int(now)})))")
