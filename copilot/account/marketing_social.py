"""Owner-scoped social OAuth and publishing primitives.

No provider credentials are accepted from a browser. Provider tokens live only
as Fernet ciphertext in the dedicated account database. Network requests use a
small fixed host allowlist and never follow redirects.
"""
import hashlib
import hmac
import ipaddress
import json
import os
import re
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

import pelanggan_meja as meja

PREFIX = '/api/akaun/copilot/marketing/connections'
PLATFORMS = ('facebook', 'instagram', 'threads')
LABELS = {'facebook': 'Facebook Page', 'instagram': 'Instagram Professional', 'threads': 'Threads'}
HOSTS = frozenset({'graph.facebook.com', 'graph-video.facebook.com', 'graph.threads.com'})
ID = re.compile(r'^[a-f0-9]{32}$')
PROVIDER_ID = re.compile(r'^[0-9]{1,40}(?:_[0-9]{1,40})?$')
STATE = re.compile(r'^[A-Za-z0-9_-]{40,100}$')
VERSION = re.compile(r'^v[0-9]{1,3}\.[0-9]{1,2}$')
FORMATS = {
    'facebook': {'text', 'link', 'image', 'video'},
    'instagram': {'image', 'video', 'reel'},
    'threads': {'text', 'link', 'image', 'video'},
}
SCOPES = {
    'facebook': ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'publish_video'],
    'instagram': ['pages_show_list', 'pages_read_engagement', 'instagram_basic', 'instagram_content_publish'],
    'threads': ['threads_basic', 'threads_content_publish'],
}


class SocialError(Exception):
    def __init__(self, message='Platform tidak dapat dihubungi. Cuba sambungkan semula.',
                 status=502, kind='provider', retryable=False, ambiguous=False):
        super().__init__(message)
        self.status = status
        self.kind = kind
        self.retryable = retryable
        self.ambiguous = ambiguous


def ensure_social_schema(conn, postgres):
    statements = [
        '''CREATE TABLE IF NOT EXISTS marketing_connections (
           id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, platform TEXT NOT NULL,
           account_id TEXT NOT NULL, account_name TEXT NOT NULL, page_id TEXT NOT NULL,
           token_cipher TEXT NOT NULL, token_expires_at BIGINT NOT NULL DEFAULT 0,
           scopes_json TEXT NOT NULL, status TEXT NOT NULL,
           created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
           UNIQUE (akaun_id, platform))''',
        '''CREATE TABLE IF NOT EXISTS marketing_oauth_states (
           state_hash TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, platform TEXT NOT NULL,
           expires_at BIGINT NOT NULL, used_at BIGINT NOT NULL DEFAULT 0,
           created_at BIGINT NOT NULL)''',
        '''CREATE TABLE IF NOT EXISTS marketing_oauth_candidates (
           id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, state_hash TEXT NOT NULL,
           platform TEXT NOT NULL, account_id TEXT NOT NULL, account_name TEXT NOT NULL,
           page_id TEXT NOT NULL, token_cipher TEXT NOT NULL,
           token_expires_at BIGINT NOT NULL, scopes_json TEXT NOT NULL,
           expires_at BIGINT NOT NULL)''',
        '''CREATE TABLE IF NOT EXISTS marketing_post_bindings (
           post_id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, connection_id TEXT NOT NULL,
           account_id TEXT NOT NULL, platform TEXT NOT NULL, created_at TEXT NOT NULL)''',
        '''CREATE TABLE IF NOT EXISTS marketing_publish_attempts (
           id TEXT PRIMARY KEY, post_id TEXT NOT NULL, akaun_id TEXT NOT NULL,
           stage TEXT NOT NULL, container_id TEXT NOT NULL DEFAULT '',
           provider_post_id TEXT NOT NULL DEFAULT '', retry_count INTEGER NOT NULL DEFAULT 0,
           available_at BIGINT NOT NULL, lease_until BIGINT NOT NULL,
           worker_id TEXT NOT NULL, last_error TEXT NOT NULL DEFAULT '',
           created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''',
        'CREATE INDEX IF NOT EXISTS marketing_oauth_owner ON marketing_oauth_states (akaun_id, created_at)',
        'CREATE INDEX IF NOT EXISTS marketing_candidate_owner ON marketing_oauth_candidates (akaun_id, expires_at)',
        'CREATE INDEX IF NOT EXISTS marketing_attempt_due ON marketing_publish_attempts (available_at, lease_until)',
    ]
    for sql in statements:
        conn.cursor().execute(sql)


