"""Bounded, account-owned media storage for the Marketing post editor.

Only opaque ready-file URLs are public, so providers can fetch an approved
attachment without an account credential. Uploads never schedule or publish.
"""
import hashlib
import importlib.util
import json
import math
import os
import posixpath
import re
import selectors
import secrets
import shutil
import stat
import subprocess
import sys
import threading
import time
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlsplit

import pelanggan_meja as meja


PREFIX = '/api/akaun/copilot/marketing/media'
PUBLIC_PREFIX = PREFIX + '/file/'
ID = re.compile(r'^[a-f0-9]{32}$')
TOKEN = re.compile(r'^[a-f0-9]{64}$')
PUBLIC_FILE = re.compile(r'^([a-f0-9]{64})\.(jpg|mp4|mov)$')
INPUT_TYPES = {'image/jpeg': 'image', 'image/png': 'image',
               'video/mp4': 'video', 'video/quicktime': 'video'}
MAX_IMAGE_BYTES = 10 * 1024 * 1024
MAX_VIDEO_BYTES = 100 * 1024 * 1024
MAX_OUTPUT_IMAGE_BYTES = 8_000_000
MAX_ACCOUNT_BYTES = 512 * 1024 * 1024
MAX_ACCOUNT_FILES = 200
MAX_ACCOUNT_ROWS = 1000
MAX_PENDING = 2
MAX_IMAGE_PIXELS = 32_000_000
MAX_IMAGE_SIDE = 8192
MIN_VIDEO_SECONDS = 3
MAX_VIDEO_SECONDS = 300
UPLOAD_SECONDS = 300
LEASE_SECONDS = 900
UNATTACHED_SECONDS = 86400
TOMBSTONE_SECONDS = 7 * 86400
MIN_FREE_BYTES = 128 * 1024 * 1024
_UPLOAD_SLOTS = threading.BoundedSemaphore(4)
_IMAGE_SLOTS = threading.BoundedSemaphore(1)


class MediaError(Exception):
    def __init__(self, message, status=400, code='invalid_media'):
        super().__init__(message)
        self.status, self.code = status, code


def error_body(error):
    return {'ok': False, 'code': error.code, 'error': str(error)}


def ensure_media_schema(conn, postgres):
    cur = conn.cursor()
    cur.execute('''CREATE TABLE IF NOT EXISTS marketing_media (
        id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, state TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT '', input_mime TEXT NOT NULL DEFAULT '',
        input_size BIGINT NOT NULL DEFAULT 0, size_bytes BIGINT NOT NULL DEFAULT 0,
        source_sha256 TEXT NOT NULL DEFAULT '', public_token TEXT NOT NULL UNIQUE,
        extension TEXT NOT NULL DEFAULT '', mime_type TEXT NOT NULL DEFAULT '',
        format TEXT NOT NULL DEFAULT '', width INTEGER NOT NULL DEFAULT 0,
        height INTEGER NOT NULL DEFAULT 0, duration_seconds DOUBLE PRECISION NOT NULL DEFAULT 0,
        frame_rate DOUBLE PRECISION NOT NULL DEFAULT 0, fast_start INTEGER NOT NULL DEFAULT 0,
        has_edit_list INTEGER NOT NULL DEFAULT 0,
        lease TEXT NOT NULL DEFAULT '', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)''')
    cur.execute('''CREATE TABLE IF NOT EXISTS marketing_media_links (
        post_id TEXT PRIMARY KEY, akaun_id TEXT NOT NULL, media_id TEXT NOT NULL)''')
    cur.execute('CREATE INDEX IF NOT EXISTS marketing_media_owner ON marketing_media (akaun_id,state,updated_at)')
    cur.execute('CREATE INDEX IF NOT EXISTS marketing_media_links_media ON marketing_media_links (media_id,akaun_id)')


def _config(admission=False):
    from pelanggan_akaun import _asal_https
    origin = (os.getenv('MARKETING_MEDIA_PUBLIC_BASE_URL') or '').strip()
    raw_root = (os.getenv('MARKETING_MEDIA_ROOT') or '').strip()
    root = Path(raw_root)
    if not raw_root or not root.is_absolute() or root == Path('/') or not _asal_https(origin):
        raise MediaError('Storan media belum tersedia. Gunakan URL media dahulu.', 503, 'media_unavailable')
    if admission and os.getenv('MARKETING_MEDIA_ENABLED') != '1':
        raise MediaError('Muat naik media belum diaktifkan. Gunakan URL media dahulu.', 503, 'media_unavailable')
    if admission and (importlib.util.find_spec('PIL') is None or not shutil.which('ffprobe')):
        raise MediaError('Pengesahan media belum tersedia pada pelayan.', 503, 'media_unavailable')
    return {'root': root, 'origin': origin}


def capabilities():
    enabled = False
    try:
        _config(True)
        enabled = True
    except (MediaError, ValueError):
        pass
    return {'enabled': enabled, 'max_image_bytes': MAX_IMAGE_BYTES, 'max_video_bytes': MAX_VIDEO_BYTES,
            'max_account_bytes': MAX_ACCOUNT_BYTES, 'max_account_files': MAX_ACCOUNT_FILES,
            'accepted_mime_types': list(INPUT_TYPES), 'output_image_mime_type': 'image/jpeg',
            'max_output_image_bytes': MAX_OUTPUT_IMAGE_BYTES,
            'min_video_duration_seconds': MIN_VIDEO_SECONDS, 'max_video_duration_seconds': MAX_VIDEO_SECONDS,
            'video_codecs': ['h264', 'hevc'], 'audio_codecs': ['aac'],
            'unattached_retention_seconds': UNATTACHED_SECONDS}


