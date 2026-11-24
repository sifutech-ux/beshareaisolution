"""Exercise a real merchant, anonymous visitor and Google Search export in isolated QA."""
import csv
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import time
from pathlib import Path
from urllib.error import URLError
from urllib.request import Request, urlopen
from playwright.sync_api import expect, sync_playwright
ROOT = Path(__file__).resolve().parents[2]
ORIGIN = 'http://127.0.0.1:8789'

def check():
    with urlopen(ORIGIN + '/qa-login') as r:
        session = json.load(r)
    key = secrets.token_hex(16)
    plan = {'version': 0, 'title': 'Katering Pejabat', 'offer': 'Pakej 20 orang RM250', 'product': 'Katering'}
    headers = {'Authorization': 'Bearer ' + session['token'], 'Content-Type': 'application/json'}
    create = Request(ORIGIN + '/api/akaun/copilot/marketing/campaigns/' + key, method='PUT', data=json.dumps(plan).encode(), headers=headers)
    with urlopen(create) as response:
        assert response.status == 200
    with sync_playwright() as pw:
        b = pw.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'))
        owner = b.new_page()
        errors = []
        owner.on('pageerror', lambda error: errors.append(str(error)))
        owner.goto(ORIGIN + '/qa')
        owner.get_by_text('Buka ruang kerja ujian').click()
        owner.locator('a[href="#promosi"]').click()
        owner.locator('#promo-campaign').select_option(key)
        owner.locator('#promo-business').fill('Dapur Ujian')
        owner.locator('#promo-area').fill('Ampang')
        drafts = [
            'SOALAN_PENIAGA: Pakej ini untuk berapa orang?',
            'Makan tengah hari untuk 20 orang di pejabat. Pakej RM250 di Ampang.',
            json.dumps({'keywords': ['katering pejabat Ampang'], 'negatives': ['kerja kosong'],
                        'headlines': ['Katering Pejabat Ampang', 'Pakej Untuk 20 Orang', 'Minta Sebut Harga'],
                        'descriptions': ['Sediakan makan tengah hari untuk pejabat.', 'Pakej 20 orang RM250 di Ampang.']})
        ]
        prompts = []
        def handle_draft(route):
            prompts.append(''.join(t['teks'] for t in route.request.post_data_json['giliran']))
            route.fulfill(json={'ok': True, 'bukan_model': False, 'sumber': 'openai', 'jawapan': drafts[len(prompts)-1]})
        owner.route('**/api/marketing/draf', handle_draft)
        owner.locator('#promo-ai').click()
        expect(owner.locator('#promo-feedback')).to_contain_text('Pakej ini untuk berapa orang?')
        expect(owner.locator('#promo-offer')).to_have_value(plan['offer'])
        owner.locator('#promo-offer').fill('Pakej katering pejabat untuk 20 orang RM250')
        owner.locator('#promo-ai').click()
        expect(owner.locator('#promo-offer')).to_have_value(drafts[1])
        assert '4P' in prompts[1] and 'Pakej katering pejabat untuk 20 orang RM250' in prompts[1]
        owner.locator('#promo-draft').click()
        expect(owner.locator('#promo-feedback')).to_contain_text('draf')
        visitor = b.new_page(viewport={'width': 390, 'height': 844})
        visitor.goto(ORIGIN + '/marketing/promosi.html?p=' + key)
        expect(visitor.locator('#loading')).to_have_text('Halaman promosi tidak tersedia.')
        owner.get_by_role('button', name='Terbitkan halaman').click()
        expect(owner.locator('#promo-qr')).to_have_attribute('href', re.compile('^data:image/gif;base64,'))
        owner.locator('#promo-source').select_option('google')
        visitor.goto(owner.locator('#promo-link').get_attribute('href'))
        expect(visitor.locator('#title')).to_have_text('Katering Pejabat')
        visitor.get_by_label('Nama anda').fill('Pelanggan Contoh')
        visitor.get_by_label('Telefon atau e-mel').fill('qa@example.test')
        visitor.get_by_label('Butiran pertanyaan').fill('20 orang hari Jumaat')
        visitor.get_by_role('checkbox').check()
        visitor.get_by_role('button', name='Hantar pertanyaan').click()
        expect(visitor.locator('#receipt')).to_contain_text('sudah diterima')
        owner.locator('#promo-refresh').click()
        expect(owner.locator('#promo-count')).to_contain_text('Google · 1 pertanyaan, 0 tempahan')
        owner.locator('#promo-leads select').select_option('tempahan')
        expect(owner.locator('#promo-count')).to_contain_text('1 tempahan')
        owner.locator('#search-form [name=budget]').fill('10')
        owner.locator('#search-ai').click()
        expect(owner.locator('#search-form [name=keywords]')).to_have_value('katering pejabat Ampang')
        assert 'Google Search' in prompts[2] and '4P' not in prompts[2]
        with owner.expect_download() as downloaded:
            owner.get_by_role('button', name='Eksport CSV Google Ads Editor').click()
        with open(downloaded.value.path(), encoding='utf-8-sig') as f:
            rows = list(csv.DictReader(f))
        assert rows[0]['Campaign Status'] == 'Paused' and rows[0]['Daily budget'] == '10'
        assert rows[1]['Location'] == 'Ampang' and 'utm_source=google' in rows[-1]['Final URL']
        owner.get_by_role('button', name='Hentikan paparan').click()
        expect(owner.locator('#promo-feedback')).to_contain_text('draf')
        visitor.reload()
        expect(visitor.locator('#loading')).to_contain_text('tidak tersedia')
        assert not errors, errors
        print('PASS draft, publish, tracked URL, QR, mobile anonymous form, inbox, order, paused Search CSV, unpublish')
if __name__ == '__main__':
    server = subprocess.Popen([sys.executable, 'marketing/tests/preview.py', '.', '--port', '8789'], cwd=ROOT, env={**os.environ, 'MARKETING_UI_QA': '1'})
    try:
        for _ in range(50):
            if server.poll() is not None:
                raise RuntimeError('Isolated preview failed to start')
            try:
                with urlopen(ORIGIN + '/qa', timeout=1):
                    break
            except URLError:
                time.sleep(0.1)
        check()
    finally:
        server.terminate()
        server.wait(timeout=5)
