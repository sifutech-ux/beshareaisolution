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
const account = { id: 'selected-facebook', name: 'Page Perniagaan Ujian', account_id: 'page-test' }
const connected = { provider: 'facebook', platform: 'facebook', configured: true, connectable: true, status: 'connected', connected: true, publishable: true, message: 'Akaun disambungkan.', accounts: [account] }
const post = { id: 'b'.repeat(32), version: 1, campaign_id: campaign.id, platform: 'facebook', format: 'text', body: 'Draf ujian.', media_url: '', media_id: '', scheduled_at: '', status: 'draft' }
const response = (data, status = 200) => ({ status, ok: status >= 200 && status < 300, json: async () => data })
const checked = (status = 'valid', connection = connected, extra = {}) => ({ ok: true, platform: 'facebook', connection, health_check: { status, checked_at: '2026-11-19T12:00:00Z', code: status, message: status === 'invalid' ? 'Akses ditarik balik. Sambungkan semula akaun.' : status === 'unknown' ? 'Akses belum dapat disahkan. Cuba semula sebentar lagi.' : 'Akses telah disahkan.', ...extra } })
const tick = () => new Promise(resolve => setImmediate(resolve))
function deferred() { let resolve; const promise = new Promise(done => { resolve = done }); return { promise, resolve } }

class Element {
  constructor(tag = 'div') { this.tag = tag; this.id = ''; this.children = []; this.listeners = {}; this.dataset = {}; this.style = {}; this.hidden = false; this.disabled = false; this.open = false; this.value = ''; this.text = ''; this.classList = { add() {}, remove() {}, toggle() {} } }
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
}

async function workspace({ health = () => response(checked()), connections = [connected], posts = [], enabled = true, threadsAccess = 'approved' } = {}) {
  const nodes = new Map(), get = id => { if (!nodes.has(id)) { const node = new Element(); node.id = id; nodes.set(id, node) } return nodes.get(id) }
  for (const [, tag, id, attributes] of html.matchAll(/<([a-z][\w-]*)\b[^>]*\bid="([^"]+)"([^>]*)>/g)) { const node = new Element(tag); node.id = id; node.hidden = /\bhidden\b/.test(attributes); nodes.set(id, node) }
  for (const prefix of ['ai', 'post', 'campaign', 'source', 'result']) get(prefix + '-form').children = [...nodes.entries()].filter(([id, node]) => id.startsWith(prefix + '-') && ['input', 'select', 'textarea'].includes(node.tag)).map(([, node]) => node)
  get('campaign-status').value = 'all'
  const requests = [], checks = [], listeners = {}, timers = new Map(), saved = []
  let stored = sessionA, timerId = 0, now = Date.parse('2026-11-19T12:00:00Z')
  class Clock extends Date { constructor(...args) { super(...(args.length ? args : [now])) } static now() { return now } }
  const location = { origin: 'https://example.invalid', hash: '#kandungan' }
  const window = { BeshareAsal: { asalPerkhidmatan: () => ({ akaun: 'https://account.example.invalid', nasihat: 'https://advice.example.invalid' }) }, addEventListener(name, listener) { (listeners[name] ||= []).push(listener) } }
  const context = vm.createContext({
    window, crypto: webcrypto, structuredClone, TextEncoder, AbortController, URL, Intl, Date: Clock,
    Option: class extends Element { constructor(label, value) { super('option'); this.textContent = label; this.value = value } },
    location, localStorage: { getItem: () => stored, removeItem() { stored = null } },
    document: { getElementById: get, createElement: tag => new Element(tag), createElementNS: (_ns, tag) => new Element(tag), createTextNode: text => { const node = new Element('#text'); node.textContent = text; return node }, querySelectorAll(selector) {
      if (selector === 'dialog[open]') return [...nodes.values()].filter(node => node.tag === 'dialog' && node.open)
      if (selector === 'dialog form') return [...nodes.values()].filter(node => node.tag === 'form')
      if (selector === 'dialog:not(#confirm-dialog)') return [...nodes.entries()].filter(([id, node]) => node.tag === 'dialog' && id !== 'confirm-dialog').map(([, node]) => node)
      return []
    } },
    setTimeout(fn, ms) { const id = ++timerId; timers.set(id, { fn, ms }); return id }, clearTimeout(id) { timers.delete(id) },
    fetch: async (url, options) => {
      const path = new URL(url).pathname; requests.push({ url, path, options })
      if (path.endsWith('/check')) { const call = { url, path, options }; checks.push(call); return health(call, checks.length) }
      if (options.method === 'GET' && path === '/api/akaun/saya') return response({ akaun_id: stored === sessionB ? 'owner-b' : 'owner-a', nama: 'Ujian kesihatan' })
      if (options.method === 'GET' && path.endsWith('/workspace')) return response({ ok: true, campaigns: [campaign], posts: stored === sessionB ? [] : structuredClone(posts), sources: [], results: [], connections: stored === sessionB ? [] : structuredClone(connections), capabilities: { connection_health_checks: enabled, worker_enabled: true, auto_publish: true, threads_customer_access: threadsAccess } })
      if (options.method === 'PUT' && path.includes('/posts/')) { const payload = JSON.parse(options.body), record = { ...payload, id: path.split('/').pop(), version: payload.version + 1 }; saved.push(record); return response({ ok: true, post: record }) }
      throw Error('Unexpected request: ' + options.method + ' ' + url)
    },
  })
  vm.runInContext(core, context); window.MarketingCore = context.MarketingCore; vm.runInContext(source, context); await tick()
  assert.equal(get('workspace-state').dataset.state, 'ready')
  const app = {
    get, requests, checks, saved,
    card: platform => get('connection-list').children.find(node => node.dataset.platform === platform),
    async openPost() { if (posts.length && stored === sessionA) await get('post-list').querySelectorAll('button')[0].emit('click'); else await get('new-post').emit('click') },
    async input(id, value) { get(id).value = value; await get('post-form').emit('input', { target: get(id) }) },
    async save() { await get('post-form').emit('submit'); await tick() },
    async navigate(view) { location.hash = '#' + view; for (const listener of listeners.hashchange || []) listener({}); await tick() },
    setSession(value, emit = true) { stored = value; if (emit) for (const listener of listeners.storage || []) listener({ key: 'beshare-akaun' }) },
    advance(ms) { now += ms },
    session: () => stored,
  }
  app.check = platform => app.card(platform).querySelectorAll('button').find(node => /Semak sambungan|Sedang menyemak/.test(node.textContent)).emit('click')
  return app
}