def _row(cur):
    row = cur.fetchone()
    return dict(zip([col[0] for col in cur.description], row)) if row else None


def _rows(cur):
    fields = [col[0] for col in cur.description]
    return [dict(zip(fields, row)) for row in cur.fetchall()]


def _cipher():
    try:
        from cryptography.fernet import Fernet
        key = os.environ.get('MARKETING_TOKEN_KEY', '').strip()
        if not key:
            raise ValueError('missing')
        return Fernet(key.encode('ascii'))
    except (ImportError, ValueError, TypeError, UnicodeError):
        raise SocialError('Sambungan sosial belum dikonfigurasi pada pelayan.', 503, 'configuration') from None


def _encrypt(token):
    if not isinstance(token, str) or not token or len(token) > 16000:
        raise SocialError('Platform tidak memulangkan akses yang sah.')
    return _cipher().encrypt(token.encode('utf-8')).decode('ascii')


def _decrypt(value):
    try:
        return _cipher().decrypt(str(value).encode('ascii')).decode('utf-8')
    except SocialError:
        raise
    except Exception:
        raise SocialError('Akses tersimpan tidak dapat dibaca. Sambungkan akaun semula.', 409, 'authorization') from None


def _public_https(value):
    if not isinstance(value, str) or len(value) > 2048 or any(c.isspace() for c in value):
        return False
    try:
        parsed = urllib.parse.urlsplit(value)
        host = (parsed.hostname or '').lower()
        if parsed.scheme != 'https' or not host or parsed.username or parsed.password or parsed.port not in (None, 443):
            return False
        if host in {'localhost', 'metadata.google.internal'} or host.endswith(('.localhost', '.local', '.internal')) or '.' not in host:
            return False
        try:
            ipaddress.ip_address(host)
            return False  # URL hostnames only; no private or numeric IP literals.
        except ValueError:
            pass
        return not re.fullmatch(r'[0-9.]+', host)
    except (ValueError, TypeError):
        return False


def _config(platform):
    if platform not in PLATFORMS:
        raise SocialError('Platform tidak disokong.', 400, 'validation')
    stem = 'MARKETING_THREADS_' if platform == 'threads' else 'MARKETING_META_'
    cfg = {
        'app_id': os.getenv(stem + 'APP_ID', '').strip(),
        'app_secret': os.getenv(stem + 'APP_SECRET', '').strip(),
        'redirect_uri': os.getenv('MARKETING_OAUTH_REDIRECT_URI', '').strip(),
        'version': os.getenv('MARKETING_THREADS_API_VERSION' if platform == 'threads' else 'MARKETING_META_API_VERSION',
                             'v1.0' if platform == 'threads' else 'v27.0').strip(),
        'login_config_id': os.getenv('MARKETING_META_LOGIN_CONFIG_ID', '').strip() if platform != 'threads' else '',
    }
    if not cfg['app_id'].isdigit() or not cfg['app_secret'] or not VERSION.fullmatch(cfg['version']):
        raise SocialError('Aplikasi platform belum dikonfigurasi pada pelayan.', 503, 'configuration')
    if cfg['login_config_id'] and not cfg['login_config_id'].isdigit():
        raise SocialError('Konfigurasi Facebook Login tidak sah.', 503, 'configuration')
    parsed = urllib.parse.urlsplit(cfg['redirect_uri'])
    if (not _public_https(cfg['redirect_uri']) or parsed.path != '/marketing/connect.html'
            or parsed.query or parsed.fragment):
        raise SocialError('Alamat pulang OAuth belum dikonfigurasi dengan sah.', 503, 'configuration')
    _cipher()
    return cfg


def _enabled():
    return os.getenv('MARKETING_PUBLISH_ENABLED', '').strip() == '1'


