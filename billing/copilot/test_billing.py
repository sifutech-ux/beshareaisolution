import copy
import hashlib
import hmac
import json
import os
import sqlite3
import unittest
from unittest.mock import patch
from pelanggan_bil import Billing, BillingError, schema, verify_event, safe_url, config

NOW = 1800000000
CFG = {'mode':'test','key':'rk_test_fixture','price':'price_copilot','amount':2900,
       'portal':'bpc_fixture','webhook':'whsec_fixture'}


class FakeStripe:
    def __init__(self):
        self.objects = {'prices/price_copilot':{'id':'price_copilot','active':True,'livemode':False,
            'currency':'myr','unit_amount':2900,'recurring':{'interval':'month','interval_count':1}}}
        self.keys, self.calls = {}, []
        self.lose_checkout_response = False

    def call(self, path, values=None, key=None):
        self.calls.append((path,copy.deepcopy(values),key))
        if values is None:
            return copy.deepcopy(self.objects[path])
        if key in self.keys:
            old_values, result = self.keys[key]
            if values != old_values:
                raise BillingError('Idempotency request changed')
            return copy.deepcopy(result)
        if path == 'customers':
            result={'id':'cus_'+values['metadata[beshare_owner]'],'livemode':False}
        elif path == 'checkout/sessions':
            sid='cs_test_'+str(len(self.keys))
            result={'id':sid,'customer':values['customer'],'status':'open','subscription':None,
                'livemode':False,'url':'https://checkout.stripe.com/c/pay/'+sid}
            self.objects[path+'/'+sid]=result
        elif path == 'billing_portal/sessions':
            result={'id':'bps_fixture','url':'https://billing.stripe.com/p/session/test_fixture'}
        else:
            raise AssertionError(path)
        self.keys[key]=(copy.deepcopy(values),copy.deepcopy(result))
        if path=='checkout/sessions' and self.lose_checkout_response:
            self.lose_checkout_response=False
            raise BillingError('network timeout')
        return copy.deepcopy(result)

    def complete(self, service, owner='ownerA', trial=False):
        row=service.row(owner)
        sub_id='sub_'+owner
        self.objects['checkout/sessions/'+row['checkout']].update(status='complete',subscription=sub_id)
        self.objects['subscriptions/'+sub_id]={'id':sub_id,'livemode':False,'customer':row['customer'],
            'metadata':{'beshare_product':'copilot','beshare_owner':owner,'beshare_attempt':row['attempt']},
            'status':'trialing' if trial else 'active','trial_end':NOW+7*86400 if trial else None,
            'cancel_at_period_end':False,'latest_invoice':'in_'+owner,
            'items':{'data':[{'price':{'id':'price_copilot'},'quantity':1,'current_period_start':NOW,'current_period_end':NOW+30*86400}]}}
        self.objects['invoices/in_'+owner]={'status':'paid','customer':row['customer'],
            'parent':{'subscription_details':{'subscription':sub_id}},
            'lines':{'data':[{'pricing':{'price_details':{'price':'price_copilot'}},'period':{'end':NOW+30*86400}}]}}
        return {'id':'evt_'+owner,'type':'checkout.session.completed','livemode':False,
            'data':{'object':{'id':row['checkout'],'customer':row['customer'],'subscription':sub_id}}}


