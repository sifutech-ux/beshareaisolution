import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const source = readFileSync(new URL('../connect.js', import.meta.url), 'utf8')
const bearerA = 'synthetic-bearer-account-a', bearerB = 'synthetic-bearer-account-b'
const sessionA = JSON.stringify({ token: bearerA, email: 'a@example.invalid' })
const sessionB = JSON.stringify({ token: bearerB, email: 'b@example.invalid' })
const candidateId = 'a'.repeat(32)
const choice = { ok: true, selection_required: true, platform: 'facebook', candidates: [{ id: candidateId, name: 'Page pilihan' }] }
const connected = { ok: true, selection_required: false, account: { name: 'Page pilihan' } }
const response = (data, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => data })
function deferred() { let resolve; const promise = new Promise(done => { resolve = done }); return { promise, resolve } }

class Element {
  constructor(tag = 'div') { this.tag = tag; this.children = []; this.dataset = {}; this.listeners = {}; this.disabled = false; this.hidden = false; this.text = '' }
  set textContent(value) { this.text = String(value); this.children = [] }
  get textContent() { return this.text + this.children.map(child => child.textContent).join('') }
  append(...nodes) { this.children.push(...nodes) }
  replaceChildren(...nodes) { this.text = ''; this.children = [...nodes] }
  setAttribute(name, value) { this[name] = value }
  addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener) }
  querySelectorAll(tag) { return this.children.flatMap(child => [...(child.tag === tag ? [child] : []), ...child.querySelectorAll(tag)]) }
  async click() { for (const listener of this.listeners.click || []) await listener() }
}

function callback({ stored = sessionA, fetcher = async () => response(choice), reads } = {}) {
  const ids = Object.fromEntries(['status', 'title', 'intro', 'accounts', 'login'].map(id => [id, new Element()]))
  ids.login.hidden = true
  const requests = [], listeners = {}, output = [], returned = { code: 'synthetic-code', state: 's'.repeat(43) }
  let raw = stored, readCount = 0
  const context = vm.createContext({
    window: { BeshareOAuthReturn: returned, addEventListener(type, fn) { (listeners[type] ||= []).push(fn) } },
    location: { origin: 'https://beshareaisolution.com' },
    BeshareAsal: { asalPerkhidmatan: () => ({ akaun: 'https://akaun.beshareaisolution.com' }) },
    document: { getElementById: id => ids[id], createElement: tag => new Element(tag) },
    localStorage: { getItem() { return reads ? reads(readCount++, raw) : raw } },
    AbortController, setTimeout: () => 1, clearTimeout() {},
    console: { log(...args) { output.push(args) }, error(...args) { output.push(args) } },
    fetch: (url, options) => { requests.push({ url, options }); return fetcher(url, options, requests.length) },
  })
  const finished = vm.runInContext(source, context)
  return {
    ids, requests, output, returned, finished,
    setSession(value, emit = true) {
      raw = value
      if (emit) for (const listener of listeners.storage || []) listener({ key: 'beshare-akaun', newValue: value })
    },
    storageEvent(event) { for (const listener of listeners.storage || []) listener(event) },
    buttons() { return ids.accounts.querySelectorAll('button') },
  }
}

function assertStopped(app) {
  assert.match(app.ids.title.textContent, /Sesi BeShare telah berubah/)
  assert.match(app.ids.status.textContent, /akaun BeShare yang memulakan sambungan/)
  assert.equal(app.ids.login.hidden, false)
  assert.equal(app.buttons().length, 0)
  assert.equal(app.returned.code, '')
  assert.equal(app.returned.state, '')
  assert.ok(!app.ids.title.textContent.includes('berjaya'))
  const visible = Object.values(app.ids).map(node => node.textContent).join(' ') + JSON.stringify(app.output)
  for (const secret of [bearerA, bearerB, 'synthetic-code', 's'.repeat(43)]) assert.ok(!visible.includes(secret))
  for (const { url, options } of app.requests) {
    assert.ok(!url.includes(bearerA) && !url.includes(bearerB))
    assert.ok(!options.body.includes(bearerA) && !options.body.includes(bearerB))
    assert.equal(options.headers.Authorization, 'Bearer ' + bearerA)
  }
}

