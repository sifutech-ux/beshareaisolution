import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { webcrypto } from 'node:crypto'
import vm from 'node:vm'

const source = readFileSync(new URL('../marketing.js', import.meta.url), 'utf8')
const core = readFileSync(new URL('../core.js', import.meta.url), 'utf8')
const html = readFileSync(new URL('../index.html', import.meta.url), 'utf8')
const sessionA = JSON.stringify({ token: 'synthetic-account-a' }), sessionB = JSON.stringify({ token: 'synthetic-account-b' })
const campaign = { id: 'a'.repeat(32), title: 'Kempen ujian', status: 'draft', goal: 'awareness', budget_total: '0.00', channels: ['facebook'] }
const oldPost = { id: 'b'.repeat(32), version: 1, campaign_id: campaign.id, platform: 'facebook', format: 'image', media_id: 'c'.repeat(32), media_url: 'https://media.example.invalid/old.jpg', body: 'Teks disimpan.', status: 'draft', scheduled_at: '' }
const capability = { enabled: true, accepted_mime_types: ['image/jpeg', 'image/png', 'video/mp4', 'video/quicktime'], max_image_bytes: 10 * 1024 * 1024, max_video_bytes: 100 * 1024 * 1024 }
const response = (data, status = 200) => ({ status, ok: status >= 200 && status < 300, json: async () => data })
const tick = () => new Promise(resolve => setImmediate(resolve))
const file = (name = 'gambar.png', type = 'image/png', size = 1024) => ({ name, type, size })
const mediaReply = (xhr, overrides = {}) => ({ ok: true, media: { id: new URL(xhr.url).pathname.split('/').pop(), url: 'https://media.example.invalid/' + new URL(xhr.url).pathname.split('/').pop() + (xhr.file.type.startsWith('video/') ? '.mp4' : '.jpg'), name: xhr.file.name, format: xhr.file.type.startsWith('video/') ? 'video' : 'image', mime_type: xhr.file.type.startsWith('video/') ? 'video/mp4' : 'image/jpeg', size_bytes: 900, ...overrides } })

class Element {
  constructor(tag = 'div') {
    this.tag = tag; this.id = ''; this.children = []; this.listeners = {}; this.dataset = {}; this.style = {}
    this.disabled = false; this.hidden = false; this.open = false; this.value = ''; this.text = ''; this.files = []
    const classes = new Set(); this.classList = { add: name => classes.add(name), remove: name => classes.delete(name), toggle(name, active) { if (active) classes.add(name); else classes.delete(name) } }
  }
  set textContent(value) { this.text = String(value); this.children = [] }
  get textContent() { return this.text + this.children.map(child => child.textContent).join('') }
  get options() { return this.children.filter(child => child.tag === 'option') }
  get lastChild() { if (!this.children.length) this.children.push(new Element()); return this.children.at(-1) }
  append(...nodes) { this.children.push(...nodes) }
  replaceChildren(...nodes) { this.text = ''; this.children = nodes; if (this.tag === 'select') this.value = nodes[0]?.value || '' }
  add(option) { this.children.push(option); if (this.options.length === 1) this.value = option.value }
  setAttribute(name, value) { this[name] = value }
  removeAttribute(name) { delete this[name] }
  addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener) }
  async emit(name, event = {}) { for (const listener of this.listeners[name] || []) await listener({ preventDefault() {}, target: this, ...event }) }
  querySelectorAll(selector) { const tags = selector.split(','); return this.children.flatMap(child => [...(tags.includes(child.tag) ? [child] : []), ...child.querySelectorAll(selector)]) }
  reportValidity() { return true }
  showModal() { this.open = true }
  close() { this.open = false; this.emit('close') }
  reset() { this.querySelectorAll('input,select,textarea').forEach(node => { node.value = '' }) }
  focus() {}
  click() { return this.emit('click') }
}

