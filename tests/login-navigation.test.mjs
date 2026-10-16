import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import test from 'node:test'

const source = readFileSync(new URL('../akaun/akaun.js', import.meta.url), 'utf8')
const desk = readFileSync(new URL('../penasihat/penasihat.js', import.meta.url), 'utf8')
const key = 'beshare-akaun'
const session = JSON.stringify({ token: 'synthetic-token', email: 'test@example.test' })
function page({ stored, search = '', ok = true, token = 'synthetic-token', unavailable = false, storageFails = false } = {}) {
  const values = new Map(stored === undefined ? [] : [[key, stored]])
  const elements = new Map()
  const redirects = [], calls = []
  function element(id) {
    if (!elements.has(id)) elements.set(id, { value: '', hidden: false, disabled: false, classList: { toggle() {} }, addEventListener(name, fn) { this[name] = fn } })
    return elements.get(id)
  }
  const context = vm.createContext({
    location: { origin: 'https://fixture.example', search, replace(url) { redirects.push(url) } },
    document: { getElementById: element },
    BeshareAsal: { asalPerkhidmatan() { return { akaun: 'https://account.example.test' } } },
    BeshareDraf: { kuarantinLegasi() {} },
    localStorage: { getItem(k) { return values.get(k) ?? null }, setItem(k, v) { if (storageFails) throw new Error('storage unavailable'); values.set(k, v) }, removeItem(k) { values.delete(k) } },
    sessionStorage: {},
    fetch: async (url) => { calls.push(url); if (unavailable) throw new Error('offline'); return { ok, json: async () => ({ token, email: 'test@example.test', error: 'Login gagal' }) } },
  })
  vm.runInContext(source, context)
  return { context, redirects, calls, values, element, async submit() { element('email').value = 'test@example.test'; element('password').value = 'synthetic-password'; await element('borang').submit({ preventDefault() {} }) } }
}

for (const search of ['', '?selepas=penasihat', '?selepas=https://evil.example', '?selepas=//evil.example']) {
  test(`successful login goes to dashboard: ${search || 'default'}`, async () => {
    const p = page({ search }); await p.submit()
    assert.deepEqual(p.redirects, ['../penasihat/#utama'])
    assert.equal(JSON.parse(p.values.get(key)).token, 'synthetic-token')
    assert.equal(p.element('password').value, '')
  })
}
test('registration uses the same dashboard destination', async () => {
  const p = page(); p.element('mod-daftar').click(); await p.submit()
  assert.match(p.calls[0], /\/daftar$/)
  assert.deepEqual(p.redirects, ['../penasihat/#utama'])
})
test('stored session proceeds to dashboard validation without another login', () => {
  const p = page({ stored: session })
  assert.deepEqual(p.redirects, ['../penasihat/#utama'])
  assert.equal(p.calls.length, 0)
})
for (const stored of ['{', 'null', JSON.stringify({ token: '', email: 'test@example.test' })]) {
  test(`invalid local session stays at login: ${stored}`, () => {
    const p = page({ stored }); assert.equal(p.redirects.length, 0); assert.equal(p.element('borang').hidden, false)
  })
}
for (const options of [{ ok: false }, { token: '' }, { unavailable: true }, { storageFails: true }]) {
  test(`failed login never navigates: ${JSON.stringify(options)}`, async () => {
    const p = page(options); await p.submit()
    assert.equal(p.redirects.length, 0); assert.equal(p.values.has(key), false)
    assert.equal(p.element('hantar').disabled, false)
  })
}
test('account logout removes local session and password', () => {
  const p = page(); p.values.set(key, session); p.element('password').value = 'secret-fixture'
  p.element('keluar').click()
  assert.equal(p.values.has(key), false); assert.equal(p.element('password').value, '')
})

// Exercise the actual dashboard entry function with UI collaborators stubbed.
const entry = desk.slice(desk.indexOf('async function mula()'), desk.indexOf('function bukaAudio()'))
for (const scenario of ['guest', 'expired', 'offline', 'active', 'onboarding']) {
  test(`existing dashboard protection: ${scenario}`, async () => {
    const screens = []; let removed = false; let calls = 0
    const context = vm.createContext({
      AKAUN: 'https://account.example.test', akaunId: null, keadaan: null, mesejVersi: null,
      localStorage: {}, sessionStorage: {}, status: {}, location: { hash: '#utama' }, SKRIN_MEJA: ['utama', 'nasihat', 'pelan', 'angka', 'profil'],
      BeshareDraf: { kuarantinLegasi() {}, idAkaunSah: () => true, versiSah: () => true },
      sesiAkaun: () => scenario !== 'guest', pergi: s => screens.push(s), tunjukPintu: () => screens.push('pintu'),
      kepalaAkaun: () => ({}), lupusSesi: () => { removed = true }, jalurAkaun() {}, lukis() {},
      rekodSah: value => value, tunjukBorang: () => screens.push('mula'),
      fetch: async () => { calls++; if (scenario === 'offline') throw new Error('offline'); return { ok: scenario !== 'expired', status: scenario === 'expired' ? 401 : 200, json: async () => ({ akaun_id: 'synthetic-account', mesej_versi: 1, penasihat: scenario === 'onboarding' ? null : { nama: 'Synthetic shop' } }) } },
    })
    await vm.runInContext(`${entry}\nmula()`, context)
    assert.equal(screens.at(-1), { guest: 'pintu', expired: 'pintu', offline: 'muat', active: 'utama', onboarding: 'mula' }[scenario])
    assert.equal(removed, scenario === 'expired')
    if (scenario === 'guest') assert.equal(calls, 0)
  })
}

test('dashboard logout clears session and in-memory account data before leaving', () => {
  const values = new Map([[key, session], ['beshare-tema', 'gelap']])
  const context = vm.createContext({
    AKAUN_KUNCI: key, localStorage: { removeItem(k) { values.delete(k) } }, location: {},
    keadaan: { nama: 'Synthetic shop' }, akaunId: 'synthetic-account', mesejVersi: 1,
    konflikVersi: true, salinanKonflik: {}, pelanAkaun: {}, pelanDimuat: true, pelanGagal: true,
    pelanKonflik: {}, angkaAkaun: [{}], angkaDimuat: true, angkaGagal: true, angkaKonflik: {}, angkaSunting: {}, angkaPendua: {},
  })
  const logout = desk.slice(desk.indexOf('function lupusSesi()'), desk.indexOf('function tunjukKonflik('))
  vm.runInContext(`${logout}\nkeluarAkaun()`, context)
  assert.equal(values.has(key), false)
  assert.equal(values.get('beshare-tema'), 'gelap')
  assert.equal(context.keadaan, null)
  assert.equal(context.akaunId, null)
  assert.equal(context.angkaAkaun.length, 0)
  assert.equal(context.location.href, '../akaun/')
})
