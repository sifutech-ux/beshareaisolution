import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import test from 'node:test'

const require = createRequire(import.meta.url)
const meja = require('../meja-akaun.js')

const HARI = '2026-10-12'

test('minggu Malaysia bermula pada Isnin', () => {
  assert.equal(meja.isninMinggu('2026-10-12'), '2026-10-12')
  assert.equal(meja.isninMinggu('2026-10-13'), '2026-10-12')
  assert.equal(meja.isninMinggu('2026-10-18'), '2026-10-12')
  assert.equal(meja.isninMinggu('2026-10-11'), '2026-10-05')
  assert.equal(meja.ahadMinggu('2026-10-12'), '2026-10-18')
  assert.equal(meja.hariMalaysia(Date.UTC(2026, 9, 11, 16, 30)), '2026-10-12')
  assert.equal(meja.hariMalaysia(Date.UTC(2026, 9, 11, 15, 30)), '2026-10-11')
})

test('pasangan jenis dan unit ikut kontrak pelayan', () => {
  assert.equal(meja.sahPasangan('hasil', 'MYR', '120.5').nilai, '120.50')
  assert.equal(meja.sahPasangan('kos', 'MYR', '0').nilai, '0.00')
  assert.equal(meja.sahPasangan('hasil', 'unit', '2').ralat, 'unit_tidak_sah')
  assert.equal(meja.sahPasangan('lain', 'unit', '3').nilai, '3')
  assert.equal(meja.sahPasangan('lain', 'unit', '3.5').ralat, 'nilai')
  assert.equal(meja.sahPasangan('lain', 'MYR', '10').nilai, '10.00')
  assert.equal(meja.badanAngka({
    tarikh: '2026-10-11', jenis: 'hasil', nilai: '88', unit: 'MYR', nota: '  jualan  ', hariIni: HARI,
  }).nilai, '88.00')
  assert.equal(meja.badanAngka({
    tarikh: '2026-10-13', jenis: 'hasil', nilai: '88', unit: 'MYR', hariIni: HARI,
  }), null)
  assert.equal(meja.badanAngka({
    tarikh: '2026-10-12', jenis: 'kos', nilai: '1', unit: 'unit', hariIni: HARI,
  }), null)
})

test('import ditolak tanpa persetujuan dan pemilikan', () => {
  const asas = {
    milik: true,
    kunci: 'a'.repeat(16),
    minggu: '2026-10-12',
    versi: 0,
    tugasan: [{ teks: 'Hantar kotak', siap: false }],
  }
  assert.equal(meja.badanImportPelan({ ...asas, persetujuan: false }), null)
  assert.equal(meja.badanImportPelan({ ...asas, persetujuan: true, milik: false }), null)
  const badan = meja.badanImportPelan({ ...asas, persetujuan: true })
  assert.equal(badan.persetujuan, true)
  assert.equal(badan.kunci_idem, 'a'.repeat(16))
  assert.equal(meja.badanImportAngka({
    persetujuan: true,
    milik: false,
    kunci: 'b'.repeat(16),
    hariIni: HARI,
    rekod: [{ tarikh: HARI, jenis: 'hasil', nilai: '10', unit: 'MYR' }],
  }), null)
})

test('resit import guna id pelayan, bukan teks atau nilai semata-mata', () => {
  const idBaharu = 'a'.repeat(32)
  const idLama = 'b'.repeat(32)
  const tugasan = [{ teks: 'Hantar kotak', siap: false }]
  assert.deepEqual(meja.idImportPelan([], {
    tugasan: [{ id: idBaharu, teks: 'hantar   kotak', siap: true }],
  }, tugasan), [idBaharu])
  assert.equal(meja.idImportPelan([idBaharu], {
    tugasan: [{ id: idBaharu, teks: 'Hantar kotak', siap: false }],
  }, tugasan), null)
  assert.equal(meja.idImportPelan(null, {
    tugasan: [{ id: idBaharu, teks: 'Hantar kotak', siap: false }],
  }, tugasan), null)
  assert.equal(meja.sahkanIdPelan({ tugasan: [{ id: idLama, teks: 'Hantar kotak' }] }, [idBaharu]), false)
  assert.equal(meja.sahkanIdPelan({ tugasan: [{ id: idBaharu, teks: 'teks lain' }] }, [idBaharu]), true)
  const dihantar = [{ tarikh: HARI, jenis: 'hasil', unit: 'MYR', nilai: '10.00' }]
  assert.deepEqual(meja.idImportAngka({
    rekod: [{ id: idBaharu, status: 'dimasukkan_pengguna', ...dihantar[0] }],
  }, dihantar), [idBaharu])
  assert.equal(meja.idImportAngka({
    rekod: [{ status: 'dimasukkan_pengguna', ...dihantar[0] }],
  }, dihantar), null)
  assert.equal(meja.sahkanIdAngka([
    { id: idBaharu, status: 'ditarik_pengguna', ...dihantar[0] },
  ], [idBaharu]), false)
  const asal = JSON.stringify({ tugasan: [{ id: '1', teks: 'Hantar kotak', siap: false }, { id: '2', teks: 'Kekal', siap: true }], nota: 'draf' })
  const tinggal = meja.buangTugasan(asal, tugasan)
  assert.match(tinggal, /Kekal/)
  assert.doesNotMatch(tinggal, /Hantar kotak/)
  const angka = JSON.stringify({ hasil: '10.00', kos: '2.00' })
  assert.match(meja.buangAngka(angka, ['hasil']), /"kos":"2.00"/)
  assert.equal(meja.buangAngka(angka, ['hasil', 'kos']), null)
  assert.equal(meja.bolehBuangDraf(asal, asal), true)
  assert.equal(meja.bolehBuangDraf(asal, asal.replace('Hantar kotak', 'Teks baharu')), false)
  assert.equal(meja.bolehBuangDraf(null, null), false)
})

