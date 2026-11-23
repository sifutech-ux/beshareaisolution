"""Isolated, opt-in Telegram group verification and scheduled text publisher.

Run one replica for one BeShare Marketing bot. Group posting is off by default.
A lost sendMessage response is marked uncertain, never silently re-sent.
"""
import argparse
import json
import os
import re
import time

import pelanggan_meja as meja
import marketing_groups as groups


def _poll_claims(conn, postgres, stamp):
    p = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute('SELECT update_id FROM marketing_group_telegram_cursor WHERE singleton=1')
    row = cur.fetchone()
    cursor = row[0] if row else 0
    me = groups.bot_call('getMe')
    if not isinstance(me, dict) or type(me.get('id')) is not int:
        raise groups.TelegramError('Bot Telegram tidak dapat disahkan.', 'config')
    updates = groups.bot_call('getUpdates', {'offset': cursor + 1, 'limit': 20, 'timeout': 0,
                                              'allowed_updates': ['message']})
    verified = 0
    for update in updates if isinstance(updates, list) else ():
        uid = update.get('update_id')
        if type(uid) is not int or uid <= cursor:
            continue
        msg = update.get('message') or {}
        private = (msg.get('chat') or {}).get('type') == 'private'
        text = msg.get('text', '')
        sender = (msg.get('from') or {}).get('id')
        match = re.fullmatch(r'/start(?:@[A-Za-z0-9_]+)?\s+([A-Za-z0-9_-]{20,64})', text) if isinstance(text, str) else None
        if private and type(sender) is int and match:
            if groups.approve_private_start(conn, postgres, sender, match.group(1), me['id'], stamp):
                verified += 1
        def save_cursor():
            conn.cursor().execute(f'''UPDATE marketing_group_telegram_cursor SET update_id={p}
                                      WHERE singleton=1 AND update_id<{p}''', (uid, uid))
            return 200, {'ok': True}
        meja._dalam(conn, postgres, save_cursor)
        cursor = uid
    return verified


def _claim_one(conn, postgres, stamp):
    p = meja._ph(postgres)
    def claim():
        c = conn.cursor()
        c.execute(f'''SELECT j.id,j.akaun_id,j.destination_id,j.body,d.chat_id
            FROM marketing_group_jobs j
            JOIN marketing_group_destinations d ON d.id=j.destination_id AND d.akaun_id=j.akaun_id
            WHERE j.status='queued' AND j.scheduled_at<={p} AND d.platform='telegram'
            ORDER BY j.scheduled_at,j.id LIMIT 20''', (stamp,))
        for jid, owner, gid, body, chat_id in c.fetchall():
            c.execute(f'''UPDATE marketing_group_jobs SET status='sending',updated_at={p}
                WHERE id={p} AND akaun_id={p} AND status='queued' ''',
                (meja._iso(stamp), jid, owner))
            if c.rowcount:
                return 200, {'job': {'id': jid, 'owner': owner, 'gid': gid, 'body': body, 'chat_id': chat_id}}
        return 200, {'job': None}
    return meja._dalam(conn, postgres, claim)[1]['job']


def _finish(conn, postgres, job, stamp, status, error='', message_id='', delay=0):
    p = meja._ph(postgres)
    def finish():
        c = conn.cursor()
        when = int(stamp) + delay if delay else int(stamp)
        c.execute(f'''UPDATE marketing_group_jobs
            SET status={p}, last_error={p}, provider_message_id={p},sent_at={p},
                scheduled_at=CASE WHEN {p}>0 THEN {p} ELSE scheduled_at END,
                updated_at={p}
            WHERE id={p} AND akaun_id={p} AND status='sending' ''',
            (status, error, message_id, stamp if status == 'sent' else 0,
             delay, when, meja._iso(stamp), job['id'], job['owner']))
        return 200, {'ok': c.rowcount == 1}
    return meja._dalam(conn, postgres, finish)[1]['ok']


