"""Server-owned Stripe subscriptions. No browser price/customer/owner IDs are trusted.

Sandbox rollout is explicit. Checkout redirects never grant an entitlement.
Transactions serialize both checkout and webhook reconciliation per account.
"""
import hashlib
import hmac
import json
import os
import re
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request

VERSION = '2026-08-26.dahlia'
ORIGIN = 'https://beshareaisolution.com'
EVENTS = frozenset({'checkout.session.completed', 'checkout.session.async_payment_succeeded',
    'checkout.session.async_payment_failed', 'invoice.paid', 'invoice.payment_failed',
    'customer.subscription.created', 'customer.subscription.updated',
    'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed'})
TERMINAL = frozenset({'canceled', 'incomplete_expired'})


class BillingError(Exception):
    def __init__(self, message, status=503):
        super().__init__(message)
        self.status = status


def config():
    mode = os.getenv('BESHARE_STRIPE_MODE', 'test')
    key = os.getenv('BESHARE_STRIPE_SECRET_KEY', '')
    if os.getenv('BESHARE_BILLING_ENABLED') != 'true':
        raise BillingError('Pembayaran sedang disediakan.')
    if mode not in {'test', 'live'} or not key.startswith('rk_' + mode + '_'):
        raise BillingError('Konfigurasi pembayaran belum lengkap.')
    # Live requires its own explicit launch flag and separate price catalogue.
    if mode == 'live' and os.getenv('BESHARE_BILLING_LIVE_APPROVED') != 'true':
        raise BillingError('Pembayaran live belum diaktifkan.')
    price = os.getenv('BESHARE_COPILOT_PRICE', '')
    if not re.fullmatch(r'price_[A-Za-z0-9]+', price):
        raise BillingError('Pelan pembayaran belum tersedia.')
    return {'mode': mode, 'key': key, 'price': price,
        'amount': int(os.getenv('BESHARE_COPILOT_AMOUNT', '2900')),
        'portal': os.getenv('BESHARE_STRIPE_PORTAL', ''),
        'webhook': os.getenv('BESHARE_STRIPE_WEBHOOK_SECRET', '')}


def enabled_for(owner, cfg):
    allowed = set(filter(None, os.getenv('BESHARE_BILLING_ACCOUNT_ALLOWLIST', '').split(',')))
    return owner in allowed or (cfg['mode'] == 'live' and '*' in allowed)


def verify_event(raw, signature, secret, live, now):
    if not secret.startswith('whsec_'):
        raise BillingError('Webhook belum tersedia.')
    if len(raw) > 500_000 or not isinstance(signature, str) or len(signature) > 4096:
        raise BillingError('Webhook tidak sah.', 400)
    parts = [item.split('=', 1) for item in signature.split(',') if '=' in item]
    stamps = [v for k, v in parts if k == 't']
    if len(stamps) != 1 or not stamps[0].isdigit() or abs(int(stamps[0]) - int(now)) > 300:
        raise BillingError('Tandatangan webhook tidak sah.', 400)
    expected = hmac.new(secret.encode(), stamps[0].encode() + b'.' + raw, hashlib.sha256).hexdigest()
    if not any(k == 'v1' and re.fullmatch(r'[0-9a-f]{64}', v) and hmac.compare_digest(expected, v) for k, v in parts):
        raise BillingError('Tandatangan webhook tidak sah.', 400)
    try:
        event = json.loads(raw)
    except (ValueError, UnicodeError):
        raise BillingError('Webhook tidak sah.', 400) from None
    if not isinstance(event, dict) or event.get('livemode') is not live or not re.fullmatch(r'evt_[A-Za-z0-9]+', str(event.get('id', ''))):
        raise BillingError('Persekitaran webhook tidak sepadan.', 400)
    return event


class Stripe:
    def __init__(self, cfg):
        self.cfg = cfg

    def call(self, path, values=None, key=None):
        if not re.fullmatch(r'[a-z_/]+(?:/[A-Za-z0-9_]+)?', path):
            raise BillingError('Permintaan pembayaran tidak sah.')
        headers = {'Authorization': 'Bearer ' + self.cfg['key'], 'Stripe-Version': VERSION}
        if values is not None:
            if not key:
                raise BillingError('Kunci permintaan diperlukan.')
            headers.update({'Idempotency-Key': key, 'Content-Type': 'application/x-www-form-urlencoded'})
        req = urllib.request.Request('https://api.stripe.com/v1/' + path,
            data=None if values is None else urllib.parse.urlencode(values).encode(), headers=headers)
        try:
            # Stripe API is a fixed TLS endpoint. Never follow a redirect with its credential.
            class NoRedirect(urllib.request.HTTPRedirectHandler):
                def redirect_request(self, *args, **kwargs):
                    return None
            with urllib.request.build_opener(NoRedirect).open(req, timeout=15) as response:
                data = json.load(response)
        except (urllib.error.URLError, TimeoutError, ValueError):
            raise BillingError('Stripe belum dapat dihubungi. Cuba semula sebentar lagi.') from None
        if not isinstance(data, dict) or ('livemode' in data and data['livemode'] is not (self.cfg['mode'] == 'live')):
            raise BillingError('Persekitaran Stripe tidak sepadan.')
        return data