test('boot checks connected access once, coalesces overlapping actions and honors the automatic TTL', async () => {
  const pending = deferred(), app = await workspace({ health: (_call, n) => n === 1 ? pending.promise : response(checked()) })
  assert.equal(app.checks.length, 1); assert.match(app.card('facebook').textContent, /Menyemak akses/)
  await app.openPost(); await app.navigate('sambungan'); const duplicate = app.check('facebook')
  assert.equal(app.checks.length, 1); assert.equal(app.get('schedule-post').disabled, true)
  pending.resolve(response(checked())); await duplicate; await tick()
  assert.match(app.card('facebook').textContent, /Disambungkan/); assert.equal(app.get('schedule-post').disabled, false)
  await app.navigate('kandungan'); await app.navigate('sambungan'); assert.equal(app.checks.length, 1)
  app.advance(60001); await app.navigate('kandungan'); await app.navigate('sambungan'); assert.equal(app.checks.length, 2)
  await app.check('facebook'); assert.equal(app.checks.length, 3, 'Explicit checks may bypass the automatic TTL')
  assert.deepEqual(app.requests.filter(call => call.options.method !== 'GET').map(call => call.path), app.checks.map(call => call.path))
})

test('confirmed revoked access requires reconnect, keeps the saved Page name and never clears the account session', async () => {
  const invalid = { ...connected, status: 'reconnect_required', connected: false, publishable: false }
  const app = await workspace({ health: () => response(checked('invalid', invalid)) })
  await app.openPost()
  assert.match(app.card('facebook').textContent, /Sambung semula/); assert.match(app.card('facebook').textContent, /Page Perniagaan Ujian/)
  assert.match(app.card('facebook').textContent, /Akaun tersimpan/); assert.equal(app.get('schedule-post').disabled, true)
  assert.match(app.get('post-connection-note').textContent, /perlu disambungkan semula/); assert.match(app.get('post-destination-note').textContent, /Facebook sahaja.*Akaun tersimpan/)
  assert.equal(app.session(), sessionA); assert.equal(app.get('workspace-state').dataset.state, 'ready')
  await app.input('post-body', 'Draf kekal boleh disimpan.'); await app.save()
  assert.equal(app.saved[0].status, 'draft'); assert.match(app.get('post-feedback').textContent, /belum diterbitkan/)
  assert.equal(app.requests.some(call => call.path.endsWith('/schedule')), false)
})