test('normal Page selection uses the original bearer and consumes the candidate UI', async () => {
  const app = callback({ fetcher: async (_url, _options, attempt) => response(attempt === 1 ? choice : connected) })
  await app.finished
  const button = app.buttons()[0]
  assert.equal(button.disabled, false)
  await button.click()
  assert.equal(app.requests.length, 2)
  assert.ok(app.requests[1].url.endsWith('/facebook/select'))
  assert.deepEqual(JSON.parse(app.requests[1].options.body), { candidate_id: candidateId })
  assert.equal(app.requests[1].options.headers.Authorization, 'Bearer ' + bearerA)
  assert.match(app.ids.title.textContent, /berjaya disambungkan/)
  assert.equal(app.buttons().length, 0)
  await button.click()
  assert.equal(app.requests.length, 2, 'A consumed candidate cannot be selected again')
})

test('normal direct Threads completion remains usable', async () => {
  const app = callback({ fetcher: async () => response({ ...connected, platform: 'threads' }) })
  await app.finished
  assert.match(app.ids.title.textContent, /berjaya disambungkan/)
  assert.equal(app.requests.length, 1)
  assert.equal(app.returned.code, '')
})

test('a changed, removed or malformed session before the first fetch makes no request', async () => {
  for (const changed of [sessionB, null, '{broken', JSON.stringify({ token: 123 }), JSON.stringify({ token: '' })]) {
    const app = callback({ reads: count => count === 0 ? sessionA : changed })
    await app.finished
    assert.equal(app.requests.length, 0)
    assertStopped(app)
  }
})

test('session change during the completion request aborts and suppresses a late response', async () => {
  const pending = deferred()
  const app = callback({ fetcher: () => pending.promise })
  app.setSession(sessionB)
  assert.equal(app.requests[0].options.signal.aborted, true)
  pending.resolve(response(connected))
  await app.finished
  assertStopped(app)
})

test('session removal while parsing a response also suppresses success without a storage event', async () => {
  const body = deferred(), parsing = deferred()
  const app = callback({ fetcher: async () => ({ ok: true, status: 200, json() { parsing.resolve(); return body.promise } }) })
  await parsing.promise
  app.setSession(null, false)
  body.resolve(connected)
  await app.finished
  assertStopped(app)
})

test('candidate clicks recheck the current account even without a storage event', async () => {
  const app = callback()
  await app.finished
  const button = app.buttons()[0]
  app.setSession(sessionB, false)
  await button.click()
  assert.equal(app.requests.length, 1)
  assert.equal(button.disabled, true)
  assertStopped(app)
})

test('a stale selection response cannot display success or re-enable candidate buttons', async () => {
  for (const result of [response(connected), response({ ok: false, error: 'Cubaan semula' }, 503)]) {
    const pending = deferred()
    const app = callback({ fetcher: async (_url, _options, attempt) => attempt === 1 ? response(choice) : pending.promise })
    await app.finished
    const button = app.buttons()[0], selected = button.click()
    app.setSession('{malformed')
    pending.resolve(result)
    await selected
    assert.equal(button.disabled, true)
    assert.equal(app.requests[1].options.signal.aborted, true)
    assertStopped(app)
  }
})

test('storage removal clears candidates permanently even if the original bearer is restored', async () => {
  const app = callback()
  await app.finished
  const button = app.buttons()[0]
  app.storageEvent({ key: null, newValue: null })
  app.setSession(sessionA)
  await button.click()
  assert.equal(button.disabled, true)
  assert.equal(app.requests.length, 1)
  assertStopped(app)
})

test('unrelated storage changes leave the valid candidate flow available', async () => {
  const app = callback()
  await app.finished
  app.storageEvent({ key: 'unrelated-setting', newValue: 'changed' })
  assert.equal(app.buttons().length, 1)
  assert.equal(app.buttons()[0].disabled, false)
  assert.equal(app.ids.login.hidden, true)
})
