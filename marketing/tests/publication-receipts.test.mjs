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
const published = { ...post, version: 3, status: 'published', provider_post_id: '1452084641326173_122099230497555550' }
const receipt = { platform: 'facebook', account_id: '1452084641326173', account_name: "D'Herbs asal", profile_url: 'https://www.facebook.com/profile.php?id=61596666512788', post_url: 'https://www.facebook.com/122099317587555550/posts/122099230497555550', resolved_at: '2026-11-19T12:00:00Z' }
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

async function workspace({ health = () => response(checked()), connections = [connected], posts = [], enabled = false, receiptsEnabled = true, publication = () => response({ ok: true, publication: receipt }) } = {}) {
  const nodes = new Map(), get = id => { if (!nodes.has(id)) { const node = new Element(); node.id = id; nodes.set(id, node) } return nodes.get(id) }
  for (const [, tag, id, attributes] of html.matchAll(/<([a-z][\w-]*)\b[^>]*\bid="([^"]+)"([^>]*)>/g)) { const node = new Element(tag); node.id = id; node.hidden = /\bhidden\b/.test(attributes); nodes.set(id, node) }
  for (const prefix of ['ai', 'post', 'campaign', 'source', 'result']) get(prefix + '-form').children = [...nodes.entries()].filter(([id, node]) => id.startsWith(prefix + '-') && ['input', 'select', 'textarea'].includes(node.tag)).map(([, node]) => node)
  get('campaign-status').value = 'all'
  const requests = [], checks = [], publications = [], listeners = {}, timers = new Map(), saved = []
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
      if (path.endsWith('/publication')) { const call = { url, path, options }; publications.push(call); return publication(call, publications.length) }
      if (path.endsWith('/check')) { const call = { url, path, options }; checks.push(call); return health(call, checks.length) }
      if (options.method === 'GET' && path === '/api/akaun/saya') return response({ akaun_id: stored === sessionB ? 'owner-b' : 'owner-a', nama: 'Ujian pautan penerbitan' })
      if (options.method === 'GET' && path.endsWith('/workspace')) return response({ ok: true, campaigns: [campaign], posts: stored === sessionB ? [] : structuredClone(posts), sources: [], results: [], connections: stored === sessionB ? [] : structuredClone(connections), capabilities: { publication_receipts: receiptsEnabled, connection_health_checks: enabled, worker_enabled: true, auto_publish: true } })
      if (options.method === 'PUT' && path.includes('/posts/')) { const payload = JSON.parse(options.body), record = { ...payload, id: path.split('/').pop(), version: payload.version + 1 }; saved.push(record); return response({ ok: true, post: record }) }
      throw Error('Unexpected request: ' + options.method + ' ' + url)
    },
  })
  vm.runInContext(core, context); window.MarketingCore = context.MarketingCore; vm.runInContext(source, context); await tick()
  assert.equal(get('workspace-state').dataset.state, 'ready')
  const app = {
    get, requests, checks, publications, saved,
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

function receiptButtons(app) { return app.get('post-publication-links').querySelectorAll('button') }
function externalLinks(node) { return node.querySelectorAll('a').map(link => ({ text: link.textContent, href: link.href, target: link.target, rel: link.rel })) }
function assertOnlyMetadataRequests(app) { assert.ok(app.requests.filter(call => call.options.method !== 'GET').every(call => call.path.endsWith('/publication'))) }

test('cached publication links show the original Page after the selected Page changes', async () => {
  const app = await workspace({ posts: [{ ...published, publication: receipt }], connections: [{ ...connected, accounts: [{ ...account, name: 'Page pilihan baharu' }] }] })
  const card = app.get('post-list').children[0]
  assert.match(card.textContent, /Diterbitkan ke D'Herbs asal/)
  assert.doesNotMatch(card.textContent, /Page pilihan baharu|disahkan awam|visible to everyone/i)
  assert.deepEqual(externalLinks(card), [
    { text: 'Buka hantaran Facebook', href: receipt.post_url, target: '_blank', rel: 'noopener noreferrer' },
    { text: 'Buka Page', href: receipt.profile_url, target: '_blank', rel: 'noopener noreferrer' },
  ])
  await app.openPost(); await tick()
  assert.equal(app.get('preview-name').textContent, receipt.account_name)
  assert.match(app.get('post-destination-note').textContent, /Destinasi penerbitan: D'Herbs asal/)
  assert.equal(app.get('post-body').disabled, true)
  assert.equal(app.get('post-save').hidden, true)
  assert.equal(app.get('schedule-post').hidden, true)
  assert.equal(app.publications.length, 0)
  assert.equal(externalLinks(app.get('post-publication-links'))[0].href, receipt.post_url)
})

test('historical published posts resolve their receipt on open, coalesce requests and cache the result', async () => {
  const pending = deferred(), app = await workspace({ posts: [published], publication: () => pending.promise })
  assert.equal(app.publications.length, 0, 'Loading a list must not fetch every provider object')
  await app.openPost(); await app.openPost()
  assert.equal(app.publications.length, 1)
  assert.equal(receiptButtons(app)[0].disabled, true)
  assert.match(app.get('post-publication-links').textContent, /Mendapatkan pautan asal/)
  assert.equal(app.publications[0].options.body, '{}')
  assert.ok(app.publications[0].path.endsWith('/' + published.id + '/publication'))
  pending.resolve(response({ ok: true, publication: receipt })); await tick()
  assert.equal(externalLinks(app.get('post-publication-links'))[0].href, receipt.post_url)
  await app.openPost(); await tick()
  assert.equal(app.publications.length, 1)
  assertOnlyMetadataRequests(app)
})

test('the card offers metadata lookup without reopening or publishing the saved content', async () => {
  const app = await workspace({ posts: [published] })
  const lookup = app.get('post-list').querySelectorAll('button').find(button => button.textContent === 'Semak pautan hantaran')
  await lookup.emit('click'); await tick()
  assert.equal(app.get('post-dialog').open, false)
  assert.equal(app.publications.length, 1)
  assert.equal(externalLinks(app.get('post-list'))[0].href, receipt.post_url)
  assertOnlyMetadataRequests(app)
})

test('metadata failure preserves published state and retries only the receipt request', async () => {
  const app = await workspace({ posts: [published], publication: (_call, count) => count === 1 ? Promise.reject(new TypeError('offline')) : response({ ok: true, publication: receipt }) })
  await app.openPost(); await tick()
  assert.match(app.get('post-publication-links').textContent, /Penerbitan telah direkodkan.*belum dapat dimuat/)
  assert.equal(app.get('post-status-value').textContent, 'Diterbitkan')
  assert.equal(app.get('schedule-post').hidden, true)
  assert.equal(app.get('post-body').disabled, true)
  await app.openPost(); await tick()
  assert.equal(app.publications.length, 1, 'Reopening must not automatically loop failed lookups')
  await receiptButtons(app)[0].emit('click'); await tick()
  assert.equal(app.publications.length, 2)
  assert.equal(externalLinks(app.get('post-publication-links'))[0].href, receipt.post_url)
  assertOnlyMetadataRequests(app)
})

test('unsafe, mismatched and secret-bearing receipt URLs never become external links', async t => {
  const bad = [
    { post_url: 'javascript:alert(1)' },
    { post_url: 'https://facebook.com.evil.invalid/post/1' },
    { post_url: 'https://l.facebook.com/post/1' },
    { post_url: 'https://unexpected.facebook.com/post/1' },
    { post_url: 'https://facebook.com@evil.invalid/post/1' },
    { post_url: 'http://www.facebook.com/post/1' },
    { post_url: 'https://www.facebook.com:444/post/1' },
    { post_url: 'https://www.facebook.com/post/1?access_token=synthetic-secret' },
    { post_url: 'https://www.facebook.com/post/1?CODE=synthetic-secret' },
    { post_url: 'https://www.facebook.com/post/1#private-fragment' },
    { post_url: 'https://www.facebook.com/post/1\x7f' },
    ...['/l.php', '/AWAY.PHP', '/redirect', '/flx/warn/', '//external.invalid/post'].map(path => ({ post_url: 'https://www.facebook.com' + path })),
    ...['next', 'url', 'u', 'href', 'redirect_uri', 'oauth_token'].map(key => ({ post_url: 'https://www.facebook.com/post/1?' + key + '=https%3A%2F%2Fexternal.invalid' })),
    { profile_url: 'https://evil.invalid/profile' },
    { platform: 'instagram' },
  ]
  for (const invalid of bad) await t.test(JSON.stringify(invalid), async () => {
    const value = { ...receipt, ...invalid }, app = await workspace({ posts: [{ ...published, publication: value }], publication: () => response({ ok: true, publication: value }) })
    assert.equal(externalLinks(app.get('post-list')).length, 0)
    await app.openPost(); await tick()
    assert.equal(externalLinks(app.get('post-publication-links')).length, 0)
    assert.match(app.get('post-publication-links').textContent, /belum dapat dimuat/)
    assert.doesNotMatch(app.get('post-publication-links').textContent, /synthetic-secret|javascript:/)
    assertOnlyMetadataRequests(app)
  })
})

test('Instagram and Threads use their own returned links without reconstructing a URL', async () => {
  for (const [platform, postUrl, profileUrl] of [
    ['instagram', 'https://www.instagram.com/p/ProviderCode/', 'https://www.instagram.com/akaun.asal/'],
    ['threads', 'https://www.threads.com/@akaun.asal/post/ProviderCode', 'https://www.threads.com/@akaun.asal'],
  ]) {
    const value = { ...receipt, platform, post_url: postUrl, profile_url: profileUrl }
    const app = await workspace({ posts: [{ ...published, platform, publication: value }] })
    await app.openPost(); await tick()
    assert.deepEqual(externalLinks(app.get('post-publication-links')).map(link => link.href), [postUrl, profileUrl])
    assert.equal(externalLinks(app.get('post-publication-links'))[1].text, 'Buka profil')
    assert.equal(app.publications.length, 0)
  }
})

test('known provider variants are accepted while arbitrary Instagram and Threads subdomains are rejected', async () => {
  for (const [platform, allowed, unexpected] of [
    ['facebook', 'https://m.facebook.com/posts/123', 'https://other.facebook.com/posts/123'],
    ['instagram', 'https://instagram.com/p/ProviderCode/', 'https://other.instagram.com/p/ProviderCode/'],
    ['threads', 'https://www.threads.net/@akaun/post/ProviderCode', 'https://other.threads.net/@akaun/post/ProviderCode'],
  ]) {
    const app = await workspace({ posts: [{ ...published, platform, publication: { ...receipt, platform, profile_url: '', post_url: allowed } }] })
    assert.equal(externalLinks(app.get('post-list'))[0].href, allowed)
    const rejected = await workspace({ posts: [{ ...published, platform, publication: { ...receipt, platform, profile_url: '', post_url: unexpected } }] })
    assert.equal(externalLinks(rejected.get('post-list')).length, 0)
  }
})

test('an empty optional profile link still allows opening the verified post', async () => {
  const app = await workspace({ posts: [{ ...published, publication: { ...receipt, profile_url: '' } }] })
  await app.openPost(); await tick()
  assert.equal(externalLinks(app.get('post-publication-links')).length, 1)
  assert.equal(externalLinks(app.get('post-publication-links'))[0].href, receipt.post_url)
})

test('account switch aborts a pending lookup and suppresses the old account receipt', async () => {
  const pending = deferred(), app = await workspace({ posts: [published], publication: () => pending.promise })
  await app.openPost()
  const request = app.publications[0]
  app.setSession(sessionB)
  assert.equal(request.options.signal.aborted, true)
  pending.resolve(response({ ok: true, publication: receipt })); await tick()
  assert.equal(app.get('post-dialog').open, false)
  assert.doesNotMatch(app.get('post-list').textContent, /D'Herbs asal/)
  assert.equal(externalLinks(app.get('post-publication-links')).length, 0)
})

test('draft and uncertain posts do not resolve or expose publication receipts', async () => {
  for (const status of ['draft', 'planned', 'queued', 'publishing', 'uncertain', 'failed']) {
    const app = await workspace({ posts: [{ ...published, status, publication: receipt }] })
    await app.openPost(); await tick()
    assert.equal(app.publications.length, 0)
    assert.equal(externalLinks(app.get('post-publication-links')).length, 0)
    assert.equal(app.get('post-publication-links').hidden, true)
  }
})

test('an older backend without receipt capability keeps published content readable', async () => {
  const app = await workspace({ posts: [published], receiptsEnabled: false })
  await app.openPost(); await tick()
  assert.equal(app.publications.length, 0)
  assert.equal(receiptButtons(app).length, 0)
  assert.equal(app.get('post-status-value').textContent, 'Diterbitkan')
  assert.equal(app.get('post-body').disabled, true)
})
