"""Media security, durable ownership and cancellation tests. No publishing."""
import concurrent.futures
import hashlib
import http.client
import io
import json
import os
import shutil
import sqlite3
import subprocess
import tempfile
import threading
import time
import unittest
from http.server import ThreadingHTTPServer
from pathlib import Path
from unittest.mock import Mock, patch
from urllib.parse import urlencode, urlsplit

from PIL import Image

import akaun_pelayan
import marketing_media as media
import pelanggan_akaun as account
import pelanggan_marketing as marketing
import pelanggan_meja as meja
from test_marketing import (CAMPAIGN, CAMPAIGN_ID, OWNER, OTHER, POST, POST_ID,
                            _postgres_test_connection, _reset_postgres_fixture)


SECRET = 'media-tests-only-account-secret'
KEY = '1' * 32
SECOND = '2' * 32


def picture(kind='PNG', size=(240, 240), color='red', exif=None):
    output = io.BytesIO()
    with Image.new('RGBA' if isinstance(color, tuple) and len(color) == 4 else 'RGB', size, color) as image:
        kwargs = {'exif': exif} if exif is not None else {}
        image.save(output, format=kind, **kwargs)
    return output.getvalue()


class MediaTests(unittest.TestCase):
    postgres = False

    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.db_path = str(self.root / 'account.db')
        self.storage = self.root / 'media'
        self.now = int(time.time())
        env = patch.dict(os.environ, {'MARKETING_MEDIA_ENABLED': '1',
            'MARKETING_MEDIA_ROOT': str(self.storage),
            'MARKETING_MEDIA_PUBLIC_BASE_URL': 'https://media.example.com', 'AKAUN_SECRET': SECRET})
        env.start()
        self.addCleanup(env.stop)
        network = patch('urllib.request.OpenerDirector.open', side_effect=AssertionError('Provider traffic forbidden'))
        self.network = network.start()
        self.addCleanup(network.stop)
        self.addCleanup(self.network.assert_not_called)
        if self.postgres:
            _reset_postgres_fixture(self.connect)
        conn = self.connect()
        ph = meja._ph(self.postgres)
        conn.cursor().execute('CREATE TABLE pelanggan_akaun (id TEXT PRIMARY KEY,email TEXT NOT NULL)')
        conn.cursor().executemany(f'INSERT INTO pelanggan_akaun VALUES ({ph},{ph})',
                                 [(OWNER, 'a@example.com'), (OTHER, 'b@example.com')])
        from access_fixture import grant_existing
        grant_existing(conn, postgres=self.postgres)
        marketing.ensure_marketing_schema(conn, self.postgres)
        conn.commit()
        conn.close()
        self.auth = 'Bearer ' + account.issue_token(OWNER, SECRET, self.now)
        self.other_auth = 'Bearer ' + account.issue_token(OTHER, SECRET, self.now)

    def connect(self):
        return sqlite3.connect(self.db_path, timeout=10)

    def execute(self, sql, args=()):
        conn = self.connect()
        try:
            cur = conn.cursor()
            cur.execute(sql.replace('?', '%s') if self.postgres else sql, args)
            result = cur.fetchall() if cur.description else []
            conn.commit()
            return result
        finally:
            conn.close()

    def call(self, method, key=KEY, data=b'', mime='image/png', auth=None,
             name='photo.png', stream=None, length=None, now=None, origin=None):
        return media.dispatch_media(method, media.PREFIX + '/' + key, urlencode({'name': name}),
            origin, self.auth if auth is None else auth, mime, len(data) if length is None else length,
            stream if stream is not None else io.BytesIO(data), self.connect, self.postgres, SECRET,
            self.now if now is None else now)

    def upload(self, key=KEY, **kwargs):
        status, result = self.call('PUT', key, picture(), **kwargs)
        self.assertEqual(status, 201, result)
        return result['media']

    def domain(self, method, suffix, payload=None, owner=OWNER):
        conn = self.connect()
        try:
            return marketing.dispatch_marketing(method, marketing.PREFIX + suffix, conn, self.postgres,
                                                owner, payload, self.now)
        finally:
            conn.close()

    def save_post(self, item, owner=OWNER, key=POST_ID, **extra):
        code, result = self.domain('PUT', '/campaigns/' + CAMPAIGN_ID, CAMPAIGN, owner)
        self.assertEqual(code, 200, result)
        payload = {**POST, 'media_id': item['id'], 'media_url': item['url'], 'format': item['format'], **extra}
        return self.domain('PUT', '/posts/' + key, payload, owner)

    def object_files(self):
        return list((self.storage / 'objects').glob('*'))

    def test_auth_and_origin_precede_body_reads_and_storage_creation(self):
        for auth, origin, expected in [('', None, 401), ('Bearer invalid', None, 401),
                                       (self.auth, 'https://untrusted.example.com', 403),
                                       ('Bearer ' + account.issue_token('missing', SECRET, self.now), None, 401)]:
            stream = Mock()
            code, result = self.call('PUT', data=b'x', auth=auth, origin=origin, stream=stream)
            self.assertEqual(code, expected, result)
            stream.read.assert_not_called()
            stream.read1.assert_not_called()
        self.assertFalse(self.storage.exists())
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_media')[0][0], 0)

    def test_png_normalized_to_jpeg_and_public_url_contains_no_identity(self):
        blob = picture(size=(40, 20), color=(0, 0, 255, 0))
        status, result = self.call('PUT', data=blob, name='../../private-photo.png')
        self.assertEqual(status, 201, result)
        item = result['media']
        self.assertEqual(item['name'], 'private-photo.png')
        self.assertEqual(item['mime_type'], 'image/jpeg')
        self.assertLessEqual(item['size_bytes'], 8_000_000)
        self.assertNotIn(OWNER, item['url'])
        self.assertNotIn('private-photo', item['url'])
        self.assertNotIn(KEY, item['url'])
        with Image.open(self.object_files()[0]) as image:
            self.assertEqual(image.format, 'JPEG')
            self.assertEqual(image.mode, 'RGB')
            self.assertEqual(image.getpixel((0, 0)), (255, 255, 255))
        self.assertEqual(self.call('GET')[1]['media'], item)
        self.assertEqual(len(list((self.storage / 'temporary').glob('*'))), 0)

    def test_image_orientation_metadata_and_polyglot_tail_are_removed(self):
        exif = Image.Exif()
        exif[274] = 6
        exif[270] = 'private fixture metadata'
        blob = picture('JPEG', (120, 80), exif=exif) + b'<script>not-image</script>'
        code, result = self.call('PUT', data=blob, mime='image/jpeg', name='photo.jpg')
        self.assertEqual(code, 201, result)
        self.assertEqual((result['media']['width'], result['media']['height']), (80, 120))
        stored = self.object_files()[0].read_bytes()
        self.assertNotIn(b'private fixture', stored)
        self.assertNotIn(b'<script>', stored)
        with Image.open(self.object_files()[0]) as image:
            self.assertFalse(image.getexif())

    def test_invalid_types_empty_oversize_corrupt_and_dimensions_release_capacity(self):
        bad = [(b'<svg/>', 'image/svg+xml', None, 415), (b'<html/>', 'image/png', None, 415),
               (picture('GIF'), 'image/png', None, 415), (b'', 'image/png', None, 400),
               (b'x', 'image/png', media.MAX_IMAGE_BYTES + 1, 413),
               (b'x', 'video/mp4', media.MAX_VIDEO_BYTES + 1, 413),
               (picture(size=(8193, 1)), 'image/png', None, 413)]
        for index, (blob, mime, length, expected) in enumerate(bad):
            key = f'{index + 10:032x}'
            code, result = self.call('PUT', key, blob, mime=mime, length=length)
            self.assertEqual(code, expected, result)
        self.assertFalse(self.object_files())
        self.assertEqual(self.execute('SELECT COALESCE(SUM(size_bytes),0) FROM marketing_media')[0][0], 0)

    def test_identical_retry_recovers_one_file_but_changed_payload_conflicts(self):
        blob = picture()
        self.assertEqual(self.call('PUT', data=blob)[0], 201)
        code, retry = self.call('PUT', data=blob)
        self.assertEqual(code, 200, retry)
        self.assertTrue(retry['replayed'])
        self.assertEqual(len(self.object_files()), 1)
        code, result = self.call('PUT', data=blob[:-1] + b'X')
        self.assertEqual((code, result['code']), (409, 'media_conflict'))
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_media')[0][0], 1)

    def test_owner_scoping_allows_no_metadata_cancel_reuse_or_post_reference(self):
        item = self.upload()
        for method in ('GET', 'DELETE', 'PUT'):
            code, result = self.call(method, data=picture(), auth=self.other_auth)
            self.assertEqual((code, result['code']), (404, 'media_not_found'))
            self.assertNotIn('media', result)
        self.domain('PUT', '/campaigns/' + 'f' * 32, CAMPAIGN, OTHER)
        payload = {**POST, 'campaign_id': 'f' * 32, 'format': 'image', 'media_id': KEY, 'media_url': item['url']}
        self.assertEqual(self.domain('PUT', '/posts/' + POST_ID, payload, OTHER)[0], 404)
        self.assertEqual(self.execute('SELECT COUNT(*) FROM marketing_posts')[0][0], 0)

    def test_post_binding_retains_single_file_and_external_url_flow(self):
        first, second = self.upload(), self.upload(SECOND)
        status, result = self.save_post(first)
        self.assertEqual(status, 200, result)
        self.assertEqual(self.call('DELETE')[1]['code'], 'media_in_use')
        update = {**POST, 'version': 1, 'media_id': second['id'], 'media_url': second['url'], 'format': 'image'}
        self.assertEqual(self.domain('PUT', '/posts/' + POST_ID, update)[0], 200)
        self.assertEqual(self.execute('SELECT media_id FROM marketing_media_links'), [(SECOND,)])
        self.assertEqual(self.call('DELETE')[0], 200)
        update.update(version=2, media_id='', media_url='https://example.com/external.jpg')
        self.assertEqual(self.domain('PUT', '/posts/' + POST_ID, update)[0], 200)
        self.assertEqual(self.execute('SELECT * FROM marketing_media_links'), [])
        self.assertEqual(self.call('DELETE', SECOND)[0], 200)

    def test_managed_url_normalization_cannot_bypass_ownership_or_retention(self):
        item = self.upload()
        path = urlsplit(item['url']).path
        aliases = ['https://MEDIA.EXAMPLE.COM:443' + path,
                   'https://media.example.com.:443' + path + '?cache=1#preview',
                   'https://media.example.com/' + path,
                   'https://media.example.com/api/../' + path.lstrip('/')]
        self.domain('PUT', '/campaigns/' + CAMPAIGN_ID, CAMPAIGN)
        self.domain('PUT', '/campaigns/' + 'f' * 32, CAMPAIGN, OTHER)
        for index, url in enumerate(aliases):
            payload = {**POST, 'format': 'image', 'media_url': url}
            status, result = self.domain('PUT', '/posts/' + f'{index + 30:032x}', payload)
            self.assertEqual(status, 200, result)
            self.assertEqual(result['post']['media_id'], KEY)
            self.assertEqual(result['post']['media_url'], item['url'])
            payload['campaign_id'] = 'f' * 32
            self.assertEqual(self.domain('PUT', '/posts/' + f'{index + 40:032x}', payload, OTHER)[0], 404)
        self.assertEqual(len(self.execute('SELECT * FROM marketing_media_links')), len(aliases))

    def test_cancel_before_put_blocks_late_upload_and_is_idempotent(self):
        self.assertEqual(self.call('DELETE')[0], 200)
        self.assertEqual(self.call('DELETE')[0], 200)
        self.assertEqual(self.call('PUT', data=picture())[1]['code'], 'media_cancelled')
        self.assertEqual(self.call('GET')[0], 410)
        self.assertFalse(self.object_files())

    def test_cancel_during_transfer_never_installs_a_public_file(self):
        started, release = threading.Event(), threading.Event()
        blob = picture()
        class Paused(io.BytesIO):
            def read1(self, count):
                started.set()
                if not release.wait(5):
                    raise AssertionError('Transfer fixture timed out')
                return super().read1(count)
        with concurrent.futures.ThreadPoolExecutor(1) as pool:
            future = pool.submit(self.call, 'PUT', data=blob, stream=Paused(blob))
            self.assertTrue(started.wait(5))
            self.assertEqual(self.call('GET')[1]['code'], 'upload_in_progress')
            self.assertEqual(self.call('DELETE')[0], 200)
            release.set()
            self.assertNotIn(future.result()[0], {200, 201})
        self.assertEqual(self.call('GET')[0], 410)
        self.assertFalse(self.object_files())
        self.assertEqual(list((self.storage / 'temporary').glob('*')), [])

    def test_interrupted_upload_releases_bytes_and_same_id_can_retry(self):
        blob = picture()
        code, result = self.call('PUT', data=blob, stream=io.BytesIO(blob[:20]))
        self.assertEqual((code, result['code']), (400, 'upload_incomplete'))
        self.assertEqual(self.execute('SELECT state,size_bytes FROM marketing_media'), [('failed', 0)])
        self.assertEqual(self.call('GET')[1]['code'], 'upload_failed')
        self.assertEqual(self.call('PUT', data=blob)[0], 201)

    def test_total_read_deadline_uses_partial_reads(self):
        class SlowDrip:
            def read(self, _count):
                raise AssertionError('Buffered fill-read must not be used')
            def read1(self, _count):
                return b'x'
        with patch.object(media.time, 'monotonic', side_effect=[0, 0, 301]):
            with self.assertRaises(media.MediaError) as error:
                media._read_stream(SlowDrip(), 2)
        self.assertEqual(error.exception.code, 'upload_timeout')

    def test_capacity_reservation_serializes_two_uploads(self):
        blob = picture('JPEG')
        started, release = threading.Event(), threading.Event()
        class Paused(io.BytesIO):
            def read1(self, count):
                started.set()
                if not release.wait(5):
                    raise AssertionError('Transfer fixture timed out')
                return super().read1(count)
        with patch.object(media, 'MAX_ACCOUNT_BYTES', len(blob)), concurrent.futures.ThreadPoolExecutor(1) as pool:
            future = pool.submit(self.call, 'PUT', data=blob, mime='image/jpeg', stream=Paused(blob))
            self.assertTrue(started.wait(5))
            code, result = self.call('PUT', SECOND, blob, mime='image/jpeg')
            self.assertEqual((code, result['code']), (409, 'media_capacity'))
            release.set()
            self.assertEqual(future.result()[0], 201)

    def test_normalization_cannot_expand_beyond_account_budget(self):
        blob = picture(size=(16, 16))
        with patch.object(media, 'MAX_ACCOUNT_BYTES', len(blob)):
            code, result = self.call('PUT', data=blob)
        self.assertEqual((code, result['code']), (409, 'media_capacity'))
        self.assertEqual(self.execute('SELECT state,size_bytes FROM marketing_media'), [('failed', 0)])
        self.assertFalse(self.object_files())

    def test_cleanup_keeps_referenced_files_and_removes_unused_and_crash_leftovers(self):
        first, second = self.upload(), self.upload(SECOND)
        self.assertEqual(self.save_post(first)[0], 200)
        old = self.now - media.UNATTACHED_SECONDS - 1
        self.execute('UPDATE marketing_media SET updated_at=?', (old,))
        media.cleanup(self.connect, self.postgres, self.now)
        self.assertEqual(self.call('GET')[0], 200)
        self.assertEqual(self.call('GET', SECOND)[0], 410)
        self.assertEqual(len(self.object_files()), 1)
        # Simulate cancellation commit followed by a crash before unlink.
        token = self.execute('SELECT public_token FROM marketing_media WHERE id=?', (SECOND,))[0][0]
        abandoned = self.storage / 'objects' / (token + '.jpg')
        abandoned.write_bytes(b'orphan fixture')
        self.execute('UPDATE marketing_media SET updated_at=? WHERE id=?', (old, SECOND))
        media.cleanup(self.connect, self.postgres, self.now)
        self.assertFalse(abandoned.exists())

    def test_public_file_and_single_ranges_work_without_account_bearer(self):
        item = self.upload()
        reader, row = media.public_file(urlsplit(item['url']).path, self.connect, self.postgres)
        with reader:
            self.assertEqual(len(reader.read()), item['size_bytes'])
        self.assertEqual(media.byte_range('bytes=0-9', row['size_bytes']), (0, 9, True))
        self.assertEqual(media.byte_range('bytes=-10', row['size_bytes']), (row['size_bytes'] - 10, row['size_bytes'] - 1, True))
        for value in ('bytes=0-1,4-8', 'bytes=-0', 'bytes=999999-', 'bytes=3-2', 'garbage'):
            with self.assertRaises(media.MediaError) as error:
                media.byte_range(value, row['size_bytes'])
            self.assertEqual(error.exception.status, 416)
        for path in (media.PUBLIC_PREFIX + '../photo.jpg', media.PUBLIC_PREFIX + KEY + '.jpg'):
            with self.assertRaises(media.MediaError):
                media.public_file(path, self.connect, self.postgres)

    def test_http_binary_boundary_head_ranges_and_duplicate_lengths(self):
        server = ThreadingHTTPServer(('127.0.0.1', 0), akaun_pelayan.ikat(self.connect, self.postgres))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        def request(method, path, body=None, headers=None):
            client = http.client.HTTPConnection('127.0.0.1', server.server_address[1], timeout=5)
            try:
                client.request(method, path, body, headers or {})
                response = client.getresponse()
                return response.status, dict(response.getheaders()), response.read()
            finally:
                client.close()
        code, headers, body = request('PUT', media.PREFIX + '/' + KEY + '?name=image.png', picture(),
            {'Authorization': self.auth, 'Content-Type': 'image/png', 'Origin': 'https://beshareaisolution.com'})
        self.assertEqual(code, 201, body)
        self.assertEqual(headers['Access-Control-Allow-Origin'], 'https://beshareaisolution.com')
        item = json.loads(body)['media']
        path = urlsplit(item['url']).path
        code, headers, body = request('HEAD', path)
        self.assertEqual((code, len(body)), (200, 0))
        self.assertEqual(int(headers['Content-Length']), item['size_bytes'])
        code, headers, body = request('GET', path, headers={'Range': 'bytes=0-9'})
        self.assertEqual((code, len(body)), (206, 10))
        self.assertEqual(headers['X-Content-Type-Options'], 'nosniff')
        self.assertEqual(request('GET', path, headers={'Range': 'bytes=999999-'})[0], 416)
        client = http.client.HTTPConnection('127.0.0.1', server.server_address[1], timeout=5)
        try:
            client.putrequest('PUT', media.PREFIX + '/' + SECOND)
            client.putheader('Content-Length', '1')
            client.putheader('Content-Length', '1')
            client.putheader('Content-Type', 'image/png')
            client.putheader('Authorization', self.auth)
            client.endheaders(b'x')
            response = client.getresponse()
            self.assertEqual(response.status, 400)
            response.read()
        finally:
            client.close()

    def test_instagram_aspect_blocked_at_schedule_without_provider_call(self):
        code, result = self.call('PUT', data=picture(size=(300, 100)))
        self.assertEqual(code, 201)
        code, post = self.save_post(result['media'], platform='instagram')
        self.assertEqual(code, 200, post)
        code, result = self.domain('POST', '/posts/' + POST_ID + '/schedule',
            {'version': 1, 'approved': True, 'scheduled_at': meja._iso(self.now + 600)})
        self.assertEqual((code, result['code']), (400, 'media_platform'))

    @unittest.skipUnless(shutil.which('ffmpeg') and shutil.which('ffprobe'), 'ffmpeg fixture unavailable')
    def test_real_mp4_mov_probe_and_unsupported_codec(self):
        for index, suffix in enumerate(('mp4', 'mov')):
            path = self.root / ('fixture.' + suffix)
            subprocess.run(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=160x160:r=30',
                '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-t', '3', '-c:v', 'libx264',
                '-threads', '1', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-movflags', '+faststart',
                '-use_editlist', '0', '-y', str(path)], capture_output=True, timeout=30, check=True)
            mime = 'video/mp4' if suffix == 'mp4' else 'video/quicktime'
            status, result = self.call('PUT', f'{60 + index:032x}', path.read_bytes(), mime=mime, name='movie.' + suffix)
            self.assertEqual(status, 201, result)
            self.assertEqual(result['media']['format'], 'video')
            self.assertEqual(result['media']['frame_rate'], 30)
            self.assertGreaterEqual(result['media']['duration_seconds'], 3)
        malformed = self.root / 'malformed.mp4'
        malformed.write_bytes(b'\x00\x00\x00\xffftypisom')
        with self.assertRaises(media.MediaError):
            media._video(malformed, 'video/mp4')
        valid = self.root / 'fixture.mp4'
        probed = {'format': {'format_name': 'mov,mp4', 'duration': '3'}, 'streams': [
            {'codec_type': 'video', 'codec_name': 'mpeg4', 'width': 160, 'height': 160, 'nb_read_packets': '90'}]}
        with patch.object(media, '_probe', return_value=probed):
            with self.assertRaises(media.MediaError) as error:
                media._video(valid, 'video/mp4')
        self.assertEqual(error.exception.code, 'unsupported_codec')


@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES') == '1', 'PostgreSQL fixture not enabled')
class PostgresMediaTests(MediaTests):
    postgres = True
    connect = _postgres_test_connection


if __name__ == '__main__':
    unittest.main()
