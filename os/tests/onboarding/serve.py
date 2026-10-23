#!/usr/bin/env python3
"""Loopback browser fixture. No DB, provider requests, real login, or binding.
Open /fixture/owner or /fixture/anonymous; then the actual workspace assets.
Only the SDK URL is substituted; the production signup implementation runs.
"""
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from pathlib import Path
from urllib.parse import urlsplit, parse_qs
import json
ROOT = Path(__file__).resolve().parents[3]
class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)
    def log_message(self, *args):
        pass
    def reply(self, body, kind='application/json', status=200):
        self.send_response(status)
        self.send_header('Content-Type', kind)
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-src 'none'")
        self.end_headers()
        self.wfile.write(body.encode())
    def do_GET(self):
        path = urlsplit(self.path).path
        if path.startswith('/fixture/'):
            mode = path.split('/')[-1]
            if mode not in ('owner', 'anonymous', 'throw'): return self.reply('{}', status=404)
            self.send_response(303)
            self.send_header('Set-Cookie', 'fixture=' + mode + '; Path=/; SameSite=Strict')
            self.send_header('Location', '/whatsapp/ruang.html#sambungan')
            self.end_headers(); return
        if path == '/whatsapp/akaun/pintu.php':
            owner = 'fixture=owner' in self.headers.get('Cookie', '') or 'fixture=throw' in self.headers.get('Cookie', '')
            result = {'ok': True, 'csrf': 'synthetic-fixture', 'user': {'name':'Synthetic owner', 'role':'owner'}, 'business':{'name':'LOCAL SYNTHETIC ONLY', 'public_id':'fixture', 'status':'active'}, 'connection':{}}
            return self.reply(json.dumps(result if owner else {'ok':False, 'error':'Log masuk diperlukan.'}), status=200 if owner else 401)
        if path == '/whatsapp/sambungan/awam.php':
            return self.reply(json.dumps({'ready':True,'appId':'123456','configId':'654321','version':'v26.0'}))
        if path == '/fixture-sdk.js':
            behavior = "throw new Error('synthetic SDK failure');" if 'fixture=throw' in self.headers.get('Cookie', '') else ''
            return self.reply('window.FB={init:function(){},login:function(cb,options){'+behavior+'}}; window.fbAsyncInit();', 'text/javascript')
        if path == '/whatsapp/ruang.js':
            source = (ROOT / 'whatsapp/ruang.js').read_text().replace('https://connect.facebook.net/en_US/sdk.js', '/fixture-sdk.js')
            return self.reply(source, 'text/javascript')
        if path.endswith('.php'): return self.reply('{}', status=403)
        if path.endswith('.html'):
            target = (ROOT / path.lstrip('/')).resolve()
            if ROOT not in target.parents or not target.is_file(): return self.reply('{}', status=404)
            return self.reply(target.read_text(), 'text/html; charset=utf-8')
        if Path(path).suffix not in ('.css', '.svg', '.png', '.js', '.ico') or '..' in path or any(part.startswith('.') for part in path.split('/') if part):
            return self.reply('{}', status=404)
        super().do_GET()
    def do_POST(self):
        action = parse_qs(urlsplit(self.path).query).get('tindakan', [''])[0]
        self.rfile.read(int(self.headers.get('Content-Length', '0')))
        if urlsplit(self.path).path == '/whatsapp/akaun/pintu.php' and action == 'sambung' and 'fixture=owner' in self.headers.get('Cookie', ''):
            return self.reply(json.dumps({'ok':True,'state':'a'*64}))
        self.reply('{"ok":false,"error":"Synthetic fixture denies this action"}', status=403)
if __name__ == '__main__':
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    print('http://127.0.0.1:' + str(server.server_port), flush=True)
    server.serve_forever()