class BillingTests(unittest.TestCase):
    def setUp(self):
        self.env=patch.dict(os.environ,{'BESHARE_BILLING_ACCOUNT_ALLOWLIST':'ownerA,ownerB'})
        self.env.start()
        self.conn=sqlite3.connect(':memory:')
        self.conn.execute('CREATE TABLE pelanggan_akaun(id TEXT PRIMARY KEY,email TEXT)')
        self.conn.executemany('INSERT INTO pelanggan_akaun VALUES (?,?)',[('ownerA','a@example.invalid'),('ownerB','b@example.invalid')])
        schema(self.conn);self.conn.commit()
        self.stripe=FakeStripe()
        self.service=Billing(self.conn,False,CFG,self.stripe,NOW)

    def tearDown(self):
        self.conn.close();self.env.stop()

    def test_checkout_is_bound_and_idempotent(self):
        first=self.service.checkout('ownerA');second=self.service.checkout('ownerA')
        self.assertEqual(first,second)
        writes=[c for c in self.stripe.calls if c[0]=='checkout/sessions']
        self.assertEqual(len(writes),1)
        data=writes[0][1]
        self.assertEqual(data['customer'],'cus_ownerA')
        self.assertEqual(data['line_items[0][price]'],'price_copilot')
        self.assertEqual(data['subscription_data[trial_period_days]'],7)
        self.assertFalse(self.service.view('ownerA')['active'])

    def test_timeout_keeps_attempt_and_does_not_create_second_checkout(self):
        self.stripe.lose_checkout_response=True
        with self.assertRaises(BillingError):self.service.checkout('ownerA')
        attempt=self.service.row('ownerA')['attempt']
        self.service.checkout('ownerA')
        self.assertEqual(attempt,self.service.row('ownerA')['attempt'])
        self.assertEqual(len([key for key in self.stripe.keys if key.startswith('beshare-checkout')]),1)

    def test_no_account_or_allowlist_no_stripe_calls(self):
        with self.assertRaises(BillingError):self.service.checkout('foreign')
        with patch.dict(os.environ,{'BESHARE_BILLING_ACCOUNT_ALLOWLIST':''}):
            with self.assertRaises(BillingError):self.service.checkout('ownerA')
        self.assertFalse(self.stripe.calls)

    def test_paid_event_and_duplicate(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service)
        self.service.webhook(event)
        self.assertTrue(self.service.view('ownerA')['active'])
        before=len(self.stripe.calls)
        self.assertTrue(self.service.webhook(event)['duplicate'])
        self.assertEqual(before,len(self.stripe.calls))
        self.assertFalse(self.service.view('ownerB')['active'])

    def test_trial_and_no_duplicate_subscription(self):
        self.service.checkout('ownerA');self.service.webhook(self.stripe.complete(self.service,trial=True))
        self.assertTrue(self.service.view('ownerA')['active'])
        self.assertFalse(self.service.view('ownerA')['trial_available'])
        with self.assertRaises(BillingError):self.service.checkout('ownerA')

    def test_unpaid_invoice_does_not_activate(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service)
        self.stripe.objects['invoices/in_ownerA']['status']='open'
        self.service.webhook(event)
        self.assertFalse(self.service.view('ownerA')['active'])

    def test_wrong_invoice_subscription_or_period_cannot_grant(self):
        for wrong in ('subscription','period'):
            with self.subTest(wrong=wrong):
                self.service.checkout('ownerA');event=self.stripe.complete(self.service)
                event['id']='evt_'+wrong
                invoice=self.stripe.objects['invoices/in_ownerA']
                if wrong=='subscription':invoice['parent']['subscription_details']['subscription']='sub_foreign'
                else:invoice['lines']['data'][0]['period']['end']=NOW+1
                self.service.webhook(event)
                self.assertFalse(self.service.view('ownerA')['active'])
                self.service.sql('DELETE FROM copilot_billing');self.conn.commit()

    def test_foreign_owner_metadata_is_rejected_without_commit(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service)
        self.stripe.objects['subscriptions/sub_ownerA']['metadata']['beshare_owner']='ownerB'
        with self.assertRaises(BillingError):self.service.webhook(event)
        self.assertFalse(self.service.view('ownerA')['active'])
        self.assertEqual(self.service.sql('SELECT COUNT(*) FROM copilot_billing_events').fetchone()[0],0)

    def test_cancellation_at_period_end_keeps_paid_access(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service)
        self.stripe.objects['subscriptions/sub_ownerA']['cancel_at_period_end']=True
        self.service.webhook(event)
        self.assertTrue(self.service.view('ownerA')['active'])
        self.assertTrue(self.service.view('ownerA')['cancel_at_period_end'])

    def test_delayed_event_uses_current_subscription_state(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service)
        self.service.webhook(event)
        self.stripe.objects['subscriptions/sub_ownerA']['status']='canceled'
        event['id']='evt_late';event['type']='invoice.paid'
        self.service.webhook(event)
        self.assertFalse(self.service.view('ownerA')['active'])

    def test_timestamp_cancellation_caps_trial_and_expires_without_another_event(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service,trial=True)
        self.stripe.objects['subscriptions/sub_ownerA']['cancel_at']=NOW+86400
        self.service.webhook(event)
        view=self.service.view('ownerA')
        self.assertTrue(view['cancel_at_period_end'])
        self.assertTrue(view['active'])
        self.assertEqual(view['access_until'],NOW+86400)
        self.service.now=NOW+86400
        self.assertFalse(self.service.view('ownerA')['active'])

    def test_timestamp_cancellation_caps_paid_access_and_can_be_undone(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service)
        self.stripe.objects['subscriptions/sub_ownerA']['cancel_at']=NOW+86400
        self.service.webhook(event)
        self.assertTrue(self.service.view('ownerA')['cancel_at_period_end'])
        self.assertEqual(self.service.view('ownerA')['access_until'],NOW+86400)
        self.stripe.objects['subscriptions/sub_ownerA']['cancel_at']=None
        event['id']='evt_cancel_undone';self.service.webhook(event)
        self.assertFalse(self.service.view('ownerA')['cancel_at_period_end'])
        self.assertEqual(self.service.view('ownerA')['access_until'],NOW+30*86400)

    def test_portal_uses_current_owner_only(self):
        self.service.checkout('ownerA');self.service.checkout('ownerB')
        self.service.portal('ownerB')
        self.assertEqual(self.stripe.calls[-1][1]['customer'],'cus_ownerB')

    def test_new_checkout_after_cancel_keeps_trial_used(self):
        self.service.checkout('ownerA');event=self.stripe.complete(self.service,trial=True)
        self.service.webhook(event)
        self.stripe.objects['subscriptions/sub_ownerA']['status']='canceled'
        self.service.checkout('ownerA')
        data=[c[1] for c in self.stripe.calls if c[0]=='checkout/sessions'][-1]
        self.assertNotIn('subscription_data[trial_period_days]',data)

    def test_old_unknown_attempt_requires_review_after_23_hours(self):
        self.stripe.lose_checkout_response=True
        with self.assertRaises(BillingError):self.service.checkout('ownerA')
        self.service.now+=24*3600
        with self.assertRaises(BillingError):self.service.checkout('ownerA')