test('unknown provider health and network failures preserve the connection, show uncertainty and recover on explicit check', async t => {
  for (const mode of ['unknown', 'network', 'malformed']) await t.test(mode, async () => {
    const app = await workspace({ health: (_call, n) => {
      if (n > 1) return response(checked())
      if (mode === 'network') throw new TypeError('offline')
      return response(mode === 'unknown' ? checked('unknown') : { ok: true, connection: connected })
    } })
    await app.openPost(); assert.match(app.card('facebook').textContent, /Belum dapat disahkan/)
    assert.match(app.card('facebook').textContent, /Putuskan sambungan/); assert.doesNotMatch(app.card('facebook').textContent, /Sambung semula akaun/)
    assert.equal(app.get('schedule-post').disabled, true); assert.equal(app.session(), sessionA)
    await app.check('facebook'); assert.equal(app.get('schedule-post').disabled, false); assert.match(app.card('facebook').textContent, /Disambungkan/)
  })
})

test('account changes abort an in-flight check and suppress its late Page name and invalid status', async () => {
  const pending = deferred(), app = await workspace({ health: () => pending.promise })
  app.setSession(sessionB); assert.equal(app.checks[0].options.signal.aborted, true); await tick()
  pending.resolve(response(checked('invalid', { ...connected, status: 'reconnect_required', accounts: [{ ...account, name: 'Private old Page' }] }))); await tick()
  assert.doesNotMatch(app.get('connection-list').textContent, /Private old Page|Page Perniagaan Ujian|Sambung semula/)
  assert.equal(app.session(), sessionB); assert.equal(app.checks.length, 1)
})

test('a silent account change while parsing health cannot leak the previous account response', async () => {
  const pending = deferred(), app = await workspace({ health: () => pending.promise })
  pending.resolve({ ok: true, status: 200, json: async () => { app.setSession(sessionB, false); return checked('invalid', { ...connected, status: 'reconnect_required' }) } }); await tick(); await tick()
  assert.doesNotMatch(app.get('connection-list').textContent, /Page Perniagaan Ujian/); assert.equal(app.session(), sessionB)
})

test('workspace refresh supersedes old health responses without overwriting the newest result', async () => {
  const pending = deferred(), app = await workspace({ health: (_call, n) => n === 1 ? pending.promise : response(checked()) })
  await app.get('refresh-workspace').emit('click'); await tick()
  assert.equal(app.checks[0].options.signal.aborted, true); assert.equal(app.checks.length, 2)
  pending.resolve(response(checked('invalid', { ...connected, status: 'reconnect_required', publishable: false }))); await tick()
  assert.match(app.card('facebook').textContent, /Disambungkan/); assert.doesNotMatch(app.card('facebook').textContent, /Sambung semula akaun/)
})

test('read-only queued and published posts show actual publication state rather than the draft selector', async t => {
  for (const [status, label] of [['queued', 'Dalam giliran'], ['published', 'Diterbitkan']]) await t.test(status, async () => {
    const app = await workspace({ posts: [{ ...post, status, provider_post_id: status === 'published' ? 'provider-record' : '' }] }); await app.openPost()
    assert.equal(app.get('post-state-label').hidden, true); assert.equal(app.get('post-status-readonly').hidden, false)
    assert.equal(app.get('post-status-value').textContent, label); assert.equal(app.get('post-save').hidden, true); assert.equal(app.get('schedule-post').hidden, true)
    assert.doesNotMatch(app.get('post-publication-note').textContent, /^Draf/); assert.equal(app.get('preview-name').textContent, 'Akaun Facebook', 'Current connection must not be represented as an old publication destination')
  })
})

