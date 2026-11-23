"""Run desktop and mobile Ads flows against the isolated Marketing preview and a fake Meta account."""
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path
from urllib.error import URLError
from urllib.request import urlopen

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ORIGIN = 'http://127.0.0.1:8516'
PLAN = 'd' * 32


def check(browser, width, height):
    page = browser.new_page(viewport={'width': width, 'height': height})
    errors, writes = [], []
    page.on('pageerror', lambda exc: errors.append(str(exc)))
    page.goto(ORIGIN + '/qa')
    page.locator('#mula').click()
    page.locator('[data-view="facebook-ads"]').click()
    expect(page.locator('#ads-account')).to_contain_text('belum dikonfigurasi')
    expect(page.locator('#ads-connect')).to_be_disabled()

    def workspace(route):
        data = route.fetch().json()
        grant = next(row for row in data['connections'] if row['platform'] == 'facebook_ads')
        grant.update(configured=True, connected=True, status='connected',
                     accounts=[{'name': 'Akaun Ujian', 'account_id': '6789'}])
        data['campaigns'] = [{'title': 'Produk Ujian', 'id': PLAN, 'version': 2, 'status': 'draft',
                              'goal': 'awareness', 'budget_total': '0.00', 'channels': ['facebook']}]
        data['capabilities']['ads']['post_whatsapp_ads'] = True
        data['posts'] = [{'id': 'b' * 32, 'campaign_id': PLAN, 'platform': 'facebook', 'status': 'published',
                          'body': 'Menu hari ini', 'provider_post_id': '1001_987', 'format': 'text', 'version': 1}]
        route.fulfill(json=data)

    def report(route):
        body = route.request.post_data_json
        if body.get('after'):
            rows = [{'name': 'Kempen lain', 'id': '100', 'status': 'PAUSED', 'metrics': None}]
        else:
            rows = [{'name': 'Promosi kedai', 'id': '99', 'status': 'ACTIVE',
                     'metrics': {'spend': '8.40', 'account_currency': 'USD', 'impressions': '321', 'clicks': '0'}}]
        route.fulfill(json={'ok': True, 'after': '' if body.get('after') else 'next', 'campaigns': rows})

    def create(route):
        writes.append(route.request.post_data_json)
        route.fulfill(json={'ok': True, 'campaign_id': '200', 'existing': False})

    page.route('**/marketing/workspace', workspace)
    page.route('**/facebook-ads/report', report)
    page.route('**/facebook-ads/campaigns', create)
    page.locator('#refresh-workspace').click()
    expect(page.locator('#ads-account')).to_contain_text('Akaun Ujian')
    expect(page.locator('#ads-rows')).to_contain_text('USD')
    page.locator('#ads-more').click()
    expect(page.locator('#ads-rows')).to_contain_text('Kempen lain')
    with page.expect_request(lambda r: r.url.endswith('/facebook-ads/report')) as changed:
        page.locator('#ads-period').select_option('last_30d')
    assert changed.value.post_data_json['period'] == 'last_30d'
    expect(page.locator('#ads-rows tr')).to_have_count(1)
    page.locator('#ads-plan').select_option(PLAN)
    page.locator('#ads-ordinary').check()
    page.locator('#ads-create').click()
    expect(page.locator('#ads-create-status')).to_contain_text('200')
    assert writes == [{'account_id': '6789', 'plan_id': PLAN, 'version': 2,
                       'objective': 'OUTCOME_TRAFFIC', 'ordinary_ad': True}]
    assert page.locator('#ads-manager').get_attribute('href').endswith('?act=6789')
    assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), width
    page.get_by_role('link', name='Pilih hantaran').click()
    page.get_by_role('button', name='Jadikan iklan').click()
    page.locator('#boost-budget').fill('60.09')
    approvals = []
    previews = []
    def preview_post(route):
        body = route.request.post_data_json
        destination = body['destination']
        expected = {'account_id': '6789', 'post_id': 'b' * 32, 'days': 3, 'country': 'MY', 'budget': '60.09',
                    'destination': destination}
        if destination == 'link':
            expected['destination_url'] = 'https://t.me/kedai'
        assert body == expected
        previews.append(destination)
        route.fulfill(json={'ok': True, 'review_id': 'a' * 40, 'preview': {
            'page_name': 'Kedai', 'account_name': 'Akaun Ujian', 'account_id': '6789',
            'text': 'Menu hari ini', 'phone': '+60123456789', 'destination': destination,
            'destination_url': body.get('destination_url'), 'currency': 'MYR', 'budget_minor': 6009,
            'country': 'MY', 'days': 3, 'post_url': 'https://www.facebook.com/1001/posts/987'}})
    def launch_ad(route):
        approvals.append(route.request.post_data_json)
        route.fulfill(json={'ok': True, 'campaign_id': '1234'})
    page.route('**/facebook-ads/post-preview', preview_post)
    page.route('**/facebook-ads/post-launch', launch_ad)
    page.locator('#boost-preview').click()
    assert not previews
    for channel, recipient in [('whatsapp', '+60123456789'), ('link', 'https://t.me/kedai'), ('messenger', 'Messenger · Kedai')]:
        page.locator('#boost-channel').select_option(channel)
        if channel == 'link':
            page.locator('#boost-preview').click()
            assert previews == ['whatsapp']
            page.locator('#boost-url').fill(recipient)
        else:
            expect(page.locator('#boost-url')).to_be_hidden()
        page.locator('#boost-preview').click()
        expect(page.locator('#boost-summary')).to_contain_text('60.09')
        expect(page.locator('#boost-destination')).to_contain_text(recipient)
        if channel != 'messenger':
            page.locator('#boost-edit').click()
            expect(page.locator('#boost-review')).to_be_hidden()
    assert previews == ['whatsapp', 'link', 'messenger']
    expect(page.locator('#boost-launch')).to_be_visible()
    page.locator('#boost-launch').click()
    assert not approvals
    page.locator('#boost-ordinary').check()
    page.locator('#boost-launch').click()
    expect(page.locator('#boost-status')).to_contain_text('1234')
    assert approvals == [{'account_id': '6789', 'post_id': 'b' * 32, 'review_id': 'a' * 40,
                          'approve_spend': True, 'ordinary_ad': True}]
    assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), width
    assert not errors, errors
    page.close()
    print(f'Passed Facebook Ads UI at {width} × {height}.')


if __name__ == '__main__':
    preview = subprocess.Popen([sys.executable, 'marketing/tests/preview.py', '.', '--port', '8516'],
                               cwd=ROOT, env={**os.environ, 'MARKETING_UI_QA': '1'})
    try:
        for _ in range(50):
            if preview.poll() is not None:
                raise RuntimeError('Isolated preview did not start')
            try:
                with urlopen(ORIGIN + '/qa', timeout=1):
                    break
            except URLError:
                time.sleep(0.1)
        with sync_playwright() as runner:
            browser = runner.chromium.launch(executable_path=shutil.which('google-chrome') or shutil.which('chromium'))
            for width, height in [(1440, 900), (390, 844)]:
                check(browser, width, height)
            browser.close()
    finally:
        preview.terminate()
        preview.wait(timeout=5)