async function workspace({ posts = [], mediaGet, uploadEnabled = true } = {}) {
  const nodes = new Map(), get = id => { if (!nodes.has(id)) { const node = new Element(); node.id = id; nodes.set(id, node) } return nodes.get(id) }
  for (const [, tag, id, attributes] of html.matchAll(/<([a-z][\w-]*)\b[^>]*\bid="([^"]+)"([^>]*)>/g)) { const node = new Element(tag); node.id = id; node.hidden = /\bhidden\b/.test(attributes); nodes.set(id, node) }
  for (const prefix of ['ai', 'post', 'campaign', 'source', 'result']) get(prefix + '-form').children = [...nodes.entries()].filter(([id, node]) => id.startsWith(prefix + '-') && ['input', 'select', 'textarea'].includes(node.tag)).map(([, node]) => node)
  get('campaign-status').value = 'all'
  const requests = [], uploads = [], revoked = [], listeners = {}, timers = new Map(), saved = []
  let stored = sessionA, timerId = 0, blobId = 0
  class ObjectURL extends URL { static createObjectURL() { return 'blob:https://example.invalid/' + ++blobId } static revokeObjectURL(url) { revoked.push(url) } }
  class XHR {
    constructor() { this.upload = {}; this.headers = {}; uploads.push(this) }
    open(method, url) { this.method = method; this.url = url }
    setRequestHeader(name, value) { this.headers[name] = value }
    send(value) { this.file = value }
    abort() { this.aborted = true; this.onabort?.() }
    respond(data, status = 201) { this.status = status; this.responseText = JSON.stringify(data); this.onload() }
    progress(loaded, total = this.file.size) { this.upload.onprogress({ lengthComputable: true, loaded, total }) }
    fail() { this.onerror() }
  }
  const window = { BeshareAsal: { asalPerkhidmatan: () => ({ akaun: 'https://account.example.invalid', nasihat: 'https://advice.example.invalid' }) }, addEventListener(name, listener) { (listeners[name] ||= []).push(listener) } }
  const context = vm.createContext({
    window, crypto: webcrypto, structuredClone, TextEncoder, AbortController, URL: ObjectURL, Intl, XMLHttpRequest: XHR,
    Option: class extends Element { constructor(label, value) { super('option'); this.textContent = label; this.value = value } },
    location: { origin: 'https://example.invalid', hash: '#kandungan' },
    localStorage: { getItem: () => stored, removeItem() { stored = null } },
    document: { getElementById: get, createElement: tag => new Element(tag), createElementNS: (_ns, tag) => new Element(tag), createTextNode: text => { const node = new Element('#text'); node.textContent = text; return node }, querySelectorAll(selector) {
      if (selector === 'dialog[open]') return [...nodes.values()].filter(node => node.tag === 'dialog' && node.open)
      if (selector === 'dialog form') return [...nodes.values()].filter(node => node.tag === 'form')
      if (selector === 'dialog:not(#confirm-dialog)') return [...nodes.entries()].filter(([id, node]) => node.tag === 'dialog' && id !== 'confirm-dialog').map(([, node]) => node)
      return []
    } },
    setTimeout(fn, ms) { const id = ++timerId; timers.set(id, { fn, ms }); return id }, clearTimeout(id) { timers.delete(id) },
    fetch: async (url, options) => {
      const path = new URL(url).pathname; requests.push({ url, path, options })
      if (options.method === 'GET' && path === '/api/akaun/identiti') return response({ akaun_id: stored === sessionB ? 'owner-b' : 'owner-a', nama: 'Ujian media' })
      if (options.method === 'GET' && path.endsWith('/workspace')) return response({ ok: true, campaigns: [campaign], posts: stored === sessionB ? [] : posts, sources: [], results: [], connections: [{ platform: 'facebook', status: 'connected', publishable: true, configured: true }], capabilities: { worker_enabled: true, auto_publish: true, media_upload: { ...capability, enabled: uploadEnabled } } })
      if (path.includes('/media/')) {
        if (options.method === 'DELETE') return response({ ok: true })
        if (options.method === 'GET') return mediaGet ? mediaGet(path.split('/').pop()) : response({ ok: false, code: 'media_not_found', error: 'Media tiada.' }, 404)
      }
      if (options.method === 'PUT' && path.includes('/posts/')) { const payload = JSON.parse(options.body), post = { ...payload, id: path.split('/').pop(), version: payload.version + 1 }; saved.push(post); return response({ ok: true, post }) }
      throw Error('Unexpected request: ' + options.method + ' ' + url)
    },
  })
  vm.runInContext(core, context); window.MarketingCore = context.MarketingCore; vm.runInContext(source, context); await tick()
  assert.equal(get('workspace-state').dataset.state, 'ready')
  const app = {
    get, uploads, requests, revoked, saved,
    open: () => posts.length && stored === sessionA ? get('post-list').querySelectorAll('button')[0].emit('click') : get('new-post').emit('click'),
    choose: (...files) => get('post-file').emit('change', { target: { files } }),
    drop: (...files) => get('post-media-drop').emit('drop', { dataTransfer: { files } }),
    async input(id, value) { get(id).value = value; await get('post-form').emit('input', { target: get(id) }) },
    async save() { await get('post-form').emit('submit'); await tick() },
    setSession(value, emit = true) { stored = value; if (emit) for (const listener of listeners.storage || []) listener({ key: 'beshare-akaun' }) },
    flushPreview() { for (const [id, timer] of timers) if (timer.ms === 450) { timers.delete(id); timer.fn() } },
  }
  await app.open(); return app
}

