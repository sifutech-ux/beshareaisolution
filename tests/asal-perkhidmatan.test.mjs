import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import vm from 'node:vm'

const require = createRequire(import.meta.url)
const asal = require('../akaun-asal.js')

function baca(nama) {
  return readFileSync(new URL(`../${nama}`, import.meta.url), 'utf8')
}

const penasihat = baca('penasihat/penasihat.js')
const akaun = baca('akaun/akaun.js')
const peta = baca('akaun-asal.js')

test('peta asal hanya pada akaun-asal dan dimuatkan sebelum skrip', () => {
  assert.match(peta, /function asalPerkhidmatan/)
  assert.match(peta, /includes\('beshare-ipo-bot\.onrender\.com'\)/)
  assert.equal(peta.includes('https://beshare-ipo-bot.onrender.com'), false)
  for (const src of [penasihat, akaun]) {
    assert.equal(src.includes('function asalPerkhidmatan'), false)
    assert.equal(src.includes('https://akaun.beshareaisolution.com'), false)
    assert.equal(src.includes('https://beshare-ipo-bot.onrender.com'), false)
    assert.match(src, /BeshareAsal\.asalPerkhidmatan\(location\.origin\)/)
  }
  const halamanAkaun = baca('akaun/index.html')
  const halamanMeja = baca('penasihat/index.html')
  assert.ok(halamanAkaun.indexOf('akaun-asal.js') < halamanAkaun.indexOf('akaun.js'))
  assert.ok(halamanMeja.indexOf('akaun-asal.js') < halamanMeja.indexOf('penasihat.js'))
  assert.equal(baca('penasihat/penasihat.css').includes('akaun-asal'), false)
})

test('produksi dan staging tidak bercampur dan asal asing senyap', () => {
  const produksi = asal.asalPerkhidmatan('https://beshareaisolution.com')
  const www = asal.asalPerkhidmatan('https://www.beshareaisolution.com')
  const staging = asal.asalPerkhidmatan('https://staging.beshareaisolution.com')
  assert.equal(produksi.akaun, 'https://akaun.beshareaisolution.com')
  assert.equal(produksi.nasihat, 'https://ai-video-saas-ten.vercel.app')
  assert.deepEqual(www, produksi)
  assert.equal(staging.akaun, 'https://akaun-staging.beshareaisolution.com')
  assert.equal(staging.nasihat, 'https://nasihat-staging.beshareaisolution.com')
  assert.equal(staging.akaun.includes('ai-video-saas-ten'), false)
  assert.equal(produksi.akaun.includes('staging'), false)
  for (const origin of [
    'https://evil.example',
    'https://beshare-ipo-bot.onrender.com',
    'http://beshareaisolution.com',
    'http://localhost:3000',
    '',
    null,
  ]) {
    assert.equal(asal.asalPerkhidmatan(origin), null, String(origin))
  }
  assert.deepEqual(asal.asalPerkhidmatan('http://127.0.0.1:8000'), {
    akaun: 'http://127.0.0.1:8767',
    nasihat: 'http://127.0.0.1:3210',
  })
})

test('lima modul kekal dan token tidak dihantar tanpa asal', () => {
  assert.match(penasihat, /const SKRIN_MEJA = \['utama', 'nasihat', 'pelan', 'angka', 'profil'\]/)
  for (const [nama, penanda] of [
    ['async function mintaAkaun', 'if (!AKAUN) throw'],
    ['async function simpanAkaun', 'if (!AKAUN'],
    ['async function mainSuara', 'if (!SUARA_API)'],
    ['async function hantarRakaman', 'if (!DENGAR_API)'],
  ]) {
    const badan = penasihat.slice(penasihat.indexOf(nama))
    assert.ok(badan.indexOf(penanda) !== -1 && badan.indexOf(penanda) < badan.indexOf('fetch('), nama)
  }
  const nasihat = penasihat.slice(penasihat.indexOf('if (!NASIHAT_API || !sesi)'))
  assert.ok(nasihat.indexOf('if (!NASIHAT_API || !sesi)') < nasihat.indexOf('fetch(NASIHAT_API'))
})

