"""Goal planning for a reviewed ad. All estimates use the selected owner's data."""
import json
import re
from decimal import Decimal

import marketing_social as social
import pelanggan_meja as meja

FIELDS = 'account_currency,spend,reach,inline_link_clicks,actions'
ACTIONS = {'messages': 'onsite_conversion.messaging_conversation_started_7d', 'engagement': 'post_engagement'}


def goal(body, channel):
    key, count = body.get('goal_metric'), body.get('goal_count')
    primary = 'clicks' if channel == 'link' else 'messages'
    if key not in (primary, 'reach', 'engagement') or type(count) is not int or not 1 <= count <= 10000000:
        raise social.SocialError('Semak ukuran dan jumlah sasaran simulasi.', 400, 'validation')
    return key, count


def result(stats, key):
    if key in ACTIONS:
        rows = stats.get('actions')
        if not isinstance(rows, list):
            return None
        values = [r.get('value') for r in rows if isinstance(r, dict) and r.get('action_type') == ACTIONS[key]]
        if len(values) != 1:
            return None
        raw = values[0]
    else:
        raw = stats.get({'clicks': 'inline_link_clicks', 'reach': 'reach'}[key])
    return int(raw) if re.fullmatch(r'\d{1,15}', str(raw)) else None


def insight_rows(conn, postgres, owner, account, ids, stamp, transport):
    from marketing_facebook_ads import _connection, _rows
    grant, base = _connection(conn, postgres, owner, account, stamp)
    conn.rollback()
    reply = social.provider_call('GET', base + '/insights', {
        'fields': 'adset_id,' + FIELDS, 'level': 'adset', 'date_preset': 'maximum',
        'filtering': json.dumps([{'field': 'adset.id', 'operator': 'IN', 'value': ids}]), 'limit': len(ids)
    }, social._decrypt(grant['token_cipher']), transport)
    current, _ = _connection(conn, postgres, owner, account, stamp)
    if current['token_cipher'] != grant['token_cipher']:
        raise social.SocialError('Akaun iklan berubah. Muat semula.', 409, 'account_changed')
    return {r['adset_id']: r for r in _rows(reply) if r.get('adset_id') in ids}


def audience(conn, postgres, owner, account, spec, stamp, transport):
    from marketing_facebook_ads import _connection, _rows
    from marketing_post_ads import delivery
    grant, base = _connection(conn, postgres, owner, account, stamp)
    conn.rollback()
    try:
        response = social.provider_call('GET', base + '/delivery_estimate', {
            'fields': 'estimate_ready,estimate_mau_lower_bound,estimate_mau_upper_bound',
            'optimization_goal': delivery(spec)[1],
            'targeting_spec': json.dumps({'geo_locations': {'countries': [spec['country']]}, 'publisher_platforms': ['facebook']})
        }, social._decrypt(grant['token_cipher']), transport, timeout=6)
        rows = _rows(response)
    except social.SocialError:
        return None
    if len(rows) != 1 or rows[0].get('estimate_ready') is not True:
        return None
    low, high = (rows[0].get(k) for k in ('estimate_mau_lower_bound', 'estimate_mau_upper_bound'))
    return [low, high] if type(low) is int and type(high) is int and 0 < low <= high else None


def suggestion(plan, text, channel):
    offer = str(plan.get('offer') or '').strip()
    if offer and offer.casefold() not in text.casefold():
        return 'Nyatakan tawaran daripada pelan dalam hantaran: ' + offer[:200]
    product = str(plan.get('product') or plan.get('title') or 'produk ini').strip()[:100]
    if channel == 'link':
        return 'Terangkan apa yang pelanggan boleh buat selepas klik pautan untuk ' + product + ', seperti melihat katalog atau mengisi borang tempahan.'
    return 'Akhiri hantaran tentang ' + product + ' dengan satu soalan mudah yang pelanggan boleh jawab dalam mesej pertama.'


