"""Signed provider lifecycle callbacks; no account session or provider API calls.

Only Meta's HMAC signed_request adapter is enabled. Threads has separate app
credentials but its lifecycle wire contract still needs provider verification;
the reserved Threads routes fail closed. Minimal receipt/subject fingerprints
prevent a retry or an in-flight old OAuth flow from restoring deleted access.
"""
import base64
import hashlib
import hmac
import html
import json
import os
import re
import secrets
import time
import urllib.parse

import marketing_social as social
import pelanggan_meja as meja

PREFIX = '/api/akaun/copilot/marketing/provider'
STATUS_PATH = PREFIX + '/deletion-status'
MAX_BODY = 16_384
CODE = re.compile(r'^[a-f0-9]{64}$')
ERROR = 'Permintaan platform tidak dapat disahkan.'
REVOKED = 'Akses platform telah dibatalkan. Sambungkan semula jika diperlukan.'
UNCERTAIN = 'Akses dibatalkan semasa penerbitan. Semak platform sebelum membuat siaran baharu.'


class CallbackError(Exception):
    def __init__(self, status=400):
        self.status = status


def is_provider_path(path):
    return path == PREFIX or path.startswith(PREFIX + '/')


def _callback_config(provider):
    # Do not silently apply the Facebook payload contract or secret to Threads.
    if provider != 'meta':
        raise CallbackError(503)
    app_id = os.getenv('MARKETING_META_APP_ID', '').strip()
    secret = os.getenv('MARKETING_META_APP_SECRET', '').strip()
    if not social.PERSON_ID.fullmatch(app_id) or not secret:
        raise CallbackError(503)
    return app_id, secret


def _status_url():
    value = os.getenv('MARKETING_DATA_DELETION_STATUS_URL', '').strip()
    parsed = urllib.parse.urlsplit(value)
    if (not social._public_https(value) or parsed.path != STATUS_PATH
            or parsed.query or parsed.fragment):
        raise CallbackError(503)
    return value


def _decode64(value):
    if not value or not re.fullmatch(r'[A-Za-z0-9_-]+={0,2}', value):
        raise CallbackError()
    try:
        decoded = base64.b64decode(value + '=' * (-len(value) % 4), altchars=b'-_', validate=True)
    except (ValueError, TypeError):
        raise CallbackError() from None
    if base64.urlsafe_b64encode(decoded).decode('ascii').rstrip('=') != value.rstrip('='):
        raise CallbackError()
    return decoded


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise CallbackError()
        result[key] = value
    return result


def verify_signed_form(raw, content_type, provider):
    """Verify before opening the DB. No undocumented freshness window."""
    app_id, secret = _callback_config(provider)
    if (not isinstance(raw, bytes) or not raw or len(raw) > MAX_BODY
            or (content_type or '').split(';', 1)[0].strip().lower() != 'application/x-www-form-urlencoded'):
        raise CallbackError()
    try:
        form = raw.decode('ascii')
        if re.search(r'%(?![A-Fa-f0-9]{2})', form):
            raise CallbackError()
        pairs = urllib.parse.parse_qsl(form, keep_blank_values=True, strict_parsing=True,
                                      encoding='ascii', errors='strict', max_num_fields=2)
        if len(pairs) != 1 or pairs[0][0] != 'signed_request':
            raise CallbackError()
        signed = pairs[0][1]
        if signed.count('.') != 1:
            raise CallbackError()
        encoded_signature, encoded_payload = signed.split('.')
        signature = _decode64(encoded_signature)
        expected = hmac.new(secret.encode('utf-8'), encoded_payload.encode('ascii'), hashlib.sha256).digest()
        if len(signature) != 32 or not hmac.compare_digest(signature, expected):
            raise CallbackError(403)
        payload_bytes = _decode64(encoded_payload)
        if len(payload_bytes) > 8192:
            raise CallbackError()
        payload = json.loads(payload_bytes.decode('utf-8'), object_pairs_hook=_unique_object,
                             parse_constant=lambda _value: (_ for _ in ()).throw(CallbackError()))
        if not isinstance(payload, dict) or len(payload) > 32:
            raise CallbackError()
        algorithm = payload.get('algorithm', 'HMAC-SHA256')
        if not isinstance(algorithm, str) or algorithm.upper() != 'HMAC-SHA256':
            raise CallbackError()
        user_id = social._numeric_id(payload.get('user_id'))
        # Canonical claims also deduplicate harmless base64 padding/key-order
        # variants. Never retain the signed body, user ID, or any token in receipts.
        canonical = json.dumps(payload, sort_keys=True, ensure_ascii=True, separators=(',', ':'))
        receipt = hashlib.sha256((provider + '\0' + app_id + '\0' + canonical).encode('ascii')).hexdigest()
        return app_id, user_id, receipt
    except CallbackError:
        raise
    except (ValueError, TypeError, UnicodeError, social.SocialError, RecursionError):
        raise CallbackError() from None