def _send(conn, postgres, job, bot_id, stamp):
    p = meja._ph(postgres)
    c = conn.cursor()
    c.execute(f'''SELECT chat_id,status FROM marketing_group_destinations
                  WHERE id={p} AND akaun_id={p} AND platform='telegram' ''',
              (job['gid'], job['owner']))
    target = c.fetchone()
    if not target or target[1] != 'verified' or target[0] != job['chat_id']:
        _finish(conn, postgres, job, stamp, 'failed', 'Sambungan kumpulan tidak lagi sah.')
        return 'failed'
    c.execute(f'''SELECT 1 FROM marketing_group_jobs
        WHERE destination_id={p} AND status='sent' AND sent_at>{p}
        AND id!={p} LIMIT 1''', (job['gid'], stamp - 86400, job['id']))
    if c.fetchone():
        _finish(conn, postgres, job, stamp, 'failed', 'Had satu hantaran setiap 24 jam bagi kumpulan ini.')
        return 'failed'
    try:
        bot = groups.bot_call('getChatMember', {'chat_id': job['chat_id'], 'user_id': bot_id})
        if not isinstance(bot, dict) or bot.get('status') not in ('creator', 'administrator'):
            _finish(conn, postgres, job, stamp, 'failed', 'Bot bukan pentadbir kumpulan lagi.')
            return 'failed'
        result = groups.bot_call('sendMessage', {'chat_id': job['chat_id'], 'text': job['body'],
                                                'disable_notification': False})
        message_id = result.get('message_id') if isinstance(result, dict) else None
        if type(message_id) is not int:
            _finish(conn, postgres, job, stamp, 'uncertain', 'Respons penghantaran tiada ID yang sah.')
            return 'uncertain'
        _finish(conn, postgres, job, stamp, 'sent', message_id=str(message_id))
        return 'sent'
    except groups.TelegramError as exc:
        if exc.kind == 'rate_limit':
            _finish(conn, postgres, job, stamp, 'queued', 'Telegram memerlukan jeda.', delay=exc.retry_after)
            return 'deferred'
        if exc.kind == 'rejected':
            _finish(conn, postgres, job, stamp, 'failed', 'Telegram menolak penghantaran atau izin telah berubah.')
            return 'failed'
        _finish(conn, postgres, job, stamp, 'uncertain', 'Respons API tidak pasti; semak Telegram sebelum membuat siaran baharu.')
        return 'uncertain'


def _recover_stuck(conn, postgres, stamp):
    p = meja._ph(postgres)
    def recover():
        c = conn.cursor()
        c.execute(f'''UPDATE marketing_group_jobs SET status='uncertain',
            last_error='Worker terhenti ketika penghantaran; semak Telegram.',
            updated_at={p} WHERE status='sending' AND updated_at<{p}''',
            (meja._iso(stamp), meja._iso(stamp - 600)))
        return 200, {'recovered': c.rowcount}
    return meja._dalam(conn, postgres, recover)[1]['recovered']


def run_once(connect, postgres=True, now=None, limit=10):
    result = {'worker_enabled': os.getenv('MARKETING_GROUP_WORKER_ENABLED') == '1',
              'publishing_enabled': os.getenv('MARKETING_GROUP_PUBLISH_ENABLED') == '1',
              'verified': 0, 'sent': 0, 'failed': 0, 'uncertain': 0, 'deferred': 0, 'recovered': 0}
    if not result['worker_enabled']:
        return result
    conn = connect()
    stamp = int(time.time() if now is None else now)
    try:
        result['verified'] = _poll_claims(conn, postgres, stamp)
        if not result['publishing_enabled']:
            return result
        result['recovered'] = _recover_stuck(conn, postgres, stamp)
        me = groups.bot_call('getMe')
        bot_id = me.get('id') if isinstance(me, dict) else None
        if type(bot_id) is not int:
            raise groups.TelegramError('Identiti bot Telegram tidak sah.', 'config')
        for _ in range(max(1, min(20, int(limit)))):
            job = _claim_one(conn, postgres, stamp)
            if not job:
                break
            try:
                result[_send(conn, postgres, job, bot_id, stamp)] += 1
            except Exception:
                conn.rollback()
                # Sending state is intentionally left durable and later becomes uncertain.
                raise
    finally:
        conn.close()
    return result


def main():
    parser = argparse.ArgumentParser(description='Permissioned BeShare Telegram Group Publisher')
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--interval', type=int, default=15)
    parser.add_argument('--limit', type=int, default=10)
    args = parser.parse_args()
    from pelanggan_pangkalan import buka
    conn = buka()
    try:
        groups.ensure_schema(conn, True)
        conn.commit()
    finally:
        conn.close()
    while True:
        try:
            print(json.dumps(run_once(buka, True, limit=args.limit)), flush=True)
        except Exception:
            print(json.dumps({'ok': False, 'error': 'Pekerja kumpulan gagal; semak log selamat dan pangkalan akaun.'}), flush=True)
            if args.once:
                raise SystemExit(1)
        if args.once:
            break
        time.sleep(max(5, min(args.interval, 120)))


if __name__ == '__main__':
    main()
