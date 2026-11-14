"""Loopback-only UI QA. Real account code + labelled synthetic model responses. Never deploy."""
import functools
import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import sqlite3
import sys
import tempfile
import threading
import time
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer, BaseHTTPRequestHandler

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'account'))
import pelanggan_akaun as account
import akaun_pelayan

tmp = tempfile.TemporaryDirectory(prefix='copilot-ui-qa-')
db = str(Path(tmp.name) / 'account.sqlite')
secret = secrets.token_hex(32)
service_secret = secrets.token_hex(32)
os.environ['AKAUN_SECRET'] = secret
os.environ['COPILOT_KUTA_SECRET'] = service_secret

def connect():
    return sqlite3.connect(db, timeout=10)

def call(method, path, data=None, auth='', idem=None, signed=False):
    raw = json.dumps(data or {}, separators=(',', ':')).encode()
    headers = None
    if signed:
        stamp = str(int(time.time())); nonce = secrets.token_hex(16)
        msg = f'{stamp}.{nonce}.{method}.{path}.{hashlib.sha256(raw).hexdigest()}'
        headers = {'masa': stamp, 'nonce': nonce, 'tandatangan': hmac.new(service_secret.encode(), msg.encode(), hashlib.sha256).hexdigest()}
    return account.dispatch(method, path, 'http://localhost:8000', auth, raw, connect, False, secret, 'qa',
                            kuta_headers=headers, idempotency_key=idem)

_, login = call('POST', '/api/akaun/daftar', {'email': 'synthetic@example.test', 'password': secrets.token_hex(12)})
auth = 'Bearer ' + login['token']
call('POST', '/api/akaun/penasihat', {'penasihat': {'nama': 'Kedai Kopi Ujian', 'jualan': 'Kopi untuk pekerja pejabat Ampang',
    'peringkat': 'jual', 'mesej': [{'dari': 'penasihat', 'teks': 'Pratonton ujian. Respons AI di sini ialah contoh sintetik.'}]}}, auth)