def _scope(provider, app_id, user_id, postgres):
    ph = meja._ph(postgres)
    platforms = ('threads',) if provider == 'threads' else ('facebook', 'instagram')
    sql = f'provider_app_id={ph} AND provider_user_id={ph} AND platform IN ({",".join([ph] * len(platforms))})'
    return sql, (app_id, user_id, *platforms)


def _remove_owner_grants(conn, postgres, owner, scope, params, stamp):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    owner_scope, values = f'akaun_id={ph} AND {scope}', (owner, *params)
    cur.execute(f'SELECT post_id FROM marketing_post_bindings WHERE {owner_scope}', values)
    posts = [row[0] for row in cur.fetchall()]
    for post_id in posts:
        cur.execute(f'''SELECT status,provider_post_id FROM marketing_posts
                        WHERE id={ph} AND akaun_id={ph}''', (post_id, owner))
        row = cur.fetchone()
        if row:
            status, provider_id = row
            if status == 'publishing' or (provider_id and status not in {'published', 'uncertain'}):
                status, message = 'uncertain', UNCERTAIN
            elif status == 'queued':
                status, message = 'cancelled', REVOKED
            else:
                message = UNCERTAIN if status == 'uncertain' else ''
            cur.execute(f'''UPDATE marketing_posts SET status={ph},provider_post_id='',attempt_id='',
                            last_error={ph},version=version+1,updated_at={ph}
                            WHERE id={ph} AND akaun_id={ph}''',
                        (status, message, meja._iso(stamp), post_id, owner))
        # Removing the durable attempt also invalidates a worker's lease. A
        # request already in flight cannot be recalled; its post stays uncertain.
        cur.execute(f'DELETE FROM marketing_publish_attempts WHERE post_id={ph} AND akaun_id={ph}', (post_id, owner))
    cur.execute(f'DELETE FROM marketing_post_bindings WHERE {owner_scope}', values)
    cur.execute(f'SELECT state_hash FROM marketing_oauth_candidates WHERE {owner_scope}', values)
    states = [row[0] for row in cur.fetchall()]
    for state in states:
        cur.execute(f'DELETE FROM marketing_oauth_states WHERE state_hash={ph} AND akaun_id={ph}', (state, owner))
    cur.execute(f'DELETE FROM marketing_oauth_candidates WHERE {owner_scope}', values)
    cur.execute(f'DELETE FROM marketing_connections WHERE {owner_scope}', values)


