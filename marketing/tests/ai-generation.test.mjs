import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { webcrypto } from 'node:crypto'
import vm from 'node:vm'

const source = readFileSync(new URL('../marketing.js', import.meta.url), 'utf8')
const core = readFileSync(new URL('../core.js', import.meta.url), 'utf8')
const html = readFileSync(new URL('../index.html', import.meta.url), 'utf8')
const sessionA = JSON.stringify({ token: 'synthetic-account-a', email: 'a@example.invalid' })
const sessionB = JSON.stringify({ token: 'synthetic-account-b', email: 'b@example.invalid' })
const campaign = { id: 'a'.repeat(32), title: 'Kempen contoh', status: 'draft', goal: 'awareness', budget_total: '0.00', channels: ['facebook'], product: 'Produk contoh' }
const result = (jawapan = 'Draf model untuk semakan.') => ({ ok: true, bukan_model: false, sumber: 'openai', jawapan, langkah: [] })
const response = (data, status = 200) => ({ status, ok: status >= 200 && status < 300, json: async () => data })
const tick = () => new Promise(resolve => setImmediate(resolve))
function deferred() { let resolve; const promise = new Promise(done => { resolve = done }); return { promise, resolve } }

class Element {
  constructor(tag = 'div') {
    this.tag = tag; this.children = []; this.listeners = {}; this.dataset = {}; this.style = {}
    this.disabled = false; this.hidden = false; this.open = false; this.value = ''; this.text = ''
    this.classList = { add() {}, remove() {}, toggle() {} }
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
  async emit(name, event = {}) { for (const listener of this.listeners[name] || []) await listener({ preventDefault() {}, ...event }) }
  querySelectorAll(selector) { const tags = selector.split(','); return this.children.flatMap(child => [...(tags.includes(child.tag) ? [child] : []), ...child.querySelectorAll(selector)]) }
  reportValidity() { return true }
  showModal() { this.open = true }
  close() { this.open = false }
  reset() { this.querySelectorAll('input,select,textarea').forEach(node => { node.value = '' }) }
  focus() {}
}

async function workspace(fetchAI) {
  const nodes = new Map(), get = id => { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id) }
  for (const [, tag, id, attributes] of html.matchAll(/<([a-z][\w-]*)\b[^>]*\bid="([^"]+)"([^>]*)>/g)) {
    const node = new Element(tag); node.hidden = /\bhidden\b/.test(attributes); nodes.set(id, node)
  }
  for (const prefix of ['ai', 'post', 'campaign', 'source', 'result']) {
    get(prefix + '-form').children = [...nodes.entries()].filter(([id, node]) => id.startsWith(prefix + '-') && ['input', 'select', 'textarea'].includes(node.tag)).map(([, node]) => node)
  }
  get('campaign-status').value = 'all'
  const requests = [], aiRequests = [], listeners = {}, timers = new Map()
  let stored = sessionA, timerId = 0
  const window = {
    BeshareAsal: { asalPerkhidmatan: () => ({ akaun: 'https://account.example.invalid', nasihat: 'https://advice.example.invalid' }) },
    addEventListener(name, listener) { (listeners[name] ||= []).push(listener) },
  }
  const context = vm.createContext({
    window, crypto: webcrypto, structuredClone, TextEncoder, AbortController, URL, Intl,
    Option: class extends Element { constructor(label, value) { super('option'); this.textContent = label; this.value = value } },
    location: { origin: 'https://example.invalid', hash: '#kempen' },
    localStorage: { getItem: () => stored, removeItem() { stored = null } },
    document: {
      getElementById: get, createElement: tag => new Element(tag), createElementNS: (_ns, tag) => new Element(tag),
      createTextNode: text => { const node = new Element('#text'); node.textContent = text; return node },
      querySelectorAll(selector) {
        if (selector === 'dialog[open]') return [...nodes.values()].filter(node => node.tag === 'dialog' && node.open)
        if (selector === 'dialog form') return [...nodes.values()].filter(node => node.tag === 'form')
        if (selector === 'dialog:not(#confirm-dialog)') return [...nodes.entries()].filter(([id, node]) => node.tag === 'dialog' && id !== 'confirm-dialog').map(([, node]) => node)
        return []
      },
    },
    setTimeout(fn, ms) { const id = ++timerId; timers.set(id, { fn, ms }); return id }, clearTimeout(id) { timers.delete(id) },
    fetch: async (url, options) => {
      requests.push({ url, options })
      if (url.endsWith('/api/marketing/draf')) { aiRequests.push({ url, options }); return fetchAI(url, options, aiRequests.length) }
      if (options.method === 'GET' && url.endsWith('/api/akaun/saya')) return response({ akaun_id: stored === sessionB ? 'owner-b' : 'owner-a', nama: 'Perniagaan contoh' })
      if (options.method === 'GET' && url.endsWith('/workspace')) return response({ ok: true, campaigns: [campaign], posts: [], sources: [], results: [], connections: [], capabilities: { worker_enabled: true, auto_publish: true } })
      throw Error('Unexpected request: ' + options.method + ' ' + url)
    },
  })
  vm.runInContext(core, context); window.MarketingCore = context.MarketingCore
  vm.runInContext(source, context); await tick()
  assert.equal(get('workspace-state').dataset.state, 'ready')
  const app = {
    get, requests, aiRequests,
    async openAI() { await get('new-post').emit('click'); await get('post-ai').emit('click') },
    generate: () => get('ai-form').emit('submit'),
    setSession(value, emit = true) { stored = value; if (emit) for (const listener of listeners.storage || []) listener({ key: 'beshare-akaun' }) },
    timeoutAI() { const timeout = [...timers.values()].find(timer => timer.ms === 90000); assert.ok(timeout); timeout.fn() },
  }
  await app.openAI()
  return app
}

