"""Turn a published Facebook Page story into a capped click-to-WhatsApp ad."""
import hashlib
import json
import re
import secrets
import time

import marketing_facebook_ads as ads
import marketing_publication as publication
import marketing_social as social
import pelanggan_meja as meja

# Meta uses hundredths for these account currencies. Never guess a currency offset.
CURRENCIES = {'MYR', 'SGD', 'AUD', 'USD', 'GBP', 'EUR', 'NZD', 'CAD'}
COUNTRIES = {'MY', 'SG', 'BN', 'ID', 'TH', 'AU', 'GB', 'US'}
WHERE = 'akaun_id={} AND account_id={} AND post_id={}'
CHECK = 'Penghantaran sedang diproses atau belum dapat disahkan. Semak kempen dalam Meta Ads Manager sebelum mencuba lagi.'


def ensure_schema(conn):
    conn.cursor().execute("""CREATE TABLE IF NOT EXISTS marketing_post_ads (
        akaun_id TEXT NOT NULL, account_id TEXT NOT NULL, post_id TEXT NOT NULL,
        platform TEXT NOT NULL, provider_app_id TEXT NOT NULL, provider_user_id TEXT NOT NULL,
        page_app_id TEXT NOT NULL, page_user_id TEXT NOT NULL, nonce TEXT NOT NULL,
        settings TEXT NOT NULL, expires_at BIGINT NOT NULL, phase TEXT NOT NULL,
        campaign_id TEXT NOT NULL DEFAULT '', adset_id TEXT NOT NULL DEFAULT '',
        creative_id TEXT NOT NULL DEFAULT '', ad_id TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (akaun_id,account_id,post_id))""")


def fail(message, kind='validation', status=400):
    raise social.SocialError(message, status, kind)


def source(conn, postgres, owner, body, stamp):
    grant, account_url = ads._connection(conn, postgres, owner, body['account_id'], stamp)
    post = publication._post(conn, postgres, owner, body['post_id'])
    if json.loads(post['data_json']).get('platform') != 'facebook':
        fail('Pilih hantaran Facebook Page yang sudah diterbitkan.')
    page, _ = social.load_bound_connection(conn, postgres, owner, post, stamp)
    return grant, account_url, post, page


def context(conn, postgres, owner, body, stamp, transport):
    grant, account_url, post, page = source(conn, postgres, owner, body, stamp)
    conn.rollback()
    account = social.provider_call('GET', account_url, {'fields': 'account_id,name,currency,account_status'},
                                   social._decrypt(grant['token_cipher']), transport)
    if str(account.get('account_id')) != body['account_id'] or str(account.get('account_status')) != '1':
        fail('Akaun iklan sudah berubah atau tidak aktif.', 'account_changed', 409)
    if account.get('currency') not in CURRENCIES:
        fail('Mata wang akaun ini belum disokong untuk iklan daripada hantaran.', 'currency', 409)
    page_id = social._numeric_id(page['account_id'])
    def read(node, fields):
        return social.provider_call('GET', page['base'] + '/' + node, {'fields': fields}, page['token'], transport)
    profile = read(page_id, 'id,name,whatsapp_number')
    phone = re.sub(r'[ ()-]', '', str(profile.get('whatsapp_number') or '')).lstrip('+')
    if str(profile.get('id')) != page_id or not re.fullmatch(r'[1-9][0-9]{6,14}', phone):
        fail('Pautkan nombor WhatsApp Business pada Facebook Page asal, kemudian buka semula pratonton.', 'whatsapp', 409)
    raw = social._provider_id(post['provider_post_id'])
    story_id = raw if '_' in raw else page_id + '_' + raw
    # Facebook can return a photo/video ID instead of a Page post ID. Find the
    # original published story by its attached media, never by matching copy.
    form = json.loads(post['data_json']).get('format')
    if '_' not in raw and form in {'image', 'video', 'reel'}:
        when = social._epoch(post['updated_at'])
        params = {'fields': 'id,attachments{target}', 'limit': 100}
        if when:
            params.update(since=when - 86400, until=when + 86400)
        matches = []
        for _ in range(3):
            feed = social.provider_call('GET', page['base'] + '/' + page_id + '/published_posts',
                                        params, page['token'], transport)
            for entry in ads._rows(feed):
                attachments = (entry.get('attachments') or {}).get('data') or []
                if any(str((item.get('target') or {}).get('id')) == raw for item in attachments):
                    matches.append(entry.get('id'))
            after = ads._after(feed)
            if not after:
                break
            params['after'] = after
        if after or len(matches) != 1:
            fail('Meta belum dapat memadankan media dengan satu hantaran asal. Semak hantaran ini dalam Ads Manager.', 'story', 409)
        story_id = matches[0]
    if not isinstance(story_id, str) or not re.fullmatch(re.escape(page_id) + r'_[0-9]{1,40}', story_id):
        fail('Hantaran ini bukan milik Facebook Page yang dipautkan.', 'story', 409)
    story = read(story_id, 'id,from,message,permalink_url,is_published,full_picture,updated_time')
    if (story.get('id') != story_id or str((story.get('from') or {}).get('id')) != page_id
            or story.get('is_published') is not True):
        fail('Meta belum mengesahkan hantaran Page ini sebagai hantaran yang telah diterbitkan.', 'story', 409)
    return {'page_id': page_id, 'page_name': str(profile.get('name') or page_id),
            'account_id': body['account_id'], 'account_name': str(account.get('name') or body['account_id']),
            'currency': account['currency'], 'phone': '+' + phone, 'story_id': story_id,
            'text': str(story.get('message') or ''), 'post_url': publication._url(story.get('permalink_url'), 'facebook'),
            'picture': str(story.get('full_picture') or ''), 'updated': str(story.get('updated_time') or '')}