test('file upload previews immediately, reports progress, blocks save/schedule and attaches only after success', async () => {
  const app = await workspace(), uploading = app.choose(file())
  assert.equal(app.uploads.length, 1)
  const xhr = app.uploads[0], local = app.get('preview-media').children[0]
  assert.match(local.src, /^blob:/); assert.equal(local.tag, 'img')
  assert.equal(app.get('post-format').value, 'image'); assert.equal(app.get('post-save').disabled, true); assert.equal(app.get('schedule-post').disabled, true)
  assert.equal(app.get('post-body').disabled, false)
  await app.input('post-body', 'Teks semasa muat naik.'); await app.save(); await app.get('schedule-post').emit('click')
  assert.equal(app.saved.length, 0); assert.equal(app.requests.some(x => x.path.endsWith('/schedule')), false)
  assert.equal(app.get('preview-media').children[0], local, 'Typing must not reload the media preview')
  xhr.progress(512); assert.equal(app.get('post-media-progress').value, 50)
  xhr.progress(1024); assert.match(app.get('post-media-feedback').textContent, /Menyediakan media/)
  const ready = mediaReply(xhr); xhr.respond(ready); await uploading
  assert.equal(app.get('post-media').value, ready.media.url); assert.equal(app.get('post-save').disabled, false)
  assert.equal(app.get('preview-media').children[0].src, ready.media.url); assert.ok(app.revoked.includes(local.src))
  assert.equal(xhr.method, 'PUT'); assert.equal(xhr.headers.Authorization, 'Bearer synthetic-account-a'); assert.equal(xhr.headers['Content-Type'], 'image/png')
  await app.save()
  assert.equal(app.saved[0].media_id, ready.media.id); assert.equal(app.saved[0].media_url, ready.media.url); assert.equal(app.saved[0].body, 'Teks semasa muat naik.')
  assert.equal(app.requests.some(x => x.path.endsWith('/schedule')), false)
})

test('video drag/drop enables controls and preview remains stable during caption edits', async () => {
  const app = await workspace(), uploading = app.drop(file('tawaran.mov', 'video/quicktime'))
  const preview = app.get('preview-media').children[0]
  assert.equal(preview.tag, 'video'); assert.equal(preview.controls, true); assert.equal(preview.playsInline, true); assert.equal(preview.preload, 'metadata')
  await app.input('post-body', 'Kapsyen video.'); assert.equal(app.get('preview-media').children[0], preview)
  app.uploads[0].respond(mediaReply(app.uploads[0])); await uploading
  assert.equal(app.get('post-format').value, 'video'); await app.save(); assert.equal(app.saved[0].format, 'video')
})

