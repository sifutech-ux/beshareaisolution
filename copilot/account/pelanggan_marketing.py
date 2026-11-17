"""Account-owned marketing plans, references and reported results.

Saving a post never publishes it. The separate schedule operation checks the
connected provider before admitting an approved, versioned post to the queue.
"""
import importlib
import ipaddress
import json
import re
from datetime import date, datetime
from decimal import Decimal
from urllib.parse import urlsplit

import pelanggan_meja as meja


PREFIX = '/api/akaun/copilot/marketing'
ID = re.compile(r'^[a-f0-9]{32}$')
MONEY = re.compile(r'^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$')
UTC_TIME = re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$')
DAY = re.compile(r'^\d{4}-\d{2}-\d{2}$')
CHANNELS = {'facebook', 'instagram', 'threads', 'google_ads'}
POST_PLATFORMS = {'facebook', 'instagram', 'threads'}
CAMPAIGN_STATUSES = {'draft', 'ready', 'active', 'completed', 'archived'}
EDITABLE_POST_STATUSES = {'draft', 'planned', 'cancelled', 'failed'}
LOCKED_POST_STATUSES = {'queued', 'publishing', 'published', 'uncertain'}
RESOURCES = {
    'campaigns': ('marketing_campaigns', 'campaign', 200),
    'posts': ('marketing_posts', 'post', 2000),
    'sources': ('marketing_sources', 'source', 1000),
    'results': ('marketing_results', 'result', 5000),
}


