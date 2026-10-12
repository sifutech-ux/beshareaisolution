import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import fs from 'node:fs'
import test from 'node:test'

const require = createRequire(import.meta.url)
const draf = require('../stor-draf.js')

function stor(awal = {}) {
  const data = { ...awal }
  return {
    data,
    getItem(kunci) { return Object.prototype.hasOwnProperty.call(data, kunci) ? data[kunci] : null },
    setItem(kunci, nilai) { data[kunci] = String(nilai) },
    removeItem(kunci) { delete data[kunci] },
  }
}

const ID_A = 'a'.repeat(32)
const ID_B = 'b'.repeat(32)
const PROFIL = JSON.stringify({
  nama: 'Kedai Aminah',
  jualan: 'Kuih raya untuk jiran taman',
  peringkat: 'jual',
  mesej: [{ dari: 'penasihat', teks: 'Rahsia akaun Aminah jangan dipindah.' }],
})

test('kuarantin menyalin kemudian memadam asal hanya selepas sah', () => {
  const kekal = stor({
    'beshare-penasihat': PROFIL,
    'beshare-pelan-draf': '{"tugasan":[{"id":"1","teks":"Hantar kotak","siap":false}]}',
    'beshare-angka-draf': '{"hasil":"120.50","kos":"40.00"}',
  })
  const sesi = stor({ 'beshare-penasihat': PROFIL })
  const hasil = draf.kuarantinLegasi(kekal, sesi)
  assert.equal(hasil.siap, true)
  assert.equal(kekal.getItem('beshare-penasihat'), null)
  assert.equal(kekal.getItem('beshare-pelan-draf'), null)
  assert.equal(kekal.getItem('beshare-angka-draf'), null)
  assert.equal(sesi.getItem('beshare-penasihat'), null)
  const kuar = JSON.parse(kekal.getItem(draf.KUNCI_KUARANTIN))
  assert.equal(kuar.tanda, draf.TANDA)
  assert.match(kuar.nota, /Jangan muat naik/)
  assert.equal(kuar.salinan.profil, PROFIL)
  assert.equal(kuar.salinan.profilSesi, PROFIL)
  assert.match(kuar.salinan.pelan, /Hantar kotak/)
  assert.match(kuar.salinan.angka, /120\.50/)
})

test('pemindahan terganggu tidak memadam asal', () => {
  const kekal = stor({ 'beshare-penasihat': PROFIL })
  const sesi = stor()
  kekal.setItem = () => { throw new Error('stor penuh') }
  const hasil = draf.kuarantinLegasi(kekal, sesi)
  assert.equal(hasil.siap, false)
  assert.equal(kekal.data['beshare-penasihat'], PROFIL)
  assert.equal(kekal.getItem(draf.KUNCI_KUARANTIN), null)
})

test('salinan kuarantin yang tidak sepadan tidak memadam asal', () => {
  const kekal = stor({ 'beshare-penasihat': PROFIL })
  const sesi = stor()
  kekal.setItem = (kunci, nilai) => { kekal.data[kunci] = `${nilai} rosak` }
  const hasil = draf.kuarantinLegasi(kekal, sesi)
  assert.equal(hasil.siap, false)
  assert.equal(kekal.data['beshare-penasihat'], PROFIL)
})

test('kuarantin sedia ada tidak ditulis semula oleh draf lama yang lain', () => {
  const pelan = '{"tugasan":[{"id":"1","teks":"PELAN-LAMA","siap":false}]}'
  const kedua = '{"nama":"Draf kedua"}'
  const angka = '{"hasil":"9.00","kos":"1.00"}'
  const kekal = stor({
    [draf.KUNCI_KUARANTIN]: JSON.stringify({
      tanda: draf.TANDA,
      nota: 'Draf lama tidak dipercayai. Bukan milik akaun yang sedang masuk. Jangan muat naik.',
      salinan: { profil: PROFIL, pelan },
      simpanan: ['JEJAK-LAMA'],
    }),
    'beshare-penasihat': kedua,
    'beshare-angka-draf': angka,
  })
  const hasil = draf.kuarantinLegasi(kekal, stor())
  assert.equal(hasil.siap, true)
  assert.equal(kekal.getItem('beshare-penasihat'), null)
  assert.equal(kekal.getItem('beshare-angka-draf'), null)
  const kuar = JSON.parse(kekal.getItem(draf.KUNCI_KUARANTIN))
  assert.equal(kuar.salinan.profil, PROFIL)
  assert.equal(kuar.salinan.pelan, pelan)
  assert.equal(kuar.salinan.angka, angka)
  assert.ok(kuar.simpanan.includes(kedua))
  assert.ok(kuar.simpanan.includes('JEJAK-LAMA'))
})

