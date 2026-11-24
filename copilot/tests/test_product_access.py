import json
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import pelanggan_akaun as account

T = 1795450000


class ProductAccessTests(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.db = Path(self.dir.name)/'account.db'
        self.key = 'fixture-key'
        account._HITS.clear()
        _, user = self.call('daftar', {'email':'buyer@example.test','password':'initial-pass'})
        self.token = user['token']
        self.id = account.read_token(self.token,self.key,T)

    def call(self, path, body=None, auth='', when=T):
        return account.dispatch('GET' if body is None else 'POST', '/api/akaun/'+path, None, auth,
            json.dumps(body or {}).encode(),lambda: sqlite3.connect(self.db),False,self.key,'fixture',when)

    def operator(self, **data):
        with patch('pelanggan_akses.verified', return_value=True):
            return self.call('operasi',data,'Ticket fixture')

    def change(self, product, state, revision=1, **fields):
        return self.operator(operation='change',actor=9,id=self.id,product=product,state=state,revision=revision,**fields)

    def test_grants_are_product_specific_expire_and_can_be_revoked(self):
        auth = 'Bearer '+self.token
        self.assertEqual(self.call('identiti',auth=auth)[0],200)
        self.assertEqual(self.call('saya',auth=auth)[0],402)
        self.assertEqual(self.call('izin/marketing',auth=auth)[0],402)
        self.assertEqual(self.call('operasi',{'operation':'list'},auth)[0],403)
        self.assertEqual(self.change('marketing','free')[0],200)
        self.assertEqual(self.call('izin/marketing',auth=auth)[0],200)
        self.assertEqual(self.call('izin/copilot',auth=auth)[0],402)
        self.assertEqual(self.change('marketing','suspended',2)[0],200)
        self.assertEqual(self.change('marketing','free',2)[0],409)
        self.assertEqual(self.call('copilot/marketing/workspace',auth=auth)[1]['state'],'suspended')
        self.assertEqual(self.change('copilot','paid',receipt='in_test123',until='2026-12-31')[0],200)
        self.assertEqual(self.call('saya',auth=auth)[0],200)
        import pelanggan_akses
        with sqlite3.connect(self.db) as c:
            self.assertFalse(pelanggan_akses.allowed(c,'?',self.id,'copilot',1800000000))
            c.execute('DELETE FROM pelanggan_akses')
            self.assertFalse(pelanggan_akses.allowed(c,'?',self.id,'copilot',T))

    def test_password_recovery_revokes_old_sessions_and_is_single_use(self):
        token = self.operator(operation='recover',email='buyer@example.test')[1]['token']
        self.assertEqual(self.call('pulih',{'token':token,'password':'new-password'})[0],200)
        self.assertEqual(self.call('pulih',{'token':token,'password':'next-password'})[0],400)
        self.assertEqual(self.call('identiti',auth='Bearer '+self.token)[0],401)
        self.assertEqual(self.call('masuk',{'email':'buyer@example.test','password':'initial-pass'})[0],401)
        status,new=self.call('masuk',{'email':'buyer@example.test','password':'new-password'})
        self.assertEqual(status,200)
        self.assertEqual(self.call('identiti',auth='Bearer '+new['token'])[0],200)