def ensure_marketing_schema(conn, postgres):
    cur = conn.cursor()
    cur.execute('''CREATE TABLE IF NOT EXISTS marketing_campaigns (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, data_json TEXT NOT NULL,
        version INTEGER NOT NULL, status TEXT NOT NULL,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
    cur.execute('''CREATE TABLE IF NOT EXISTS marketing_posts (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, campaign_id TEXT NOT NULL,
        data_json TEXT NOT NULL, version INTEGER NOT NULL, status TEXT NOT NULL,
        scheduled_at TEXT NOT NULL, provider_post_id TEXT NOT NULL DEFAULT '',
        last_error TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL, attempt_id TEXT NOT NULL DEFAULT '')''')
    cur.execute('''CREATE TABLE IF NOT EXISTS marketing_sources (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, campaign_id TEXT NOT NULL,
        data_json TEXT NOT NULL, version INTEGER NOT NULL,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)''')
    cur.execute('''CREATE TABLE IF NOT EXISTS marketing_results (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, campaign_id TEXT NOT NULL,
        result_date TEXT NOT NULL, platform TEXT NOT NULL, data_json TEXT NOT NULL,
        version INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        UNIQUE (akaun_id, campaign_id, result_date, platform))''')
    for table, _singular, _limit in RESOURCES.values():
        cur.execute(f'CREATE INDEX IF NOT EXISTS {table}_owner ON {table} (akaun_id, updated_at)')
    cur.execute('CREATE INDEX IF NOT EXISTS marketing_posts_due ON marketing_posts (status, scheduled_at)')
    cur.execute('CREATE INDEX IF NOT EXISTS marketing_posts_campaign ON marketing_posts (akaun_id, campaign_id)')


def _error(code, message, **extra):
    return {'ok': False, 'code': code, 'error': message, **extra}


def _reject(message, code='invalid', status=400, **extra):
    raise meja.Tolak(status, _error(code, message, **extra))


def _payload(value, allowed, required=()):
    if not isinstance(value, dict) or set(value) - set(allowed) or set(required) - set(value):
        _reject('Bentuk permintaan tidak sah.')
    return value


def _version(value):
    if type(value) is not int or value < 0 or value > 2_147_483_646:
        _reject('Versi simpanan tidak sah.')
    return value


def _text(value, limit, required=False):
    if not isinstance(value, str) or len(value) > limit or '\x00' in value:
        _reject('Teks terlalu panjang atau tidak sah.')
    value = value.strip()
    if required and not value:
        _reject('Lengkapkan maklumat yang diperlukan.')
    return value


def _choice(value, choices):
    if not isinstance(value, str) or value not in choices:
        _reject('Pilihan saluran, jenis atau status tidak sah.')
    return value


def _identifier(value, optional=False):
    if optional and value == '':
        return ''
    if not isinstance(value, str) or not ID.fullmatch(value):
        _reject('Pengenal kempen tidak sah.')
    return value


def _money(value):
    if not isinstance(value, str) or not MONEY.fullmatch(value):
        _reject('Masukkan jumlah bukan negatif dengan paling banyak dua tempat perpuluhan.')
    return format(Decimal(value).quantize(Decimal('0.01')), '.2f')


def _count(value):
    if type(value) is not int or not 0 <= value <= 1_000_000_000:
        _reject('Kiraan mesti nombor bulat bukan negatif.')
    return value


def _date(value, optional=False):
    if optional and value == '':
        return ''
    if not isinstance(value, str) or not DAY.fullmatch(value):
        _reject('Gunakan tarikh YYYY-MM-DD.')
    try:
        parsed = date.fromisoformat(value)
    except ValueError:
        _reject('Tarikh tidak sah.')
    if not 2000 <= parsed.year <= 2100:
        _reject('Tarikh di luar julat yang disokong.')
    return parsed.isoformat()


def _utc(value, optional=False):
    if optional and value == '':
        return ''
    if not isinstance(value, str) or not UTC_TIME.fullmatch(value):
        _reject('Gunakan masa UTC seperti 2026-11-17T09:30:00Z.')
    try:
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError:
        _reject('Masa tidak sah.')
    if not 2000 <= parsed.year <= 2100:
        _reject('Masa di luar julat yang disokong.')
    return parsed.strftime('%Y-%m-%dT%H:%M:%SZ')


def _url(value, required=False):
    value = _text(value, 2048, required)
    if not value:
        return ''
    if any(char.isspace() or ord(char) < 32 for char in value) or '\\' in value:
        _reject('Pautan HTTPS tidak sah.')
    try:
        parts = urlsplit(value)
        host = parts.hostname
        port = parts.port
        if parts.scheme != 'https' or not host or parts.username is not None or parts.password is not None:
            raise ValueError('https')
        if port is not None and not 1 <= port <= 65535:
            raise ValueError('port')
        host = host.rstrip('.').lower()
        try:
            address = ipaddress.ip_address(host)
        except ValueError:
            ascii_host = host.encode('idna').decode('ascii')
            if ('.' not in ascii_host or len(ascii_host) > 253
                    or ascii_host.endswith(('.localhost', '.local', '.internal', '.test', '.invalid'))
                    or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label)
                           for label in ascii_host.split('.'))):
                raise ValueError('host')
        else:
            if not address.is_global:
                raise ValueError('private')
    except (ValueError, UnicodeError):
        _reject('Gunakan pautan HTTPS awam tanpa nama pengguna atau kata laluan.')
    return value


def _campaign(payload):
    allowed = {'version', 'title', 'product', 'offer', 'audience', 'goal', 'budget_total',
               'destination_url', 'start_date', 'end_date', 'channels', 'status'}
    _payload(payload, allowed, {'version', 'title'})
    data = {key: _text(payload.get(key, ''), limit, key == 'title') for key, limit in
            (('title', 160), ('product', 1000), ('offer', 2000), ('audience', 1000))}
    data['goal'] = _choice(payload.get('goal', 'leads'), {'awareness', 'leads', 'sales', 'repeat'})
    data['budget_total'] = _money(payload.get('budget_total', '0.00'))
    data['destination_url'] = _url(payload.get('destination_url', ''))
    data['start_date'] = _date(payload.get('start_date', ''), True)
    data['end_date'] = _date(payload.get('end_date', ''), True)
    if data['start_date'] and data['end_date'] and data['end_date'] < data['start_date']:
        _reject('Tarikh akhir mesti pada atau selepas tarikh mula.')
    channels = payload.get('channels', [])
    if not isinstance(channels, list) or len(channels) > len(CHANNELS):
        _reject('Senarai saluran tidak sah.')
    data['channels'] = sorted({_choice(channel, CHANNELS) for channel in channels})
    data['status'] = _choice(payload.get('status', 'draft'), CAMPAIGN_STATUSES)
    return data


def _post(payload):
    allowed = {'version', 'campaign_id', 'platform', 'format', 'body', 'media_url', 'scheduled_at', 'status'}
    _payload(payload, allowed, {'version', 'campaign_id', 'platform'})
    data = {'campaign_id': _identifier(payload.get('campaign_id')),
            'platform': _choice(payload.get('platform'), POST_PLATFORMS),
            'format': _choice(payload.get('format', 'text'), {'text', 'image', 'video'}),
            'body': _text(payload.get('body', ''), 5000),
            'media_url': _url(payload.get('media_url', '')),
            'scheduled_at': _utc(payload.get('scheduled_at', ''), True),
            'status': _choice(payload.get('status', 'draft'), {'draft', 'planned'})}
    return data


def _source(payload):
    allowed = {'version', 'campaign_id', 'title', 'url', 'observation', 'hypothesis', 'source_type', 'captured_at'}
    _payload(payload, allowed, {'version', 'title'})
    kind = _choice(payload.get('source_type', 'reference'), {'reference', 'manual'})
    return {'campaign_id': _identifier(payload.get('campaign_id', ''), True),
            'title': _text(payload.get('title'), 160, True),
            'url': _url(payload.get('url', ''), kind == 'reference'),
            'observation': _text(payload.get('observation', ''), 4000),
            'hypothesis': _text(payload.get('hypothesis', ''), 2000),
            'source_type': kind,
            'captured_at': _utc(payload.get('captured_at', ''), True)}


def _result(payload):
    counts = {'impressions', 'clicks', 'enquiries', 'qualified_leads', 'orders'}
    allowed = {'version', 'campaign_id', 'date', 'platform', 'spend', 'revenue', 'note', 'origin'} | counts
    _payload(payload, allowed, {'version', 'campaign_id', 'date', 'platform'})
    if payload.get('origin', 'user_reported') != 'user_reported':
        _reject('Rekod ini hanya menerima hasil yang dimasukkan pengguna.')
    data = {'campaign_id': _identifier(payload.get('campaign_id')),
            'date': _date(payload.get('date')),
            'platform': _choice(payload.get('platform'), CHANNELS | {'other'}),
            'spend': _money(payload.get('spend', '0.00')),
            'revenue': _money(payload.get('revenue', '0.00')),
            'note': _text(payload.get('note', ''), 2000),
            'origin': 'user_reported'}
    data.update({key: _count(payload.get(key, 0)) for key in counts})
    return data


VALIDATORS = {'campaigns': _campaign, 'posts': _post, 'sources': _source, 'results': _result}


def _record(cursor, row):
    stored = dict(zip([column[0] for column in cursor.description], row))
    data = json.loads(stored.pop('data_json'))
    stored.pop('akaun_id', None)
    stored.pop('result_date', None)
    stored.pop('attempt_id', None)
    return {**data, **stored}


def _read(conn, postgres, owner, resource, key):
    table = RESOURCES[resource][0]
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT * FROM {table} WHERE id={ph} AND akaun_id={ph}', (key, owner))
    row = cur.fetchone()
    return _record(cur, row) if row else None


def _list(conn, postgres, owner, resource):
    table, _singular, limit = RESOURCES[resource]
    cur = conn.cursor()
    cur.execute(f'SELECT * FROM {table} WHERE akaun_id={meja._ph(postgres)} ORDER BY updated_at DESC,id LIMIT {limit}', (owner,))
    return [_record(cur, row) for row in cur.fetchall()]


def _owned_campaign(conn, postgres, owner, key):
    item = _read(conn, postgres, owner, 'campaigns', key)
    if not item:
        _reject('Kempen tidak ditemui dalam akaun ini.', 'not_found', 404)
    return item


def _save(conn, postgres, owner, resource, key, payload, stamp):
    data = VALIDATORS[resource](payload)
    version = _version(payload.get('version'))
    meja._kunci_akaun(conn, postgres, owner)
    if data.get('campaign_id'):
        _owned_campaign(conn, postgres, owner, data['campaign_id'])
    current = _read(conn, postgres, owner, resource, key)
    table, singular, limit = RESOURCES[resource]
    ph = meja._ph(postgres)
    cur = conn.cursor()
    if current:
        if (all(current.get(field) == value for field, value in data.items())
                and version in {current['version'], current['version'] - 1}):
            return 200, {'ok': True, singular: current, 'replayed': True}
        if current['version'] != version:
            return 409, _error('conflict', 'Rekod sudah berubah. Muat salinan terkini dahulu.', current=current)
        if resource == 'posts' and (current['status'] not in EDITABLE_POST_STATUSES or current['provider_post_id']):
            return 409, _error('post_locked', 'Batalkan jadual sebelum menyunting. Penerbitan yang telah bermula tidak boleh ditulis semula.', current=current)
    else:
        cur.execute(f'SELECT id FROM {table} WHERE id={ph}', (key,))
        if cur.fetchone() or version != 0:
            return 404, _error('not_found', 'Rekod tidak ditemui dalam akaun ini.')
        cur.execute(f'SELECT COUNT(*) FROM {table} WHERE akaun_id={ph}', (owner,))
        if cur.fetchone()[0] >= limit:
            return 409, _error('capacity', f'Had {limit} rekod dicapai. Sunting rekod sedia ada.')
    if resource == 'results':
        cur.execute(f'''SELECT id FROM marketing_results WHERE akaun_id={ph} AND campaign_id={ph}
            AND result_date={ph} AND platform={ph} AND id<>{ph}''',
                    (owner, data['campaign_id'], data['date'], data['platform'], key))
        duplicate = cur.fetchone()
        if duplicate:
            return 409, _error('duplicate_result', 'Hasil bagi kempen, tarikh dan saluran ini sudah ada. Sunting rekod tersebut.', existing_id=duplicate[0])
    stamp_iso = meja._iso(stamp)
    stored_data = {field: value for field, value in data.items()
                   if resource != 'posts' or field not in {'status', 'scheduled_at'}}
    columns = {'data_json': json.dumps(stored_data, ensure_ascii=False, sort_keys=True),
               'version': (current['version'] if current else 0) + 1, 'updated_at': stamp_iso}
    if resource == 'campaigns':
        columns['status'] = data['status']
    elif resource == 'posts':
        columns.update(campaign_id=data['campaign_id'], status=data['status'], scheduled_at=data['scheduled_at'],
                       provider_post_id='', last_error='', attempt_id='')
    elif resource == 'sources':
        columns['campaign_id'] = data['campaign_id']
    elif resource == 'results':
        columns.update(campaign_id=data['campaign_id'], result_date=data['date'], platform=data['platform'])
    if current:
        cur.execute(f'''UPDATE {table} SET {','.join(field + '=' + ph for field in columns)}
            WHERE id={ph} AND akaun_id={ph} AND version={ph}''',
                    (*columns.values(), key, owner, version))
        if cur.rowcount != 1:
            return 409, _error('conflict', 'Rekod sudah berubah. Muat semula.', current=_read(conn, postgres, owner, resource, key))
    else:
        columns.update(id=key, akaun_id=owner, created_at=stamp_iso)
        cur.execute(f'''INSERT INTO {table} ({','.join(columns)}) VALUES ({','.join([ph] * len(columns))})
            ON CONFLICT (id) DO NOTHING''', tuple(columns.values()))
        if cur.rowcount != 1:
            return 404, _error('not_found', 'Rekod tidak ditemui dalam akaun ini.')
    return 200, {'ok': True, singular: _read(conn, postgres, owner, resource, key)}


def _social():
    try:
        return importlib.import_module('marketing_social')
    except ModuleNotFoundError as error:
        if error.name != 'marketing_social':
            raise
        return None


def _provider_status(conn, postgres, owner):
    capabilities = {'campaigns': True, 'post_planning': True, 'manual_results': True,
                    'reference_notes': True, 'ai_drafts': True, 'external_research': False,
                    'auto_publish': False, 'worker_enabled': False,
                    'publishing_enabled': False, 'worker_configured': False, 'worker_health': 'not_checked',
                    'ads': {'meta': False, 'google': False}}
    provider = _social()
    if provider is None:
        return [], capabilities
    connections = provider.get_connections(conn, postgres, owner)
    capabilities.update(provider.capabilities(conn, postgres, owner))
    return connections, capabilities


def _schedule(conn, postgres, owner, key, payload, stamp):
    _payload(payload, {'version', 'approved', 'scheduled_at'}, {'version', 'approved', 'scheduled_at'})
    if payload.get('approved') is not True:
        _reject('Sahkan kandungan dan masa penerbitan dahulu.', 'approval_required')
    version = _version(payload.get('version'))
    scheduled_at = _utc(payload.get('scheduled_at'))
    meja._kunci_akaun(conn, postgres, owner)
    post = _read(conn, postgres, owner, 'posts', key)
    if not post:
        return 404, _error('not_found', 'Siaran tidak ditemui dalam akaun ini.')
    if (post['status'] in LOCKED_POST_STATUSES and post['scheduled_at'] == scheduled_at
            and version in {post['version'], post['version'] - 1}):
        return 200, {'ok': True, 'post': post, 'replayed': True}
    if post['version'] != version:
        return 409, _error('conflict', 'Siaran sudah berubah. Semak kandungan terkini dahulu.', current=post)
    if post['status'] not in {'draft', 'planned'} or post['provider_post_id']:
        return 409, _error('post_locked', 'Siaran ini tidak boleh dijadualkan semula. Semak status penerbitan.', current=post)
    scheduled = datetime.fromisoformat(scheduled_at.replace('Z', '+00:00')).timestamp()
    if scheduled <= int(stamp):
        _reject('Pilih masa penerbitan pada masa akan datang.')
    campaign = _owned_campaign(conn, postgres, owner, post['campaign_id'])
    if campaign['status'] in {'completed', 'archived'}:
        _reject('Kempen yang selesai atau diarkibkan tidak boleh menerima jadual baharu.', 'campaign_closed', 409)
    if (post['format'] == 'text' and not post['body']) or (post['format'] != 'text' and not post['media_url']):
        _reject('Lengkapkan teks atau pautan media yang diperlukan sebelum menjadualkan.')
    provider = _social()
    ready, reason = provider.check_schedule(conn, postgres, owner, post, scheduled_at, stamp) if provider else (False, 'Sambungan penerbitan belum tersedia.')
    if ready is not True:
        return 409, _error('connection_not_ready', reason or 'Sambungan saluran belum bersedia untuk menerbitkan.')
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''UPDATE marketing_posts SET status='queued',scheduled_at={ph},version=version+1,
        last_error='',attempt_id='',updated_at={ph} WHERE id={ph} AND akaun_id={ph}
        AND version={ph} AND status IN ('draft','planned')''', (scheduled_at, meja._iso(stamp), key, owner, version))
    if cur.rowcount != 1:
        return 409, _error('conflict', 'Status siaran sudah berubah. Muat semula.', current=_read(conn, postgres, owner, 'posts', key))
    return 200, {'ok': True, 'post': _read(conn, postgres, owner, 'posts', key)}


