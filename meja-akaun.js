/* Pelan, angka dan import. Bukan bukti identiti dan bukan penyulitan. */
(function (root, factory) {
  const api = factory()
  if (typeof module === 'object' && module.exports) module.exports = api
  root.MejaAkaun = api
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict'

  const WANG = /^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/
  const BILANG = /^(?:0|[1-9]\d{0,9})$/
  const TARIKH = /^\d{4}-\d{2}-\d{2}$/
  const KUNCI = /^[A-Za-z0-9_-]{16,64}$/
  const SLOT = ['pelan-telefon', 'angka-telefon', 'pelan-kuarantin', 'angka-kuarantin']
  const LABEL = 'Dimasukkan sendiri. Bukan rekod kewangan yang disahkan.'

  function hariMalaysia(ms) {
    const tarikh = ms instanceof Date ? ms : new Date(typeof ms === 'number' ? ms : Date.now())
    const bahagian = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Kuala_Lumpur',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(tarikh)
    const peta = {}
    for (const item of bahagian) peta[item.type] = item.value
    return `${peta.year}-${peta.month}-${peta.day}`
  }

  function pecah(iso) {
    if (typeof iso !== 'string' || !TARIKH.test(iso)) return null
    const [tahun, bulan, hari] = iso.split('-').map(Number)
    const tarikh = new Date(Date.UTC(tahun, bulan - 1, hari))
    if (tarikh.getUTCFullYear() !== tahun || tarikh.getUTCMonth() !== bulan - 1 || tarikh.getUTCDate() !== hari) return null
    if (tahun < 2000 || tahun > 2100) return null
    return tarikh
  }

  function tulisHari(tarikh) {
    const bulan = String(tarikh.getUTCMonth() + 1).padStart(2, '0')
    const hari = String(tarikh.getUTCDate()).padStart(2, '0')
    return `${tarikh.getUTCFullYear()}-${bulan}-${hari}`
  }

  function isninMinggu(iso) {
    const tarikh = pecah(iso)
    if (!tarikh) return null
    const jarak = (tarikh.getUTCDay() + 6) % 7
    tarikh.setUTCDate(tarikh.getUTCDate() - jarak)
    return tulisHari(tarikh)
  }

  function ahadMinggu(isnin) {
    const tarikh = pecah(isnin)
    if (!tarikh || tarikh.getUTCDay() !== 1) return null
    tarikh.setUTCDate(tarikh.getUTCDate() + 6)
    return tulisHari(tarikh)
  }

  function tolakHari(iso, hari) {
    const tarikh = pecah(iso)
    if (!tarikh || !Number.isInteger(hari)) return null
    tarikh.setUTCDate(tarikh.getUTCDate() - hari)
    return tulisHari(tarikh)
  }

  function sahTarikh(iso, hariIni) {
    const tarikh = pecah(iso)
    const akhir = pecah(hariIni)
    if (!tarikh || !akhir) return false
    return tarikh.getTime() >= Date.UTC(2000, 0, 1) && tarikh.getTime() <= akhir.getTime()
  }

  function sahWang(nilai) {
    if (typeof nilai !== 'string' || !WANG.test(nilai)) return null
    const [penuh, sen = ''] = nilai.split('.')
    return `${penuh}.${(sen + '00').slice(0, 2)}`
  }

  function sahBilang(nilai) {
    return typeof nilai === 'string' && BILANG.test(nilai) ? nilai : null
  }

  function sahPasangan(jenis, unit, nilai) {
    if (jenis === 'hasil' || jenis === 'kos' || jenis === 'lain') {
      if (unit === 'MYR') {
        const canon = sahWang(nilai)
        return canon ? { ok: true, nilai: canon } : { ok: false, ralat: 'nilai' }
      }
      if (jenis === 'lain' && unit === 'unit') {
        const canon = sahBilang(nilai)
        return canon ? { ok: true, nilai: canon } : { ok: false, ralat: 'nilai' }
      }
      if (unit === 'MYR' || unit === 'unit') return { ok: false, ralat: 'unit_tidak_sah' }
    }
    return { ok: false, ralat: 'bentuk' }
  }

  function sahNota(nota) {
    if (nota == null) return ''
    if (typeof nota !== 'string') return null
    const bersih = nota.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim()
    if (bersih.length > 240) return null
    return bersih
  }

  function integer(nilai, kosong) {
    if (typeof nilai !== 'number' || !Number.isInteger(nilai) || nilai < kosong || nilai > 1000000) return null
    return nilai
  }

  function capTeks(teks) {
    return String(teks || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase('ms')
  }

  function teksTugas(nilai) {
    if (typeof nilai !== 'string') return null
    const bersih = nilai.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim()
    if (bersih.length < 2 || bersih.length > 160) return null
    return bersih
  }

  function kunciIdem() {
    const bait = new Uint8Array(16)
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) crypto.getRandomValues(bait)
    else for (let i = 0; i < bait.length; i += 1) bait[i] = Math.floor(Math.random() * 256)
    return Array.from(bait, (n) => n.toString(16).padStart(2, '0')).join('')
  }

  function kunciTunda(id, slot) {
    if (typeof id !== 'string' || !/^[a-f0-9]{32}$/.test(id) || !SLOT.includes(slot)) return null
    return `beshare-import.${id}.${slot}`
  }

  function stabil(nilai) {
    if (Array.isArray(nilai)) return nilai.map(stabil)
    if (nilai && typeof nilai === 'object') {
      return Object.keys(nilai).sort().reduce((obj, kunci) => {
        obj[kunci] = stabil(nilai[kunci])
        return obj
      }, {})
    }
    return nilai
  }

  function samaBadan(kiri, kanan) {
    return JSON.stringify(stabil(kiri)) === JSON.stringify(stabil(kanan))
  }

  function badanTugas(minggu, teks, versi) {
    const bersih = teksTugas(teks)
    const nombor = integer(versi, 0)
    if (!pecah(minggu) || pecah(minggu).getUTCDay() !== 1 || !bersih || nombor === null) return null
    return { minggu_mula: minggu, teks: bersih, versi: nombor }
  }

  function badanSiap(siap, versi) {
    const nombor = integer(versi, 1)
    if (typeof siap !== 'boolean' || nombor === null) return null
    return { siap, versi: nombor }
  }

  function badanPadam(versi) {
    const nombor = integer(versi, 1)
    return nombor === null ? null : { versi: nombor }
  }

  function badanAngka(input) {
    const pasangan = sahPasangan(input && input.jenis, input && input.unit, input && input.nilai)
    const nota = sahNota(input && input.nota)
    if (!pasangan.ok || nota === null || !sahTarikh(input && input.tarikh, input && input.hariIni)) return null
    const badan = {
      tarikh: input.tarikh,
      jenis: input.jenis,
      nilai: pasangan.nilai,
      unit: input.unit,
      nota,
    }
    if (input.sahkan === true) badan.sahkan_pendua = true
    if (input.versi != null) {
      const nombor = integer(input.versi, 1)
      if (nombor === null) return null
      badan.versi = nombor
    }
    return badan
  }

  function tugasanImport(senarai) {
    if (!Array.isArray(senarai) || !senarai.length || senarai.length > 30) return null
    const bersih = []
    const dilihat = new Set()
    for (const item of senarai) {
      if (!item || typeof item.siap !== 'boolean') return null
      const teks = teksTugas(item.teks)
      if (!teks) return null
      const cap = capTeks(teks)
      if (dilihat.has(cap)) continue
      dilihat.add(cap)
      bersih.push({ teks, siap: item.siap })
    }
    return bersih.length ? bersih : null
  }

  function badanImportPelan(input) {
    if (!input || input.persetujuan !== true || input.milik !== true) return null
    if (typeof input.kunci !== 'string' || !KUNCI.test(input.kunci)) return null
    const tugasan = tugasanImport(input.tugasan)
    const versi = integer(input.versi, 0)
    const tindakan = input.tindakan || ''
    if (!tugasan || versi === null || (tindakan !== '' && tindakan !== 'tambah_baharu')) return null
    if (!pecah(input.minggu) || pecah(input.minggu).getUTCDay() !== 1) return null
    return {
      persetujuan: true,
      jenis: 'pelan',
      kunci_idem: input.kunci,
      minggu_mula: input.minggu,
      versi,
      tindakan,
      tugasan,
    }
  }

  function badanImportAngka(input) {
    if (!input || input.persetujuan !== true || input.milik !== true) return null
    if (typeof input.kunci !== 'string' || !KUNCI.test(input.kunci)) return null
    if (!Array.isArray(input.rekod) || !input.rekod.length || input.rekod.length > 30) return null
    const rekod = []
    for (const item of input.rekod) {
      const badan = badanAngka({ ...item, hariIni: input.hariIni })
      if (!badan) return null
      delete badan.sahkan_pendua
      delete badan.versi
      rekod.push(badan)
    }
    const hasil = {
      persetujuan: true,
      jenis: 'angka',
      kunci_idem: input.kunci,
      rekod,
    }
    if (input.sahkan === true) hasil.sahkan_pendua = true
    return hasil
  }

  function idSah(id) {
    return typeof id === 'string' && /^[a-f0-9]{32}$/.test(id)
  }

  function idImportPelan(idSebelum, pelan, tugasan) {
    if (!Array.isArray(idSebelum) || !Array.isArray(tugasan) || !tugasan.length) return null
    const lama = new Set(idSebelum.filter(idSah))
    const baharu = []
    const dilihat = new Set()
    for (const item of (pelan && pelan.tugasan) || []) {
      if (!item || !idSah(item.id) || lama.has(item.id) || dilihat.has(item.id)) continue
      dilihat.add(item.id)
      baharu.push(item)
    }
    const guna = new Set()
    const padanan = []
    for (const item of tugasan) {
      const row = baharu.find((calon) => !guna.has(calon.id) && capTeks(calon.teks) === capTeks(item.teks))
      if (!row) return null
      guna.add(row.id)
      padanan.push(row.id)
    }
    return padanan
  }

  function idImportAngka(jawapan, dihantar) {
    const rekod = jawapan && jawapan.rekod
    if (!Array.isArray(rekod) || !Array.isArray(dihantar) || !dihantar.length || rekod.length !== dihantar.length) return null
    const padanan = []
    const dilihat = new Set()
    for (let i = 0; i < rekod.length; i += 1) {
      const row = rekod[i]
      const cadang = dihantar[i]
      if (!row || !cadang || !idSah(row.id) || dilihat.has(row.id) || row.status !== 'dimasukkan_pengguna') return null
      if (row.tarikh !== cadang.tarikh || row.jenis !== cadang.jenis || row.unit !== cadang.unit || row.nilai !== cadang.nilai) return null
      dilihat.add(row.id)
      padanan.push(row.id)
    }
    return padanan
  }

  function sahkanIdPelan(pelan, idDiharap) {
    if (!Array.isArray(idDiharap) || !idDiharap.length || !idDiharap.every(idSah)) return false
    const ada = new Set(((pelan && pelan.tugasan) || []).map((item) => item && item.id))
    return idDiharap.every((id) => ada.has(id))
  }

  function sahkanIdAngka(senarai, idDiharap) {
    if (!Array.isArray(idDiharap) || !idDiharap.length || !idDiharap.every(idSah)) return false
    const ada = new Set((senarai || []).filter((item) => item && item.status === 'dimasukkan_pengguna').map((item) => item.id))
    return idDiharap.every((id) => ada.has(id))
  }

  function bolehBuangDraf(asal, kini) {
    return typeof asal === 'string' && asal === kini
  }

  function bacaJson(mentah) {
    if (typeof mentah !== 'string' || !mentah) return null
    try {
      const data = JSON.parse(mentah)
      return data && typeof data === 'object' ? data : null
    } catch {
      return null
    }
  }

  function bacaTugasanDraf(mentah) {
    const data = bacaJson(mentah)
    if (!data || !Array.isArray(data.tugasan)) return []
    return data.tugasan.map((item) => {
      const teks = teksTugas(item && item.teks)
      if (!teks || typeof item.siap !== 'boolean') return null
      return { teks, siap: item.siap }
    }).filter(Boolean).slice(0, 30)
  }

  function bacaAngkaDraf(mentah) {
    const data = bacaJson(mentah)
    if (!data) return []
    const hasil = []
    if (typeof data.hasil === 'string' && data.hasil) hasil.push({ jenis: 'hasil', nilai: data.hasil })
    if (typeof data.kos === 'string' && data.kos) hasil.push({ jenis: 'kos', nilai: data.kos })
    return hasil
  }

  function buangTugasan(mentah, dipilih) {
    const data = bacaJson(mentah)
    if (!data || !Array.isArray(data.tugasan)) return mentah
    const caps = new Set((dipilih || []).map((item) => capTeks(item.teks || item)))
    data.tugasan = data.tugasan.filter((item) => !caps.has(capTeks(item && item.teks)))
    return data.tugasan.length ? JSON.stringify(data) : null
  }

  function buangAngka(mentah, jenis) {
    const data = bacaJson(mentah)
    if (!data) return mentah
    for (const nama of jenis || []) {
      if (nama === 'hasil' || nama === 'kos') delete data[nama]
    }
    if (!data.hasil && !data.kos) return null
    return JSON.stringify(data)
  }

  function jumlahKewangan(rekod, dari, hingga) {
    if (!pecah(dari) || !pecah(hingga) || dari > hingga) return null
    const dilihat = new Set()
    let hasil = 0
    let kos = 0
    let adaHasil = false
    let adaKos = false
    for (const item of rekod || []) {
      if (!item || !idSah(item.id) || dilihat.has(item.id)) continue
      dilihat.add(item.id)
      if (item.status !== 'dimasukkan_pengguna' || item.unit !== 'MYR') continue
      if (item.tarikh < dari || item.tarikh > hingga) continue
      const canon = sahWang(item.nilai)
      if (!canon) continue
      const sen = Math.round(Number(canon) * 100)
      if (item.jenis === 'hasil') {
        adaHasil = true
        hasil += sen
      } else if (item.jenis === 'kos') {
        adaKos = true
        kos += sen
      }
    }
    return {
      hasil: adaHasil ? (hasil / 100).toFixed(2) : null,
      kos: adaKos ? (kos / 100).toFixed(2) : null,
    }
  }

  function jumlahHasilMinggu(rekod, isnin, ahad) {
    const jumlah = jumlahKewangan(rekod, isnin, ahad)
    return jumlah ? jumlah.hasil : null
  }

  function labelTempoh(dari, hingga, jumlah) {
    if (!jumlah || !pecah(dari) || !pecah(hingga) || dari > hingga) return null
    const hasil = jumlah.hasil == null ? 'Tiada hasil MYR' : `Hasil MYR RM ${jumlah.hasil}`
    const kos = jumlah.kos == null ? 'Tiada kos MYR' : `Kos MYR RM ${jumlah.kos}`
    return `${dari} hingga ${hingga}. ${hasil}. ${kos}. Unit bukan MYR tidak dicampurkan. ${LABEL}`
  }

  return {
    LABEL,
    hariMalaysia,
    isninMinggu,
    ahadMinggu,
    tolakHari,
    sahTarikh,
    sahPasangan,
    sahNota,
    capTeks,
    kunciIdem,
    kunciTunda,
    samaBadan,
    badanTugas,
    badanSiap,
    badanPadam,
    badanAngka,
    badanImportPelan,
    badanImportAngka,
    idSah,
    idImportPelan,
    idImportAngka,
    sahkanIdPelan,
    sahkanIdAngka,
    bolehBuangDraf,
    jumlahKewangan,
    labelTempoh,
    bacaTugasanDraf,
    bacaAngkaDraf,
    buangTugasan,
    buangAngka,
    jumlahHasilMinggu,
  }
})