def _row(cur):
    value = cur.fetchone()
    return dict(zip([column[0] for column in cur.description], value)) if value else None


def _owned(conn, postgres, owner, key):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT * FROM marketing_media WHERE id={ph} AND akaun_id={ph}', (key, owner))
    return _row(cur)


def _url(row, cfg):
    return cfg['origin'] + PUBLIC_PREFIX + row['public_token'] + '.' + row['extension']


def _metadata(row, cfg):
    return {'id': row['id'], 'url': _url(row, cfg), 'format': row['format'],
            'mime_type': row['mime_type'], 'size_bytes': row['size_bytes'], 'name': row['name'],
            'width': row['width'], 'height': row['height'], 'duration_seconds': row['duration_seconds'],
            'frame_rate': row['frame_rate']}


def _object_path(row, cfg):
    if not TOKEN.fullmatch(row['public_token']) or row['extension'] not in {'jpg', 'mp4', 'mov'}:
        raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
    return cfg['root'] / 'objects' / (row['public_token'] + '.' + row['extension'])


def _temp_path(key, lease, cfg, suffix='part'):
    if not ID.fullmatch(key) or not ID.fullmatch(lease) or suffix not in {'part', 'jpg'}:
        raise MediaError('Simpanan sementara tidak sah.')
    return cfg['root'] / 'temporary' / (key + '-' + lease + '.' + suffix)


def _unlink(path):
    try:
        path.unlink(missing_ok=True)
    except OSError:
        pass


def _remove_files(rows, cfg):
    for row in rows:
        if row.get('extension'):
            _unlink(_object_path(row, cfg))
        if row.get('lease'):
            _unlink(_temp_path(row['id'], row['lease'], cfg))
            _unlink(_temp_path(row['id'], row['lease'], cfg, 'jpg'))


def _reference_exists(conn, postgres, row):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'SELECT post_id FROM marketing_media_links WHERE media_id={ph} LIMIT 1', (row['id'],))
    return bool(cur.fetchone())


def _cleanup_locked(conn, postgres, owner, stamp):
    """Caller holds the account lock. Mark first, unlink only after commit."""
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'''SELECT * FROM marketing_media WHERE akaun_id={ph} AND (
        (state='uploading' AND updated_at<{ph}) OR (state='ready' AND updated_at<{ph}) OR
        (state IN ('cancelled','failed') AND updated_at<{ph}))''',
                (owner, stamp - LEASE_SECONDS, stamp - UNATTACHED_SECONDS, stamp - UPLOAD_SECONDS))
    rows = [dict(zip([c[0] for c in cur.description], value)) for value in cur.fetchall()]
    removed = []
    for row in rows:
        if row['state'] in {'cancelled', 'failed'}:
            removed.append(row)
            continue
        if row['state'] == 'ready' and _reference_exists(conn, postgres, row):
            continue
        state = 'failed' if row['state'] == 'uploading' else 'cancelled'
        cur.execute(f'''UPDATE marketing_media SET state={ph},size_bytes=0,lease='',updated_at={ph}
            WHERE id={ph} AND akaun_id={ph}''', (state, stamp, row['id'], owner))
        removed.append(row)
    cur.execute(f'''DELETE FROM marketing_media WHERE akaun_id={ph} AND state IN ('cancelled','failed')
        AND updated_at<{ph}''', (owner, stamp - TOMBSTONE_SECONDS))
    return removed


def cleanup_owner(conn, postgres, owner, cfg, stamp):
    def work():
        meja._kunci_akaun(conn, postgres, owner)
        return 200, _cleanup_locked(conn, postgres, owner, stamp)
    _status, removed = meja._dalam(conn, postgres, work)
    _remove_files(removed, cfg)


def cleanup(connect, postgres, now=None):
    """Periodic bounded orphan cleanup; attached media has no automatic expiry."""
    cfg = _config()
    stamp = int(time.time() if now is None else now)
    conn = connect()
    try:
        ensure_media_schema(conn, postgres)
        conn.commit()
        ph = meja._ph(postgres)
        cur = conn.cursor()
        cur.execute(f'''SELECT DISTINCT akaun_id FROM marketing_media WHERE updated_at<{ph}
            AND state IN ('uploading','ready','cancelled','failed') LIMIT 500''', (stamp - LEASE_SECONDS,))
        owners = [row[0] for row in cur.fetchall()]
        conn.commit()
        for owner in owners:
            cleanup_owner(conn, postgres, owner, cfg, stamp)
        # Crash leftovers without a committed row are never public. A transfer
        # has a five-minute deadline; deleting a >24-hour temporary file is safe.
        temporary = cfg['root'] / 'temporary'
        if temporary.is_dir():
            for index, path in enumerate(temporary.iterdir()):
                if index >= 5000:
                    break
                if re.fullmatch(r'[a-f0-9]{32}-[a-f0-9]{32}\.(part|jpg)', path.name):
                    try:
                        if path.lstat().st_mtime < stamp - UNATTACHED_SECONDS:
                            _unlink(path)
                    except OSError:
                        pass
    finally:
        conn.close()