function key(app, index) { return app.aiRequests[index].options.headers['Idempotency-Key'] }
function assertNoPublication(app) {
  assert.deepEqual(app.requests.filter(item => item.options.method !== 'GET').map(item => new URL(item.url).pathname), app.aiRequests.map(() => '/api/marketing/draf'))
}

test('a successful draft can be regenerated deliberately and remains visible until its replacement arrives', async () => {
  const pending = deferred()
  const app = await workspace((_url, _options, attempt) => attempt === 1 ? response(result('Draf pertama.')) : pending.promise)
  await app.generate()
  const generating = app.generate()
  assert.equal(app.get('ai-output').value, 'Draf pertama.')
  assert.equal(app.get('ai-output-wrap').hidden, false)
  assert.equal(app.get('ai-use').disabled, true)
  await app.generate()
  assert.equal(app.aiRequests.length, 2, 'Repeated clicks while active do not start another request')
  assert.notEqual(key(app, 0), key(app, 1))
  pending.resolve(response(result('Draf kedua.'))); await generating
  assert.equal(app.get('ai-use').disabled, false)
  await app.get('ai-use').emit('click')
  assert.equal(app.get('post-body').value, 'Draf kedua.')
  assert.match(app.get('post-feedback').textContent, /tekan Simpan/)
  assertNoPublication(app)
})

test('only a definite retryable provider failure allows a new key and the next click starts that attempt', async () => {
  const app = await workspace((_url, _options, attempt) => response(attempt === 1 ? { ok: false, ralat: 'provider_failed', retryable: true, error: 'Provider failed' } : result(), attempt === 1 ? 503 : 200))
  await app.generate(); await tick()
  assert.equal(app.aiRequests.length, 1, 'No automatic provider retry')
  assert.equal(app.get('ai-use').disabled, true)
  assert.match(app.get('ai-feedback').textContent, /percubaan baharu/)
  await app.generate()
  assert.notEqual(key(app, 0), key(app, 1))
  assert.equal(app.get('ai-use').disabled, false)
})

test('pending, uncertain, unknown and unconfirmed provider failures retain the key until settled replay', async t => {
  const cases = [
    ['pending', 409, { ralat: 'menunggu' }], ['uncertain', 503, { ralat: 'tidak_pasti' }],
    ['unknown', 503, {}], ['provider failure without retry grant', 503, { ralat: 'provider_failed' }],
    ['provider failure explicitly not retryable', 503, { ralat: 'provider_failed', retryable: false }],
  ]
  for (const [name, status, fields] of cases) await t.test(name, async () => {
    const app = await workspace((_url, _options, attempt) => attempt === 1 ? response({ ok: false, error: 'Belum selesai.', ...fields }, status) : response({ ...result('Draf tersimpan.'), sumber: 'tersimpan', main_semula: true }))
    await app.generate(); await tick()
    assert.equal(app.aiRequests.length, 1)
    if (['pending', 'uncertain'].includes(name)) assert.match(app.get('ai-feedback').textContent, /permintaan yang sama/)
    assert.equal(app.get('ai-feedback').querySelectorAll('a').length, 0)
    await app.generate()
    assert.equal(key(app, 0), key(app, 1))
    assert.equal(app.get('ai-output').value, 'Draf tersimpan.')
    assert.equal(app.get('ai-use').disabled, false)
  })
})

