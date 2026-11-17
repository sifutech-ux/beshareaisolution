"""Durable social publishing worker for the dedicated Copilot account DB.

Every external write has a durable request marker. A worker crash or lost POST
response becomes ``uncertain`` and is never retried automatically. Media status
polling and explicit rate-limit rejections are the only automatic retries.
"""
import argparse
import json
import secrets
import time

import pelanggan_meja as meja
import marketing_social as social

LEASE_SECONDS = 120
MAX_RATE_RETRIES = 3
PROCESSING_SECONDS = 30 * 60


class LostLease(Exception):
    pass


def _written_id(value):
    try:
        return social._provider_id(value)
    except social.SocialError:
        raise social.SocialError('Platform menerima permintaan tanpa pengenal yang dapat disahkan.',
                                 502, 'provider', ambiguous=True) from None


def _post(conn, postgres, owner, key):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT * FROM marketing_posts WHERE id={ph} AND akaun_id={ph}', (key, owner))
    return social._row(cur)


def _claim(conn, postgres, worker, stamp):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT id,akaun_id FROM marketing_posts
                    WHERE status='queued' AND scheduled_at<={ph}
                    ORDER BY scheduled_at,id LIMIT 50''', (meja._iso(stamp),))
    queued = cur.fetchall()
    cur.execute(f'''SELECT p.id,p.akaun_id FROM marketing_posts p
                    JOIN marketing_publish_attempts a ON a.id=p.attempt_id AND a.akaun_id=p.akaun_id
                    WHERE p.status='publishing' AND a.available_at<={ph} AND a.lease_until<={ph}
                    ORDER BY a.available_at,p.id LIMIT 50''', (int(stamp), int(stamp)))
    resumable = cur.fetchall()
    for key, owner in resumable + queued:
        result = {}
        def claim():
            meja._kunci_akaun(conn, postgres, owner)
            item = _post(conn, postgres, owner, key)
            if not item:
                return 200, {}
            c = conn.cursor()
            if item['status'] == 'queued':
                when = social._epoch(item['scheduled_at'])
                if when is None or when > int(stamp):
                    return 200, {}
                attempt = secrets.token_hex(16)
                c.execute(f'''UPDATE marketing_posts SET status='publishing',attempt_id={ph},
                              version=version+1,updated_at={ph},last_error=''
                              WHERE id={ph} AND akaun_id={ph} AND status='queued' AND version={ph}''',
                          (attempt, meja._iso(stamp), key, owner, item['version']))
                if c.rowcount != 1:
                    return 200, {}
                c.execute(f'''INSERT INTO marketing_publish_attempts
                              (id,post_id,akaun_id,stage,available_at,lease_until,worker_id,created_at,updated_at)
                              VALUES ({','.join([ph] * 9)})''',
                          (attempt, key, owner, 'starting', int(stamp), int(stamp) + LEASE_SECONDS,
                           worker, meja._iso(stamp), meja._iso(stamp)))
            elif item['status'] == 'publishing':
                attempt = item['attempt_id']
                c.execute(f'''SELECT * FROM marketing_publish_attempts
                              WHERE id={ph} AND akaun_id={ph} AND post_id={ph}''', (attempt, owner, key))
                old = social._row(c)
                if not old or old['available_at'] > stamp or old['lease_until'] > stamp:
                    return 200, {}
                if old['stage'] in {'requesting_container', 'requesting_publish', 'requesting_facebook'}:
                    message = 'Permintaan platform terputus. Semak akaun platform sebelum membuat siaran baharu.'
                    c.execute(f'''UPDATE marketing_posts SET status='uncertain',last_error={ph},
                                  version=version+1,updated_at={ph}
                                  WHERE id={ph} AND akaun_id={ph} AND status='publishing' AND attempt_id={ph}''',
                              (message, meja._iso(stamp), key, owner, attempt))
                    c.execute(f'''UPDATE marketing_publish_attempts SET stage='uncertain',last_error={ph},
                                  lease_until=0,worker_id='',updated_at={ph}
                                  WHERE id={ph} AND akaun_id={ph}''', (message, meja._iso(stamp), attempt, owner))
                    return 200, {'recovered_uncertain': True}
                if old['stage'] not in {'starting', 'waiting_container', 'ready', 'waiting_facebook_video'}:
                    return 200, {}
                c.execute(f'''UPDATE marketing_publish_attempts SET worker_id={ph},lease_until={ph},updated_at={ph}
                              WHERE id={ph} AND akaun_id={ph} AND lease_until<={ph}''',
                          (worker, int(stamp) + LEASE_SECONDS, meja._iso(stamp), attempt, owner, int(stamp)))
                if c.rowcount != 1:
                    return 200, {}
            else:
                return 200, {}
            c.execute(f'SELECT * FROM marketing_publish_attempts WHERE id={ph} AND akaun_id={ph}', (attempt, owner))
            result.update({'post': _post(conn, postgres, owner, key), 'attempt': social._row(c)})
            return 200, {'ok': True}
        _status, meta = meja._dalam(conn, postgres, claim)
        if result:
            return result
        if meta.get('recovered_uncertain'):
            return {'recovered_uncertain': True}
    return None


def _advance_record(conn, postgres, job, worker, stamp, stage, *, container=None,
                    provider_id=None, delay=0, error='', retry_increment=0):
    ph = meja._ph(postgres)
    attempt, item = job['attempt'], job['post']
    def update():
        meja._kunci_akaun(conn, postgres, item['akaun_id'])
        fields = ['stage=' + ph, 'available_at=' + ph, 'lease_until=' + ph,
                  'worker_id=' + ph, 'last_error=' + ph, 'updated_at=' + ph,
                  'retry_count=retry_count+' + ph]
        values = [stage, int(stamp) + delay, 0 if delay else int(stamp) + LEASE_SECONDS,
                  '' if delay else worker, error, meja._iso(stamp), retry_increment]
        if container is not None:
            fields.append('container_id=' + ph)
            values.append(container)
        if provider_id is not None:
            fields.append('provider_post_id=' + ph)
            values.append(provider_id)
        cur = conn.cursor()
        cur.execute(f'''UPDATE marketing_publish_attempts SET {','.join(fields)}
                        WHERE id={ph} AND akaun_id={ph} AND worker_id={ph}''',
                    tuple(values + [attempt['id'], item['akaun_id'], worker]))
        if cur.rowcount != 1:
            raise LostLease()
        cur.execute(f'''UPDATE marketing_posts SET last_error={ph},updated_at={ph}
                        WHERE id={ph} AND akaun_id={ph} AND attempt_id={ph} AND status='publishing' ''',
                    (error, meja._iso(stamp), item['id'], item['akaun_id'], attempt['id']))
        return 200, {'ok': True}
    meja._dalam(conn, postgres, update)
    attempt.update(stage=stage, available_at=int(stamp) + delay, worker_id='' if delay else worker,
                   retry_count=attempt['retry_count'] + retry_increment)
    if container is not None:
        attempt['container_id'] = container
    if provider_id is not None:
        attempt['provider_post_id'] = provider_id


def _finish(conn, postgres, job, worker, stamp, status, error='', provider_id=''):
    ph = meja._ph(postgres)
    item, attempt = job['post'], job['attempt']
    provider_id = provider_id or attempt['provider_post_id']
    def finish():
        meja._kunci_akaun(conn, postgres, item['akaun_id'])
        cur = conn.cursor()
        cur.execute(f'''UPDATE marketing_publish_attempts SET stage={ph},last_error={ph},
                        provider_post_id={ph},lease_until=0,worker_id='',updated_at={ph}
                        WHERE id={ph} AND akaun_id={ph} AND worker_id={ph}''',
                    (status, error, provider_id, meja._iso(stamp),
                     attempt['id'], item['akaun_id'], worker))
        if cur.rowcount != 1:
            raise LostLease()
        cur.execute(f'''UPDATE marketing_posts SET status={ph},provider_post_id={ph},last_error={ph},
                        version=version+1,updated_at={ph} WHERE id={ph} AND akaun_id={ph}
                        AND status='publishing' AND attempt_id={ph}''',
                    (status, provider_id, error, meja._iso(stamp), item['id'], item['akaun_id'], attempt['id']))
        return 200, {'ok': True}
    meja._dalam(conn, postgres, finish)


def _mark_reconnect(conn, postgres, owner, platform, stamp):
    ph = meja._ph(postgres)
    def update():
        meja._kunci_akaun(conn, postgres, owner)
        conn.cursor().execute(f'''UPDATE marketing_connections SET status='reconnect_required',updated_at={ph}
                                  WHERE akaun_id={ph} AND platform={ph} AND status='connected' ''',
                              (meja._iso(stamp), owner, platform))
        return 200, {}
    meja._dalam(conn, postgres, update)


def _publish_step(conn, postgres, job, worker, stamp, transport=None):
    item, attempt = job['post'], job['attempt']
    connection, data = social.load_bound_connection(conn, postgres, item['akaun_id'], item, stamp)
    platform = data['platform']
    form = data['format']
    base = connection['base']
    account = social._provider_id(connection['account_id'])
    token = connection['token']
    stage = attempt['stage']
    text, media = data.get('body', ''), data.get('media_url', '')
    before_request = stage
    try:
        if stage == 'starting':
            cur = conn.cursor()
            ph = meja._ph(postgres)
            cur.execute(f'''SELECT status FROM marketing_campaigns WHERE id={ph} AND akaun_id={ph}''',
                        (item['campaign_id'], item['akaun_id']))
            campaign = cur.fetchone()
            if not campaign or campaign[0] in {'completed', 'archived'}:
                _finish(conn, postgres, job, worker, stamp, 'cancelled', 'Kempen telah ditutup sebelum siaran bermula.')
                return 'cancelled'
        if stage == 'starting' and platform == 'facebook':
            if form in {'text', 'link'}:
                url = base + '/' + account + '/feed'
                params = {'message': text, 'published': 'true'}
                if form == 'link':
                    params['link'] = media
            elif form == 'image':
                url = base + '/' + account + '/photos'
                params = {'url': media, 'caption': text, 'published': 'true'}
            else:
                url = 'https://graph-video.facebook.com/' + connection['config']['version'] + '/' + account + '/videos'
                params = {'file_url': media, 'description': text, 'published': 'true'}
            _advance_record(conn, postgres, job, worker, stamp, 'requesting_facebook')
            result = social.provider_call('POST', url, params, token, transport)
            published = _written_id(result.get('post_id') or result.get('id'))
            if form == 'video':
                _advance_record(conn, postgres, job, worker, stamp, 'waiting_facebook_video',
                                provider_id=published, delay=30)
                return 'processing'
            _finish(conn, postgres, job, worker, stamp, 'published', provider_id=published)
            return 'published'
        if stage == 'starting':
            if platform == 'instagram':
                params = {'caption': text}
                if form == 'image':
                    params['image_url'] = media
                else:
                    params.update(media_type='REELS', video_url=media)
                url = base + '/' + account + '/media'
            else:
                params = {'media_type': 'TEXT' if form in {'text', 'link'} else form.upper(),
                          'text': (text + ('\n' + media if form == 'link' else '')).strip()}
                if form in {'image', 'video'}:
                    params[form + '_url'] = media
                url = base + '/' + account + '/threads'
            _advance_record(conn, postgres, job, worker, stamp, 'requesting_container')
            result = social.provider_call('POST', url, params, token, transport)
            container = _written_id(result.get('id'))
            _advance_record(conn, postgres, job, worker, stamp, 'waiting_container', container=container,
                            delay=30 if platform == 'threads' else 15)
            return 'processing'
        if stage == 'waiting_facebook_video':
            video_id = social._provider_id(attempt['provider_post_id'])
            result = social.provider_call('GET', base + '/' + video_id, {'fields': 'status'}, token, transport)
            state = result.get('status') or {}
            if state.get('video_status') == 'ready':
                phase = state.get('publishing_phase') or {}
                if phase and phase.get('status') != 'complete':
                    _advance_record(conn, postgres, job, worker, stamp, stage, delay=30)
                    return 'processing'
                _finish(conn, postgres, job, worker, stamp, 'published', provider_id=video_id)
                return 'published'
            if state.get('video_status') == 'error':
                _finish(conn, postgres, job, worker, stamp, 'failed', 'Pemprosesan video Facebook gagal.')
                return 'failed'
            if int(stamp) - (social._epoch(attempt['created_at']) or stamp) > PROCESSING_SECONDS:
                _finish(conn, postgres, job, worker, stamp, 'uncertain', 'Status video belum dapat dipastikan. Semak Facebook sebelum mencuba semula.')
                return 'uncertain'
            _advance_record(conn, postgres, job, worker, stamp, stage, delay=30)
            return 'processing'
        if stage == 'waiting_container':
            container = social._provider_id(attempt['container_id'])
            field = 'status_code' if platform == 'instagram' else 'status'
            result = social.provider_call('GET', base + '/' + container, {'fields': field}, token, transport)
            state = result.get(field)
            if state in {'ERROR', 'EXPIRED'}:
                _finish(conn, postgres, job, worker, stamp, 'failed', 'Media ditolak atau luput semasa diproses oleh platform.')
                return 'failed'
            if state == 'PUBLISHED':
                # The API did not give us the resulting media ID. Do not publish again.
                _finish(conn, postgres, job, worker, stamp, 'uncertain', 'Platform melaporkan media telah diterbitkan. Semak pautan siaran pada platform.')
                return 'uncertain'
            if state != 'FINISHED':
                processing_limit = 5 * 60 if platform == 'threads' else PROCESSING_SECONDS
                if int(stamp) - (social._epoch(attempt['created_at']) or stamp) > processing_limit:
                    _finish(conn, postgres, job, worker, stamp, 'failed', 'Media belum siap diproses dalam had masa. Semak format media.')
                    return 'failed'
                _advance_record(conn, postgres, job, worker, stamp, stage, delay=60 if platform == 'threads' else 30)
                return 'processing'
            _advance_record(conn, postgres, job, worker, stamp, 'ready')
            stage = 'ready'
        if stage == 'ready':
            before_request = 'ready'
            _advance_record(conn, postgres, job, worker, stamp, 'requesting_publish')
            endpoint = '/media_publish' if platform == 'instagram' else '/threads_publish'
            result = social.provider_call('POST', base + '/' + account + endpoint,
                                          {'creation_id': social._provider_id(attempt['container_id'])}, token, transport)
            published = _written_id(result.get('id'))
            _finish(conn, postgres, job, worker, stamp, 'published', provider_id=published)
            return 'published'
        raise social.SocialError('Tahap penerbitan tidak sah.', 409, 'validation')
    except social.SocialError as error:
        if error.ambiguous:
            _finish(conn, postgres, job, worker, stamp, 'uncertain',
                    'Respons penerbitan tidak dapat dipastikan. Semak platform sebelum membuat siaran baharu.')
            return 'uncertain'
        if error.retryable and attempt['retry_count'] < MAX_RATE_RETRIES:
            _advance_record(conn, postgres, job, worker, stamp, before_request,
                            delay=60 * (2 ** attempt['retry_count']), error=str(error), retry_increment=1)
            return 'retry_wait'
        if error.kind == 'authorization':
            _mark_reconnect(conn, postgres, item['akaun_id'], platform, stamp)
        failed_status = 'uncertain' if attempt['provider_post_id'] else 'failed'
        _finish(conn, postgres, job, worker, stamp, failed_status, str(error))
        return failed_status


def run_once(connect, postgres=True, transport=None, now=None, limit=10):
    """A bounded worker tick. Tests inject transport; CLI never uses test DB fallback."""
    result = {'enabled': social._enabled(), 'processed': 0, 'published': 0, 'failed': 0, 'uncertain': 0,
              'processing': 0, 'retry_wait': 0, 'cancelled': 0}
    if not result['enabled']:
        return result
    conn = connect()
    worker = secrets.token_hex(16)
    try:
        for _ in range(max(1, min(int(limit), 100))):
            stamp = int(now if now is not None else time.time())
            job = _claim(conn, postgres, worker, stamp)
            if not job:
                break
            result['processed'] += 1
            if job.get('recovered_uncertain'):
                result['uncertain'] += 1
                continue
            try:
                outcome = _publish_step(conn, postgres, job, worker, stamp, transport)
                result[outcome] += 1
            except LostLease:
                conn.rollback()
            except social.SocialError as error:
                # Validation/config failures occur before an external request.
                failed_status = 'uncertain' if job['attempt']['provider_post_id'] else 'failed'
                _finish(conn, postgres, job, worker, stamp, failed_status, str(error))
                result[failed_status] += 1
            except Exception:
                conn.rollback()
                # The durable request marker remains. Expiry recovery will make
                # potentially committed external writes uncertain, never replay them.
                raise RuntimeError('Pekerja penerbitan terhenti; cubaan tersimpan untuk pemulihan selamat.') from None
    finally:
        conn.close()
    return result


def main():
    parser = argparse.ArgumentParser(description='BeShare account-only social publisher')
    parser.add_argument('--once', action='store_true', help='Process a bounded batch and exit')
    parser.add_argument('--interval', type=int, default=15)
    parser.add_argument('--limit', type=int, default=10)
    args = parser.parse_args()
    from pelanggan_pangkalan import buka
    from pelanggan_akaun import ensure_schema
    conn = buka()  # Existing account DB identity guard, no DATABASE_URL fallback.
    try:
        ensure_schema(conn, True)
        social.ensure_social_schema(conn, True)
        conn.commit()
    finally:
        conn.close()
    while True:
        try:
            print(json.dumps(run_once(buka, True, limit=args.limit)), flush=True)
        except Exception:
            print(json.dumps({'ok': False, 'error': 'Pekerja tidak dapat melengkapkan kitaran; semak pangkalan akaun.'}), flush=True)
            if args.once:
                raise SystemExit(1)
        if args.once:
            break
        time.sleep(max(5, min(args.interval, 60)))


if __name__ == '__main__':
    main()