def settings(body, ctx):
    budget, days, country = body.get('budget'), body.get('days'), body.get('country')
    if not isinstance(budget, str) or not re.fullmatch(r'(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?', budget):
        fail('Masukkan jumlah bajet yang sah dengan sehingga dua tempat perpuluhan.')
    whole, _, fraction = budget.partition('.')
    minor = int(whole) * 100 + int(fraction.ljust(2, '0'))
    if minor < 1 or type(days) is not int or not 1 <= days <= 30 or not isinstance(country, str) or country not in COUNTRIES:
        fail('Semak bajet, negara audiens dan tempoh 1 hingga 30 hari.')
    creative = hashlib.sha256(json.dumps([ctx['text'], ctx['post_url'], ctx['updated']]).encode()).hexdigest()
    return {k: ctx[k] for k in ('page_id', 'story_id', 'currency', 'phone')} | {
        'creative_hash': creative, 'budget_minor': minor, 'days': days, 'country': country}


def record(conn, postgres, key):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute('SELECT * FROM marketing_post_ads WHERE ' + WHERE.format(ph, ph, ph), key)
    return social._row(cur)


def preview(conn, postgres, owner, body, stamp, transport):
    if set(body) != {'account_id', 'post_id', 'budget', 'days', 'country'}:
        fail('Semak maklumat pratonton.')
    ctx = context(conn, postgres, owner, body, stamp, transport)
    spec = settings(body, ctx)
    data = json.dumps(spec, sort_keys=True)
    key = (owner, body['account_id'], body['post_id'])
    ph = meja._ph(postgres)
    def save():
        meja._kunci_akaun(conn, postgres, owner)
        grant, _, _, page = source(conn, postgres, owner, body, stamp)
        old = record(conn, postgres, key)
        if old and old['phase'] == 'active':
            return 200, {'ok': True, 'already_submitted': True, 'campaign_id': old['campaign_id']}
        if old and old['phase'] == 'sending':
            fail(CHECK, 'check_meta', 409)
        nonce = secrets.token_hex(20)
        resume = old and old['settings'] == data
        ids = [old[k] if resume else '' for k in ('campaign_id', 'adset_id', 'creative_id', 'ad_id')]
        if old:
            conn.cursor().execute('DELETE FROM marketing_post_ads WHERE ' + WHERE.format(ph, ph, ph), key)
        fields = ('akaun_id','account_id','post_id','platform','provider_app_id','provider_user_id',
                  'page_app_id','page_user_id','nonce','settings','expires_at','phase','campaign_id','adset_id','creative_id','ad_id')
        values = (*key, ads.PLATFORM, grant['provider_app_id'], grant['provider_user_id'], page['provider_app_id'],
                  page['provider_user_id'], nonce, data, stamp + 900, 'review', *ids)
        conn.cursor().execute(f"INSERT INTO marketing_post_ads ({','.join(fields)}) VALUES ({','.join([ph]*len(fields))})", values)
        return 200, {'ok': True, 'preview': {**ctx, **spec}, 'review_id': nonce}
    return meja._dalam(conn, postgres, save)


