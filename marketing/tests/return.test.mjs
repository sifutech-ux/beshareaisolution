import test from 'node:test'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
const require = createRequire(import.meta.url)
const { target } = require('../../akaun/kembali.js')

test('login returns to the named Marketing workspace', () => {
  assert.equal(target('?selepas=marketing'), '../marketing/')
  assert.equal(target('?selepas=penasihat'), '../penasihat/#utama')
})

test('external, malformed and arbitrary return URLs cannot become destinations', () => {
  for (const value of ['', '?selepas=https://example.com', '?selepas=//example.com',
    '?selepas=javascript:alert(1)', '?selepas=%2F%2Fevil.test', '?selepas=marketing/../admin',
    '?selepas=%00marketing', '?selepas=MARKETING', null, {}]) {
    assert.equal(target(value), '../penasihat/#utama')
  }
})
