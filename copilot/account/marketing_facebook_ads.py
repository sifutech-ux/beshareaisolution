"""Facebook Ads for one owner-selected ad account. Owner-scoped campaign reports and creation."""
import json
import secrets

import marketing_social as social
import pelanggan_meja as meja

PLATFORM = 'facebook_ads'
OBJECTIVES = {'OUTCOME_AWARENESS', 'OUTCOME_TRAFFIC', 'OUTCOME_ENGAGEMENT', 'OUTCOME_LEADS', 'OUTCOME_SALES'}
PERIODS = {'last_7d', 'last_30d'}
UNCERTAIN = 'Permintaan ini sudah dihantar. Semak kempen dalam Meta Ads Manager sebelum membuat kempen lain.'


def ensure_schema(conn):
    conn.cursor().execute("""CREATE TABLE IF NOT EXISTS marketing_ads_creations (
        akaun_id TEXT NOT NULL, account_id TEXT NOT NULL, plan_id TEXT NOT NULL,
        platform TEXT NOT NULL, provider_app_id TEXT NOT NULL, provider_user_id TEXT NOT NULL,
        attempt_id TEXT NOT NULL, campaign_id TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
        PRIMARY KEY (akaun_id,account_id,plan_id))""")
    conn.cursor().execute("""CREATE INDEX IF NOT EXISTS marketing_ads_grant
        ON marketing_ads_creations (provider_app_id,provider_user_id)""")


def _rows(data):
    rows = data.get('data') if isinstance(data, dict) else None
    if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
        raise social.SocialError('Senarai daripada Meta tidak dapat dibaca.')
    return rows


def _after(data):
    paging = data.get('paging') or {}
    if not paging.get('next'):
        return ''
    after = (paging.get('cursors') or {}).get('after')
    if not isinstance(after, str) or not 0 < len(after) <= 2000:
        raise social.SocialError('Halaman seterusnya daripada Meta tidak dapat dibaca.')
    return after


def account_candidates(base, token, expiry, app_id, user_id, started, transport):
    params = {'fields': 'account_id,name,account_status', 'limit': 100}
    candidates = []
    cipher = social._encrypt(token)
    for _ in range(3):
        data = social.provider_call('GET', base + '/me/adaccounts', params, token, transport)
        for row in _rows(data):
            if str(row.get('account_status')) != '1':
                continue
            account = social._numeric_id(row.get('account_id'))
            candidates.append({
                'id': secrets.token_hex(16), 'platform': PLATFORM, 'account_id': account,
                'account_name': str(row.get('name') or account)[:160], 'page_id': '',
                'token_cipher': cipher, 'token_expires_at': expiry,
                'scopes_json': json.dumps(social.SCOPES[PLATFORM]), 'provider_app_id': app_id,
                'provider_user_id': user_id, 'authorization_started_at': started,
            })
        after = _after(data)
        if not after:
            break
        params['after'] = after
    if not candidates:
        raise social.SocialError('Tiada akaun iklan aktif ditemui. Semak akses akaun iklan anda dalam Meta Business Settings.', 409, 'authorization')
    return candidates


def _connection(conn, postgres, owner, expected_id, stamp):
    ph = meja._ph(postgres)
    cfg = social._config(PLATFORM)
    cur = conn.cursor()
    cur.execute(f'SELECT * FROM marketing_connections WHERE akaun_id={ph} AND platform={ph}', (owner, PLATFORM))
    row = social._row(cur)
    if (not row or row['status'] != 'connected' or not social._identity_matches(row, cfg)
            or row['token_expires_at'] <= stamp or not set(social.SCOPES[PLATFORM]).issubset(json.loads(row['scopes_json']))):
        raise social.SocialError('Sambungkan akaun Facebook Ads untuk meneruskan.', 409, 'authorization')
    if row['account_id'] != expected_id:
        raise social.SocialError('Akaun iklan pilihan telah berubah. Muat semula ruang kerja.', 409, 'account_changed')
    return row, social._base(PLATFORM, cfg) + '/act_' + social._numeric_id(row['account_id'])


def _report(conn, postgres, owner, payload, stamp, transport):
    period, cursor = payload.get('period'), payload.get('after', '')
    if (set(payload) - {'period', 'account_id', 'after'} or not isinstance(period, str) or period not in PERIODS
            or not isinstance(cursor, str) or len(cursor) > 2000):
        raise social.SocialError('Tempoh atau halaman laporan tidak sah.', 400, 'validation')
    grant, base = _connection(conn, postgres, owner, payload['account_id'], stamp)
    metrics = 'account_currency,spend,impressions,clicks'
    params = {'fields': 'id,name,effective_status,insights.date_preset(' + period + '){' + metrics + '}', 'limit': 25}
    if cursor:
        params['after'] = cursor
    data = social.provider_call('GET', base + '/campaigns', params, social._decrypt(grant['token_cipher']), transport)
    rows = []
    for row in _rows(data):
        insights = _rows(row['insights']) if 'insights' in row else []
        # One aggregate per campaign, all placements, in the ad account's own currency.
        stats = insights[0] if insights else None
        rows.append({'id': social._numeric_id(row.get('id')), 'name': str(row.get('name') or '')[:250],
                     'status': str(row.get('effective_status') or '')[:48],
                     'metrics': {k: str(stats[k]) for k in metrics.split(',') if k in stats} if stats else None})
    from marketing_ad_simulation import for_report
    for_report(conn, postgres, owner, payload['account_id'], rows)
    current, _ = _connection(conn, postgres, owner, payload['account_id'], stamp)
    if current['token_cipher'] != grant['token_cipher']:
        raise social.SocialError('Sambungan telah berubah. Muat semula laporan.', 409, 'account_changed')
    return 200, {'ok': True, 'campaigns': rows, 'after': _after(data)}