@unittest.skipUnless(os.getenv('BESHARE_BILLING_TEST_POSTGRES') == '1', 'isolated PostgreSQL fixture only')
class PostgresBillingTests(BillingTests):
    def setUp(self):
        import psycopg2
        # Fixed disposable fixture; never load any application DB credential.
        self.connect=lambda: psycopg2.connect(host='127.0.0.1',port=5432,
            dbname='beshare_billing_test',user='billing_qa',password='synthetic-billing-ci-only')
        self.env=patch.dict(os.environ,{'BESHARE_BILLING_ACCOUNT_ALLOWLIST':'ownerA,ownerB'})
        self.env.start()
        self.conn=self.connect()
        cur=self.conn.cursor()
        cur.execute('CREATE TABLE IF NOT EXISTS pelanggan_akaun(id TEXT PRIMARY KEY,email TEXT)')
        schema(self.conn)
        for table in ['copilot_billing_events','copilot_billing','pelanggan_akaun']:
            cur.execute('DELETE FROM '+table)
        cur.executemany('INSERT INTO pelanggan_akaun VALUES (%s,%s)',[('ownerA','a@example.invalid'),('ownerB','b@example.invalid')])
        self.conn.commit()
        self.stripe=FakeStripe()
        self.service=Billing(self.conn,True,CFG,self.stripe,NOW)

    def test_concurrent_checkout_reuses_one_session(self):
        from concurrent.futures import ThreadPoolExecutor
        from threading import Barrier
        barrier=Barrier(2)
        def checkout(_):
            conn=self.connect()
            try:
                barrier.wait(timeout=5)
                return Billing(conn,True,CFG,self.stripe,NOW).checkout('ownerA')
            finally:conn.close()
        with ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(checkout,range(2)))
        self.assertEqual(results[0],results[1])
        self.assertEqual(len([key for key in self.stripe.keys if key.startswith('beshare-checkout')]),1)


class BoundaryTests(unittest.TestCase):
    def test_signature_and_raw_body_binding(self):
        raw=json.dumps({'id':'evt_fixture','livemode':False}).encode()
        sig=hmac.new(b'whsec_fixture',str(NOW).encode()+b'.'+raw,hashlib.sha256).hexdigest()
        header=f't={NOW},v1={sig}'
        self.assertEqual(verify_event(raw,header,'whsec_fixture',False,NOW)['id'],'evt_fixture')
        for body,head,live,stamp in [(raw+b' ',header,False,NOW),(raw,header,True,NOW),(raw,header,False,NOW+301),(raw,header+',t=1',False,NOW)]:
            with self.assertRaises(BillingError):verify_event(body,head,'whsec_fixture',live,stamp)

    def test_redirect_restrictions(self):
        self.assertEqual(safe_url('https://checkout.stripe.com/c/pay/x','checkout.stripe.com'),'https://checkout.stripe.com/c/pay/x')
        for value in ['javascript:alert(1)','https://checkout.stripe.com.evil.invalid/','https://x@checkout.stripe.com/','http://checkout.stripe.com/']:
            with self.assertRaises(BillingError):safe_url(value,'checkout.stripe.com')

    def test_live_needs_explicit_separate_configuration(self):
        with patch.dict(os.environ,{'BESHARE_BILLING_ENABLED':'true','BESHARE_STRIPE_MODE':'live','BESHARE_STRIPE_SECRET_KEY':'rk_test_fixture','BESHARE_COPILOT_PRICE':'price_fixture'}):
            with self.assertRaises(BillingError):config()


if __name__=='__main__':unittest.main()