test('failed replacement preserves old attachment and current text, and reconciliation avoids a duplicate upload', async () => {
  let recovered
  const app = await workspace({ posts: [oldPost], mediaGet: () => response(recovered) })
  const replacing = app.choose(file('video.mp4', 'video/mp4')), xhr = app.uploads[0]
  await app.input('post-body', 'Teks yang baru disunting.'); xhr.fail(); await replacing
  assert.equal(app.get('post-media').value, oldPost.media_url); assert.equal(app.get('post-format').value, 'image')
  assert.equal(app.get('preview-media').children[0].src, oldPost.media_url); assert.match(app.get('post-media-feedback').textContent, /Lampiran sebelumnya masih digunakan/)
  await app.save(); assert.equal(app.saved[0].media_id, oldPost.media_id); assert.equal(app.saved[0].body, 'Teks yang baru disunting.')
  recovered = mediaReply(xhr); await app.get('post-media-retry').emit('click')
  assert.equal(app.uploads.length, 1); assert.equal(app.get('post-media').value, recovered.media.url); assert.equal(app.get('post-format').value, 'video')
  await app.save(); assert.equal(app.saved[1].media_id, recovered.media.id)
})

test('an interrupted upload retries the same ID only after absence or definite failure is confirmed', async t => {
  for (const [status, code] of [[404, 'media_not_found'], [409, 'upload_failed']]) await t.test(code, async () => {
    const app = await workspace({ mediaGet: () => response({ ok: false, code, error: 'Belum tersedia.' }, status) })
    const first = app.choose(file()); app.uploads[0].fail(); await first
    const retrying = app.get('post-media-retry').emit('click'); await tick()
    assert.equal(app.uploads.length, 2); assert.equal(app.uploads[1].url, app.uploads[0].url)
    app.uploads[1].respond(mediaReply(app.uploads[1])); await retrying
    assert.equal(app.get('post-media-retry').hidden, true)
  })
})

test('a still processing upload is reconciled without sending its bytes again', async () => {
  let lookups = 0, recovered
  const app = await workspace({ mediaGet: () => ++lookups === 1 ? response({ ok: false, code: 'upload_in_progress', error: 'Masih diproses.' }, 409) : response(recovered) })
  const first = app.choose(file()); const xhr = app.uploads[0]; recovered = mediaReply(xhr); xhr.fail(); await first
  await app.get('post-media-retry').emit('click'); assert.equal(app.uploads.length, 1); assert.match(app.get('post-media-feedback').textContent, /masih diproses/)
  await app.get('post-media-retry').emit('click'); assert.equal(app.uploads.length, 1); assert.equal(app.get('post-media').value, recovered.media.url)
})

test('cancel aborts and retires the upload; retry uses a new ID and ignores a late success', async () => {
  const app = await workspace({ posts: [oldPost] }), first = app.choose(file()), xhr = app.uploads[0], oldURL = app.get('preview-media').children[0].src
  await app.get('post-media-cancel').emit('click'); await first
  assert.equal(xhr.aborted, true); assert.ok(app.revoked.includes(oldURL)); assert.equal(app.get('post-media').value, oldPost.media_url)
  assert.equal(app.requests.filter(x => x.options.method === 'DELETE').length, 1)
  xhr.respond(mediaReply(xhr)); await tick(); assert.equal(app.get('post-media').value, oldPost.media_url)
  const retrying = app.get('post-media-retry').emit('click'), second = app.uploads[1]
  assert.notEqual(second.url, xhr.url); second.respond(mediaReply(second)); await retrying
  assert.equal(app.get('post-media').value, mediaReply(second).media.url)
})