def start_cleanup(connect, postgres):
    def run():
        while True:
            try:
                cleanup(connect, postgres)
            except Exception:
                # No credentials, file names, account IDs or provider data in logs.
                pass
            threading.Event().wait(1800)
    thread = threading.Thread(target=run, name='marketing-media-cleanup', daemon=True)
    thread.start()
    return thread


def _name(query):
    values = parse_qs(query, keep_blank_values=True)
    if set(values) - {'name'} or len(values.get('name', [])) > 1:
        raise MediaError('Nama fail tidak sah.')
    value = values.get('name', ['media'])[0]
    if not isinstance(value, str) or not value or len(value) > 180 or any(ord(c) < 32 for c in value):
        raise MediaError('Nama fail mesti antara 1 hingga 180 aksara.')
    # This name is display-only and never participates in a path or HTTP header.
    return value.replace('\\', '/').rsplit('/', 1)[-1] or 'media'


def _request_type(content_type, length):
    if content_type not in INPUT_TYPES:
        raise MediaError('Pilih gambar JPEG/PNG atau video MP4/MOV.', 415, 'unsupported_media')
    maximum = MAX_IMAGE_BYTES if INPUT_TYPES[content_type] == 'image' else MAX_VIDEO_BYTES
    if type(length) is not int or length <= 0:
        raise MediaError('Fail kosong atau saiz muat naik tidak sah.')
    if length > maximum:
        raise MediaError('Gambar dihadkan kepada 10 MiB dan video kepada 100 MiB.', 413, 'media_too_large')


def _reserve(conn, postgres, owner, key, name, mime, length, cfg, stamp):
    ph = meja._ph(postgres)
    def work():
        meja._kunci_akaun(conn, postgres, owner)
        row = _owned(conn, postgres, owner, key)
        cur = conn.cursor()
        if row:
            if row['state'] == 'cancelled':
                raise MediaError('Muat naik ini telah dibatalkan. Pilih fail semula.', 410, 'media_cancelled')
            if row['state'] == 'uploading':
                raise MediaError('Muat naik masih diproses. Semak semula sebentar lagi.', 409, 'upload_in_progress')
            if row['state'] == 'ready':
                if (row['input_mime'], row['input_size'], row['name']) != (mime, length, name):
                    raise MediaError('Pengenal muat naik telah digunakan untuk fail lain.', 409, 'media_conflict')
                return 200, {'row': row, 'replay': True}
        else:
            cur.execute(f'SELECT id FROM marketing_media WHERE id={ph}', (key,))
            if cur.fetchone():
                raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
            cur.execute(f'SELECT COUNT(*) FROM marketing_media WHERE akaun_id={ph}', (owner,))
            if cur.fetchone()[0] >= MAX_ACCOUNT_ROWS:
                raise MediaError('Had percubaan muat naik dicapai. Cuba lagi kemudian.', 429, 'media_capacity')
        cur.execute(f'''SELECT COUNT(*),COALESCE(SUM(size_bytes),0),
            COALESCE(SUM(CASE WHEN state='uploading' THEN 1 ELSE 0 END),0)
            FROM marketing_media WHERE akaun_id={ph} AND state IN ('uploading','ready')''', (owner,))
        count, size, pending = cur.fetchone()
        if size + length > MAX_ACCOUNT_BYTES or count >= MAX_ACCOUNT_FILES:
            raise MediaError('Storan media akaun mencapai had 512 MiB atau 200 fail.', 409, 'media_capacity')
        if pending >= MAX_PENDING:
            raise MediaError('Dua muat naik sedang diproses. Tunggu sehingga selesai.', 429, 'upload_busy')
        if shutil.disk_usage(cfg['root']).free < length + MAX_OUTPUT_IMAGE_BYTES + MIN_FREE_BYTES:
            raise MediaError('Storan media sementara penuh. Cuba lagi kemudian.', 503, 'storage_full')
        lease = secrets.token_hex(16)
        token = row['public_token'] if row else secrets.token_hex(32)
        extension = 'jpg' if INPUT_TYPES[mime] == 'image' else ('mov' if mime == 'video/quicktime' else 'mp4')
        values = (name, mime, length, length, INPUT_TYPES[mime], extension, lease, stamp, key, owner)
        if row:
            cur.execute(f'''UPDATE marketing_media SET state='uploading',name={ph},input_mime={ph},
                input_size={ph},size_bytes={ph},format={ph},extension={ph},lease={ph},updated_at={ph}
                WHERE id={ph} AND akaun_id={ph}''', values)
        else:
            cur.execute(f'''INSERT INTO marketing_media
                (name,input_mime,input_size,size_bytes,format,extension,lease,updated_at,id,akaun_id,state,public_token,created_at)
                VALUES ({','.join([ph] * 10)},'uploading',{ph},{ph})''', (*values, token, stamp))
        return 200, {'row': _owned(conn, postgres, owner, key), 'replay': False}
    return meja._dalam(conn, postgres, work)[1]


