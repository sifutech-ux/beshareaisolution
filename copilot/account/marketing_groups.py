"""Owner-scoped Group Manager and permissioned Telegram distribution.

Separate bot from WhatsApp AI OS. No browser bot tokens, no user-selected API hosts,
and no live sends until a signed-in owner approves a scheduled delivery and the
separately supervised worker is explicitly enabled.
"""
import hashlib
import json
import os
import re
import secrets
import time
import urllib.error
import urllib.request

import pelanggan_meja as meja

ROOT = '/api/akaun/copilot/marketing/groups'
KEY = re.compile(r'^[a-f0-9]{32}$')
CHAT = re.compile(r'^(?:-?[0-9]{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$')
BOT_TOKEN = re.compile(r'^[0-9]{5,15}:[A-Za-z0-9_-]{30,}$')
CODE = re.compile(r'^[A-Za-z0-9_-]{20,64}$')
MAX_GROUPS = 100
MAX_JOBS = 1000
CLAIM_TTL = 15 * 60
STATUS = ('pending', 'verified', 'disabled')
JOB_STATUS = ('queued', 'sending', 'sent', 'failed', 'uncertain', 'cancelled')


class TelegramError(Exception):
    def __init__(self, message, kind='uncertain', retry_after=0):
        super().__init__(message)
        self.kind = kind
        self.retry_after = retry_after


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


def bot_call(method, params=None, transport=None):
    """Allowlisted Telegram Bot API; never echo token or provider URLs in errors."""
    token = os.getenv('MARKETING_TELEGRAM_BOT_TOKEN', '')
    if not BOT_TOKEN.fullmatch(token):
        raise TelegramError('Bot Telegram belum dikonfigurasi.', 'config')
    if method not in {'getMe', 'getChat', 'getChatMember', 'getUpdates', 'sendMessage'}:
        raise TelegramError('Kaedah Telegram tidak dibenarkan.', 'config')
    data = json.dumps(params or {}, ensure_ascii=False).encode('utf-8')
    url = 'https://api.telegram.org/bot' + token + '/' + method
    req = urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json'}, method='POST')
    try:
        if transport is None:
            with urllib.request.build_opener(NoRedirect()).open(req, timeout=12) as response:
                body = response.read(256 * 1024)
        else:
            body = transport(req)
        result = json.loads(body)
    except urllib.error.HTTPError as exc:
        try:
            result = json.loads(exc.read(4096))
        except (ValueError, OSError):
            raise TelegramError('Telegram menolak permintaan tanpa butiran.', 'uncertain') from None
    except (urllib.error.URLError, TimeoutError, OSError, ValueError):
        raise TelegramError('Respons Telegram belum dapat dipastikan.', 'uncertain') from None
    if not isinstance(result, dict) or result.get('ok') is not True:
        code = result.get('error_code') if isinstance(result, dict) else None
        delay = (result.get('parameters') or {}).get('retry_after', 0) if isinstance(result, dict) else 0
        try:
            delay = max(1, min(3600, int(delay)))
        except (TypeError, ValueError):
            delay = 60
        if code == 429:
            raise TelegramError('Had kadar Telegram dicapai.', 'rate_limit', delay)
        if code in {400, 403}:
            raise TelegramError('Bot tidak mempunyai izin atau destinasi tidak sah.', 'rejected')
        raise TelegramError('Telegram belum mengesahkan permintaan.', 'uncertain')
    return result.get('result')


def _ph(postgres):
    return meja._ph(postgres)


def _fail(status, msg):
    return status, {'ok': False, 'error': msg}


def _text(v, limit):
    if not isinstance(v, str) or len(v) > limit or '\x00' in v:
        raise ValueError('Teks tidak sah atau terlalu panjang.')
    return v.strip()


def _row(row):
    if not row:
        return None
    keys = ('id', 'akaun_id', 'platform', 'ownership', 'name', 'chat_ref', 'chat_id',
            'status', 'version', 'created_at', 'updated_at')
    return dict(zip(keys, row))