function muatAkaun(origin, denganPeta = true) {
  const panggilan = []
  const elemen = {}
  function buat(id) {
    if (!elemen[id]) {
      elemen[id] = {
        hidden: true,
        textContent: '',
        value: '',
        disabled: false,
        autocomplete: '',
        classList: { toggle() {} },
        addEventListener(jenis, fungsi) { this[`on${jenis}`] = fungsi },
      }
    }
    return elemen[id]
  }
  const konteks = {
    location: { origin, hostname: new URL(origin).hostname, search: '', href: `${origin}/akaun/` },
    localStorage: { getItem() { return null }, setItem() {}, removeItem() {} },
    document: { getElementById: buat },
    fetch: async (url) => {
      panggilan.push(String(url))
      return { ok: false, json: async () => ({ error: 'ujian' }) }
    },
    BeshareDraf: { kuarantinLegasi() {} },
    URLSearchParams,
  }
  konteks.globalThis = konteks
  vm.createContext(konteks)
  if (denganPeta) vm.runInContext(peta, konteks)
  vm.runInContext(akaun, konteks)
  return { panggilan, elemen }
}

test('borang akaun tidak merangkak ke hos yang tidak diluluskan', async () => {
  const asing = muatAkaun('https://preview.example')
  asing.elemen.email.value = 'aminah@kedai.my'
  asing.elemen.password.value = 'kata-rahsia'
  await asing.elemen.borang.onsubmit({ preventDefault() {} })
  assert.equal(asing.panggilan.length, 0)
  assert.match(asing.elemen.ralat.textContent, /tidak disambungkan/)

  const produksi = muatAkaun('https://beshareaisolution.com')
  produksi.elemen.email.value = 'aminah@kedai.my'
  produksi.elemen.password.value = 'kata-rahsia'
  await produksi.elemen.borang.onsubmit({ preventDefault() {} })
  assert.equal(produksi.panggilan.length, 1)
  assert.equal(produksi.panggilan[0].startsWith('https://akaun.beshareaisolution.com/api/akaun/'), true)
  assert.equal(produksi.panggilan[0].includes('beshare-ipo-bot'), false)

  const staging = muatAkaun('https://staging.beshareaisolution.com')
  staging.elemen.email.value = 'aminah@kedai.my'
  staging.elemen.password.value = 'kata-rahsia'
  await staging.elemen.borang.onsubmit({ preventDefault() {} })
  assert.equal(staging.panggilan[0].startsWith('https://akaun-staging.beshareaisolution.com/api/akaun/'), true)

  const tanpaPeta = muatAkaun('https://beshareaisolution.com', false)
  tanpaPeta.elemen.email.value = 'aminah@kedai.my'
  tanpaPeta.elemen.password.value = 'kata-rahsia'
  await tanpaPeta.elemen.borang.onsubmit({ preventDefault() {} })
  assert.equal(tanpaPeta.panggilan.length, 0)
})

function elemenLonggar() {
  const el = {
    hidden: false,
    textContent: '',
    value: '',
    disabled: false,
    checked: false,
    className: '',
    id: '',
    dataset: {},
    style: { setProperty() {} },
    classList: { add() {}, remove() {}, toggle() {}, contains() { return false } },
    addEventListener() {},
    setAttribute() {},
    getAttribute() { return null },
    append() {},
    replaceChildren() {},
    querySelector() { return elemenLonggar() },
    querySelectorAll() { return [] },
    closest() { return elemenLonggar() },
    focus() {},
  }
  return el
}

test('meja pada asal asing tidak merangkak walaupun sesi ada', () => {
  const panggilan = []
  const badan = elemenLonggar()
  const akar = elemenLonggar()
  const konteks = {
    location: {
      origin: 'https://preview.example',
      hostname: 'preview.example',
      search: '',
      href: 'https://preview.example/penasihat/',
      hash: '',
    },
    localStorage: {
      getItem(kunci) {
        if (kunci === 'beshare-akaun') return JSON.stringify({ token: 'token-ujian', email: 'aminah@kedai.my' })
        return null
      },
      setItem() {},
      removeItem() {},
    },
    sessionStorage: { getItem() { return null }, setItem() {}, removeItem() {} },
    document: {
      body: badan,
      documentElement: akar,
      activeElement: null,
      getElementById() { return elemenLonggar() },
      querySelector() { return elemenLonggar() },
      querySelectorAll() { return [] },
    },
    fetch: async (url) => {
      panggilan.push(String(url))
      throw new Error('rangkaian tidak patut')
    },
    history: { replaceState() {} },
    window: {},
    BeshareDraf: { kuarantinLegasi() {} },
    MejaAkaun: {},
    URLSearchParams,
    ResizeObserver: undefined,
  }
  konteks.window = konteks
  konteks.globalThis = konteks
  konteks.matchMedia = () => ({ matches: false, addEventListener() {} })
  konteks.visualViewport = null
  vm.createContext(konteks)
  vm.runInContext(peta, konteks)
  vm.runInContext(penasihat, konteks)
  assert.equal(panggilan.length, 0)
})