def simulate(conn, postgres, owner, body, spec, ctx, stamp, transport):
    from pelanggan_marketing import _read
    key, count = goal(body, spec['destination'])
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT campaign_id FROM marketing_posts WHERE akaun_id={ph} AND id={ph}', (owner, body['post_id']))
    plan_id = cur.fetchone()[0]
    plan = _read(conn, postgres, owner, 'campaigns', plan_id) or {}
    simulation = {'metric': key, 'target': count, 'sample': 0, 'range': None,
                  'as_of': stamp, 'advice': suggestion(plan, ctx['text'], spec['destination'])}
    # Only completed BeShare flights in this plan. A previous preview's expiry is
    # not a campaign end date, so use the timestamp recorded on actual submission.
    cur.execute(f"""SELECT a.adset_id,a.settings FROM marketing_post_ads a JOIN marketing_posts p
        ON p.id=a.post_id AND p.akaun_id=a.akaun_id
        WHERE a.akaun_id={ph} AND a.account_id={ph} AND p.campaign_id={ph} AND a.phase='active'
        ORDER BY a.expires_at DESC LIMIT 100""", (owner, body['account_id'], plan_id))
    ids = []
    for adset_id, saved in cur.fetchall():
        previous = json.loads(saved)
        start = previous.get('submitted_at', 0)
        if (stamp - 90 * 86400 <= start and start + (previous['days'] + 7) * 86400 <= stamp
                and all(previous.get(k) == spec.get(k) for k in ('page_id','currency','destination','country','days','destination_url','phone'))
                and spec['budget_minor'] / 2 <= previous['budget_minor'] <= spec['budget_minor'] * 2):
            ids.append(adset_id)
        if len(ids) == 25:
            break
    simulation['audience'] = audience(conn, postgres, owner, body['account_id'], spec, stamp, transport)
    if not ids:
        return simulation
    try:
        rows = insight_rows(conn, postgres, owner, body['account_id'], ids, stamp, transport)
    except social.SocialError as exc:
        if exc.kind in ('authorization', 'account_changed'):
            raise
        simulation['history_unavailable'] = True
        return simulation
    counts = []
    met = 0
    for stats in rows.values():
        measured = result(stats, key)
        spend = str(stats.get('spend', ''))
        if stats.get('account_currency') == spec['currency'] and re.fullmatch(r'\d{1,12}(\.\d{1,2})?', spend) and Decimal(spend) > 0 and measured is not None:
            met += int(measured >= count)
            counts.append(int(Decimal(spec['budget_minor']) * measured / (100 * Decimal(spend))))
    simulation['sample'] = len(counts)
    # With missing outcomes a positive-only sample could be misleading.
    if len(counts) >= 3 and len(counts) == len(ids):
        simulation['range'] = [min(counts), max(counts)]
        simulation['historical_met'] = met
    return simulation


def for_report(conn, postgres, owner, account_id, campaigns):
    ids = [r['id'] for r in campaigns]
    if not ids:
        return
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f"""SELECT campaign_id,post_id,settings FROM marketing_post_ads
        WHERE akaun_id={ph} AND account_id={ph} AND phase='active'
        AND campaign_id IN ({','.join([ph]*len(ids))})""", (owner,account_id,*ids))
    matches = {cid: post for cid,post,s in cur.fetchall() if 'simulation' in json.loads(s)}
    for row in campaigns:
        if row['id'] in matches:
            row['simulation_post'] = matches[row['id']]


def outcome(conn, postgres, owner, body, stamp, transport, save_orders=False):
    from marketing_facebook_ads import _connection
    from marketing_post_ads import record
    keys = {'account_id','post_id','campaign_id'} | ({'orders'} if save_orders else set())
    if set(body) != keys or (save_orders and (type(body['orders']) is not int or not 0 <= body['orders'] <= 10000000)):
        raise social.SocialError('Semak butiran hasil kempen.', 400, 'validation')
    def read():
        grant, _ = _connection(conn, postgres, owner, body['account_id'], stamp)
        row = record(conn, postgres, (owner,body['account_id'],body['post_id']))
        if (not row or row['phase'] != 'active' or row['campaign_id'] != body['campaign_id']
                or row['provider_user_id'] != grant['provider_user_id'] or row['provider_app_id'] != grant['provider_app_id']):
            raise social.SocialError('Kempen simulasi tidak ditemui dalam akaun ini.', 404, 'not_found')
        spec = json.loads(row['settings'])
        if 'simulation' not in spec:
            raise social.SocialError('Tiada simulasi asal untuk kempen ini.', 404, 'not_found')
        return row, spec
    if save_orders:
        def save():
            meja._kunci_akaun(conn, postgres, owner)
            row, spec = read()
            spec['orders'] = body['orders']
            ph = meja._ph(postgres)
            conn.cursor().execute(f'UPDATE marketing_post_ads SET settings={ph} WHERE akaun_id={ph} AND nonce={ph}',
                                  (json.dumps(spec), owner, row['nonce']))
            return 200, {'ok': True, 'orders': spec['orders']}
        return meja._dalam(conn, postgres, save)
    row, spec = read()
    stats = insight_rows(conn, postgres, owner, body['account_id'], [row['adset_id']], stamp, transport).get(row['adset_id'], {})
    return 200, {'ok': True, 'simulation': spec['simulation'], 'actual': result(stats, spec['simulation']['metric']),
                 'spend': stats.get('spend') if stats.get('account_currency') == spec['currency'] else None,
                 'currency': spec['currency'], 'orders': spec.get('orders')}
