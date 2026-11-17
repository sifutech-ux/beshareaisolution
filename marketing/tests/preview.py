"""Isolated UI QA for Marketing. Temporary account, real CRUD, no provider calls.

Run only with MARKETING_UI_QA=1. Never deploy this development server. The managed
preview supplies PORT; its single server also proxies the account API locally.
"""
import argparse
import functools
import json
import os
from pathlib import Path
import secrets
import sqlite3
import sys
import tempfile
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

if os.environ.get('MARKETING_UI_QA') != '1':
    raise SystemExit('Set MARKETING_UI_QA=1 for the isolated test account.')

parser = argparse.ArgumentParser()
parser.add_argument('root', nargs='?', default='.')
parser.add_argument('--host', default='127.0.0.1')
parser.add_argument('--port', type=int, default=int(os.environ.get('PORT', '3000')))
parser.add_argument('--strictPort', action='store_true')
ARGS = parser.parse_args()
ROOT = Path(ARGS.root).resolve()
sys.path.insert(0, str(ROOT / 'copilot' / 'account'))
import pelanggan_akaun as account

# A preview must never inherit live social application settings.
for key in list(os.environ):
    if key.startswith('MARKETING_') and key != 'MARKETING_UI_QA':
        os.environ.pop(key)

TEMP = tempfile.TemporaryDirectory(prefix='beshare-marketing-qa-')
DATABASE = str(Path(TEMP.name) / 'account.sqlite')
SECRET = secrets.token_hex(32)


def connect():
    return sqlite3.connect(DATABASE, timeout=10)


def dispatch(method, path, body=b'', authorization='', idempotency=None):
    return account.dispatch(method, path, 'http://localhost:8000', authorization,
                            body, connect, False, SECRET, 'isolated-marketing-qa',
                            idempotency_key=idempotency)


status, LOGIN = dispatch('POST', '/api/akaun/daftar', json.dumps({
    'email': 'marketing-qa@example.test', 'password': secrets.token_hex(24),
}).encode())
if status != 200:
    raise SystemExit('Unable to initialize isolated preview account.')
dispatch('POST', '/api/akaun/penasihat', json.dumps({'penasihat': {
    'nama': 'Kopi Aurora Ujian', 'jualan': 'Kopi dan pastri untuk pekerja pejabat',
    'peringkat': 'jual', 'mesej': [{'dari': 'penasihat', 'teks': 'Profil contoh untuk ujian Marketing.'}],
}}).encode(), 'Bearer ' + LOGIN['token'])

LANDING = '''<!doctype html><html lang="ms"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pratonton BeShare AI Marketing</title>
<style>body{margin:0;background:#0d0b20;color:#f5efff;font:17px/1.7 system-ui;display:grid;min-height:100vh;place-items:center}
main{max-width:640px;padding:32px}small{color:#bcb0d6}h1{font-size:42px;line-height:1.15}
button{border:0;background:#c4a1ff;color:#160d2c;padding:16px 24px;font:600 16px system-ui;border-radius:12px;cursor:pointer}
</style><main><small>BESHARE AI · RUANG UJIAN</small><h1>BeShare AI Marketing</h1>
<p>Semak kempen, kandungan, rujukan dan rekod hasil menggunakan akaun contoh yang berasingan.</p>
<p>Data disimpan dalam pangkalan data ujian sementara. Sambungan sosial dan panggilan AI sebenar tidak diaktifkan di sini.</p>
<button id="mula">Buka ruang kerja ujian →</button><p id="ralat" role="status"></p></main>
<script>document.querySelector('#mula').onclick=async()=>{try{const r=await fetch('/qa-login');if(!r.ok)throw Error('Akaun ujian tidak tersedia.');localStorage.setItem('beshare-akaun',JSON.stringify(await r.json()));location.href='/marketing/'}catch(e){document.querySelector('#ralat').textContent=e.message}}</script></html>'''
BADGE = '''<aside style="position:fixed;bottom:0;left:0;right:0;z-index:99999;background:#392b09;color:#ffedb4;text-align:center;padding:5px 8px;font:11px/1.4 system-ui;pointer-events:none">PRATONTON UJIAN · Akaun contoh · Tiada siaran atau iklan sebenar</aside>'''


class Preview(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def send_json(self, status, data):
        raw = json.dumps(data, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def send_html(self, raw):
        body = raw.encode()
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def api(self):
        path = urlsplit(self.path).path
        length = int(self.headers.get('Content-Length', '0'))
        if length > 100_000:
            self.send_json(413, {'error': 'Permintaan terlalu besar.'})
            return
        body = self.rfile.read(length) if length else b''
        if path.startswith('/api/akaun/'):
            code, data = dispatch(self.command, path, body,
                                  self.headers.get('Authorization', ''),
                                  self.headers.get('Idempotency-Key'))
            self.send_json(code, data)
        else:
            self.send_json(503, {'error': 'Pratonton ujian: AI sebenar tidak disambungkan. Anda boleh menulis dan menyimpan draf sendiri.'})

    def do_GET(self):
        path = urlsplit(self.path).path
        if path.startswith('/api/'):
            return self.api()
        if path in ('/qa', '/qa/'):
            return self.send_html(LANDING)
        if path == '/qa-login':
            return self.send_json(200, LOGIN)
        if path == '/akaun-asal.js':
            # Preview-only mapping; the production origin allowlist is unchanged.
            raw = b'window.BeshareAsal={asalPerkhidmatan:()=>({akaun:location.origin,nasihat:location.origin})};'
            self.send_response(200)
            self.send_header('Content-Type', 'text/javascript')
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            self.wfile.write(raw)
            return
        relative = path.lstrip('/')
        if any(part.startswith('.') for part in Path(relative).parts):
            return self.send_error(404)
        target = (ROOT / relative).resolve()
        if ROOT not in (target, *target.parents):
            return self.send_error(404)
        if target.is_dir():
            target = target / 'index.html'
        if target.suffix not in {'.html', '.css', '.js', '.svg', '.webp', '.png', '.jpg', '.jpeg', '.ico', '.woff', '.woff2'}:
            return self.send_error(404)
        if not target.is_file():
            return self.send_error(404)
        if target.suffix == '.html':
            return self.send_html(target.read_text().replace('</body>', BADGE + '</body>'))
        super().do_GET()

    do_POST = api
    do_PUT = api
    do_DELETE = api


if __name__ == '__main__':
    port = ARGS.port
    handler = functools.partial(Preview, directory=str(ROOT))
    print(f'Isolated Marketing QA is ready on port {port}. Open /qa.', flush=True)
    ThreadingHTTPServer((ARGS.host, port), handler).serve_forever()