def _create(conn, postgres, owner, payload, stamp, transport):
    from pelanggan_marketing import _read
    if (set(payload) != {'account_id', 'plan_id', 'version', 'objective', 'ordinary_ad'}
            or not isinstance(payload['plan_id'], str) or not social.ID.fullmatch(payload['plan_id'])
            or type(payload['version']) is not int or payload['version'] < 1
            or not isinstance(payload['objective'], str) or payload['objective'] not in OBJECTIVES
            or payload['ordinary_ad'] is not True):
        raise social.SocialError('Semak pelan, objektif dan kategori iklan.', 400, 'validation')
    ph = meja._ph(postgres)
    key = (owner, payload['account_id'], payload['plan_id'])
    where = f'akaun_id={ph} AND account_id={ph} AND plan_id={ph}'

    attempt = secrets.token_hex(16)

    def reserve():
        meja._kunci_akaun(conn, postgres, owner)
        grant, base = _connection(conn, postgres, owner, key[1], stamp)
        cur = conn.cursor()
        cur.execute(f'SELECT campaign_id FROM marketing_ads_creations WHERE {where}', key)
        previous = cur.fetchone()
        if previous:
            if not previous[0]:
                raise social.SocialError(UNCERTAIN, 409, 'check_meta')
            return 200, {'ok': True, 'campaign_id': previous[0], 'existing': True}
        plan = _read(conn, postgres, owner, 'campaigns', key[2])
        if not plan:
            raise social.SocialError('Pelan kempen tidak ditemui.', 404, 'not_found')
        if plan['version'] != payload['version'] or plan['status'] in {'archived', 'completed'}:
            raise social.SocialError('Pelan telah berubah atau ditutup. Muat semula ruang kerja.', 409, 'conflict')
        token = social._decrypt(grant['token_cipher'])
        # Commit this marker before sending. A process crash or lost response must not duplicate a campaign.
        cur.execute(f"""INSERT INTO marketing_ads_creations
            (akaun_id,account_id,plan_id,platform,provider_app_id,provider_user_id,attempt_id,created_at)
            VALUES ({','.join([ph] * 8)})""", (*key, PLATFORM, grant['provider_app_id'], grant['provider_user_id'], attempt, meja._iso(stamp)))
        return 200, {'url': base + '/campaigns', 'name': plan['title'], 'token': token}

    status, reserved = meja._dalam(conn, postgres, reserve)
    if 'url' not in reserved:
        return status, reserved

    params = {'name': reserved['name'], 'objective': payload['objective'], 'buying_type': 'AUCTION',
              'status': 'PAUSED', 'special_ad_categories': '[]', 'is_adset_budget_sharing_enabled': 'false'}
    try:
        result = social.provider_call('POST', reserved['url'], params, reserved['token'], transport)
    except social.SocialError as error:
        if error.ambiguous:
            raise social.SocialError(UNCERTAIN, 409, 'check_meta') from None
        conn.cursor().execute(f'DELETE FROM marketing_ads_creations WHERE {where} AND attempt_id={ph}', (*key, attempt))
        conn.commit()
        raise
    campaign_id = result.get('id')
    if not isinstance(campaign_id, str) or not social.PERSON_ID.fullmatch(campaign_id):
        raise social.SocialError(UNCERTAIN, 409, 'check_meta')
    cur = conn.cursor()
    cur.execute(f'UPDATE marketing_ads_creations SET campaign_id={ph} WHERE {where} AND attempt_id={ph}', (campaign_id, *key, attempt))
    conn.commit()
    return 200, {'ok': True, 'campaign_id': campaign_id, 'existing': False}


def dispatch(method, action, conn, postgres, owner, payload, stamp, transport=None):
    if method != 'POST' or action not in {'report', 'campaigns', 'post-preview', 'post-launch', 'post-outcome', 'post-orders'}:
        return 404, {'ok': False, 'error': 'Endpoint tidak ditemui.'}
    if not isinstance(payload, dict) or not isinstance(payload.get('account_id'), str) or not social.PERSON_ID.fullmatch(payload['account_id']):
        return 400, {'ok': False, 'error': 'Akaun iklan tidak sah.'}
    try:
        if action in {'post-preview', 'post-launch', 'post-outcome', 'post-orders'}:
            from marketing_post_ads import dispatch as dispatch_post
            return dispatch_post(action, conn, postgres, owner, payload, stamp, transport)
        operation = _report if action == 'report' else _create
        return operation(conn, postgres, owner, payload, stamp, transport)
    except social.SocialError as error:
        conn.rollback()
        return error.status, {'ok': False, 'error': str(error), 'code': error.kind}
