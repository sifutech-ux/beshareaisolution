"""Penjaga identiti pangkalan akaun.

Tidak membaca DATABASE_URL sebagai sandaran dan tidak membuka beshare_ipo.db.
Identiti PostgreSQL disahkan sebelum sebarang skema akaun dicipta.
"""
import os
import re
import sqlite3
import tempfile
import urllib.parse

NAME_RE = re.compile(r'^copilot_akaun_[a-z0-9_]{1,40}$')
ROLE_RE = NAME_RE
DENIED_NAMES = frozenset({
    'postgres',
    'template0',
    'template1',
    'template2',
    'beshare_ipo',
    'rdsadmin',
    'supabase_admin',
})
IPO_RELATIONS = (
    'portfolio_records',
    'ipo_analysis_records',
    'trade_events',
    'price_alerts',
    'user_profiles_v2',
)
_QUERY_BENAR = frozenset({'sslmode', 'connect_timeout'})


class PangkalanDitolak(RuntimeError):
    """Konfigurasi atau identiti pangkalan tidak selamat. Tiada skema dicipta."""


def _palsu_selamat(nilai):
    return nilai is False or nilai == 0


def _hak_sempit(baris, bilangan):
    return bool(baris) and len(baris) >= bilangan and all(_palsu_selamat(item) for item in baris[:bilangan])


def _bersih(nilai):
    return (nilai or '').strip()


def _nama_url(url):
    if not url:
        return ''
    return urllib.parse.urlsplit(url).path.lstrip('/').split('/')[0]


def baca_konfigurasi(env=None):
    """Sahkan konfigurasi tanpa membuka sambungan."""
    sumber = os.environ if env is None else env
    url = _bersih(sumber.get('ACCOUNT_DATABASE_URL'))
    name = _bersih(sumber.get('ACCOUNT_DATABASE_NAME'))
    role = _bersih(sumber.get('ACCOUNT_DATABASE_ROLE'))
    if not url or not name or not role:
        raise PangkalanDitolak('Konfigurasi pangkalan akaun tidak lengkap.')
    rendah = url.lower()
    if rendah.startswith(('sqlite', 'file:')) or 'beshare_ipo.db' in rendah:
        raise PangkalanDitolak('SQLite dan fail IPO ditolak.')
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme not in {'postgres', 'postgresql'}:
        raise PangkalanDitolak('Hanya PostgreSQL dibenarkan.')
    if parsed.fragment or not parsed.hostname:
        raise PangkalanDitolak('URL pangkalan tidak lengkap.')
    query = urllib.parse.parse_qs(parsed.query, keep_blank_values=True)
    if set(query) - _QUERY_BENAR:
        raise PangkalanDitolak('Pertanyaan URL pangkalan tidak dibenarkan.')
    dbname = parsed.path.lstrip('/').split('/')[0]
    if not dbname or dbname != name or not parsed.username or parsed.username != role:
        raise PangkalanDitolak('Nama atau peranan URL tidak sepadan.')
    if not NAME_RE.fullmatch(name) or name in DENIED_NAMES or 'beshare_ipo' in name:
        raise PangkalanDitolak('Nama pangkalan akaun tidak dibenarkan.')
    if not ROLE_RE.fullmatch(role) or role in DENIED_NAMES:
        raise PangkalanDitolak('Peranan pangkalan akaun tidak dibenarkan.')
    ipo_name = _bersih(sumber.get('IPO_DATABASE_NAME'))
    if ipo_name and ipo_name == name:
        raise PangkalanDitolak('Pangkalan IPO ditolak.')
    legacy = _bersih(sumber.get('DATABASE_URL'))
    if legacy and (legacy == url or _nama_url(legacy) == name or 'beshare_ipo.db' in legacy.lower() and name in legacy.lower()):
        raise PangkalanDitolak('Pangkalan akaun bertindih dengan DATABASE_URL.')
    return url, name, role


def sahkan_identiti(cursor, name, role):
    """Sahkan current_database dan current_user sebelum sebarang DDL akaun."""
    cursor.execute('SELECT current_database(), current_user')
    identiti = cursor.fetchone()
    if not identiti or len(identiti) < 2:
        raise PangkalanDitolak('Identiti pangkalan tidak dapat dibaca.')
    actual_name, actual_role = identiti[0], identiti[1]
    if actual_name != name or actual_role != role:
        raise PangkalanDitolak('Identiti pangkalan tidak sepadan.')
    if (
        not NAME_RE.fullmatch(str(actual_name))
        or str(actual_name) in DENIED_NAMES
        or 'beshare_ipo' in str(actual_name)
        or not ROLE_RE.fullmatch(str(actual_role))
        or str(actual_role) in DENIED_NAMES
    ):
        raise PangkalanDitolak('Identiti pangkalan tidak dibenarkan.')
    cursor.execute(
        '''SELECT rolsuper, rolcreatedb, rolcreaterole, rolbypassrls, rolreplication
           FROM pg_roles WHERE rolname = current_user'''
    )
    hak = cursor.fetchone()
    if not _hak_sempit(hak, 5):
        raise PangkalanDitolak('Peranan pangkalan terlalu luas.')
    cursor.execute(
        '''
        WITH RECURSIVE keahlian AS (
            SELECT m.roleid AS oid
            FROM pg_auth_members m
            JOIN pg_roles diri ON diri.oid = m.member
            WHERE diri.rolname = current_user
            UNION
            SELECT m.roleid
            FROM pg_auth_members m
            JOIN keahlian k ON m.member = k.oid
        )
        SELECT COALESCE(bool_or(
            r.rolsuper OR r.rolcreatedb OR r.rolcreaterole OR r.rolbypassrls OR r.rolreplication
        ), false)
        FROM keahlian k
        JOIN pg_roles r ON r.oid = k.oid
        '''
    )
    warisan = cursor.fetchone()
    if not warisan or not _palsu_selamat(warisan[0]):
        raise PangkalanDitolak('Peranan mewarisi hak yang terlalu luas.')
    for relation in IPO_RELATIONS:
        cursor.execute('SELECT to_regclass(%s)', (f'public.{relation}',))
        ditemui = cursor.fetchone()
        if not ditemui or ditemui[0]:
            raise PangkalanDitolak('Jadual IPO ditemui. Skema akaun tidak dicipta.')


def _sambung(url):
    import psycopg2
    return psycopg2.connect(url, connect_timeout=5)


def buka(connect=None, env=None):
    """Buka PostgreSQL akaun. Lempar sebelum sambungan jika konfigurasi tidak sah."""
    url, name, role = baca_konfigurasi(env)
    factory = connect or _sambung
    conn = factory(url)
    try:
        cursor = conn.cursor()
        try:
            sahkan_identiti(cursor, name, role)
        finally:
            close = getattr(cursor, 'close', None)
            if close:
                close()
        return conn
    except Exception:
        conn.close()
        raise


def buka_ujian(path):
    """SQLite sementara untuk ujian yang menyatakan laluan ini secara langsung.

    Pelayan produksi tidak memanggil fungsi ini.
    """
    root = os.path.realpath(tempfile.gettempdir())
    target = os.path.realpath(path)
    base = os.path.basename(target)
    if not target.startswith(root + os.sep):
        raise PangkalanDitolak('Ujian SQLite mesti di direktori sementara.')
    if not base.startswith('copilot_akaun_') or not base.endswith('.db') or 'beshare_ipo' in base:
        raise PangkalanDitolak('Nama fail ujian tidak dibenarkan.')
    return sqlite3.connect(target)