test('a draft clearly identifies its single platform and saving never requests a schedule', async () => {
  const app = await workspace({ posts: [post] }); await app.openPost()
  assert.match(app.get('post-publication-note').textContent, /Draf ini belum diterbitkan/)
  assert.match(app.get('post-destination-note').textContent, /Facebook sahaja.*Page Perniagaan Ujian/)
  assert.match(app.get('post-list').textContent, /Draf disimpan · belum diterbitkan/)
  await app.save(); assert.equal(app.requests.some(call => call.path.endsWith('/schedule')), false)
})

test('new Threads connections show testing availability before redirect and let the user return', async () => {
  const disconnected = { ...connected, provider: 'threads', platform: 'threads', status: 'disconnected', connected: false, publishable: false, accounts: [], message: '' }
  const app = await workspace({ connections: [disconnected], threadsAccess: 'testing' })
  assert.match(app.card('threads').textContent, /Akses terhad/)
  assert.match(app.card('threads').textContent, /pelanggan baharu.*kelulusan Meta/)
  const connect = app.card('threads').querySelectorAll('button').find(node => node.textContent.includes('Sambungkan akaun'))
  assert.equal(connect.disabled, false, 'Eligible testers must retain a working connect action')
  const connecting = connect.emit('click'); await tick()
  assert.equal(app.get('confirm-dialog').open, true)
  assert.equal(app.get('confirm-accept').textContent, 'Teruskan sebagai penguji')
  assert.match(app.get('confirm-text').textContent, /tidak menghantar jemputan/)
  await app.get('confirm-cancel').emit('click'); await connecting
  assert.equal(app.get('confirm-dialog').open, false)
  assert.equal(app.requests.some(call => call.path.endsWith('/begin')), false)
})

test('Threads testing availability does not disable an already connected tester or its draft scheduling', async () => {
  const threads = { ...connected, provider: 'threads', platform: 'threads', accounts: [{ ...account, name: 'Penguji sedia ada' }] }
  const app = await workspace({ connections: [threads], posts: [{ ...post, platform: 'threads' }], threadsAccess: 'testing', enabled: false })
  assert.match(app.card('threads').textContent, /Disambungkan/)
  assert.doesNotMatch(app.card('threads').textContent, /Akses terhad|pelanggan baharu/)
  await app.openPost()
  assert.equal(app.get('schedule-post').hidden, false)
  assert.equal(app.get('schedule-post').disabled, false)
  assert.equal(app.get('confirm-dialog').open, false)
})

test('approved Threads access removes the testing notice for new connections', async () => {
  const app = await workspace({ connections: [{ ...connected, provider: 'threads', platform: 'threads', status: 'disconnected', accounts: [], message: '' }], threadsAccess: 'approved' })
  assert.match(app.card('threads').textContent, /Belum disambungkan/)
  assert.doesNotMatch(app.card('threads').textContent, /Akses terhad|kelulusan Meta|jemputan penguji/)
})

test('editing platform or time during schedule health preflight cannot confirm an obsolete post', async () => {
  const pending = deferred(), app = await workspace({ health: (_call, n) => n === 1 ? response(checked()) : pending.promise })
  await app.openPost(); await app.input('post-body', 'Teks yang diluluskan.'); await app.input('post-scheduled', '2026-11-20T10:00')
  app.advance(60001); const scheduling = app.get('schedule-post').emit('click'); await tick()
  await app.input('post-platform', 'instagram'); await app.input('post-scheduled', '2026-11-20T12:00')
  pending.resolve(response(checked())); await scheduling
  assert.equal(app.get('confirm-dialog').open, false); assert.match(app.get('post-feedback').textContent, /Kandungan berubah semasa semakan/)
  assert.equal(app.get('post-platform').value, 'instagram'); assert.equal(app.get('post-scheduled').value, '2026-11-20T12:00')
  assert.equal(app.requests.some(call => call.path.endsWith('/schedule')), false); assert.equal(app.saved.length, 0)
})

test('older backends without the health capability keep the existing connection flow', async () => {
  const app = await workspace({ enabled: false }); await app.openPost()
  assert.equal(app.checks.length, 0); assert.equal(app.get('schedule-post').disabled, false); assert.doesNotMatch(app.card('facebook').textContent, /Semak sambungan/)
})