def _cancel(conn, postgres, owner, key, payload, stamp):
    _payload(payload, {'version'}, {'version'})
    version = _version(payload.get('version'))
    meja._kunci_akaun(conn, postgres, owner)
    post = _read(conn, postgres, owner, 'posts', key)
    if not post:
        return 404, _error('not_found', 'Siaran tidak ditemui dalam akaun ini.')
    if post['status'] == 'cancelled' and version in {post['version'], post['version'] - 1}:
        return 200, {'ok': True, 'post': post, 'replayed': True}
    if post['version'] != version:
        return 409, _error('conflict', 'Status siaran sudah berubah. Muat semula.', current=post)
    if post['status'] not in {'draft', 'planned', 'queued', 'failed'}:
        return 409, _error('post_locked', 'Penerbitan telah bermula atau memerlukan semakan. Pembatalan tidak dapat dipastikan.', current=post)
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''UPDATE marketing_posts SET status='cancelled',version=version+1,updated_at={ph}
        WHERE id={ph} AND akaun_id={ph} AND version={ph}
        AND status IN ('draft','planned','queued','failed')''', (meja._iso(stamp), key, owner, version))
    if cur.rowcount != 1:
        return 409, _error('conflict', 'Status siaran sudah berubah. Muat semula.', current=_read(conn, postgres, owner, 'posts', key))
    return 200, {'ok': True, 'post': _read(conn, postgres, owner, 'posts', key)}


def dispatch_marketing(method, path, conn, postgres, owner, payload, stamp):
    cur = conn.cursor()
    cur.execute(f'SELECT id FROM pelanggan_akaun WHERE id={meja._ph(postgres)}', (owner,))
    if not cur.fetchone():
        return 401, _error('unauthorized', 'Sila masuk akaun dahulu.')
    if method == 'GET' and path == PREFIX + '/workspace':
        connections, capabilities = _provider_status(conn, postgres, owner)
        return 200, {'ok': True, **{resource: _list(conn, postgres, owner, resource) for resource in RESOURCES},
                     'connections': connections, 'capabilities': capabilities}
    if path.startswith(PREFIX + '/connections'):
        provider = _social()
        if provider:
            return provider.dispatch_social(method, path, conn, postgres, owner, payload, stamp)
    for resource, (_table, singular, _limit) in RESOURCES.items():
        root = PREFIX + '/' + resource
        if method == 'GET' and path == root:
            return 200, {'ok': True, resource: _list(conn, postgres, owner, resource)}
        if not path.startswith(root + '/'):
            continue
        suffix = path[len(root) + 1:].split('/')
        if not ID.fullmatch(suffix[0]):
            break
        key = suffix[0]
        if resource == 'posts' and len(suffix) == 2 and method == 'POST':
            if suffix[1] == 'schedule':
                return meja._dalam(conn, postgres, lambda: _schedule(conn, postgres, owner, key, payload, stamp))
            if suffix[1] == 'cancel':
                return meja._dalam(conn, postgres, lambda: _cancel(conn, postgres, owner, key, payload, stamp))
        if len(suffix) != 1:
            break
        if method == 'GET':
            item = _read(conn, postgres, owner, resource, key)
            return (200, {'ok': True, singular: item}) if item else (404, _error('not_found', 'Rekod tidak ditemui dalam akaun ini.'))
        if method == 'PUT':
            return meja._dalam(conn, postgres, lambda: _save(conn, postgres, owner, resource, key, payload, stamp))
        if resource == 'posts' and method == 'DELETE':
            return meja._dalam(conn, postgres, lambda: _cancel(conn, postgres, owner, key, payload, stamp))
    return 404, _error('not_found', 'Endpoint tidak ditemui.')
