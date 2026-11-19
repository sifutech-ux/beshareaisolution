import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import vm from 'node:vm'

const html = readFileSync(new URL('../connect.html', import.meta.url), 'utf8')
const script = html.match(/<script>([\s\S]*?)<\/script>/)?.[1]
assert.ok(script, 'Callback parameters must be consumed before deferred scripts load')

function parse(params) {
  const changes = [], window = {}
  vm.runInNewContext(script, {
    URLSearchParams, window,
    location: { pathname: '/marketing/connect.html', search: '?' + new URLSearchParams(params).toString() },
    history: { replaceState(...args) { changes.push(args) } },
  })
  assert.deepEqual(changes, [[null, '', '/marketing/connect.html']])
  return window.BeshareOAuthReturn
}

test('the inline callback parser remains allowed by its exact Content Security Policy hash', () => {
  const hash = createHash('sha256').update(script).digest('base64')
  assert.ok(html.includes("'sha256-" + hash + "'"))
  assert.ok(html.indexOf('<script>') < html.indexOf('<script src='))
})

test('known callback failures retain only an allowlisted category and erase code/state immediately', () => {
  for (const [params, expected] of [
    [{ error_code: '1349245' }, 'tester_required'],
    [{ error: 'access_denied' }, 'cancelled'],
    [{ error: 'temporarily_unavailable' }, 'temporary'],
    [{ error: 'server_error' }, 'temporary'],
    [{ error: 'unrecognized_provider_failure' }, 'provider'],
    [{ error_code: 'unknown' }, 'provider'],
    [{ error_message: 'raw diagnostic' }, 'provider'],
  ]) {
    const returned = parse({ ...params, code: 'synthetic-code', state: 'synthetic-state', error_message: 'PRIVATE RAW PROVIDER DETAIL' })
    assert.equal(returned.failure, expected)
    assert.equal(returned.denied, true)
    assert.equal(returned.code, '')
    assert.equal(returned.state, '')
    assert.deepEqual(Object.keys(returned).sort(), ['code', 'denied', 'failure', 'state'])
    assert.doesNotMatch(JSON.stringify(returned), /PRIVATE RAW PROVIDER DETAIL|synthetic-code|synthetic-state|1349245/)
  }
})

test('unrecognized descriptions cannot impersonate a tester or cancellation error', () => {
  for (const error_message of ['access_denied', '1349245', 'The user has not accepted the invite to test the app.']) {
    assert.equal(parse({ error_message }).failure, 'provider')
  }
})

test('successful callback codes remain available only in memory for normal completion', () => {
  const returned = parse({ code: 'synthetic-code', state: 's'.repeat(43) })
  assert.equal(returned.code, 'synthetic-code')
  assert.equal(returned.state, 's'.repeat(43))
  assert.equal(returned.failure, '')
  assert.equal(returned.denied, false)
})
