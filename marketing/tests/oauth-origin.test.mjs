import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const source = readFileSync(new URL('../marketing.js', import.meta.url), 'utf8')
const beginSource = source.match(/  async function beginConnect\(platform\) \{[\s\S]*?(?=\n  async function disconnect\()/)?.[0]
assert.ok(beginSource, 'The social connection entry point must exist')

function entryPoint(origin) {
  const requests = [], navigations = [], links = [], notices = []
  let focused = null
  const notice = { append(node) { links.push(node) } }
  const context = vm.createContext({
    API: '/api/akaun/copilot/marketing', URL,
    location: { origin, assign(url) { navigations.push(url) } },
    request: async (path, options) => {
      requests.push({ path, method: options.method })
      return { authorization_url: 'https://www.facebook.com/v27.0/dialog/oauth?state=synthetic-state' }
    },
    notify() {},
    message(text) { notices.push(text) },
    $(id) { assert.equal(id, 'global-message'); return notice },
    el(tag, text, className) {
      assert.equal(tag, 'a')
      return { textContent: text, className, focus() { focused = this } }
    },
  })
  vm.runInContext(beginSource, context)
  return { run: context.beginConnect, requests, navigations, links, notices, focused: () => focused }
}

test('www social connection stops before OAuth and offers normal apex login without transferring a session', async () => {
  for (const platform of ['facebook', 'instagram', 'threads']) {
    const app = entryPoint('https://www.beshareaisolution.com')
    await app.run(platform)
    assert.equal(app.requests.length, 0)
    assert.equal(app.navigations.length, 0)
    assert.equal(app.links.length, 1)
    assert.equal(app.links[0].href, 'https://beshareaisolution.com/akaun/?selepas=marketing')
    assert.match(app.links[0].textContent, /Masuk/)
    assert.match(app.notices[0], /akaun BeShare yang sama/)
    assert.equal(app.focused(), app.links[0])
    assert.deepEqual([...new URL(app.links[0].href).searchParams], [['selepas', 'marketing']])
  }
})

test('apex, staging and local development retain the existing connection flow', async () => {
  for (const origin of ['https://beshareaisolution.com', 'https://staging.beshareaisolution.com', 'http://127.0.0.1:8000']) {
    for (const platform of ['facebook', 'instagram', 'threads']) {
      const app = entryPoint(origin)
      await app.run(platform)
      assert.deepEqual(app.requests, [{ path: '/api/akaun/copilot/marketing/connections/' + platform + '/begin', method: 'POST' }])
      assert.equal(app.navigations.length, 1)
      assert.equal(app.links.length, 0)
      assert.equal(app.notices.length, 0)
    }
  }
})