def launch(conn, postgres, owner, body, stamp, transport):
    if (set(body) != {'account_id','post_id','review_id','approve_spend','ordinary_ad'}
            or not isinstance(body['review_id'], str) or not re.fullmatch('[0-9a-f]{40}', body['review_id'])
            or body['approve_spend'] is not True or body['ordinary_ad'] is not True):
        fail('Kelulusan belanja dan pengesahan kategori iklan diperlukan.')
    started = time.monotonic()
    ph = meja._ph(postgres)
    key = (owner, body['account_id'], body['post_id'])
    def reserve():
        meja._kunci_akaun(conn, postgres, owner)
        grant, base, _, page = source(conn, postgres, owner, body, stamp)
        row = record(conn, postgres, key)
        if not row or row['nonce'] != body['review_id']:
            fail('Pratonton telah berubah. Semak semula butiran iklan.', 'stale', 409)
        if row['phase'] == 'active':
            return 200, {'ok': True, 'campaign_id': row['campaign_id']}
        if row['phase'] == 'sending':
            fail(CHECK, 'check_meta', 409)
        if (row['expires_at'] < stamp or row['phase'] != 'review'
                or (grant['provider_app_id'], grant['provider_user_id'], page['provider_app_id'], page['provider_user_id'])
                != tuple(row[k] for k in ('provider_app_id','provider_user_id','page_app_id','page_user_id'))):
            fail('Semak semula pratonton sebelum meluluskan iklan.', 'stale', 409)
        conn.cursor().execute(f"UPDATE marketing_post_ads SET phase='sending' WHERE nonce={ph} AND akaun_id={ph}", (row['nonce'],owner))
        return 200, (row, base, social._decrypt(grant['token_cipher']))
    status, reserved = meja._dalam(conn, postgres, reserve)
    if isinstance(reserved, dict):
        return status, reserved
    row, base, token = reserved
    spec = json.loads(row['settings'])
    def save(field, value):
        cur = conn.cursor()
        cur.execute(f"UPDATE marketing_post_ads SET {field}={ph} WHERE nonce={ph} AND akaun_id={ph} AND phase='sending'",
                    (value,row['nonce'],owner))
        conn.commit()
        if cur.rowcount != 1:
            fail(CHECK, 'check_meta', 409)
        row[field] = value
    def create(field, edge, params):
        if row[field]:
            return
        reply = social.provider_call('POST', base + '/' + edge, params, token, transport)
        if not isinstance(reply.get('id'), str) or not social.PERSON_ID.fullmatch(reply['id']):
            raise social.SocialError(CHECK, 409, 'check_meta', ambiguous=True)
        save(field, reply['id'])
    try:
        ctx = context(conn, postgres, owner, body, stamp, transport)
        verify = settings({'budget': '%d.%02d' % divmod(spec['budget_minor'], 100), **spec}, ctx)
        if verify != spec:
            fail('Hantaran, mata wang atau destinasi WhatsApp berubah. Buka pratonton semula.', 'stale', 409)
        name = ('BeShare · ' + (ctx['text'].strip() or ctx['page_name']))[:100]
        create('campaign_id', 'campaigns', {'name': name, 'objective': 'OUTCOME_ENGAGEMENT', 'buying_type': 'AUCTION',
               'status': 'PAUSED', 'special_ad_categories': '[]', 'is_adset_budget_sharing_enabled': 'false'})
        api = base.rsplit('/', 1)[0]
        def check_parent():
            campaign = social.provider_call('GET', api + '/' + row['campaign_id'],
                                            {'fields': 'status,adsets.limit(2){id}'}, token, transport)
            children = campaign.get('adsets') or {'data': []}
            ids = [item.get('id') for item in ads._rows(children)]
            if (campaign.get('status') != 'PAUSED' or children.get('paging', {}).get('next')
                    or ids != ([row['adset_id']] if row['adset_id'] else [])):
                fail('Kempen ini telah diubah di Meta. Semak status dan set iklannya dalam Ads Manager.', 'mismatch', 409)
        check_parent()
        create('adset_id', 'adsets', {'name': name, 'campaign_id': row['campaign_id'], 'destination_type': 'WHATSAPP',
               'billing_event': 'IMPRESSIONS', 'optimization_goal': 'CONVERSATIONS', 'bid_strategy': 'LOWEST_COST_WITHOUT_CAP',
               'promoted_object': json.dumps({'page_id': spec['page_id'], 'whatsapp_phone_number': spec['phone'].lstrip('+')}),
               'targeting': json.dumps({'geo_locations': {'countries': [spec['country']]}, 'publisher_platforms': ['facebook']}),
               'lifetime_budget': spec['budget_minor'], 'end_time': stamp + spec['days'] * 86400, 'status': 'ACTIVE'})
        create('creative_id', 'adcreatives', {'name': name, 'object_story_id': spec['story_id'],
               'call_to_action': json.dumps({'type': 'WHATSAPP_MESSAGE', 'value': {'app_destination': 'WHATSAPP',
                                             'link': 'https://wa.me/' + spec['phone'][1:]}})})
        create('ad_id', 'ads', {'name': name, 'adset_id': row['adset_id'],
               'creative': json.dumps({'creative_id': row['creative_id']}), 'status': 'ACTIVE'})
        # The parent is activated last, after the capped set and destination ad exist.
        check_parent()
        actual = social.provider_call('GET', api + '/' + row['adset_id'],
                                     {'fields': 'campaign_id,lifetime_budget,promoted_object,destination_type,end_time,targeting,ads.limit(2){id,creative{id,object_story_id}}'}, token, transport)
        promoted = actual.get('promoted_object') or {}
        children = actual.get('ads') or {'data': []}
        children_match = ads._rows(children) == [{'id': row['ad_id'], 'creative': {
            'id': row['creative_id'], 'object_story_id': spec['story_id']}}]
        if (not children_match or children.get('paging', {}).get('next')
                or str(actual.get('campaign_id')) != row['campaign_id'] or str(actual.get('lifetime_budget')) != str(spec['budget_minor'])
                or actual.get('destination_type') != 'WHATSAPP' or str(promoted.get('page_id')) != spec['page_id']
                or re.sub(r'[^0-9]', '', str(promoted.get('whatsapp_phone_number') or '')) != spec['phone'][1:]
                or (actual.get('targeting') or {}).get('geo_locations', {}).get('countries') != [spec['country']]
                or not stamp < (social._epoch(actual.get('end_time')) or 0) <= stamp + spec['days'] * 86400):
            fail('Butiran set iklan Meta tidak sepadan dengan pratonton. Semak kempen dijeda dalam Ads Manager.', 'mismatch', 409)
        grant_now, _, _, page_now = source(conn, postgres, owner, body, stamp + int(time.monotonic() - started))
        if ((grant_now['provider_user_id'], page_now['provider_user_id']) != (row['provider_user_id'], row['page_user_id'])
                or social._decrypt(grant_now['token_cipher']) != token):
            fail('Sambungan telah berubah. Semak pratonton semula.', 'stale', 409)
        conn.rollback()
        reply = social.provider_call('POST', api + '/' + row['campaign_id'], {'status': 'ACTIVE'}, token, transport)
        if reply.get('success') is not True:
            raise social.SocialError(CHECK, 409, 'check_meta', ambiguous=True)
        save('phase', 'active')
        return 200, {'ok': True, 'campaign_id': row['campaign_id']}
    except social.SocialError as error:
        if error.ambiguous:
            raise social.SocialError(CHECK, 409, 'check_meta') from None
        save('phase', 'rejected')
        raise


def dispatch(action, conn, postgres, owner, body, stamp, transport):
    if not isinstance(body.get('post_id'), str) or not social.ID.fullmatch(body['post_id']):
        fail('Hantaran tidak sah.')
    if action == 'post-preview':
        return preview(conn, postgres, owner, body, stamp, transport)
    return launch(conn, postgres, owner, body, stamp, transport)