test('a new editor aborts stale upload work and late results cannot alter the new draft', async () => {
  const app = await workspace(), first = app.choose(file()), xhr = app.uploads[0]
  await app.get('new-post').emit('click'); await first; await app.input('post-body', 'Kandungan baharu.')
  assert.equal(xhr.aborted, true); xhr.respond(mediaReply(xhr)); await tick()
  assert.equal(app.get('post-media').value, ''); assert.equal(app.get('post-body').value, 'Kandungan baharu.'); assert.equal(app.get('post-format').value, 'text')
  assert.equal(app.get('post-save').disabled, false)
})

test('account change aborts upload and never cleans up the old asset using the new account token', async () => {
  const app = await workspace(), first = app.choose(file()), xhr = app.uploads[0]
  app.setSession(sessionB); await first; await tick(); xhr.respond(mediaReply(xhr)); await tick()
  assert.equal(xhr.aborted, true); assert.equal(app.get('post-media').value, ''); assert.equal(app.get('post-body').value, '')
  assert.equal(app.requests.some(x => x.options.method === 'DELETE'), false)
  await app.open(); const second = app.choose(file()), current = app.uploads[1]
  assert.equal(current.headers.Authorization, 'Bearer synthetic-account-b'); current.respond(mediaReply(current)); await second
  assert.equal(app.get('post-media').value, mediaReply(current).media.url)
})

test('a silent account switch while an upload returns clears its result before display', async () => {
  const app = await workspace(), first = app.choose(file()), xhr = app.uploads[0]
  app.setSession(sessionB, false); xhr.respond(mediaReply(xhr)); await first; await tick()
  assert.equal(app.get('post-media').value, ''); assert.equal(app.get('post-dialog').open, false); assert.equal(app.get('workspace-state').dataset.state, 'ready')
})

test('external HTTPS media still saves without a managed media ID and previews after input settles', async () => {
  const app = await workspace({ uploadEnabled: false })
  assert.equal(app.get('post-file-choose').disabled, true); assert.match(app.get('post-media-feedback').textContent, /Muat naik belum tersedia/)
  await app.input('post-format', 'image'); await app.input('post-media', 'https://images.example.invalid/tawaran.jpg'); app.flushPreview()
  assert.equal(app.get('preview-media').children[0].src, 'https://images.example.invalid/tawaran.jpg')
  await app.save(); assert.equal(app.saved[0].media_id, ''); assert.equal(app.saved[0].media_url, 'https://images.example.invalid/tawaran.jpg'); assert.equal(app.uploads.length, 0)
})

test('invalid, oversized and multiple files cannot change an existing attachment', async () => {
  const app = await workspace({ posts: [oldPost] })
  for (const files of [[file('logo.svg', 'image/svg+xml')], [file('video.mp4', 'video/mp4', 101 * 1024 * 1024)], [file(), file()]]) {
    await app.choose(...files); assert.equal(app.uploads.length, 0); assert.equal(app.get('post-media').value, oldPost.media_url); assert.equal(app.get('post-media-feedback').dataset.kind, 'error')
  }
})

test('locked publication disables uploads, removal and direct drop actions', async () => {
  const app = await workspace({ posts: [{ ...oldPost, status: 'queued' }] })
  assert.equal(app.get('post-file-choose').disabled, true); assert.equal(app.get('post-media-remove').disabled, true)
  await app.drop(file()); await app.choose(file()); await app.get('post-media-remove').emit('click')
  assert.equal(app.uploads.length, 0); assert.equal(app.get('post-media').value, oldPost.media_url)
})

test('closing after discarding cleans an unsaved upload but retains a saved attachment', async t => {
  for (const saveFirst of [false, true]) await t.test(saveFirst ? 'saved' : 'unsaved', async () => {
    const app = await workspace(), uploading = app.choose(file()), xhr = app.uploads[0]
    xhr.respond(mediaReply(xhr)); await uploading; if (saveFirst) await app.save()
    const closing = app.get('post-dialog').emit('cancel'); if (!saveFirst) await app.get('confirm-accept').emit('click'); await closing
    assert.equal(app.get('post-dialog').open, false)
    assert.equal(app.requests.filter(x => x.options.method === 'DELETE').length, saveFirst ? 0 : 1)
  })
})
