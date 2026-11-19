"""Owner-scoped publication links resolved by read-only provider requests.

A receipt records the destination of an existing provider post. It is not a
public-visibility check and never republishes or changes a post's status.
"""
import json
import threading
from urllib.parse import parse_qsl, unquote, urlsplit

import marketing_social as social
import pelanggan_meja as meja


URL_HOSTS = {
    'facebook': frozenset({'facebook.com', 'www.facebook.com', 'm.facebook.com'}),
    'instagram': frozenset({'instagram.com', 'www.instagram.com'}),
    'threads': frozenset({'threads.com', 'www.threads.com', 'threads.net', 'www.threads.net'}),
}
PUBLIC_FIELDS = ('platform', 'account_id', 'account_name', 'profile_url', 'post_url', 'resolved_at')
IDENTITY_FIELDS = ('id', 'account_id', 'page_id', 'platform', 'provider_app_id', 'provider_user_id', 'token_cipher')
_SLOTS = threading.BoundedSemaphore(4)


def ensure_publication_schema(conn, postgres):
    conn.cursor().execute('''CREATE TABLE IF NOT EXISTS marketing_publications (
        post_id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, provider_post_id TEXT NOT NULL,
        platform TEXT NOT NULL, account_id TEXT NOT NULL, account_name TEXT NOT NULL,
        profile_url TEXT NOT NULL, post_url TEXT NOT NULL, resolved_at TEXT NOT NULL,
        provider_app_id TEXT NOT NULL, provider_user_id TEXT NOT NULL)''')
    conn.cursor().execute('CREATE INDEX IF NOT EXISTS marketing_publications_owner ON marketing_publications (akaun_id, post_id)')
    conn.cursor().execute('CREATE INDEX IF NOT EXISTS marketing_publications_grant ON marketing_publications (provider_app_id, provider_user_id)')


def _invalid():
    return social.SocialError('Destinasi hantaran belum dapat disahkan dengan platform.', 502, 'publication_unverified')


def _url(value, platform, optional=False):
    if optional and value in ('', None):
        return ''
    if (not isinstance(value, str) or not value or len(value) > 2048
            or '\\' in value or any(c.isspace() or ord(c) < 32 or ord(c) == 127 for c in value)):
        raise _invalid()
    try:
        parsed = urlsplit(value)
        if (parsed.scheme != 'https' or parsed.hostname not in URL_HOSTS.get(platform, ())
                or parsed.username or parsed.password or parsed.port not in (None, 443)
                or parsed.fragment or not parsed.path or parsed.path.startswith('//')):
            raise ValueError('url')
        # A receipt must not expose credentials or turn a provider link into an
        # external redirect. Only the provider-returned destination is retained.
        sensitive = {'access_token', 'appsecret_proof', 'client_secret', 'token', 'code', 'state',
                     'oauth_token', 'redirect_uri', 'next', 'url', 'u', 'href'}
        if any(key.lower() in sensitive for key, _ in parse_qsl(parsed.query, keep_blank_values=True)):
            raise ValueError('query')
        # Check decoded paths too: an encoded redirect must not evade the
        # allowlist, nor can path normalization turn a link into one.
        path = parsed.path
        for _ in range(3):
            decoded = unquote(path, errors='strict')
            if decoded == path:
                break
            path = decoded
        if (unquote(path, errors='strict') != path or path.startswith('//') or '\\' in path
                or any(ord(c) < 32 or ord(c) == 127 for c in path)
                or any(part in {'.', '..'} for part in path.split('/'))):
            raise ValueError('path')
        if path.lower().rstrip('/') in {'/l.php', '/away.php', '/redirect', '/flx/warn'}:
            raise ValueError('redirect')
    except (TypeError, ValueError):
        raise _invalid() from None
    return value


def _public(row):
    try:
        return {**{key: row[key] for key in PUBLIC_FIELDS},
                'profile_url': _url(row['profile_url'], row['platform'], optional=True),
                'post_url': _url(row['post_url'], row['platform'])}
    except (KeyError, social.SocialError):
        return None


def attach_cached(conn, postgres, owner, posts):
    """One owner-scoped query for a post list; never calls the provider."""
    if not posts:
        return posts
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT r.* FROM marketing_publications r JOIN marketing_posts p
                    ON p.id=r.post_id AND p.akaun_id=r.akaun_id
                    AND p.provider_post_id=r.provider_post_id
                    WHERE r.akaun_id={ph} AND p.status='published' ''', (owner,))
    receipts = {row['post_id']: row for row in social._rows(cur)}
    for post in posts:
        row = receipts.get(post['id'])
        post['publication'] = (_public(row) if row and post.get('status') == 'published'
                               and row['provider_post_id'] == post.get('provider_post_id')
                               and row['platform'] == post.get('platform') else None)
    return posts


def _post(conn, postgres, owner, key):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT * FROM marketing_posts WHERE id={ph} AND akaun_id={ph}', (key, owner))
    row = social._row(cur)
    if not row:
        raise social.SocialError('Siaran tidak ditemui dalam akaun ini.', 404, 'not_found')
    if row['status'] != 'published' or not row['provider_post_id']:
        raise social.SocialError('Pautan tersedia selepas platform mengesahkan penerbitan.', 409, 'publication_not_ready')
    return row


def _cached(conn, postgres, owner, post):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT * FROM marketing_publications
                    WHERE post_id={ph} AND akaun_id={ph} AND provider_post_id={ph}''',
                (post['id'], owner, post['provider_post_id']))
    row = social._row(cur)
    platform = json.loads(post['data_json']).get('platform')
    return _public(row) if row and row['platform'] == platform else None