def _read_stream(stream, length, target=None, set_timeout=None):
    digest = hashlib.sha256()
    remaining = length
    started = time.monotonic()
    while remaining:
        deadline_remaining = UPLOAD_SECONDS - (time.monotonic() - started)
        if deadline_remaining <= 0:
            raise MediaError('Muat naik mengambil masa terlalu lama. Cuba semula.', 408, 'upload_timeout')
        if set_timeout:
            set_timeout(min(30, deadline_remaining))
        # BufferedReader.read(n) can wait to fill n while a slow sender drips
        # bytes forever. read1 returns after one receive so the deadline runs.
        read = getattr(stream, 'read1', stream.read)
        block = read(min(65536, remaining))
        if not block or len(block) > remaining:
            raise MediaError('Muat naik terputus. Cuba semula.', 400, 'upload_incomplete')
        remaining -= len(block)
        digest.update(block)
        if target:
            target.write(block)
    return digest.hexdigest()


def _image(source, destination, mime):
    if not _IMAGE_SLOTS.acquire(timeout=60):
        raise MediaError('Pelayan sedang memproses gambar. Cuba sebentar lagi.', 503, 'upload_busy')
    try:
        return _normalize_image(source, destination, mime)
    finally:
        _IMAGE_SLOTS.release()


def _normalize_image(source, destination, mime):
    images = []
    try:
        from PIL import Image, ImageOps
        with Image.open(source) as probe:
            expected = 'JPEG' if mime == 'image/jpeg' else 'PNG'
            if probe.format != expected or getattr(probe, 'n_frames', 1) != 1:
                raise MediaError('Fail gambar tidak sepadan dengan jenis JPEG/PNG atau mempunyai animasi.', 415, 'unsupported_media')
            width, height = probe.size
            if min(width, height) < 1 or max(width, height) > MAX_IMAGE_SIDE or width * height > MAX_IMAGE_PIXELS:
                raise MediaError('Gambar melebihi had 8,192 piksel atau 32 megapiksel.', 413, 'media_dimensions')
            probe.verify()
        with Image.open(source) as original:
            original.load()
            oriented = ImageOps.exif_transpose(original) if original.getexif().get(274, 1) != 1 else original
            if oriented is not original:
                images.append(oriented)
            clean = Image.new('RGB', oriented.size, 'white')
            images.append(clean)
            if 'A' in oriented.getbands() or 'transparency' in oriented.info:
                rgba = oriented if oriented.mode == 'RGBA' else oriented.convert('RGBA')
                if rgba is not oriented:
                    images.append(rgba)
                alpha = rgba.getchannel('A')
                try:
                    clean.paste(rgba, mask=alpha)
                finally:
                    alpha.close()
            else:
                result = oriented if oriented.mode == 'RGB' else oriented.convert('RGB')
                if result is not oriented:
                    images.append(result)
                clean.paste(result)
            width, height = clean.size
            # A new RGB image drops EXIF, GPS, comments and embedded profiles.
            for quality in (90, 85, 80, 75, 65):
                clean.save(destination, format='JPEG', quality=quality, optimize=True)
                if destination.stat().st_size <= MAX_OUTPUT_IMAGE_BYTES:
                    os.chmod(destination, 0o600)
                    return {'extension': 'jpg', 'mime_type': 'image/jpeg', 'format': 'image',
                            'width': width, 'height': height, 'duration_seconds': 0,
                            'size_bytes': destination.stat().st_size}
            raise MediaError('Gambar masih melebihi 8 MB selepas pemprosesan. Kecilkan gambar dan cuba semula.', 413, 'media_too_large')
    except MediaError:
        raise
    except Exception:
        raise MediaError('Gambar rosak atau tidak dapat dibaca. Pilih JPEG/PNG yang sah.', 415, 'invalid_media') from None
    finally:
        for item in reversed(images):
            item.close()


def _number(value):
    try:
        result = float(value)
    except (ValueError, TypeError):
        raise MediaError('Maklumat video tidak dapat disahkan.', 415, 'invalid_media') from None
    if not math.isfinite(result):
        raise MediaError('Maklumat video tidak dapat disahkan.', 415, 'invalid_media')
    return result


def _container(source):
    """Validate bounded ISO-BMFF box lengths and reject external data refs."""
    size = source.stat().st_size
    boxes = []
    count = 0
    edit = False
    def children(data, start=0, end=None):
        nonlocal count, edit
        end = len(data) if end is None else end
        while start < end:
            count += 1
            if count > 10000 or end - start < 8:
                raise ValueError()
            length, kind = int.from_bytes(data[start:start + 4], 'big'), data[start + 4:start + 8]
            header = 8
            if length == 1:
                if end - start < 16:
                    raise ValueError()
                length, header = int.from_bytes(data[start + 8:start + 16], 'big'), 16
            if length < header or start + length > end:
                raise ValueError()
            body, finish = start + header, start + length
            if kind == b'edts':
                edit = True
            if kind in {b'trak', b'mdia', b'minf', b'stbl', b'edts', b'dinf', b'mvex'}:
                children(data, body, finish)
            if kind == b'dref':
                if finish - body < 8:
                    raise ValueError()
                entries, offset = int.from_bytes(data[body + 4:body + 8], 'big'), body + 8
                if entries > 32:
                    raise ValueError()
                for _ in range(entries):
                    if finish - offset < 12:
                        raise ValueError()
                    entry_size = int.from_bytes(data[offset:offset + 4], 'big')
                    if (entry_size != 12 or data[offset + 4:offset + 8] != b'url '
                            or not int.from_bytes(data[offset + 8:offset + 12], 'big') & 1):
                        raise MediaError('Video menggunakan rujukan fail luaran. Eksport video lengkap sebagai MP4/MOV.', 415, 'invalid_media')
                    offset += entry_size
                if offset != finish:
                    raise ValueError()
            start = finish
    with source.open('rb') as reader:
        offset = 0
        while offset < size:
            count += 1
            if count > 10000 or size - offset < 8:
                raise ValueError()
            reader.seek(offset)
            header = reader.read(8)
            length, kind = int.from_bytes(header[:4], 'big'), header[4:]
            head = 8
            if length == 1:
                length, head = int.from_bytes(reader.read(8), 'big'), 16
            elif length == 0:
                length = size - offset
            if length < head or offset + length > size:
                raise ValueError()
            boxes.append((kind, offset))
            if kind == b'moov':
                if length > 16 * 1024 * 1024:
                    raise ValueError()
                children(reader.read(length - head))
            offset += length
    names = [kind for kind, _ in boxes]
    if names.count(b'moov') != 1 or b'mdat' not in names or names.count(b'ftyp') != 1:
        raise ValueError()
    return {'fast_start': int(names.index(b'moov') < names.index(b'mdat')), 'has_edit_list': int(edit)}