test('network interruption and browser timeout keep the same request key', async t => {
  for (const mode of ['network', 'timeout']) await t.test(mode, async () => {
    const app = await workspace((_url, options, attempt) => {
      if (attempt > 1) return response(result())
      if (mode === 'network') throw new TypeError('offline')
      return new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })), { once: true }))
    })
    const first = app.generate(); if (mode === 'timeout') app.timeoutAI(); await first
    assert.equal(app.aiRequests.length, 1)
    assert.equal(app.get('ai-generate').disabled, false)
    await app.generate()
    assert.equal(key(app, 0), key(app, 1))
  })
})

test('a failed regeneration preserves the edited previous draft and its original destination', async () => {
  const app = await workspace((_url, _options, attempt) => attempt === 1 ? response(result('Draf Facebook.')) : response({ ok: false, ralat: 'provider_failed', retryable: true }, 503))
  await app.generate()
  app.get('ai-output').value = 'Draf Facebook yang telah disunting.'
  app.get('ai-platform').value = 'threads'
  await app.generate()
  assert.equal(app.get('ai-output-wrap').hidden, false)
  assert.equal(app.get('ai-use').disabled, false)
  assert.match(app.get('ai-feedback').textContent, /Draf sebelumnya masih tersedia untuk Facebook/)
  await app.get('ai-use').emit('click')
  assert.equal(app.get('post-platform').value, 'facebook')
  assert.equal(app.get('post-body').value, 'Draf Facebook yang telah disunting.')
  assertNoPublication(app)
})

test('profile assistance appears only for the explicit profile error reason', async t => {
  for (const [status, ralat, expected] of [[422, 'profil', 1], [409, 'menunggu', 0], [409, 'kunci_berbeza', 0], [422, 'bentuk', 0]]) await t.test(ralat, async () => {
    const app = await workspace(() => response({ ok: false, ralat, error: 'Semak permintaan.' }, status))
    await app.generate()
    assert.equal(app.get('ai-feedback').querySelectorAll('a').length, expected)
  })
})

test('an invalid model response never becomes usable or discards the existing key', async () => {
  const app = await workspace((_url, _options, attempt) => response(attempt === 1 ? { ...result('Bukan replay yang disahkan.'), sumber: 'tersimpan' } : result('Jawapan yang sah.')))
  await app.generate()
  assert.equal(app.get('ai-use').disabled, true)
  assert.equal(app.get('ai-output-wrap').hidden, true)
  await app.generate()
  assert.equal(key(app, 0), key(app, 1))
  assert.equal(app.get('ai-output').value, 'Jawapan yang sah.')
})

test('account switching aborts the old request, suppresses late output and restores the next account controls', async () => {
  const pending = deferred()
  const app = await workspace((_url, _options, attempt) => attempt === 1 ? pending.promise : response(result('Draf akaun B.')))
  const first = app.generate()
  app.setSession(sessionB)
  assert.equal(app.aiRequests[0].options.signal.aborted, true)
  pending.resolve(response(result('Rahsia akaun A.'))); await first; await tick()
  assert.equal(app.get('ai-output').value, '')
  assert.equal(app.get('ai-use').disabled, true)
  assert.equal(app.get('ai-generate').disabled, false)
  assert.equal(app.get('ai-campaign').disabled, false)
  await app.openAI(); await app.generate()
  assert.equal(app.get('ai-output').value, 'Draf akaun B.')
  assert.equal(app.aiRequests[1].options.headers.Authorization, 'Bearer synthetic-account-b')
  assert.notEqual(key(app, 0), key(app, 1))
  assertNoPublication(app)
})

test('a silent session change during response parsing cannot reveal the previous account result', async () => {
  const pending = deferred()
  const app = await workspace(() => pending.promise)
  const generating = app.generate()
  pending.resolve({ ok: true, status: 200, json: async () => { app.setSession(sessionB, false); return result('Rahsia akaun A.') } })
  await generating; await tick()
  assert.equal(app.get('ai-output').value, '')
  assert.equal(app.get('ai-use').disabled, true)
  assert.equal(app.get('ai-generate').disabled, false)
  assert.equal(app.get('workspace-state').dataset.state, 'ready')
})

test('using a draft after a silent account change clears it instead of copying it into the next account', async () => {
  const app = await workspace(() => response(result('Rahsia akaun A.')))
  await app.generate(); app.setSession(sessionB, false)
  await app.get('ai-use').emit('click'); await tick()
  assert.equal(app.get('ai-output').value, '')
  assert.equal(app.get('post-body').value, '')
  assert.equal(app.get('ai-use').disabled, true)
  assertNoPublication(app)
})