def apply_callback(conn, postgres, provider, app_id, user_id, receipt_hash, stamp):
    ph = meja._ph(postgres)
    def remove():
        subject, _revoked_at = social._lock_subject(conn, postgres, provider, app_id, user_id)
        cur = conn.cursor()
        cur.execute(f'SELECT confirmation_code FROM marketing_provider_receipts WHERE receipt_hash={ph}', (receipt_hash,))
        previous = cur.fetchone()
        if previous:
            return 200, {'confirmation_code': previous[0]}
        scope, params = _scope(provider, app_id, user_id, postgres)
        owners = set()
        for table in ('marketing_connections', 'marketing_oauth_candidates', 'marketing_post_bindings'):
            cur.execute(f'SELECT DISTINCT akaun_id FROM {table} WHERE {scope}', params)
            owners.update(row[0] for row in cur.fetchall())
        # Consistent subject -> sorted owner order matches OAuth grant saves.
        for owner in sorted(owners):
            meja._kunci_akaun(conn, postgres, owner)
            _remove_owner_grants(conn, postgres, owner, scope, params, stamp)
        cur.execute(f'''UPDATE marketing_provider_subjects SET revoked_at={ph}
                        WHERE provider={ph} AND app_id={ph} AND subject_hash={ph}''',
                    (max(int(stamp), _revoked_at), provider, app_id, subject))
        code = secrets.token_hex(32)
        cur.execute(f'''INSERT INTO marketing_provider_receipts (receipt_hash,confirmation_code,created_at)
                        VALUES ({ph},{ph},{ph})''', (receipt_hash, code, int(stamp)))
        return 200, {'confirmation_code': code}
    return meja._dalam(conn, postgres, remove)


def _status_code(query):
    if not isinstance(query, str) or len(query) > 128:
        raise CallbackError()
    try:
        pairs = urllib.parse.parse_qsl(query, strict_parsing=True, keep_blank_values=True, max_num_fields=2)
    except ValueError:
        raise CallbackError() from None
    if len(pairs) != 1 or pairs[0][0] != 'code' or not CODE.fullmatch(pairs[0][1]):
        raise CallbackError()
    return pairs[0][1]


def dispatch_provider(method, path, raw, content_type, query, connect, postgres, now=None):
    conn = None
    try:
        stamp = int(time.time() if now is None else now)
        if path == STATUS_PATH:
            if method != 'GET':
                raise CallbackError(405)
            code = _status_code(query)
            verified = None
        else:
            match = re.fullmatch(re.escape(PREFIX) + r'/(meta|threads)/(deauthorize|data-deletion)', path)
            if not match:
                raise CallbackError(404)
            if method != 'POST':
                raise CallbackError(405)
            provider, action = match.groups()
            verified = verify_signed_form(raw, content_type, provider)
            status_url = _status_url() if action == 'data-deletion' else None
        # Authentication and syntax validation are complete before any DB access.
        conn = connect()
        from pelanggan_marketing import ensure_marketing_schema
        ensure_marketing_schema(conn, postgres)
        social.ensure_social_schema(conn, postgres)
        conn.commit()
        if verified is None:
            cur = conn.cursor()
            cur.execute(f'SELECT 1 FROM marketing_provider_receipts WHERE confirmation_code={meja._ph(postgres)}', (code,))
            if not cur.fetchone():
                raise CallbackError(404)
            return 200, {'ok': True, 'status': 'completed'}
        app_id, user_id, receipt = verified
        status, result = apply_callback(conn, postgres, provider, app_id, user_id, receipt, stamp)
        if action == 'deauthorize':
            return status, {'ok': True}
        code = result['confirmation_code']
        return status, {'url': status_url + '?code=' + code, 'confirmation_code': code}
    except CallbackError as error:
        if conn:
            conn.rollback()
        return error.status, {'ok': False, 'error': ERROR}
    except Exception:
        if conn:
            conn.rollback()
        return 503, {'ok': False, 'error': 'Permintaan platform belum dapat diselesaikan. Cuba semula.'}
    finally:
        if conn:
            conn.close()


def status_html(status):
    title = 'Pemadaman data sambungan selesai' if status == 200 else 'Status tidak ditemui'
    message = ('Data sambungan sosial yang berkaitan telah dibuang daripada BeShare AI Marketing. '
               'Kempen dan kandungan yang anda tulis sendiri dikekalkan. '
               'Siaran yang sudah berada pada platform sosial tidak dipadamkan oleh proses ini.') if status == 200 else (
               'Pautan status tidak sah atau tidak tersedia. Sila semak pautan pengesahan anda.')
    return ('<!doctype html><html lang="ms"><meta charset="utf-8">'
            '<meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>' + html.escape(title) + '</title><body><main><h1>' + html.escape(title)
            + '</h1><p>' + html.escape(message) + '</p></main></body></html>').encode('utf-8')