test('kuarantin yang tidak boleh dibaca tidak ditulis semula', () => {
  const kekal = stor({
    [draf.KUNCI_KUARANTIN]: 'bukan-json',
    'beshare-penasihat': PROFIL,
  })
  const hasil = draf.kuarantinLegasi(kekal, stor())
  assert.equal(hasil.siap, false)
  assert.equal(kekal.getItem(draf.KUNCI_KUARANTIN), 'bukan-json')
  assert.equal(kekal.getItem('beshare-penasihat'), PROFIL)
})

test('bacaan semula yang gagal memulihkan kuarantin sedia ada', () => {
  const lama = JSON.stringify({
    tanda: draf.TANDA,
    nota: 'Draf lama tidak dipercayai. Bukan milik akaun yang sedang masuk. Jangan muat naik.',
    salinan: { profil: PROFIL },
    simpanan: [],
  })
  const kekal = stor({
    [draf.KUNCI_KUARANTIN]: lama,
    'beshare-penasihat': '{"nama":"Lain"}',
  })
  const tulen = kekal.setItem.bind(kekal)
  let tulis = 0
  kekal.setItem = (kunci, nilai) => {
    tulis += 1
    if (tulis === 1) kekal.data[kunci] = 'rosak'
    else tulen(kunci, nilai)
  }
  const hasil = draf.kuarantinLegasi(kekal, stor())
  assert.equal(hasil.siap, false)
  assert.equal(kekal.data['beshare-penasihat'], '{"nama":"Lain"}')
  assert.equal(kekal.data[draf.KUNCI_KUARANTIN], lama)
})

test('id akaun dan kunci hanya dari nilai pelayan yang sah', () => {
  assert.equal(draf.idAkaunSah(ID_A), true)
  assert.equal(draf.idAkaunSah('akaun-dari-stor'), false)
  assert.equal(draf.idAkaunSah(`${ID_A} `), false)
  assert.equal(draf.kunciSkop(ID_A, 'pelan'), `beshare-draf.${ID_A}.pelan`)
  assert.equal(draf.kunciSkop(ID_B, 'angka'), `beshare-draf.${ID_B}.angka`)
  assert.equal(draf.kunciSkop(ID_A, 'profil'), null)
  assert.equal(draf.kunciSkop('bukan-id', 'pelan'), null)
  assert.notEqual(draf.kunciSkop(ID_A, 'pelan'), draf.kunciSkop(ID_B, 'pelan'))
})

test('konflik versi mengekalkan teks yang belum ada pada pelayan', () => {
  const pelayan = {
    mesej: [{ dari: 'penasihat', teks: 'Salinan pelayan.' }],
  }
  const tempatan = {
    mesej: [
      { dari: 'penasihat', teks: 'Salinan pelayan.' },
      { dari: 'klien', teks: 'Harga baru minggu ini' },
    ],
  }
  const ekor = draf.ekorBelum(tempatan, pelayan)
  assert.equal(ekor.length, 1)
  assert.equal(ekor[0].teks, 'Harga baru minggu ini')
  const susun = draf.susunTeksBelum('catatan belum dihantar', ekor.map((item) => item.teks), 600)
  assert.match(susun.kotak, /catatan belum dihantar/)
  assert.match(susun.kotak, /Harga baru minggu ini/)
  assert.deepEqual(susun.tinggal, [])
  const badan = draf.badanSimpan(tempatan, 4)
  assert.equal(badan.versi, 4)
  assert.equal(draf.badanSimpan(tempatan, '4'), null)
  assert.equal(draf.badanSimpan(tempatan, 3.5), null)
})

test('halaman akaun dan penasihat tidak memuat naik atau membaca draf lama sebagai akaun semasa', () => {
  const penasihat = fs.readFileSync(new URL('../penasihat/penasihat.js', import.meta.url), 'utf8')
  const akaun = fs.readFileSync(new URL('../akaun/akaun.js', import.meta.url), 'utf8')
  const modul = fs.readFileSync(new URL('../stor-draf.js', import.meta.url), 'utf8')
  assert.match(penasihat, /\/api\/penasihat/)
  assert.doesNotMatch(penasihat, /\/api\/copilot\/nasihat/)
  assert.doesNotMatch(penasihat, /beshare-penasihat|beshare-pelan-draf|beshare-angka-draf/)
  assert.match(penasihat, /badanSimpan\(keadaan, mesejVersi\)/)
  assert.match(penasihat, /status === 409/)
  assert.match(penasihat, /status === 401/)
  assert.doesNotMatch(akaun, /removeItem\('beshare-penasihat'\)/)
  assert.match(akaun, /kuarantinLegasi/)
  assert.doesNotMatch(modul, /fetch\(/)
  assert.doesNotMatch(penasihat, /console\.(log|debug|info)/)
})