test('jumlah hasil hanya rekod pengguna dalam minggu', () => {
  const id = (n) => n.repeat(32)
  const rekod = [
    { id: id('a'), status: 'dimasukkan_pengguna', jenis: 'hasil', unit: 'MYR', tarikh: '2026-10-12', nilai: '10.50' },
    { id: id('a'), status: 'dimasukkan_pengguna', jenis: 'hasil', unit: 'MYR', tarikh: '2026-10-12', nilai: '10.50' },
    { id: id('b'), status: 'dimasukkan_pengguna', jenis: 'hasil', unit: 'MYR', tarikh: '2026-10-18', nilai: '1.00' },
    { id: id('c'), status: 'dimasukkan_pengguna', jenis: 'hasil', unit: 'MYR', tarikh: '2026-10-11', nilai: '99.00' },
    { id: id('d'), status: 'dimasukkan_pengguna', jenis: 'hasil', unit: 'MYR', tarikh: '2026-10-19', nilai: '7.00' },
    { id: id('e'), status: 'dimasukkan_pengguna', jenis: 'kos', unit: 'MYR', tarikh: '2026-10-12', nilai: '4.00' },
    { id: id('f'), status: 'dimasukkan_pengguna', jenis: 'lain', unit: 'unit', tarikh: '2026-10-12', nilai: '3' },
    { id: id('g'), status: 'ditarik_pengguna', jenis: 'hasil', unit: 'MYR', tarikh: '2026-10-13', nilai: '8.00' },
    { status: 'dimasukkan_pengguna', jenis: 'hasil', unit: 'MYR', tarikh: '2026-10-14', nilai: '5.00' },
  ]
  assert.equal(meja.jumlahHasilMinggu(rekod, '2026-10-12', '2026-10-18'), '11.50')
  assert.equal(meja.jumlahKewangan(rekod, '2026-10-12', '2026-10-18').kos, '4.00')
  assert.equal(meja.jumlahKewangan(rekod, '2026-07-14', '2026-10-12').hasil, '109.50')
  assert.equal(meja.tolakHari('2026-10-12', 90), '2026-07-14')
  const label = meja.labelTempoh('2026-07-14', '2026-10-12', meja.jumlahKewangan(rekod, '2026-07-14', '2026-10-12'))
  assert.match(label, /2026-07-14 hingga 2026-10-12/)
  assert.match(label, /Hasil MYR RM 109\.50/)
  assert.match(label, /Kos MYR RM 4\.00/)
  assert.match(label, /Unit bukan MYR tidak dicampurkan/)
  assert.equal(meja.jumlahHasilMinggu([], '2026-10-12', '2026-10-18'), null)
})

test('kunci idempotensi kekal untuk badan yang sama', () => {
  const kunci = meja.kunciIdem()
  assert.match(kunci, /^[a-f0-9]{32}$/)
  const id = 'a'.repeat(32)
  assert.equal(meja.kunciTunda(id, 'pelan-telefon'), `beshare-import.${id}.pelan-telefon`)
  assert.equal(meja.kunciTunda('bukan', 'pelan-telefon'), null)
  const badan = { jenis: 'pelan', tugasan: [{ teks: 'A', siap: false }], versi: 0 }
  assert.equal(meja.samaBadan(badan, { versi: 0, jenis: 'pelan', tugasan: [{ siap: false, teks: 'A' }] }), true)
  assert.equal(meja.samaBadan(badan, { ...badan, versi: 1 }), false)
  assert.equal(meja.badanTugas('2026-10-12', '  Hantar  ', 0).teks, 'Hantar')
  assert.equal(meja.badanTugas('2026-10-13', 'Hantar', 0), null)
  assert.equal(meja.badanSiap(true, 0), null)
})