def _public(row):
    return {k: v for k, v in row.items() if k != 'akaun_id'}


def _read(conn, postgres, owner, gid):
    p = _ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT id,akaun_id,platform,ownership,name,chat_ref,chat_id,status,
                   version,created_at,updated_at FROM marketing_group_destinations
                   WHERE akaun_id={p} AND id={p}''', (owner, gid))
    return _row(cur.fetchone())


def _all(conn, postgres, owner):
    p = _ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT id,akaun_id,platform,ownership,name,chat_ref,chat_id,status,
                   version,created_at,updated_at FROM marketing_group_destinations
                   WHERE akaun_id={p} AND status!='disabled' ORDER BY updated_at DESC LIMIT 100''', (owner,))
    return [_public(_row(row)) for row in cur.fetchall()]


def _jobs(conn, postgres, owner):
    p = _ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT j.id,j.destination_id,d.name,j.body,j.scheduled_at,j.status,
                    j.sent_at,j.last_error,j.created_at FROM marketing_group_jobs j
                    JOIN marketing_group_destinations d ON d.id=j.destination_id AND d.akaun_id=j.akaun_id
                    WHERE j.akaun_id={p} ORDER BY j.created_at DESC LIMIT 50''', (owner,))
    cols = ('id', 'destination_id', 'destination_name', 'body', 'scheduled_at',
            'status', 'sent_at', 'last_error', 'created_at')
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def ensure_schema(conn, postgres):
    c = conn.cursor()
    c.execute('''CREATE TABLE IF NOT EXISTS marketing_group_destinations (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, platform TEXT NOT NULL,
        ownership TEXT NOT NULL, name TEXT NOT NULL, chat_ref TEXT NOT NULL DEFAULT '',
        chat_id TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pending',
        version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
    c.execute('''CREATE UNIQUE INDEX IF NOT EXISTS marketing_group_one_chat
        ON marketing_group_destinations (chat_id) WHERE platform='telegram' AND chat_id!='' AND status='verified' ''')
    c.execute('''CREATE INDEX IF NOT EXISTS marketing_group_owner
        ON marketing_group_destinations (akaun_id,status,updated_at)''')
    c.execute('''CREATE TABLE IF NOT EXISTS marketing_group_claims (
        code_hash TEXT PRIMARY KEY, destination_id TEXT NOT NULL, akaun_id TEXT NOT NULL,
        expires_at BIGINT NOT NULL, consumed_at BIGINT NOT NULL DEFAULT 0)''')
    c.execute('''CREATE INDEX IF NOT EXISTS marketing_group_claim_lookup
        ON marketing_group_claims (destination_id,akaun_id,expires_at)''')
    c.execute('''CREATE TABLE IF NOT EXISTS marketing_group_jobs (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, destination_id TEXT NOT NULL,
        body TEXT NOT NULL, scheduled_at BIGINT NOT NULL, status TEXT NOT NULL,
        sent_at BIGINT NOT NULL DEFAULT 0, provider_message_id TEXT NOT NULL DEFAULT '',
        last_error TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
    c.execute('''CREATE INDEX IF NOT EXISTS marketing_group_jobs_due
        ON marketing_group_jobs (status,scheduled_at)''')
    c.execute('''CREATE INDEX IF NOT EXISTS marketing_group_jobs_owner
        ON marketing_group_jobs (akaun_id,created_at)''')
    c.execute('''CREATE TABLE IF NOT EXISTS marketing_group_telegram_cursor (
        singleton INTEGER PRIMARY KEY, update_id BIGINT NOT NULL)''')
    c.execute('''INSERT INTO marketing_group_telegram_cursor (singleton,update_id)
                 VALUES (1,0) ON CONFLICT (singleton) DO NOTHING''')


def _create(conn, postgres, owner, payload, stamp):
    if not isinstance(payload, dict) or set(payload) != {'id', 'platform', 'ownership', 'name', 'chat_ref', 'approved'}:
        return _fail(400, 'Maklumat kumpulan tidak lengkap.')
    gid = payload['id']
    if not isinstance(gid, str) or not KEY.fullmatch(gid):
        return _fail(400, 'Pengenal kumpulan tidak sah.')
    if payload['platform'] not in ('telegram', 'whatsapp') or payload['ownership'] not in ('owned', 'partner'):
        return _fail(400, 'Platform atau jenis kumpulan tidak disokong.')
    if payload['approved'] is not True:
        return _fail(400, 'Sahkan bahawa promosi dibenarkan oleh pentadbir.')
    try:
        name = _text(payload['name'], 120)
        reference = _text(payload['chat_ref'], 64)
    except ValueError as exc:
        return _fail(400, str(exc))
    if not name or (payload['platform'] == 'telegram' and reference and not CHAT.fullmatch(reference)):
        return _fail(400, 'Nama atau ID Telegram tidak sah.')
    if payload['platform'] == 'whatsapp' and reference:
        return _fail(400, 'ID WhatsApp group tidak diperlukan untuk perkongsian manual.')
    existing = _read(conn, postgres, owner, gid)
    if existing:
        if all(existing[k] == v for k, v in [('name', name), ('chat_ref', reference),
               ('platform', payload['platform']), ('ownership', payload['ownership'])]):
            return 200, {'ok': True, 'group': _public(existing), 'replayed': True}
        return _fail(409, 'Kumpulan ini sudah wujud; sila daftar ID baharu.')
    p = _ph(postgres)
    c = conn.cursor()
    c.execute(f'SELECT COUNT(*) FROM marketing_group_destinations WHERE akaun_id={p} AND status!=\'disabled\'', (owner,))
    if c.fetchone()[0] >= MAX_GROUPS:
        return _fail(429, 'Had kumpulan telah dicapai.')
    status = 'pending' if payload['platform'] == 'telegram' else 'verified'
    now = meja._iso(stamp)
    c.execute(f'''INSERT INTO marketing_group_destinations
       (id,akaun_id,platform,ownership,name,chat_ref,chat_id,status,version,created_at,updated_at)
       VALUES ({','.join([p]*11)})''',
       (gid, owner, payload['platform'], payload['ownership'], name, reference, '', status, 1, now, now))
    return 201, {'ok': True, 'group': _public(_read(conn, postgres, owner, gid))}


def _claim(conn, postgres, owner, gid, stamp, bot):
    row = _read(conn, postgres, owner, gid)
    if not row:
        return _fail(404, 'Kumpulan tidak ditemui.')
    if row['platform'] != 'telegram' or row['status'] == 'disabled':
        return _fail(400, 'Pengesahan hanya untuk Telegram yang aktif.')
    if row['status'] == 'verified':
        return _fail(409, 'Kumpulan sudah disahkan.')
    # One new code invalidates previous pending codes for this destination.
    token = secrets.token_urlsafe(24)
    digest = hashlib.sha256(token.encode()).hexdigest()
    p = _ph(postgres)
    c = conn.cursor()
    c.execute(f'DELETE FROM marketing_group_claims WHERE destination_id={p} AND akaun_id={p}', (gid, owner))
    c.execute(f'''INSERT INTO marketing_group_claims
             (code_hash,destination_id,akaun_id,expires_at,consumed_at)
             VALUES ({','.join([p]*5)})''', (digest, gid, owner, int(stamp) + CLAIM_TTL, 0))
    mode = 'start' if row['chat_ref'] else 'startgroup'
    # For a private Telegram group the admin selects the group directly,
    # without discovering or pasting its numeric chat ID.
    extra = '&admin=manage_chat' if mode == 'startgroup' else ''
    return 200, {'ok': True, 'expires_at': int(stamp) + CLAIM_TTL,
                 'verification_url': 'https://t.me/' + bot['username'] + '?' + mode + '=' + token + extra,
                 'verification_mode': 'group_picker' if mode == 'startgroup' else 'admin_private'}


def _schedule(conn, postgres, owner, payload, stamp):
    # Fail closed: do not accumulate a hidden backlog while publishing is off.
    # Otherwise old queued jobs could suddenly broadcast when the worker is enabled.
    if os.getenv('MARKETING_GROUP_PUBLISH_ENABLED') != '1':
        return _fail(503, 'Penerbitan Telegram belum diaktifkan pada pelayan.')
    if not isinstance(payload, dict) or set(payload) != {'id', 'destination_id', 'body', 'scheduled_at', 'approved'}:
        return _fail(400, 'Maklumat penerbitan tidak sah.')
    if payload['approved'] is not True:
        return _fail(400, 'Sahkan penerbitan terlebih dahulu.')
    jid, gid = payload['id'], payload['destination_id']
    if not isinstance(jid, str) or not KEY.fullmatch(jid) or not isinstance(gid, str) or not KEY.fullmatch(gid):
        return _fail(400, 'Pengenal tidak sah.')
    try:
        body = _text(payload['body'], 4096)
    except ValueError as exc:
        return _fail(400, str(exc))
    when = payload['scheduled_at']
    if not body or type(when) is not int or when < int(stamp) - 5 or when > int(stamp) + 90 * 86400:
        return _fail(400, 'Isi mesej dan tetapkan masa dalam 90 hari.')
    group = _read(conn, postgres, owner, gid)
    if not group:
        return _fail(404, 'Kumpulan tidak ditemui.')
    if group['platform'] != 'telegram' or group['status'] != 'verified':
        return _fail(409, 'Hanya Telegram yang disahkan boleh dijadualkan automatik.')
    p = _ph(postgres)
    c = conn.cursor()
    c.execute(f'''SELECT destination_id,body,scheduled_at,status FROM marketing_group_jobs
                  WHERE id={p} AND akaun_id={p}''', (jid, owner))
    old = c.fetchone()
    if old:
        if tuple(old[:3]) == (gid, body, when):
            return 200, {'ok': True, 'replayed': True, 'status': old[3]}
        return _fail(409, 'ID penghantaran telah digunakan untuk kandungan lain.')
    c.execute(f'SELECT COUNT(*) FROM marketing_group_jobs WHERE akaun_id={p}', (owner,))
    if c.fetchone()[0] >= MAX_JOBS:
        return _fail(429, 'Had rekod penghantaran telah dicapai.')
    # One planned or successful campaign per group in a rolling 24h window.
    c.execute(f'''SELECT 1 FROM marketing_group_jobs WHERE destination_id={p} AND
        ((status IN ('queued','sending','sent','uncertain') AND scheduled_at BETWEEN {p} AND {p}))
        LIMIT 1''', (gid, when - 86399, when + 86399))
    if c.fetchone():
        return _fail(409, 'Kumpulan ini sudah mempunyai penghantaran dalam tempoh 24 jam.')
    now = meja._iso(stamp)
    c.execute(f'''INSERT INTO marketing_group_jobs
                (id,akaun_id,destination_id,body,scheduled_at,status,created_at,updated_at)
                VALUES ({','.join([p]*8)})''', (jid, owner, gid, body, when, 'queued', now, now))
    return 201, {'ok': True, 'status': 'queued'}


def _disable(conn, postgres, owner, gid, stamp):
    row = _read(conn, postgres, owner, gid)
    if not row:
        return _fail(404, 'Kumpulan tidak ditemui.')
    p = _ph(postgres)
    c = conn.cursor()
    c.execute(f'''UPDATE marketing_group_destinations SET status='disabled',version=version+1,updated_at={p}
                   WHERE id={p} AND akaun_id={p}''', (meja._iso(stamp), gid, owner))
    c.execute(f'''UPDATE marketing_group_jobs SET status='cancelled',updated_at={p}
                   WHERE destination_id={p} AND akaun_id={p} AND status='queued' ''',
              (meja._iso(stamp), gid, owner))
    c.execute(f'DELETE FROM marketing_group_claims WHERE destination_id={p} AND akaun_id={p}', (gid, owner))
    return 200, {'ok': True}


def dispatch(method, path, conn, postgres, owner, payload, stamp):
    if method == 'GET' and path == ROOT:
        return 200, {'ok': True, 'groups': _all(conn, postgres, owner), 'jobs': _jobs(conn, postgres, owner),
                     'telegram_configured': bool(BOT_TOKEN.fullmatch(os.getenv('MARKETING_TELEGRAM_BOT_TOKEN',''))),
                     'auto_publish_enabled': os.getenv('MARKETING_GROUP_PUBLISH_ENABLED') == '1'}
    if method == 'POST' and path == ROOT:
        return meja._dalam(conn, postgres, lambda: _create(conn, postgres, owner, payload, stamp))
    if method == 'POST' and path == ROOT + '/jobs':
        return meja._dalam(conn, postgres, lambda: _schedule(conn, postgres, owner, payload, stamp))
    match = re.fullmatch(ROOT + r'/([a-f0-9]{32})(?:/(verify))?', path)
    if not match:
        return _fail(404, 'Endpoint tidak ditemui.')
    gid, verb = match.groups()
    if method == 'DELETE' and not verb:
        return meja._dalam(conn, postgres, lambda: _disable(conn, postgres, owner, gid, stamp))
    if method == 'POST' and verb == 'verify':
        if payload not in ({}, None):
            return _fail(400, 'Permintaan tidak sah.')
        if not BOT_TOKEN.fullmatch(os.getenv('MARKETING_TELEGRAM_BOT_TOKEN', '')):
            return _fail(503, 'Bot Telegram belum dikonfigurasi.')
        try:
            bot = bot_call('getMe')
            if not isinstance(bot, dict) or not isinstance(bot.get('username'), str) or not re.fullmatch(r'[A-Za-z0-9_]{5,32}', bot['username']):
                return _fail(503, 'Identiti bot belum dapat disahkan.')
        except TelegramError:
            return _fail(503, 'Sambungan bot belum tersedia.')
        return meja._dalam(conn, postgres, lambda: _claim(conn, postgres, owner, gid, stamp, bot))
    return _fail(404, 'Endpoint tidak ditemui.')


def approve_group_start(conn, postgres, sender_id, code, bot_id, chat, stamp):
    """Verify a Telegram group only from a genuine /start@bot in that group.

    Telegram supplies the chat ID and sender ID; never trust a frontend claim
    about those identities. A group admin and this bot must both be admins.
    """
    if not CODE.fullmatch(code) or type(sender_id) is not int or type(bot_id) is not int:
        return False
    if not isinstance(chat, dict) or chat.get('type') not in ('group', 'supergroup'):
        return False
    raw_chat_id = chat.get('id')
    if type(raw_chat_id) is not int or raw_chat_id >= 0:
        return False
    chat_id = str(raw_chat_id)
    if not CHAT.fullmatch(chat_id):
        return False
    digest = hashlib.sha256(code.encode()).hexdigest()
    p = _ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT destination_id,akaun_id FROM marketing_group_claims
                   WHERE code_hash={p} AND consumed_at=0 AND expires_at>={p}''',
                (digest, int(stamp)))
    record = cur.fetchone()
    if not record:
        return False
    gid, owner = record
    group = _read(conn, postgres, owner, gid)
    if not group or group['status'] != 'pending' or group['platform'] != 'telegram' or group['chat_ref']:
        return False
    try:
        admin = bot_call('getChatMember', {'chat_id': chat_id, 'user_id': sender_id})
        bot = bot_call('getChatMember', {'chat_id': chat_id, 'user_id': bot_id})
        if not isinstance(admin, dict) or admin.get('status') not in ('creator', 'administrator'):
            return False
        if not isinstance(bot, dict) or bot.get('status') not in ('creator', 'administrator'):
            return False
    except TelegramError:
        return False
    def save():
        meja._kunci_akaun(conn, postgres, owner)
        c = conn.cursor()
        c.execute(f'''SELECT 1 FROM marketing_group_destinations
            WHERE platform='telegram' AND chat_id={p} AND status='verified' AND id!={p} LIMIT 1''',
            (chat_id, gid))
        if c.fetchone():
            return 409, {'ok': False}
        c.execute(f'''UPDATE marketing_group_destinations
            SET chat_id={p},status='verified',version=version+1,updated_at={p}
            WHERE id={p} AND akaun_id={p} AND platform='telegram' AND status='pending' ''',
            (chat_id, meja._iso(stamp), gid, owner))
        if c.rowcount != 1:
            return 409, {'ok': False}
        c.execute(f'''UPDATE marketing_group_claims SET consumed_at={p}
                       WHERE code_hash={p} AND consumed_at=0 AND expires_at>={p}''',
                  (int(stamp), digest, int(stamp)))
        if c.rowcount != 1:
            return 409, {'ok': False}
        return 200, {'ok': True}
    status, _ = meja._dalam(conn, postgres, save)
    return status == 200


def approve_private_start(conn, postgres, sender_id, code, bot_id, stamp):
    """Complete pending claim only when the Telegram /start sender is a group admin."""
    if not CODE.fullmatch(code):
        return False
    digest = hashlib.sha256(code.encode()).hexdigest()
    p = _ph(postgres)
    c = conn.cursor()
    c.execute(f'''SELECT destination_id,akaun_id FROM marketing_group_claims
                  WHERE code_hash={p} AND consumed_at=0 AND expires_at>={p}''', (digest, int(stamp)))
    hit = c.fetchone()
    if not hit:
        return False
    gid, owner = hit
    group = _read(conn, postgres, owner, gid)
    if not group or group['status'] != 'pending':
        return False
    try:
        chat = bot_call('getChat', {'chat_id': group['chat_ref']})
        if not isinstance(chat, dict) or chat.get('type') not in ('group', 'supergroup', 'channel'):
            return False
        chat_id = str(chat['id'])
        member = bot_call('getChatMember', {'chat_id': chat_id, 'user_id': sender_id})
        bot = bot_call('getChatMember', {'chat_id': chat_id, 'user_id': bot_id})
        if not isinstance(member, dict) or member.get('status') not in ('creator', 'administrator'):
            return False
        if not isinstance(bot, dict) or bot.get('status') not in ('creator', 'administrator'):
            return False
        if chat['type'] == 'channel' and bot.get('status') == 'administrator' and bot.get('can_post_messages') is not True:
            return False
    except (TelegramError, KeyError, TypeError, ValueError):
        return False
    def save():
        meja._kunci_akaun(conn, postgres, owner)
        c = conn.cursor()
        c.execute(f'''SELECT 1 FROM marketing_group_destinations
            WHERE platform='telegram' AND chat_id={p} AND status='verified' AND id!={p} LIMIT 1''', (chat_id, gid))
        if c.fetchone():
            return 409, {'ok': False}
        c.execute(f'''UPDATE marketing_group_destinations
            SET chat_id={p},status='verified',version=version+1,updated_at={p}
            WHERE id={p} AND akaun_id={p} AND status='pending' ''',
            (chat_id, meja._iso(stamp), gid, owner))
        if c.rowcount != 1:
            return 409, {'ok': False}
        c.execute(f'''UPDATE marketing_group_claims SET consumed_at={p}
                       WHERE code_hash={p} AND consumed_at=0 AND expires_at>={p}''',
                  (int(stamp), digest, int(stamp)))
        if c.rowcount != 1:
            return 409, {'ok': False}
        return 200, {'ok': True}
    status, _result = meja._dalam(conn, postgres, save)
    return status == 200