def get_connections(conn, postgres, owner):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT id,platform,account_id,account_name,token_expires_at,status,updated_at
                    FROM marketing_connections WHERE akaun_id={ph}''', (owner,))
    saved = {item['platform']: item for item in _rows(cur)}
    now = int(time.time())
    answer = []
    for platform in PLATFORMS:
        configured = True
        try:
            _config(platform)
        except SocialError:
            configured = False
        row = saved.get(platform)
        connected = bool(row and row['status'] == 'connected')
        expired = bool(connected and row['token_expires_at'] and row['token_expires_at'] <= now)
        status = 'expired' if expired else ('connected' if connected else ('not_connected' if configured else 'not_configured'))
        if row and row['status'] == 'reconnect_required':
            status = 'reconnect_required'
        message = {
            'connected': 'Akaun disambungkan.' if _enabled() else 'Akaun disambungkan. Penerbitan pelayan belum diaktifkan.',
            'expired': 'Akses telah luput. Sambungkan semula.',
            'reconnect_required': 'Platform meminta sambungan semula.',
            'not_connected': 'Pilih akaun melalui sambungan rasmi platform.',
            'not_configured': 'Aplikasi platform belum dikonfigurasi pada pelayan.',
        }[status]
        answer.append({
            'provider': platform, 'platform': platform, 'label': LABELS[platform],
            'configured': configured, 'status': status, 'connected': connected and not expired,
            'connectable': configured, 'publishable': configured and connected and not expired and _enabled(),
            'formats': sorted(FORMATS[platform]), 'message': message,
            'accounts': ([{'id': row['id'], 'name': row['account_name'], 'platform': platform,
                           'account_id': row['account_id']}] if row and connected else []),
        })
    return answer


def capabilities(conn=None, postgres=False, owner=None):
    providers = get_connections(conn, postgres, owner) if conn is not None and owner else []
    return {'auto_publish': _enabled(), 'worker_enabled': _enabled(), 'worker_configured': _enabled(),
            'publishing_enabled': _enabled(), 'worker_health': 'not_checked', 'providers': providers,
            'ads': {'meta': False, 'google': False}}


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def _provider_error(body, status, method):
    data = body.get('error', body) if isinstance(body, dict) else {}
    code = data.get('code') if isinstance(data, dict) else None
    if code in {3, 10, 190, 102} or (isinstance(code, int) and 200 <= code <= 299) or status in {401, 403}:
        return SocialError('Kebenaran platform tidak mencukupi atau telah luput. Sambungkan semula.',
                           409, 'authorization')
    if code in {4, 17, 32, 613, 80004} or status == 429:
        return SocialError('Had permintaan platform dicapai. Sistem akan cuba semula secara terkawal.',
                           429, 'rate_limit', retryable=True)
    ambiguous = method == 'POST' and status >= 500
    return SocialError('Platform menolak permintaan. Semak format, media dan kebenaran akaun.',
                       502, 'provider', ambiguous=ambiguous)


def provider_call(method, url, params=None, token=None, transport=None):
    """Only provider endpoints, bounded response, no redirects or error-body leaks."""
    parsed = urllib.parse.urlsplit(url)
    if (method not in {'GET', 'POST', 'DELETE'} or parsed.scheme != 'https'
            or parsed.hostname not in HOSTS or parsed.port not in (None, 443)
            or parsed.username or parsed.password or parsed.query or parsed.fragment):
        raise SocialError('Destinasi platform tidak dibenarkan.', 400, 'validation')
    params = dict(params or {})
    if token and parsed.hostname in {'graph.facebook.com', 'graph-video.facebook.com'}:
        secret = os.getenv('MARKETING_META_APP_SECRET', '').strip()
        if secret:
            params['appsecret_proof'] = hmac.new(secret.encode('utf-8'), token.encode('utf-8'), hashlib.sha256).hexdigest()
    if transport is not None:
        try:
            result = transport(method, url, params, token)
        except SocialError:
            raise
        except Exception:
            raise SocialError('Keputusan permintaan platform tidak dapat dipastikan.',
                               502, 'network', ambiguous=method == 'POST') from None
        if not isinstance(result, dict):
            raise SocialError('Balasan platform tidak sah.', 502, 'network', ambiguous=method == 'POST')
        if result.get('error'):
            raise _provider_error(result, 400, method)
        return result
    encoded = urllib.parse.urlencode(params).encode('utf-8')
    headers = {'Accept': 'application/json', 'User-Agent': 'BeShare-Marketing/1.0'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    if method == 'GET':
        request_url = url + ('?' + encoded.decode('ascii') if encoded else '')
        data = None
    else:
        request_url, data = url, encoded
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    request = urllib.request.Request(request_url, data=data, method=method, headers=headers)
    try:
        with urllib.request.build_opener(_NoRedirect).open(request, timeout=20) as response:
            raw = response.read(2_000_001)
            if len(raw) > 2_000_000:
                raise ValueError('oversize')
            result = json.loads(raw.decode('utf-8'))
            if not isinstance(result, dict):
                raise ValueError('shape')
            if result.get('error'):
                raise _provider_error(result, response.status, method)
            return result
    except urllib.error.HTTPError as error:
        try:
            body = json.loads(error.read(100_000).decode('utf-8'))
        except Exception:
            body = {}
        raise _provider_error(body, error.code, method) from None
    except SocialError:
        raise
    except Exception:
        # A lost POST response may follow a successful publication. Never retry it blindly.
        raise SocialError('Keputusan permintaan platform tidak dapat dipastikan.',
                           502, 'network', ambiguous=method == 'POST') from None


def _base(platform, cfg):
    return ('https://graph.threads.com/' if platform == 'threads' else 'https://graph.facebook.com/') + cfg['version']


def _provider_id(value):
    value = str(value or '')
    if not PROVIDER_ID.fullmatch(value):
        raise SocialError('Pengenal akaun daripada platform tidak sah.')
    return value


def _begin(conn, postgres, owner, platform, stamp):
    cfg = _config(platform)
    state = secrets.token_urlsafe(32)
    state_hash = hashlib.sha256(state.encode('ascii')).hexdigest()
    ph = meja._ph(postgres)
    def save():
        meja._kunci_akaun(conn, postgres, owner)
        cur = conn.cursor()
        cur.execute(f'DELETE FROM marketing_oauth_states WHERE akaun_id={ph} AND expires_at<{ph}', (owner, int(stamp)))
        cur.execute(f'DELETE FROM marketing_oauth_candidates WHERE akaun_id={ph} AND expires_at<{ph}', (owner, int(stamp)))
        cur.execute(f'SELECT COUNT(*) FROM marketing_oauth_states WHERE akaun_id={ph} AND created_at>{ph}', (owner, int(stamp) - 60))
        if cur.fetchone()[0] >= 5:
            raise SocialError('Terlalu banyak cubaan sambungan. Cuba lagi sebentar nanti.', 429, 'rate_limit')
        cur.execute(f'''INSERT INTO marketing_oauth_states
                       (state_hash,akaun_id,platform,expires_at,used_at,created_at)
                       VALUES ({','.join([ph] * 6)})''',
                    (state_hash, owner, platform, int(stamp) + 600, 0, int(stamp)))
        return 200, {'ok': True}
    meja._dalam(conn, postgres, save)
    base = 'https://threads.com/oauth/authorize' if platform == 'threads' else f'https://www.facebook.com/{cfg["version"]}/dialog/oauth'
    query = {'client_id': cfg['app_id'], 'redirect_uri': cfg['redirect_uri'],
             'scope': ','.join(SCOPES[platform]), 'response_type': 'code', 'state': state}
    if cfg['login_config_id']:
        query.pop('scope')
        query['config_id'] = cfg['login_config_id']
    return 200, {'ok': True, 'platform': platform, 'authorization_url': base + '?' + urllib.parse.urlencode(query)}


def _consume_state(conn, postgres, owner, state, platform, stamp):
    if not isinstance(state, str) or not STATE.fullmatch(state):
        raise SocialError('Sesi sambungan tidak sah. Mulakan sambungan semula.', 400, 'validation')
    digest = hashlib.sha256(state.encode('ascii')).hexdigest()
    ph = meja._ph(postgres)
    selected = {}
    def consume():
        meja._kunci_akaun(conn, postgres, owner)
        cur = conn.cursor()
        cur.execute(f'''SELECT platform,expires_at,used_at FROM marketing_oauth_states
                        WHERE state_hash={ph} AND akaun_id={ph}''', (digest, owner))
        row = _row(cur)
        if not row or row['used_at'] or row['expires_at'] <= int(stamp) or (platform and row['platform'] != platform):
            raise SocialError('Sesi sambungan telah digunakan atau luput. Mulakan sambungan semula.', 409, 'validation')
        cur.execute(f'''UPDATE marketing_oauth_states SET used_at={ph}
                        WHERE state_hash={ph} AND akaun_id={ph} AND used_at=0''', (int(stamp), digest, owner))
        selected['platform'] = row['platform']
        return 200, {'ok': True}
    meja._dalam(conn, postgres, consume)
    return selected['platform'], digest


def _save_connection(conn, postgres, owner, candidate, stamp):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT id FROM marketing_connections WHERE akaun_id={ph} AND platform={ph}', (owner, candidate['platform']))
    old = cur.fetchone()
    key = old[0] if old else secrets.token_hex(16)
    fields = ('id', 'akaun_id', 'platform', 'account_id', 'account_name', 'page_id', 'token_cipher',
              'token_expires_at', 'scopes_json', 'status', 'created_at', 'updated_at')
    values = (key, owner, candidate['platform'], candidate['account_id'], candidate['account_name'],
              candidate['page_id'], candidate['token_cipher'], candidate['token_expires_at'],
              candidate['scopes_json'], 'connected', meja._iso(stamp), meja._iso(stamp))
    updates = ','.join(f'{name}=EXCLUDED.{name}' for name in fields if name not in {'id', 'akaun_id', 'platform', 'created_at'})
    cur.execute(f'''INSERT INTO marketing_connections ({','.join(fields)}) VALUES ({','.join([ph] * len(fields))})
                    ON CONFLICT (akaun_id,platform) DO UPDATE SET {updates}''', values)
    return {'id': key, 'name': candidate['account_name'], 'account_id': candidate['account_id'], 'platform': candidate['platform']}


def _complete(conn, postgres, owner, payload, platform, stamp, transport=None):
    if not isinstance(payload, dict) or set(payload) != {'code', 'state'}:
        raise SocialError('Maklumat sambungan tidak sah.', 400, 'validation')
    code = payload['code']
    if not isinstance(code, str) or not 4 <= len(code) <= 8000 or any(c.isspace() for c in code):
        raise SocialError('Kod sambungan tidak sah.', 400, 'validation')
    platform, digest = _consume_state(conn, postgres, owner, payload['state'], platform, stamp)
    cfg = _config(platform)
    if platform == 'threads':
        result = provider_call('POST', 'https://graph.threads.com/oauth/access_token', {
            'client_id': cfg['app_id'], 'client_secret': cfg['app_secret'], 'redirect_uri': cfg['redirect_uri'],
            'code': code, 'grant_type': 'authorization_code'}, transport=transport)
        short = result.get('access_token')
        _encrypt(short)  # Validate before use; short token is never stored.
        long = provider_call('GET', 'https://graph.threads.com/access_token', {
            'grant_type': 'th_exchange_token', 'client_secret': cfg['app_secret'], 'access_token': short}, transport=transport)
        token = long.get('access_token')
        cipher = _encrypt(token)
        # A successful exchange needs only threads_basic; it does not prove the
        # publishing grant. Inspect the token with an app token before saving.
        app_result = provider_call('GET', 'https://graph.threads.com/oauth/access_token', {
            'grant_type': 'client_credentials', 'client_id': cfg['app_id'],
            'client_secret': cfg['app_secret']}, transport=transport)
        app_token = app_result.get('access_token')
        _encrypt(app_token)
        debug_result = provider_call('GET', _base(platform, cfg) + '/debug_token',
                                     {'input_token': token}, app_token, transport)
        debug = debug_result.get('data')
        if not isinstance(debug, dict) or debug.get('is_valid') is not True or str(debug.get('app_id')) != cfg['app_id']:
            raise SocialError('Kebenaran akaun Threads tidak dapat disahkan. Sambungkan semula.', 409, 'authorization')
        scopes = debug.get('scopes')
        if (not isinstance(scopes, list) or not all(isinstance(scope, str) for scope in scopes)
                or not set(SCOPES[platform]).issubset(scopes)):
            raise SocialError('Kebenaran menerbitkan Threads belum diberikan. Sambungkan semula dan benarkan penerbitan.', 409, 'authorization')
        profile = provider_call('GET', _base(platform, cfg) + '/me', {'fields': 'id,username'}, token, transport)
        account = _provider_id(profile.get('id') or result.get('user_id'))
        if str(debug.get('user_id')) != account:
            raise SocialError('Identiti akaun Threads tidak sepadan.', 409, 'authorization')
        expiry = int(stamp) + min(max(int(long.get('expires_in') or 3600), 60), 90 * 86400)
        for name in ('expires_at', 'data_access_expires_at'):
            known_expiry = int(debug.get(name) or 0)
            if known_expiry > 0:
                expiry = min(expiry, known_expiry)
        if expiry <= int(stamp):
            raise SocialError('Kebenaran akaun Threads telah luput. Sambungkan semula.', 409, 'authorization')
        candidate = {'platform': platform, 'account_id': account, 'account_name': str(profile.get('username') or 'Threads')[:160],
                     'page_id': '', 'token_cipher': cipher, 'token_expires_at': expiry, 'scopes_json': json.dumps(scopes)}
        selected = {}
        def save():
            meja._kunci_akaun(conn, postgres, owner)
            selected.update(_save_connection(conn, postgres, owner, candidate, stamp))
            return 200, {'ok': True}
        meja._dalam(conn, postgres, save)
        return 200, {'ok': True, 'platform': platform, 'selection_required': False, 'account': selected}
    base = _base(platform, cfg)
    short_result = provider_call('GET', base + '/oauth/access_token', {
        'client_id': cfg['app_id'], 'client_secret': cfg['app_secret'], 'redirect_uri': cfg['redirect_uri'], 'code': code}, transport=transport)
    short = short_result.get('access_token')
    _encrypt(short)
    long_result = provider_call('GET', base + '/oauth/access_token', {
        'grant_type': 'fb_exchange_token', 'client_id': cfg['app_id'], 'client_secret': cfg['app_secret'],
        'fb_exchange_token': short}, transport=transport)
    user_token = long_result.get('access_token')
    _encrypt(user_token)
    perms = provider_call('GET', base + '/me/permissions', {}, user_token, transport)
    scopes = [item.get('permission') for item in perms.get('data', [])
              if isinstance(item, dict) and item.get('status') == 'granted' and isinstance(item.get('permission'), str)]
    required = set(SCOPES[platform]) - {'publish_video'}
    if not required.issubset(scopes):
        raise SocialError('Kebenaran penerbitan belum diberikan. Sambungkan semula dan pilih kebenaran yang diperlukan.', 409, 'authorization')
    expiry = int(stamp) + min(max(int(long_result.get('expires_in') or 3600), 60), 90 * 86400)
    fields = 'id,name,access_token,tasks'
    if platform == 'instagram':
        fields += ',instagram_business_account{id,username,name}'
    params = {'fields': fields, 'limit': 100}
    candidates = []
    for _ in range(3):
        pages = provider_call('GET', base + '/me/accounts', params, user_token, transport)
        for page in pages.get('data', []):
            if not isinstance(page, dict) or not page.get('access_token'):
                continue
            tasks = page.get('tasks', [])
            if tasks and not set(tasks).intersection({'CREATE_CONTENT', 'MANAGE', 'PROFILE_PLUS_CREATE_CONTENT', 'PROFILE_PLUS_FULL_CONTROL'}):
                continue
            ig = page.get('instagram_business_account') or {}
            if platform == 'instagram' and not ig.get('id'):
                continue
            account = _provider_id(ig.get('id') if platform == 'instagram' else page.get('id'))
            page_id = _provider_id(page.get('id'))
            name = str((ig.get('username') or ig.get('name')) if platform == 'instagram' else page.get('name'))[:160]
            candidates.append({'id': secrets.token_hex(16), 'platform': platform, 'account_id': account,
                               'account_name': name or LABELS[platform], 'page_id': page_id,
                               'token_cipher': _encrypt(page['access_token']), 'token_expires_at': expiry,
                               'scopes_json': json.dumps(scopes)})
        after = (pages.get('paging') or {}).get('cursors', {}).get('after')
        if not (pages.get('paging') or {}).get('next') or not isinstance(after, str) or len(after) > 2000:
            break
        params = dict(params, after=after)  # Never fetch provider-supplied next URLs.
    if not candidates:
        raise SocialError('Tiada Page yang layak ditemui.' if platform == 'facebook' else
                           'Tiada akaun Instagram Professional yang dipautkan kepada Page ditemui.', 409, 'authorization')
    ph = meja._ph(postgres)
    def save_candidates():
        meja._kunci_akaun(conn, postgres, owner)
        cur = conn.cursor()
        # A new flow invalidates unselected candidates for the same platform only.
        cur.execute(f'DELETE FROM marketing_oauth_candidates WHERE akaun_id={ph} AND platform={ph}', (owner, platform))
        for candidate in candidates:
            cur.execute(f'''INSERT INTO marketing_oauth_candidates
                           (id,akaun_id,state_hash,platform,account_id,account_name,page_id,token_cipher,token_expires_at,scopes_json,expires_at)
                           VALUES ({','.join([ph] * 11)})''',
                        (candidate['id'], owner, digest, platform, candidate['account_id'], candidate['account_name'],
                         candidate['page_id'], candidate['token_cipher'], candidate['token_expires_at'], candidate['scopes_json'], int(stamp) + 600))
        return 200, {'ok': True}
    meja._dalam(conn, postgres, save_candidates)
    return 200, {'ok': True, 'platform': platform, 'selection_required': True,
                 'candidates': [{'id': c['id'], 'name': c['account_name'], 'platform': platform, 'account_id': c['account_id']} for c in candidates]}


def _select(conn, postgres, owner, platform, payload, stamp):
    key = payload.get('candidate_id') if isinstance(payload, dict) else None
    if set(payload or {}) != {'candidate_id'} or not isinstance(key, str) or not ID.fullmatch(key):
        raise SocialError('Pilih akaun yang sah daripada senarai.', 400, 'validation')
    _config(platform)
    ph = meja._ph(postgres)
    def select():
        meja._kunci_akaun(conn, postgres, owner)
        cur = conn.cursor()
        cur.execute(f'''SELECT * FROM marketing_oauth_candidates
                        WHERE id={ph} AND akaun_id={ph} AND platform={ph} AND expires_at>{ph}''', (key, owner, platform, int(stamp)))
        candidate = _row(cur)
        if not candidate:
            raise SocialError('Pilihan akaun telah luput. Mulakan sambungan semula.', 404, 'validation')
        _decrypt(candidate['token_cipher'])
        account = _save_connection(conn, postgres, owner, candidate, stamp)
        cur.execute(f'DELETE FROM marketing_oauth_candidates WHERE akaun_id={ph} AND platform={ph}', (owner, platform))
        return 200, {'ok': True, 'platform': platform, 'account': account}
    return meja._dalam(conn, postgres, select)


def _disconnect(conn, postgres, owner, platform, stamp):
    ph = meja._ph(postgres)
    def remove():
        meja._kunci_akaun(conn, postgres, owner)
        cur = conn.cursor()
        cur.execute(f'''UPDATE marketing_connections SET token_cipher='',status='disconnected',updated_at={ph}
                        WHERE akaun_id={ph} AND platform={ph}''', (meja._iso(stamp), owner, platform))
        cur.execute(f'DELETE FROM marketing_oauth_candidates WHERE akaun_id={ph} AND platform={ph}', (owner, platform))
        cur.execute(f'DELETE FROM marketing_oauth_states WHERE akaun_id={ph} AND platform={ph}', (owner, platform))
        return 200, {'ok': True, 'platform': platform, 'status': 'disconnected'}
    return meja._dalam(conn, postgres, remove)


def dispatch_social(method, path, conn, postgres, owner, payload, stamp, transport=None):
    if not isinstance(owner, str) or not owner:
        return 401, {'ok': False, 'error': 'Sila masuk akaun dahulu.'}
    try:
        if method == 'GET' and path == PREFIX:
            return 200, {'ok': True, 'connections': get_connections(conn, postgres, owner), 'capabilities': capabilities(conn, postgres, owner)}
        if method == 'POST' and path == PREFIX + '/complete':
            return _complete(conn, postgres, owner, payload, None, stamp, transport)
        match = re.fullmatch(re.escape(PREFIX) + r'/(facebook|instagram|threads)(?:/(begin|complete|select))?', path)
        if match:
            platform, action = match.groups()
            if method == 'POST' and action == 'begin':
                if payload not in ({}, None):
                    raise SocialError('Permintaan sambungan tidak sah.', 400, 'validation')
                return _begin(conn, postgres, owner, platform, stamp)
            if method == 'POST' and action == 'complete':
                return _complete(conn, postgres, owner, payload, platform, stamp, transport)
            if method == 'POST' and action == 'select':
                return _select(conn, postgres, owner, platform, payload, stamp)
            if method == 'DELETE' and not action:
                return _disconnect(conn, postgres, owner, platform, stamp)
    except SocialError as error:
        conn.rollback()
        return error.status, {'ok': False, 'error': str(error), 'code': error.kind}
    except Exception:
        conn.rollback()
        return 503, {'ok': False, 'error': 'Sambungan tidak dapat diselesaikan. Mulakan sambungan semula.'}
    return 404, {'ok': False, 'error': 'Endpoint tidak ditemui.'}


def validate_post(post):
    data = json.loads(post.get('data_json') or '{}') if 'data_json' in post else dict(post)
    platform = data.get('platform')
    form = data.get('format')
    text = data.get('body', '')
    media = data.get('media_url', '')
    if platform not in PLATFORMS or form not in FORMATS[platform]:
        raise SocialError('Format ini belum disokong untuk platform yang dipilih.', 400, 'validation')
    if not isinstance(text, str) or '\x00' in text or not isinstance(media, str):
        raise SocialError('Kandungan penerbitan tidak sah.', 400, 'validation')
    if form in {'image', 'video', 'reel', 'link'} and not _public_https(media):
        raise SocialError('Gunakan URL HTTPS awam bagi media atau pautan.', 400, 'validation')
    if form == 'text' and not text.strip():
        raise SocialError('Masukkan teks sebelum menjadualkan.', 400, 'validation')
    if platform == 'threads':
        full = (text + ('\n' + media if form == 'link' else '')).strip()
        if len(full.encode('utf-8')) > 500:
            raise SocialError('Teks Threads terlalu panjang. Had awal ialah 500 bait UTF-8.', 400, 'validation')
    if platform == 'instagram' and len(text) > 2200:
        raise SocialError('Kapsyen Instagram melebihi 2,200 aksara.', 400, 'validation')
    if len(text) > 8000:
        raise SocialError('Teks penerbitan terlalu panjang.', 400, 'validation')
    return data


def _epoch(value):
    try:
        dt = datetime.fromisoformat(str(value).replace('Z', '+00:00'))
        if dt.tzinfo is None:
            return None
        return int(dt.timestamp())
    except (TypeError, ValueError, OverflowError):
        return None


def check_schedule(conn, postgres, owner, post, scheduled_at, stamp):
    """Validate and pin the currently selected account, inside caller transaction."""
    try:
        if not _enabled():
            raise SocialError('Penerbitan automatik pelayan belum diaktifkan.', 409, 'configuration')
        data = validate_post(post)
        _config(data['platform'])
        when = _epoch(scheduled_at)
        if when is None or when < int(stamp) - 60 or when > int(stamp) + 90 * 86400:
            raise SocialError('Pilih masa sah dalam 90 hari akan datang.', 400, 'validation')
        ph = meja._ph(postgres)
        cur = conn.cursor()
        cur.execute(f'''SELECT * FROM marketing_connections
                        WHERE akaun_id={ph} AND platform={ph} AND status='connected' ''', (owner, data['platform']))
        connection = _row(cur)
        if not connection:
            raise SocialError('Sambungkan akaun platform dahulu.', 409, 'authorization')
        if connection['token_expires_at'] and connection['token_expires_at'] <= when + 60:
            raise SocialError('Akses akan luput sebelum masa siaran. Sambungkan semula atau pilih masa lebih awal.', 409, 'authorization')
        _decrypt(connection['token_cipher'])
        if data['platform'] == 'facebook' and data['format'] == 'video' and 'publish_video' not in json.loads(connection['scopes_json']):
            raise SocialError('Kebenaran video Facebook diperlukan. Sambungkan semula.', 409, 'authorization')
        key = post.get('id')
        if not isinstance(key, str) or not ID.fullmatch(key):
            raise SocialError('Pengenal siaran tidak sah.', 400, 'validation')
        cur.execute(f'SELECT akaun_id FROM marketing_post_bindings WHERE post_id={ph}', (key,))
        old = cur.fetchone()
        if old and old[0] != owner:
            raise SocialError('Siaran tidak ditemui.', 404, 'validation')
        cur.execute(f'''INSERT INTO marketing_post_bindings (post_id,akaun_id,connection_id,account_id,platform,created_at)
                        VALUES ({','.join([ph] * 6)}) ON CONFLICT (post_id) DO UPDATE SET
                        connection_id=EXCLUDED.connection_id,account_id=EXCLUDED.account_id,
                        platform=EXCLUDED.platform,created_at=EXCLUDED.created_at''',
                    (key, owner, connection['id'], connection['account_id'], data['platform'], meja._iso(stamp)))
        return True, ''
    except (SocialError, ValueError, TypeError) as error:
        return False, str(error) if isinstance(error, SocialError) else 'Kandungan siaran tidak sah.'


def load_bound_connection(conn, postgres, owner, post, stamp):
    data = validate_post(post)
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT c.* FROM marketing_post_bindings b JOIN marketing_connections c
                    ON c.id=b.connection_id AND c.akaun_id=b.akaun_id AND c.account_id=b.account_id
                    AND c.platform=b.platform WHERE b.post_id={ph} AND b.akaun_id={ph}
                    AND b.platform={ph} AND c.status='connected' ''', (post['id'], owner, data['platform']))
    connection = _row(cur)
    if not connection or (connection['token_expires_at'] and connection['token_expires_at'] <= int(stamp)):
        raise SocialError('Akaun berjadual telah berubah, diputuskan atau luput. Semak sambungan dan jadualkan semula.', 409, 'authorization')
    cfg = _config(data['platform'])
    connection['token'] = _decrypt(connection['token_cipher'])
    connection['config'] = cfg
    connection['base'] = _base(data['platform'], cfg)
    return connection, data