class Front(SimpleHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        if self.path == '/qa':
            body = b'''<!doctype html><meta charset="utf-8"><title>Copilot UI QA</title><h1>Pratonton ujian Copilot</h1><p>Akaun sintetik. Respons model contoh. Tiada panggilan AI sebenar.</p><button id="mula">Buka akaun ujian</button><script>document.querySelector('#mula').onclick=async()=>{const r=await fetch('/qa-login');localStorage.setItem('beshare-akaun',JSON.stringify(await r.json()));location.href='/penasihat/#utama'}</script>'''
            self.send_response(200); self.end_headers(); self.wfile.write(body); return
        if self.path == '/qa-login':
            self.send_response(200); self.send_header('Content-Type', 'application/json'); self.end_headers(); self.wfile.write(json.dumps(login).encode()); return
        if self.path.startswith('/penasihat/') and self.path.split('?')[0] in ('/penasihat/', '/penasihat/index.html'):
            raw = (Path(self.directory)/'penasihat/index.html').read_text()
            raw = raw.replace('<div class="bingkai"', '<aside style="position:fixed;right:8px;top:2px;z-index:9999;background:#fff3cd;color:#332800;padding:4px 12px;font:12px sans-serif">UJIAN • Respons contoh sintetik</aside><div class="bingkai"', 1)
            raw = raw.replace('</body>', '''<button id="qa-suara" style="position:fixed;bottom:8px;right:8px;z-index:9999" onclick="BeshareHasil.offerVoice([{speaker:'user',text:'Latte RM8. Saya setuju hubungi lima pelanggan minggu ini.'},{speaker:'assistant',text:'Baik, semak dahulu sebelum simpan tugasan.'}])">Ujian: transkrip sintetik</button></body>''')
            self.send_response(200); self.send_header('Content-Type','text/html; charset=utf-8'); self.end_headers(); self.wfile.write(raw.encode()); return
        super().do_GET()

class Advice(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def send(self, status, data):
        self.send_response(status); self.send_header('Access-Control-Allow-Origin', 'http://localhost:8000')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, Authorization, Idempotency-Key')
        self.send_header('Content-Type', 'application/json'); self.end_headers()
        if data is not None: self.wfile.write(json.dumps(data).encode())
    def do_OPTIONS(self): self.send(204, None)
    def do_POST(self):
        if self.path != '/api/copilot/bijak':
            self.send(503, {'error': 'Ujian UI sintetik: suara langsung belum diaktifkan.'}); return
        payload = json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))))
        bearer = self.headers.get('Authorization')
        code, context = call('GET', '/api/akaun/copilot/konteks', auth=bearer)
        if code != 200: self.send(code, context); return
        code, reserved = call('POST', '/api/akaun/copilot/kuota/rezab', {'route': 'copilot_nasihat', 'idempotency_key': self.headers.get('Idempotency-Key'), 'giliran': payload['giliran']}, bearer, signed=True)
        if code != 200: self.send(code, reserved); return
        key = reserved['rezab_id']
        if reserved.get('main_semula'):
            self.send(200, {**reserved, 'sumber': 'tersimpan', 'bukan_model': False, 'cadangan_id': key}); return
        call('POST', '/api/akaun/copilot/kuota/hantar', {'rezab_id': key}, bearer, signed=True)
        question = payload['giliran'][-1]['teks'].lower()
        reply = {'jawapan': 'Respons contoh: Fokus pada satu tawaran yang sesuai dengan pelanggan anda.', 'langkah': [], 'format': 'ringkas', 'cadangan': []}
        if 'ringkaskan perkara utama' in question:
            reply.update(jawapan='Respons contoh ringkasan suara.\nAnda bersetuju menghubungi lima pelanggan minggu ini. Harga latte RM8 ialah harga produk, bukan rekod jualan.', format='tindakan', cadangan=[{'jenis':'tugas','teks':'Hubungi lima pelanggan','tarikh':context['minggu_mula'],'kategori':'','nilai':''}])
        elif 'catat' in question:
            reply.update(jawapan='Respons contoh: Semak dua rekod ini sebelum menyimpannya.', format='tindakan', cadangan=[
                {'jenis': 'angka', 'teks': 'Jualan hari ini', 'tarikh': context['hari'], 'kategori': 'hasil', 'nilai': '350.00'},
                {'jenis': 'angka', 'teks': 'Kos hari ini', 'tarikh': context['hari'], 'kategori': 'kos', 'nilai': '120.00'}])
        elif 'iklan' in question or 'pemasaran' in question or 'promosi' in question:
            reply.update(jawapan='Respons contoh iklan\n\nMulakan pagi dengan kopi segar di Ampang.\n\nLatte RM8. Singgah hari ini!', format='pemasaran')
        elif 'pelan' in question or 'tugasan' in question:
            reply.update(jawapan='Respons contoh pelan minggu ini.', format='pelan', langkah=['Semak tawaran kopi.', 'Hubungi lima pelanggan.'])
        reply['asas'] = {'minggu_mula': context['minggu_mula'], 'pelan_versi': context['pelan']['versi'] if context['pelan'] else 0, 'ingatan_versi': context['ingatan']['versi']}
        code, settled = call('POST', '/api/akaun/copilot/kuota/muktamad', {'rezab_id': key, 'hasil': 'settled', **reply}, bearer, signed=True)
        self.send(code, {'ok': code == 200, 'sumber': 'openai', 'bukan_model': False, **reply, 'cadangan_id': key} if code == 200 else settled)

if __name__ == '__main__':
    root = Path(sys.argv[1]).resolve()
    # Bind only loopback. This preview uses isolated temporary SQLite, never production data.
    for port, handler in [(8767, akaun_pelayan.ikat(connect, False)), (3210, Advice)]:
        server = ThreadingHTTPServer(('127.0.0.1', port), handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
    print('Synthetic UI preview: http://localhost:8000/qa', flush=True)
    ThreadingHTTPServer(('127.0.0.1',8000), functools.partial(Front, directory=str(root))).serve_forever()
