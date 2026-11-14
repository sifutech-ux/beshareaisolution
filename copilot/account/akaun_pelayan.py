"""Pelayan HTTP akaun sahaja.

Tidak memuatkan bot.py, dagangan, HATA, Moomoo, atau pengundian Telegram.
"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
import urllib.parse

from pelanggan_akaun import account_secret, cors_origin, dispatch


def _tulis(handler, status, body):
    handler.send_response(status)
    handler.send_header('Content-type', 'application/json')
    handler.send_header('Cache-Control', 'no-store')
    allowed = cors_origin(handler.headers.get('Origin'))
    if allowed:
        handler.send_header('Access-Control-Allow-Origin', allowed)
        handler.send_header('Access-Control-Allow-Headers', 'Content-Type, Authorization, Idempotency-Key')
        handler.send_header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        handler.send_header('Vary', 'Origin')
    handler.end_headers()
    if body is not None and status != 204:
        handler.wfile.write(json.dumps(body).encode('utf-8'))


class AkaunHandler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return

    def _connect(self):
        raise RuntimeError('Sambungan akaun tidak diikat.')

    def _postgres(self):
        return True

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        if path == '/healthz':
            _tulis(self, 200, {'status': 'ok', 'service': 'beshare-akaun'})
            return
        if path.startswith('/api/akaun/'):
            self._akaun('GET')
            return
        _tulis(self, 404, {'error': 'Endpoint tidak ditemui.'})

    def do_POST(self):
        self._kaedah('POST')

    def do_PUT(self):
        self._kaedah('PUT')

    def do_PATCH(self):
        self._kaedah('PATCH')

    def do_DELETE(self):
        self._kaedah('DELETE')

    def do_OPTIONS(self):
        self._kaedah('OPTIONS')

    def _kaedah(self, method):
        path = urllib.parse.urlparse(self.path).path
        if path.startswith('/api/akaun/'):
            self._akaun(method)
            return
        _tulis(self, 404, {'error': 'Endpoint tidak ditemui.'})

    def _akaun(self, method):
        try:
            length = int(self.headers.get('Content-Length', 0) or 0)
        except ValueError:
            _tulis(self, 400, {'error': 'Saiz permintaan tidak sah.'})
            return
        if length < 0 or length > 500_000:
            _tulis(self, 413, {'error': 'Permintaan terlalu besar.'})
            return
        raw = b''
        if method not in {'GET', 'OPTIONS'} and length:
            raw = self.rfile.read(length)
        if urllib.parse.urlparse(self.path).path == '/api/akaun/bil/webhook':
            self._billing_webhook(method, raw)
            return
        ip = (self.headers.get('X-Forwarded-For') or '').split(',')[0].strip() or self.client_address[0]
        parsed = urllib.parse.urlparse(self.path)
        try:
            status, body = dispatch(
                method, parsed.path, self.headers.get('Origin'), self.headers.get('Authorization'),
                raw, self._connect, self._postgres(), account_secret(), ip,
                kuta_headers={
                    'masa': self.headers.get('X-Copilot-Masa'),
                    'nonce': self.headers.get('X-Copilot-Nonce'),
                    'tandatangan': self.headers.get('X-Copilot-Tandatangan'),
                },
                query=urllib.parse.parse_qs(parsed.query),
                idempotency_key=self.headers.get('Idempotency-Key'),
            )
        except Exception:
            status, body = 503, {'error': 'Akaun tidak dapat dihubungi. Cuba semula.'}
        _tulis(self, status, body)


    def _billing_webhook(self, method, raw):
        from pelanggan_bil import Billing, BillingError, config, schema, verify_event
        import time
        if method != 'POST':
            _tulis(self, 405, {'ok': False})
            return
        conn = None
        try:
            cfg = config()
            event = verify_event(raw, self.headers.get('Stripe-Signature'), cfg['webhook'], cfg['mode']=='live', time.time())
            conn = self._connect()
            schema(conn)
            conn.commit()
            result = Billing(conn, self._postgres(), cfg).webhook(event)
            _tulis(self, 200, result)
        except BillingError as error:
            _tulis(self, error.status, {'ok': False, 'error': str(error)})
        except Exception:
            _tulis(self, 503, {'ok': False, 'error': 'Webhook akan dicuba semula.'})
        finally:
            if conn:
                conn.close()


def ikat(connect, postgres=True):
    class Terikat(AkaunHandler):
        def _connect(self):
            return connect()

        def _postgres(self):
            return postgres

    return Terikat


def utama():
    from pelanggan_pangkalan import buka
    port = int(os.environ.get('ACCOUNT_PORT') or os.environ.get('PORT') or '8767')
    server = ThreadingHTTPServer(('0.0.0.0', port), ikat(buka, True))
    server.serve_forever()


if __name__ == '__main__':
    utama()

