"""Isolated tests for permissioned BeShare Marketing group distribution."""
import os
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'account'))
import marketing_groups as groups
import marketing_group_worker as worker


OWNER = 'a' * 32
OTHER = 'b' * 32
GROUP = 'c' * 32
JOB = 'd' * 32
STAMP = 1795400000


class GroupsTest(unittest.TestCase):
    def setUp(self):
        # Tests explicitly opt into sending gate; production defaults off.
        enabled = patch.dict(os.environ, {'MARKETING_GROUP_PUBLISH_ENABLED': '1'})
        enabled.start()
        self.addCleanup(enabled.stop)
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.db = str(Path(self.directory.name) / 'account.sqlite')
        con = self.connect()
        con.execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT NOT NULL)')
        con.executemany('INSERT INTO pelanggan_akaun VALUES (?,?)',
                        [(OWNER, 'a@example.test'), (OTHER, 'b@example.test')])
        groups.ensure_schema(con, False)
        con.commit()
        con.close()

    def connect(self):
        return sqlite3.connect(self.db, timeout=3)

    def call(self, method, suffix='', body=None, owner=OWNER):
        conn = self.connect()
        try:
            return groups.dispatch(method, groups.ROOT + suffix, conn, False, owner, body, STAMP)
        finally:
            conn.close()

    def create(self, gid=GROUP, platform='telegram', owner=OWNER, **kwargs):
        payload = {'id': gid, 'platform': platform, 'ownership': 'owned',
                   'name': 'Group ujian', 'chat_ref': '@beshare_testing',
                   'approved': True, **kwargs}
        if platform == 'whatsapp':
            payload['chat_ref'] = ''
        return self.call('POST', '', payload, owner=owner)

    def seed_verified(self):
        self.assertEqual(self.create()[0], 201)
        conn = self.connect()
        conn.execute("UPDATE marketing_group_destinations SET status='verified',chat_id='-100123456789' WHERE id=?", (GROUP,))
        conn.commit()
        conn.close()

    def test_owner_isolation_and_approval_required(self):
        self.assertEqual(self.create(approved=False)[0], 400)
        self.assertEqual(self.create()[0], 201)
        self.assertEqual(self.create()[1]['replayed'], True)
        self.assertEqual(self.call('GET', '', owner=OTHER)[1]['groups'], [])
        self.assertEqual(self.call('DELETE', '/' + GROUP, owner=OTHER)[0], 404)
        self.assertEqual(self.create('f' * 32, owner=OTHER)[0], 201)
        self.assertEqual(self.call('GET', '', owner=OWNER)[1]['groups'][0]['name'], 'Group ujian')

    def test_whatsapp_manual_never_can_schedule(self):
        self.assertEqual(self.create(platform='whatsapp')[0], 201)
        group = self.call('GET')[1]['groups'][0]
        self.assertEqual(group['platform'], 'whatsapp')
        payload = {'id': JOB, 'destination_id': GROUP, 'body': 'Promosi', 'scheduled_at': STAMP+300, 'approved': True}
        self.assertEqual(self.call('POST', '/jobs', payload)[0], 409)

    def test_unverified_telegram_cannot_schedule(self):
        self.create()
        payload = {'id': JOB, 'destination_id': GROUP, 'body': 'Promosi', 'scheduled_at': STAMP+300, 'approved': True}
        self.assertEqual(self.call('POST', '/jobs', payload)[0], 409)

    def test_schedule_idempotency_frequency_and_disable(self):
        self.seed_verified()
        payload = {'id': JOB, 'destination_id': GROUP, 'body': 'Promosi yang diluluskan', 'scheduled_at': STAMP+300, 'approved': True}
        self.assertEqual(self.call('POST', '/jobs', payload)[0], 201)
        self.assertEqual(self.call('POST', '/jobs', payload)[1]['replayed'], True)
        self.assertEqual(self.call('POST', '/jobs', {**payload, 'id': 'e'*32})[0], 409)
        self.assertEqual(self.call('POST', '/jobs', {**payload, 'id': 'f'*32, 'scheduled_at': STAMP+86700})[0], 201)
        self.assertEqual(self.call('DELETE', '/' + GROUP)[0], 200)
        con = self.connect()
        statuses = {r[0] for r in con.execute('SELECT status FROM marketing_group_jobs').fetchall()}
        con.close()
        self.assertEqual(statuses, {'cancelled'})

    def test_bot_token_not_exposed_and_claim_is_short_lived(self):
        self.create()
        with patch.dict(os.environ, {'MARKETING_TELEGRAM_BOT_TOKEN': '123456:abcdefghijklmnopqrstuvwxyzABCDE1234567890'}, clear=False):
            with patch.object(groups, 'bot_call', return_value={'id': 111, 'username': 'beshare_mvp_bot'}):
                status, result = self.call('POST', '/' + GROUP + '/verify', {})
        self.assertEqual(status, 200, result)
        self.assertTrue(result['verification_url'].startswith('https://t.me/beshare_mvp_bot?start='))
        self.assertNotIn('123456:', str(result))
        con = self.connect()
        record = con.execute('SELECT code_hash,expires_at FROM marketing_group_claims').fetchone()
        con.close()
        self.assertEqual(record[1], STAMP + 900)
        self.assertNotIn(result['verification_url'].split('start=')[1], record[0])

    def test_admin_claim_required_to_verify(self):
        self.create()
        with patch.dict(os.environ, {'MARKETING_TELEGRAM_BOT_TOKEN': '123456:abcdefghijklmnopqrstuvwxyzABCDE1234567890'}):
            with patch.object(groups, 'bot_call', return_value={'id': 111, 'username': 'beshare_mvp_bot'}):
                result = self.call('POST', '/' + GROUP + '/verify', {})[1]
        code = result['verification_url'].split('start=')[1]
        def non_admin(name, *_args, **_kwargs):
            return {'id': -100123456789, 'type': 'supergroup'} if name == 'getChat' else {'status': 'member'}
        conn = self.connect()
        with patch.object(groups, 'bot_call', side_effect=non_admin):
            self.assertFalse(groups.approve_private_start(conn, False, 22, code, 111, STAMP))
        self.assertEqual(self.call('GET')[1]['groups'][0]['status'], 'pending')
        def admin(name, params=None):
            if name == 'getChat':
                return {'id': -100123456789, 'type': 'supergroup'}
            return {'status': 'administrator'}
        with patch.object(groups, 'bot_call', side_effect=admin):
            self.assertTrue(groups.approve_private_start(conn, False, 22, code, 111, STAMP))
            self.assertFalse(groups.approve_private_start(conn, False, 22, code, 111, STAMP))
        conn.close()
        self.assertEqual(self.call('GET')[1]['groups'][0]['status'], 'verified')

    def test_publish_gate_rejects_jobs_before_worker_activation(self):
        self.seed_verified()
        payload = {'id': JOB, 'destination_id': GROUP, 'body': 'Promosi',
                   'scheduled_at': STAMP + 300, 'approved': True}
        with patch.dict(os.environ, {'MARKETING_GROUP_PUBLISH_ENABLED': '0'}):
            status, data = self.call('POST', '/jobs', payload)
        self.assertEqual(status, 503, data)
        con = self.connect()
        self.assertEqual(con.execute('SELECT COUNT(*) FROM marketing_group_jobs').fetchone()[0], 0)
        con.close()

    def test_private_group_onboarding_without_numeric_chat_id(self):
        status, _ = self.create(chat_ref='')
        self.assertEqual(status, 201)
        self.assertEqual(self.call('GET')[1]['groups'][0]['status'], 'pending')
        with patch.dict(os.environ, {'MARKETING_TELEGRAM_BOT_TOKEN': '123456:abcdefghijklmnopqrstuvwxyzABCDE1234567890'}):
            with patch.object(groups, 'bot_call', return_value={'id': 111, 'username': 'beshare_mvp_bot'}):
                status, result = self.call('POST', '/' + GROUP + '/verify', {})
        self.assertEqual(status, 200, result)
        self.assertEqual(result['verification_mode'], 'group_picker')
        self.assertIn('?startgroup=', result['verification_url'])
        self.assertIn('&admin=manage_chat', result['verification_url'])
        code = result['verification_url'].split('?startgroup=')[1].split('&')[0]
        chat = {'id': -100123456789, 'type': 'supergroup'}
        connection = self.connect()
        with patch.object(groups, 'bot_call', return_value={'status': 'member'}):
            self.assertFalse(groups.approve_group_start(connection, False, 22, code, 111, chat, STAMP))
        self.assertEqual(self.call('GET')[1]['groups'][0]['status'], 'pending')
        with patch.object(groups, 'bot_call', return_value={'status': 'administrator'}):
            self.assertTrue(groups.approve_group_start(connection, False, 22, code, 111, chat, STAMP))
            self.assertFalse(groups.approve_group_start(connection, False, 22, code, 111, chat, STAMP))
        connection.close()
        verified = self.call('GET')[1]['groups'][0]
        self.assertEqual(verified['chat_id'], '-100123456789')
        self.assertEqual(verified['status'], 'verified')

    def test_group_link_is_only_accepted_in_the_target_bot_group(self):
        self.create(chat_ref='')
        with patch.dict(os.environ, {'MARKETING_TELEGRAM_BOT_TOKEN': '123456:abcdefghijklmnopqrstuvwxyzABCDE1234567890'}):
            with patch.object(groups, 'bot_call', return_value={'id': 111, 'username': 'beshare_mvp_bot'}):
                result = self.call('POST', '/' + GROUP + '/verify', {})[1]
        code = result['verification_url'].split('?startgroup=')[1].split('&')[0]
        updates = [
            {'update_id': 51, 'message': {'chat': {'id': -100123456789, 'type': 'supergroup'},
                                         'from': {'id': 22}, 'text': '/start@another_bot ' + code}},
            {'update_id': 52, 'message': {'chat': {'id': -100123456789, 'type': 'supergroup'},
                                         'from': {'id': 22}, 'text': '/start@beshare_mvp_bot ' + code}},
        ]
        def provider(name, params=None):
            if name == 'getMe':
                return {'id': 111, 'username': 'beshare_mvp_bot'}
            if name == 'getUpdates':
                return updates
            if name == 'getChatMember':
                return {'status': 'administrator'}
            raise AssertionError(name)
        with patch.dict(os.environ, {'MARKETING_GROUP_WORKER_ENABLED': '1', 'MARKETING_GROUP_PUBLISH_ENABLED': '0'}):
            with patch.object(groups, 'bot_call', side_effect=provider):
                result = worker.run_once(self.connect, False, now=STAMP)
        self.assertEqual(result['verified'], 1)
        self.assertEqual(result['sent'], 0)
        self.assertEqual(self.call('GET')[1]['groups'][0]['status'], 'verified')
        con = self.connect()
        self.assertEqual(con.execute('SELECT update_id FROM marketing_group_telegram_cursor').fetchone()[0], 52)
        con.close()

    def test_worker_disabled_and_uncertain_on_lost_response(self):
        self.seed_verified()
        payload = {'id': JOB, 'destination_id': GROUP, 'body': 'Hello world', 'scheduled_at': STAMP, 'approved': True}
        self.assertEqual(self.call('POST', '/jobs', payload)[0], 201)
        with patch.dict(os.environ, {'MARKETING_GROUP_WORKER_ENABLED': '0', 'MARKETING_GROUP_PUBLISH_ENABLED': '0'}):
            stats = worker.run_once(self.connect, False, now=STAMP)
        self.assertFalse(stats['worker_enabled'])
        def provider(name, params=None):
            if name == 'getMe':
                return {'id': 111}
            if name == 'getUpdates':
                return []
            if name == 'getChatMember':
                return {'status': 'administrator'}
            raise groups.TelegramError('network lost', 'uncertain')
        with patch.dict(os.environ, {'MARKETING_GROUP_WORKER_ENABLED': '1', 'MARKETING_GROUP_PUBLISH_ENABLED': '1'}):
            with patch.object(groups, 'bot_call', side_effect=provider):
                stats = worker.run_once(self.connect, False, now=STAMP)
        self.assertEqual(stats['uncertain'], 1)
        con = self.connect()
        self.assertEqual(con.execute('SELECT status FROM marketing_group_jobs WHERE id=?', (JOB,)).fetchone()[0], 'uncertain')
        con.close()


if __name__ == '__main__':
    unittest.main()