def schema(conn):
    cur = conn.cursor()
    cur.execute('''CREATE TABLE IF NOT EXISTS copilot_billing (
        owner TEXT NOT NULL, mode TEXT NOT NULL, customer TEXT UNIQUE,
        subscription TEXT UNIQUE, status TEXT NOT NULL DEFAULT 'none',
        paid_until BIGINT NOT NULL DEFAULT 0, period_start BIGINT NOT NULL DEFAULT 0,
        trial_end BIGINT NOT NULL DEFAULT 0, trial_used INTEGER NOT NULL DEFAULT 0,
        cancel_at_end INTEGER NOT NULL DEFAULT 0, attempt TEXT,
        attempt_at BIGINT NOT NULL DEFAULT 0, checkout TEXT UNIQUE,
        updated_at BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(owner, mode))''')
    cur.execute('''CREATE TABLE IF NOT EXISTS copilot_billing_events (
        event_id TEXT PRIMARY KEY, processed_at BIGINT NOT NULL)''')


class Billing:
    def __init__(self, conn, postgres, cfg, stripe=None, now=None):
        self.conn, self.postgres, self.cfg = conn, postgres, cfg
        self.stripe = stripe or Stripe(cfg)
        self.now = int(time.time() if now is None else now)
        self.ph = '%s' if postgres else '?'

    def sql(self, query, values=()):
        cur = self.conn.cursor()
        cur.execute(query.replace('?', self.ph), values)
        return cur

    def begin(self):
        self.conn.commit()
        self.sql('BEGIN' if self.postgres else 'BEGIN IMMEDIATE')

    def row(self, owner, lock=False):
        cur = self.sql('SELECT * FROM copilot_billing WHERE owner=? AND mode=?' +
            (' FOR UPDATE' if lock and self.postgres else ''), (owner, self.cfg['mode']))
        row = cur.fetchone()
        return dict(zip([d[0] for d in cur.description], row)) if row else None

    def update(self, owner, **values):
        values['updated_at'] = self.now
        self.sql('UPDATE copilot_billing SET ' + ','.join(k + '=?' for k in values) + ' WHERE owner=? AND mode=?',
            (*values.values(), owner, self.cfg['mode']))

    def identity(self, owner):
        user = self.sql('SELECT email FROM pelanggan_akaun WHERE id=?', (owner,)).fetchone()
        if not user:
            raise BillingError('Sila masuk semula.', 401)
        if not enabled_for(owner, self.cfg):
            raise BillingError('Pembayaran belum dibuka untuk akaun ini.', 403)
        return user[0]

    def view(self, owner):
        self.identity(owner)
        row = self.row(owner) or {}
        until = row.get('trial_end', 0) if row.get('status') == 'trialing' else row.get('paid_until', 0)
        active = row.get('status') in {'active', 'trialing'} and until > self.now
        return {'ok': True, 'available': True, 'mode': self.cfg['mode'],
            'status': row.get('status', 'none'), 'active': active, 'access_until': until,
            'cancel_at_period_end': bool(row.get('cancel_at_end')), 'has_customer': bool(row.get('customer')),
            'amount': self.cfg['amount'], 'currency': 'myr', 'trial_days': 7,
            'trial_available': not row.get('trial_used', 0)}

    def checkout(self, owner):
        email = self.identity(owner)
        self.begin()
        try:
            self.sql('INSERT INTO copilot_billing(owner,mode) VALUES (?,?) ON CONFLICT(owner,mode) DO NOTHING', (owner,self.cfg['mode']))
            row = self.row(owner, True)
            if row['subscription']:
                self.reconcile(owner, row['subscription'])
                row = self.row(owner, True)
                if row['status'] not in TERMINAL:
                    raise BillingError('Langganan sudah wujud. Gunakan Urus langganan.', 409)
                self.update(owner, subscription=None, checkout=None, attempt=None, attempt_at=0, paid_until=0, trial_end=0)
                row = self.row(owner, True)
            if row['checkout']:
                old = self.stripe.call('checkout/sessions/' + row['checkout'])
                if old.get('status') == 'open':
                    self.conn.commit()
                    return {'ok': True, 'url': safe_url(old.get('url'), 'checkout.stripe.com')}
                if old.get('status') == 'complete':
                    sub = object_id(old.get('subscription'), 'sub')
                    if sub:
                        self.reconcile(owner, sub)
                        self.conn.commit()
                    raise BillingError('Bayaran sedang disahkan. Muat semula status langganan.', 409)
                if old.get('status') != 'expired':
                    raise BillingError('Checkout sedang disahkan.', 409)
                self.update(owner, attempt=None, checkout=None, attempt_at=0)
                row = self.row(owner, True)
            if not row['customer']:
                customer = self.stripe.call('customers', {'email':email,
                    'metadata[beshare_product]':'copilot','metadata[beshare_owner]':owner},
                    'beshare-customer-' + self.cfg['mode'] + '-' + owner)
                customer_id = object_id(customer.get('id'), 'cus')
                if not customer_id:
                    raise BillingError('Pelanggan Stripe tidak sah.')
                self.update(owner, customer=customer_id)
            if not row['attempt']:
                self.update(owner, attempt=secrets.token_hex(16), attempt_at=self.now)
            self.conn.commit()  # Persist the retry identity before making the checkout request.
            self.begin()
            row = self.row(owner, True)
            if self.now - row['attempt_at'] >= 23*3600 and not row['checkout']:
                raise BillingError('Checkout memerlukan semakan BeShare sebelum dicuba lagi.', 409)
            price = self.stripe.call('prices/' + self.cfg['price'])
            if not (price.get('active') is True and price.get('currency') == 'myr'
                    and price.get('unit_amount') == self.cfg['amount']
                    and (price.get('recurring') or {}).get('interval') == 'month'
                    and price['recurring'].get('interval_count') == 1):
                raise BillingError('Harga pelan tidak sepadan. Hubungi BeShare.')
            data = {'mode':'subscription','customer':row['customer'],
                'line_items[0][price]':self.cfg['price'],'line_items[0][quantity]':1,
                'success_url':ORIGIN+'/penasihat/?bil=berjaya#profil',
                'cancel_url':ORIGIN+'/penasihat/?bil=batal#profil',
                'client_reference_id':row['attempt'], 'metadata[beshare_product]':'copilot',
                'metadata[beshare_owner]':owner,'metadata[beshare_attempt]':row['attempt'],
                'subscription_data[metadata][beshare_product]':'copilot',
                'subscription_data[metadata][beshare_owner]':owner,
                'subscription_data[metadata][beshare_attempt]':row['attempt']}
            if not row['trial_used']:
                data.update({'subscription_data[trial_period_days]':7,
                    'subscription_data[trial_settings][end_behavior][missing_payment_method]':'pause',
                    'payment_method_collection':'if_required'})
            session = self.stripe.call('checkout/sessions', data, 'beshare-checkout-'+row['attempt'])
            sid = object_id(session.get('id'), 'cs')
            if not sid or object_id(session.get('customer'),'cus') != row['customer']:
                raise BillingError('Checkout tidak sepadan.')
            url = safe_url(session.get('url'), 'checkout.stripe.com')
            self.update(owner, checkout=sid)
            self.conn.commit()
            return {'ok': True, 'url':url}
        except Exception:
            self.conn.rollback()
            raise

    def portal(self, owner):
        self.identity(owner)
        row = self.row(owner)
        if not row or not row['customer']:
            raise BillingError('Belum ada langganan untuk diurus.', 409)
        data = self.stripe.call('billing_portal/sessions', {'customer':row['customer'],
            'configuration':self.cfg['portal'],'return_url':ORIGIN+'/penasihat/#profil'},
            'beshare-portal-'+secrets.token_hex(16))
        return {'ok':True,'url':safe_url(data.get('url'),'billing.stripe.com')}

    def reconcile(self, owner, subscription):
        row = self.row(owner, True)
        if not row:
            raise BillingError('Padanan akaun belum tersedia.', 409)
        sub = self.stripe.call('subscriptions/'+subscription)
        meta = sub.get('metadata') or {}
        if (object_id(sub.get('customer'),'cus') != row['customer'] or meta.get('beshare_product') != 'copilot'
                or meta.get('beshare_owner') != owner or meta.get('beshare_attempt') != row['attempt']):
            raise BillingError('Langganan tidak sepadan dengan akaun.', 400)
        if not row['checkout']:
            raise BillingError('Checkout belum disimpan. Stripe akan mencuba semula.', 503)
        checkout = self.stripe.call('checkout/sessions/'+row['checkout'])
        if (object_id(checkout.get('subscription'),'sub') != subscription or checkout.get('status') != 'complete'
                or object_id(checkout.get('customer'),'cus') != row['customer']):
            raise BillingError('Checkout belum mengesahkan langganan.', 503)
        items = (sub.get('items') or {}).get('data') or []
        if len(items) != 1 or object_id(items[0].get('price'),'price') != self.cfg['price'] or items[0].get('quantity') != 1:
            raise BillingError('Pelan langganan tidak sepadan.', 400)
        status = str(sub.get('status','none'))
        paid_until, start = row['paid_until'], row['period_start']
        invoice_id = object_id(sub.get('latest_invoice'),'in')
        if invoice_id and status == 'active':
            invoice = self.stripe.call('invoices/'+invoice_id)
            parent = ((invoice.get('parent') or {}).get('subscription_details') or {}).get('subscription')
            if (invoice.get('status') == 'paid' and object_id(invoice.get('customer'),'cus') == row['customer']
                    and object_id(parent or invoice.get('subscription'),'sub') == subscription):
                period_end = int(items[0].get('current_period_end') or 0)
                period_start = int(items[0].get('current_period_start') or 0)
                # A paid invoice must contain this recurring price and its actual service period.
                lines = (invoice.get('lines') or {}).get('data') or []
                matching = [line for line in lines if object_id((line.get('pricing') or {}).get('price_details',{}).get('price') or line.get('price'),'price') == self.cfg['price']
                    and int((line.get('period') or {}).get('end') or 0) == period_end]
                if matching and period_end > paid_until:
                    paid_until, start = period_end, period_start
        trial_end = int(sub.get('trial_end') or 0)
        # Portal cancellation can use a timestamp while cancel_at_period_end is false.
        cancel_at = int(sub.get('cancel_at') or 0)
        if cancel_at > 0:
            paid_until = min(paid_until, cancel_at)
            trial_end = min(trial_end, cancel_at)
        self.update(owner, subscription=subscription,status=status,paid_until=paid_until,
            period_start=start,trial_end=trial_end,trial_used=int(bool(row['trial_used'] or trial_end)),
            cancel_at_end=int(sub.get('cancel_at_period_end') is True or cancel_at > 0))

    def webhook(self, event):
        if event.get('type') not in EVENTS:
            return {'ok':True,'ignored':True}
        obj = (event.get('data') or {}).get('object') or {}
        customer = object_id(obj.get('customer'),'cus')
        self.begin()
        try:
            if self.sql('SELECT event_id FROM copilot_billing_events WHERE event_id=?',(event['id'],)).fetchone():
                self.conn.commit()
                return {'ok':True,'duplicate':True}
            result = self.sql('SELECT owner FROM copilot_billing WHERE customer=? AND mode=?', (customer,self.cfg['mode'])).fetchone()
            if not result:
                self.conn.commit()
                return {'ok':True,'ignored':True}
            owner = result[0]
            row = self.row(owner, True)
            if event['type'].startswith('customer.subscription.'):
                subscription = object_id(obj.get('id'),'sub')
            else:
                parent = ((obj.get('parent') or {}).get('subscription_details') or {}).get('subscription')
                subscription = object_id(obj.get('subscription') or parent,'sub')
            # Old subscriptions cannot replace a newer checkout. Unrelated fixtures are ignored.
            if subscription:
                current = self.stripe.call('subscriptions/'+subscription)
                if (current.get('metadata') or {}).get('beshare_attempt') == row['attempt']:
                    self.reconcile(owner, subscription)
            self.sql('INSERT INTO copilot_billing_events(event_id,processed_at) VALUES (?,?) ON CONFLICT(event_id) DO NOTHING',(event['id'],self.now))
            self.conn.commit()
            return {'ok':True}
        except Exception:
            self.conn.rollback()
            raise


def object_id(value, prefix):
    value = value.get('id') if isinstance(value, dict) else value
    return value if isinstance(value,str) and re.fullmatch(prefix+r'_[A-Za-z0-9_]+', value) else None


def safe_url(url, host):
    p = urllib.parse.urlsplit(url or '')
    if p.scheme != 'https' or p.hostname != host or p.username or p.password or p.port not in (None,443):
        raise BillingError('Pautan pembayaran tidak sah.')
    return url


def dispatch_billing(method,path,conn,postgres,owner,stamp):
    try:
        cfg = config()
        service = Billing(conn,postgres,cfg,now=stamp)
        if method == 'GET' and path == '/api/akaun/bil':
            return 200,service.view(owner)
        if method == 'POST' and path == '/api/akaun/bil/checkout':
            return 200,service.checkout(owner)
        if method == 'POST' and path == '/api/akaun/bil/portal':
            return 200,service.portal(owner)
        return 405,{'error':'Kaedah tidak dibenarkan.'}
    except BillingError as error:
        return error.status,{'ok':False,'error':str(error)}