def _probe(command):
    # The wrapper applies limits before exec, without preexec_fn in a threaded
    # server. The Linux runtime is part of this account deployment contract.
    guard = ('import os,resource,sys; '
             'resource.setrlimit(resource.RLIMIT_AS,(805306368,805306368)); '
             'resource.setrlimit(resource.RLIMIT_CPU,(30,30)); '
             'resource.setrlimit(resource.RLIMIT_NOFILE,(32,32)); '
             'resource.setrlimit(resource.RLIMIT_CORE,(0,0)); '
             'os.execv(sys.argv[1],sys.argv[1:])')
    command = [sys.executable, '-c', guard, *command]
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    buffers = {'stdout': bytearray(), 'stderr': bytearray()}
    deadline = time.monotonic() + 40
    try:
        with selectors.DefaultSelector() as selector:
            for name, pipe in (('stdout', process.stdout), ('stderr', process.stderr)):
                selector.register(pipe, selectors.EVENT_READ, name)
            while selector.get_map():
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise subprocess.TimeoutExpired(command, 40)
                for key, _ in selector.select(min(remaining, 0.2)):
                    chunk = os.read(key.fileobj.fileno(), 4096)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        continue
                    buffers[key.data].extend(chunk)
                    if len(buffers[key.data]) > (64000 if key.data == 'stdout' else 8192):
                        raise ValueError()
        if process.wait(timeout=max(0.1, deadline - time.monotonic())) or buffers['stderr']:
            raise ValueError()
        return json.loads(buffers['stdout'])
    finally:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=5)
        process.stdout.close()
        process.stderr.close()


def _video(source, mime):
    # Limit the demuxer and disable MOV external data references. No network
    # protocol is available to the probe, even for a crafted container.
    command = [shutil.which('ffprobe') or '/usr/bin/ffprobe', '-v', 'error', '-max_alloc', '67108864',
               '-threads', '1', '-protocol_whitelist', 'file', '-f', 'mov',
               '-enable_drefs', '0', '-use_absolute_path', '0', '-count_packets',
               '-show_entries',
               'format=format_name,duration:stream=codec_type,codec_name,width,height,duration,nb_read_packets,channels,sample_rate,avg_frame_rate',
               '-of', 'json', str(source)]
    try:
        container = _container(source)
        data = _probe(command)
        fmt = data.get('format') or {}
        streams = data.get('streams') or []
        video = [item for item in streams if item.get('codec_type') == 'video']
        audio = [item for item in streams if item.get('codec_type') == 'audio']
        if 'mov' not in str(fmt.get('format_name', '')).split(',') or len(video) != 1 or len(audio) > 1:
            raise ValueError()
        track = video[0]
        if track.get('codec_name') not in {'h264', 'hevc'} or any(item.get('codec_name') != 'aac' for item in audio):
            raise MediaError('Eksport video sebagai MP4/MOV dengan H.264 atau HEVC dan audio AAC.', 415, 'unsupported_codec')
        if any(item.get('codec_type') not in {'video', 'audio', 'data'} for item in streams):
            raise ValueError()
        width, height = int(track['width']), int(track['height'])
        duration = _number(fmt.get('duration'))
        if not MIN_VIDEO_SECONDS <= duration <= MAX_VIDEO_SECONDS:
            raise MediaError('Tempoh video mesti antara 3 hingga 300 saat.', 400, 'media_duration')
        if min(width, height) < 16 or max(width, height) > 4096 or width * height > 8_400_000:
            raise MediaError('Resolusi video terlalu kecil atau melebihi 4K.', 400, 'media_dimensions')
        if int(track.get('nb_read_packets') or 0) < 1:
            raise ValueError()
        fps = str(track.get('avg_frame_rate', '0/1')).split('/')
        if len(fps) != 2 or _number(fps[1]) <= 0:
            raise ValueError()
        frame_rate = _number(fps[0]) / _number(fps[1])
        if not 1 <= frame_rate <= 240:
            raise ValueError()
        for item in audio:
            if int(item.get('channels') or 0) not in {1, 2} or int(item.get('nb_read_packets') or 0) < 1:
                raise MediaError('Gunakan audio AAC mono atau stereo.', 415, 'unsupported_codec')
            if not 1 <= _number(item.get('sample_rate')) <= 48000:
                raise MediaError('Eksport video MP4/MOV dengan audio AAC sehingga 48 kHz.', 415, 'unsupported_codec')
        return {'extension': 'mov' if mime == 'video/quicktime' else 'mp4',
                'mime_type': mime, 'format': 'video', 'width': width, 'height': height,
                'duration_seconds': round(duration, 3), 'size_bytes': source.stat().st_size,
                'frame_rate': round(frame_rate, 5), **container}
    except MediaError:
        raise
    except subprocess.TimeoutExpired:
        raise MediaError('Video terlalu rumit untuk disahkan. Eksport semula sebagai MP4 H.264/AAC.', 415, 'invalid_media') from None
    except Exception:
        raise MediaError('Video rosak atau bekas fail tidak sah. Pilih MP4/MOV yang lengkap.', 415, 'invalid_media') from None