def _owner_id(value):
    try:
        return social._numeric_id(value.get('id') if isinstance(value, dict) else value)
    except social.SocialError:
        raise _invalid() from None


def _name(value):
    if not isinstance(value, str) or not value.strip() or len(value) > 160 or '\x00' in value:
        raise _invalid()
    return value.strip()


def _facebook_link(value, form):
    # Video nodes may return a Facebook-relative permalink. Preserve that exact
    # provider path under Facebook's HTTPS origin; never construct a path from
    # a post/video ID. Protocol-relative values remain invalid.
    if form == 'video' and isinstance(value, str) and value.startswith('/') and not value.startswith('//'):
        value = 'https://www.facebook.com' + value
    return _url(value, 'facebook')


def _fetch(connection, post, platform, transport):
    account = social._numeric_id(connection['account_id'])
    provider_id = social._provider_id(post['provider_post_id'])
    form = json.loads(post['data_json']).get('format')
    base, token = connection['base'], connection['token']
    if platform == 'facebook':
        # The photo endpoint can return only its numeric photo ID; post_id,
        # when present, is a Page post ID. Photo nodes expose `link`, while
        # Page posts and video nodes expose `permalink_url`.
        link_field = 'link' if form == 'image' and '_' not in provider_id else 'permalink_url'
        fields, profile_fields, profile_path = 'id,from,' + link_field, 'id,name,link', account
    else:
        fields, profile_fields = 'id,owner,permalink', 'id,username'
        profile_path = 'me' if platform == 'threads' else account
    media = social.provider_call('GET', base + '/' + provider_id, {'fields': fields}, token, transport, timeout=8)
    if social._provider_id(media.get('id')) != provider_id:
        raise _invalid()
    if _owner_id(media.get('from') if platform == 'facebook' else media.get('owner')) != account:
        raise _invalid()
    profile = social.provider_call('GET', base + '/' + profile_path, {'fields': profile_fields}, token, transport, timeout=8)
    if _owner_id(profile) != account:
        raise _invalid()
    if platform == 'threads' and account != connection['provider_user_id']:
        raise _invalid()
    return {'platform': platform, 'account_id': account,
            'account_name': _name(profile.get('name') if platform == 'facebook' else profile.get('username')),
            'profile_url': _url(profile.get('link'), platform, optional=True) if platform == 'facebook' else '',
            'post_url': (_facebook_link(media.get(link_field), form) if platform == 'facebook'
                         else _url(media.get('permalink'), platform))}


def resolve(conn, postgres, owner, key, payload, stamp, transport=None):
    """Cache a verified destination without a provider write or status change."""
    if payload != {}:
        return 400, {'ok': False, 'code': 'invalid', 'error': 'Permintaan pautan hantaran tidak sah.'}
    slot = False
    try:
        post = _post(conn, postgres, owner, key)
        cached = _cached(conn, postgres, owner, post)
        if cached:
            return 200, {'ok': True, 'publication': cached}
        connection, data = social.load_bound_connection(conn, postgres, owner, post, stamp)
        snapshot = tuple(connection.get(field) for field in IDENTITY_FIELDS)
        # Do not keep a database transaction open during provider network calls.
        conn.rollback()
        slot = _SLOTS.acquire(blocking=False)
        if not slot:
            raise social.SocialError('Semakan pautan sedang sibuk. Cuba sebentar lagi.', 429, 'rate_limit')
        receipt = _fetch(connection, post, data['platform'], transport)
        receipt['resolved_at'] = meja._iso(stamp)

        def save():
            meja._kunci_akaun(conn, postgres, owner)
            current = _post(conn, postgres, owner, key)
            if (current['provider_post_id'] != post['provider_post_id']
                    or json.loads(current['data_json']).get('platform') != data['platform']):
                raise _invalid()
            cached_now = _cached(conn, postgres, owner, current)
            if cached_now:
                return 200, {'ok': True, 'publication': cached_now}
            selected, _ = social.load_bound_connection(conn, postgres, owner, current, stamp)
            if tuple(selected.get(field) for field in IDENTITY_FIELDS) != snapshot:
                raise social.SocialError('Sambungan berubah semasa semakan. Semak semula pautan hantaran.',
                                         409, 'publication_connection_changed')
            fields = ('post_id', 'akaun_id', 'provider_post_id', *PUBLIC_FIELDS,
                      'provider_app_id', 'provider_user_id')
            values = (key, owner, post['provider_post_id'], *(receipt[field] for field in PUBLIC_FIELDS),
                      connection['provider_app_id'], connection['provider_user_id'])
            ph = meja._ph(postgres)
            conn.cursor().execute(f'''INSERT INTO marketing_publications ({','.join(fields)})
                                      VALUES ({','.join([ph] * len(fields))})
                                      ON CONFLICT (post_id) DO NOTHING''', values)
            saved = _cached(conn, postgres, owner, current)
            if not saved:
                raise _invalid()
            return 200, {'ok': True, 'publication': saved}
        return meja._dalam(conn, postgres, save)
    except social.SocialError as error:
        conn.rollback()
        return error.status, {'ok': False, 'code': error.kind, 'error': str(error)}
    except Exception:
        conn.rollback()
        return 503, {'ok': False, 'code': 'publication_unavailable',
                     'error': 'Pautan hantaran belum dapat disahkan. Cuba lagi tanpa menerbitkan semula.'}
    finally:
        if slot:
            _SLOTS.release()
