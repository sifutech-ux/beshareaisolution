"""HTTP boundary checks; run alongside the existing account image's modules."""
import http.client
import os
import threading
import unittest
from http.server import ThreadingHTTPServer
from unittest.mock import patch
from akaun_pelayan import ikat


class HttpBillingTests(unittest.TestCase):
    def setUp(self):
        self.connections=0
        def connect():
            self.connections+=1
            raise AssertionError('Unverified webhook must never reach a database')
        self.env=patch.dict(os.environ,{'BESHARE_BILLING_ENABLED':'true','BESHARE_STRIPE_MODE':'test',
            'BESHARE_STRIPE_SECRET_KEY':'rk_test_fixture','BESHARE_COPILOT_PRICE':'price_fixture',
            'BESHARE_STRIPE_WEBHOOK_SECRET':'whsec_fixture'})
        self.env.start()
        self.server=ThreadingHTTPServer(('127.0.0.1',0),ikat(connect,False))
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()

    def tearDown(self):
        self.server.shutdown();self.server.server_close();self.thread.join();self.env.stop()

    def call(self,method,body=b'{}',headers=None):
        conn=http.client.HTTPConnection('127.0.0.1',self.server.server_port,timeout=5)
        conn.request(method,'/api/akaun/bil/webhook',body=body,headers=headers or {})
        response=conn.getresponse();status=response.status;response.read();conn.close()
        return status

    def test_webhook_checks_signature_before_database(self):
        self.assertEqual(self.call('POST'),400)
        self.assertEqual(self.connections,0)

    def test_webhook_accepts_only_post(self):
        self.assertEqual(self.call('GET'),405)
        self.assertEqual(self.connections,0)

    def test_bad_lengths_rejected_before_body_or_database(self):
        self.assertEqual(self.call('POST',headers={'Content-Length':'invalid'}),400)
        self.assertEqual(self.call('POST',headers={'Content-Length':'500001'}),413)
        self.assertEqual(self.connections,0)


if __name__=='__main__':unittest.main()