def _fail(conn, postgres, owner, key, lease, stamp):
    ph = meja._ph(postgres)
    def work():
        meja._kunci_akaun(conn, postgres, owner)
        conn.cursor().execute(f'''UPDATE marketing_media SET state='failed',size_bytes=0,lease='',updated_at={ph}
            WHERE id={ph} AND akaun_id={ph} AND state='uploading' AND lease={ph}''', (stamp, key, owner, lease))
        return 200, {}
    meja._dalam(conn, postgres, work)


def _finish(conn, postgres, owner, row, metadata, digest, staged, cfg, stamp):
    ph = meja._ph(postgres)
    installed = False
    final = _object_path({**row, **metadata}, cfg)
    def work():
        nonlocal installed
        meja._kunci_akaun(conn, postgres, owner)
        current = _owned(conn, postgres, owner, row['id'])
        if not current or current['state'] != 'uploading' or current['lease'] != row['lease']:
            raise MediaError('Muat naik telah dibatalkan atau luput.', 410, 'media_cancelled')
        cur = conn.cursor()
        cur.execute(f'''SELECT COALESCE(SUM(size_bytes),0) FROM marketing_media WHERE akaun_id={ph}
            AND state IN ('uploading','ready') AND id<>{ph}''', (owner, row['id']))
        if cur.fetchone()[0] + metadata['size_bytes'] > MAX_ACCOUNT_BYTES:
            raise MediaError('Gambar yang diproses melebihi baki storan akaun 512 MiB.', 409, 'media_capacity')
        # The owner lock serializes cancellation, post attachment and finalize.
        # File becomes public only after this ready-row transaction commits.
        os.replace(staged, final)
        installed = True
        descriptor = os.open(final.parent, os.O_RDONLY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
        fields = {**metadata, 'source_sha256': digest, 'state': 'ready', 'lease': '', 'updated_at': stamp}
        conn.cursor().execute(f'''UPDATE marketing_media SET {','.join(name + '=' + ph for name in fields)}
            WHERE id={ph} AND akaun_id={ph}''', (*fields.values(), row['id'], owner))
        return 201, {'ok': True, 'media': _metadata(_owned(conn, postgres, owner, row['id']), cfg)}
    try:
        return meja._dalam(conn, postgres, work)
    except Exception:
        if installed:
            # If commit outcome is ambiguous, never remove a file referenced by
            # a committed ready row. A fresh read distinguishes normal rollback.
            try:
                conn.rollback()
                current = _owned(conn, postgres, owner, row['id'])
                if not current or current['state'] != 'ready':
                    _unlink(final)
            except Exception:
                pass
        raise


def _upload(conn, postgres, owner, key, query, mime, length, stream, cfg, stamp, set_timeout=None):
    _request_type(mime, length)
    name = _name(query)
    for child in ('objects', 'temporary'):
        (cfg['root'] / child).mkdir(mode=0o700, parents=True, exist_ok=True)
    cleanup_owner(conn, postgres, owner, cfg, stamp)
    reservation = _reserve(conn, postgres, owner, key, name, mime, length, cfg, stamp)
    row = reservation['row']
    if reservation['replay']:
        digest = _read_stream(stream, length, set_timeout=set_timeout)
        if digest != row['source_sha256']:
            raise MediaError('Pengenal muat naik telah digunakan untuk fail lain.', 409, 'media_conflict')
        _status, result = _get(conn, postgres, owner, key, cfg)
        return 200, {**result, 'replayed': True}
    source = _temp_path(key, row['lease'], cfg)
    normalized = _temp_path(key, row['lease'], cfg, 'jpg')
    try:
        descriptor = os.open(source, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'wb') as target:
            digest = _read_stream(stream, length, target, set_timeout)
            target.flush()
            os.fsync(target.fileno())
        if row['format'] == 'image':
            metadata = _image(source, normalized, mime)
            staged = normalized
        else:
            metadata = _video(source, mime)
            staged = source
        with staged.open('rb') as validated:
            os.fsync(validated.fileno())
        return _finish(conn, postgres, owner, row, metadata, digest, staged, cfg, int(time.time()))
    except Exception:
        try:
            _fail(conn, postgres, owner, key, row['lease'], int(time.time()))
        except Exception:
            pass
        raise
    finally:
        _unlink(source)
        _unlink(normalized)


def _get(conn, postgres, owner, key, cfg):
    row = _owned(conn, postgres, owner, key)
    if not row:
        raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
    if row['state'] == 'uploading':
        raise MediaError('Muat naik masih diproses. Semak semula sebentar lagi.', 409, 'upload_in_progress')
    if row['state'] == 'cancelled':
        raise MediaError('Muat naik ini telah dibatalkan. Pilih fail semula.', 410, 'media_cancelled')
    if row['state'] != 'ready':
        raise MediaError('Muat naik terputus atau gagal. Cuba semula fail ini.', 409, 'upload_failed')
    if not _object_path(row, cfg).is_file():
        raise MediaError('Fail media tidak tersedia. Muat naik semula dengan pengenal baharu.', 409, 'media_missing')
    return 200, {'ok': True, 'media': _metadata(row, cfg)}


def _cancel(conn, postgres, owner, key, cfg, stamp):
    ph = meja._ph(postgres)
    removed = []
    def work():
        meja._kunci_akaun(conn, postgres, owner)
        row = _owned(conn, postgres, owner, key)
        cur = conn.cursor()
        if row:
            if _reference_exists(conn, postgres, row):
                raise MediaError('Media masih digunakan oleh siaran tersimpan. Tukar lampiran dan simpan siaran dahulu.', 409, 'media_in_use')
            cur.execute(f'''UPDATE marketing_media SET state='cancelled',size_bytes=0,lease='',updated_at={ph}
                WHERE id={ph} AND akaun_id={ph}''', (stamp, key, owner))
            removed.append(row)
        else:
            cur.execute(f'SELECT id FROM marketing_media WHERE id={ph}', (key,))
            if cur.fetchone():
                raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
            cur.execute(f'SELECT COUNT(*) FROM marketing_media WHERE akaun_id={ph}', (owner,))
            if cur.fetchone()[0] >= MAX_ACCOUNT_ROWS:
                raise MediaError('Had percubaan muat naik dicapai. Cuba lagi kemudian.', 429, 'media_capacity')
            # A tombstone also wins if cancellation arrives before the PUT.
            cur.execute(f'''INSERT INTO marketing_media (id,akaun_id,state,public_token,created_at,updated_at)
                VALUES ({ph},{ph},'cancelled',{ph},{ph},{ph})''',
                        (key, owner, secrets.token_hex(32), stamp, stamp))
        return 200, {'ok': True, 'deleted': True, 'id': key}
    result = meja._dalam(conn, postgres, work)
    _remove_files(removed, cfg)
    return result


def dispatch_media(method, path, query, origin, authorization, content_type, length,
                   stream, connect, postgres, secret, now=None, set_timeout=None):
    from pelanggan_akaun import _bearer, cors_origin, read_token
    stamp = int(time.time() if now is None else now)
    conn = None
    slot = False
    try:
        if origin and not cors_origin(origin):
            raise MediaError('Permintaan ini tidak diterima.', 403, 'forbidden_origin')
        if method == 'OPTIONS':
            return 204, None
        if not secret:
            raise MediaError('Akaun belum sedia pada pelayan.', 503, 'media_unavailable')
        owner = read_token(_bearer(authorization), secret, stamp)
        if not owner:
            raise MediaError('Sila masuk akaun dahulu.', 401, 'unauthorized')
        key = path[len(PREFIX) + 1:] if path.startswith(PREFIX + '/') else ''
        if not ID.fullmatch(key):
            raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
        if method not in {'PUT', 'GET', 'DELETE'}:
            raise MediaError('Kaedah media tidak disokong.', 405, 'method_not_allowed')
        cfg = _config(method == 'PUT')
        conn = connect()
        cur = conn.cursor()
        cur.execute(f'SELECT id FROM pelanggan_akaun WHERE id={meja._ph(postgres)}', (owner,))
        if not cur.fetchone():
            raise MediaError('Sila masuk akaun dahulu.', 401, 'unauthorized')
        ensure_media_schema(conn, postgres)
        conn.commit()
        if method == 'PUT':
            slot = _UPLOAD_SLOTS.acquire(blocking=False)
            if not slot:
                raise MediaError('Pelayan sedang memproses media. Cuba sebentar lagi.', 503, 'upload_busy')
            return _upload(conn, postgres, owner, key, query, content_type, length, stream, cfg, stamp, set_timeout)
        cleanup_owner(conn, postgres, owner, cfg, stamp)
        if method == 'GET':
            return _get(conn, postgres, owner, key, cfg)
        return _cancel(conn, postgres, owner, key, cfg, stamp)
    except MediaError as error:
        if conn:
            conn.rollback()
        return error.status, error_body(error)
    except (TimeoutError, OSError):
        if conn:
            conn.rollback()
        return 408, {'ok': False, 'code': 'upload_interrupted', 'error': 'Muat naik terputus atau storan tidak dapat dicapai. Cuba semula.'}
    except Exception:
        if conn:
            conn.rollback()
        return 503, {'ok': False, 'code': 'media_unavailable', 'error': 'Media tidak dapat diproses. Cuba semula.'}
    finally:
        if slot:
            _UPLOAD_SLOTS.release()
        if conn:
            conn.close()


def resolve_attachment(conn, postgres, owner, data):
    """Called under the post owner's transaction/lock before accepting a save."""
    key = data.get('media_id', '')
    cfg = None
    if not key:
        # A pasted managed URL must still belong to this account. External URLs
        # retain the existing flow and are never downloaded by this service.
        try:
            cfg = _config()
        except MediaError:
            return data
        parsed = urlsplit(data.get('media_url', ''))
        base = urlsplit(cfg['origin'])
        normalized_path = '/' + posixpath.normpath(unquote(parsed.path)).lstrip('/')
        same_origin = (parsed.scheme == base.scheme
                       and (parsed.hostname or '').rstrip('.').lower() == base.hostname
                       and (parsed.port or 443) == (base.port or 443))
        if same_origin and normalized_path.startswith(PUBLIC_PREFIX):
            part = normalized_path[len(PUBLIC_PREFIX):]
            match = PUBLIC_FILE.fullmatch(part)
            if not match:
                raise MediaError('URL media tersimpan tidak sah.')
            cur = conn.cursor()
            ph = meja._ph(postgres)
            cur.execute(f'SELECT id FROM marketing_media WHERE public_token={ph} AND akaun_id={ph}', (match[1], owner))
            value = cur.fetchone()
            if not value:
                raise MediaError('Media tidak ditemui dalam akaun ini.', 404, 'media_not_found')
            key = value[0]
        else:
            return data
    if not isinstance(key, str) or not ID.fullmatch(key):
        raise MediaError('Pengenal media tidak sah.')
    cfg = cfg or _config()
    row = _owned(conn, postgres, owner, key)
    if not row or row['state'] != 'ready':
        raise MediaError('Media belum siap atau tidak ditemui dalam akaun ini.', 404, 'media_not_found')
    if data['format'] != row['format']:
        raise MediaError('Jenis siaran mesti sepadan dengan gambar atau video yang dimuat naik.', 400, 'media_format_mismatch')
    if not _object_path(row, cfg).is_file():
        raise MediaError('Fail media tidak tersedia. Muat naik semula.', 409, 'media_missing')
    return {**data, 'media_id': key, 'media_url': _url(row, cfg)}


def bind_attachment(conn, postgres, owner, post_id, media_id, stamp):
    ph = meja._ph(postgres)
    cur = conn.cursor()
    cur.execute(f'DELETE FROM marketing_media_links WHERE post_id={ph} AND akaun_id={ph}', (post_id, owner))
    if media_id:
        cur.execute(f'INSERT INTO marketing_media_links (post_id,akaun_id,media_id) VALUES ({ph},{ph},{ph})',
                    (post_id, owner, media_id))
        cur.execute(f'UPDATE marketing_media SET updated_at={ph} WHERE id={ph} AND akaun_id={ph}',
                    (int(stamp), media_id, owner))


def validate_platform_attachment(conn, postgres, owner, post):
    row = _owned(conn, postgres, owner, post['media_id'])
    if not row or row['state'] != 'ready':
        raise MediaError('Media tidak ditemui dalam akaun ini.', 404, 'media_not_found')
    platform, ratio = post['platform'], row['width'] / row['height']
    if row['format'] == 'image':
        if platform == 'instagram' and not 0.8 <= ratio <= 1.91:
            raise MediaError('Gambar Instagram perlu nisbah 4:5 hingga 1.91:1. Laraskan gambar dan muat naik semula.', 400, 'media_platform')
        if platform == 'threads' and not 0.1 <= ratio <= 10:
            raise MediaError('Nisbah gambar Threads perlu antara 1:10 hingga 10:1.', 400, 'media_platform')
    elif platform in {'instagram', 'threads'}:
        if row['width'] > 1920 or not 0.01 <= ratio <= 10 or not 23 <= row['frame_rate'] <= 60:
            raise MediaError('Video Instagram/Threads perlu lebar sehingga 1,920 piksel, 23–60 FPS dan nisbah 1:100 hingga 10:1.', 400, 'media_platform')
        if not row['fast_start'] or row['has_edit_list']:
            raise MediaError('Eksport semula video untuk web: MP4/MOV dengan fast start dan tanpa edit list, kemudian muat naik semula.', 400, 'media_platform')


def public_file(path, connect, postgres):
    match = PUBLIC_FILE.fullmatch(path[len(PUBLIC_PREFIX):]) if path.startswith(PUBLIC_PREFIX) else None
    if not match:
        raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
    cfg = _config()
    conn = connect()
    try:
        ph = meja._ph(postgres)
        cur = conn.cursor()
        cur.execute(f'''SELECT * FROM marketing_media WHERE public_token={ph} AND extension={ph} AND state='ready' ''', match.groups())
        row = _row(cur)
        if not row:
            raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
        descriptor = os.open(_object_path(row, cfg), os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0))
        reader = os.fdopen(descriptor, 'rb')
        info = os.fstat(reader.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size != row['size_bytes']:
            reader.close()
            raise MediaError('Media tidak ditemui.', 404, 'media_not_found')
        return reader, row
    except OSError:
        raise MediaError('Media tidak ditemui.', 404, 'media_not_found') from None
    finally:
        conn.close()


def byte_range(value, size):
    if not value:
        return 0, size - 1, False
    match = re.fullmatch(r'bytes=(\d*)-(\d*)', value)
    if not match or not any(match.groups()):
        raise MediaError('Julat media tidak sah.', 416, 'invalid_range')
    first, last = match.groups()
    if first:
        start, end = int(first), min(int(last), size - 1) if last else size - 1
    else:
        suffix = int(last)
        if suffix < 1:
            raise MediaError('Julat media tidak sah.', 416, 'invalid_range')
        start, end = max(0, size - suffix), size - 1
    if start >= size or start > end:
        raise MediaError('Julat media tidak sah.', 416, 'invalid_range')
    return start, end, True
