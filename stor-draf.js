/* Pemisahan draf telefon. Awalan kunci bukan penyulitan dan bukan bukti identiti. */
(function (root, factory) {
  const api = factory()
  if (typeof module === 'object' && module.exports) module.exports = api
  root.BeshareDraf = api
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict'

  const KUNCI_KUARANTIN = 'beshare-kuarantin'
  const TANDA = 'draf-tidak-dipercayai'
  const NOTA = 'Draf lama tidak dipercayai. Bukan milik akaun yang sedang masuk. Jangan muat naik.'
  const ASAL = [
    { nama: 'profil', tempat: 'kekal', kunci: 'beshare-penasihat' },
    { nama: 'pelan', tempat: 'kekal', kunci: 'beshare-pelan-draf' },
    { nama: 'angka', tempat: 'kekal', kunci: 'beshare-angka-draf' },
    { nama: 'profilSesi', tempat: 'sesi', kunci: 'beshare-penasihat' },
  ]

  function idAkaunSah(id) {
    return typeof id === 'string' && /^[a-f0-9]{32}$/.test(id)
  }

  function versiSah(nilai) {
    return typeof nilai === 'number' && Number.isInteger(nilai) && nilai >= 1 && nilai <= 1000000
  }

  function kunciSkop(id, jenis) {
    if (!idAkaunSah(id)) return null
    if (jenis !== 'pelan' && jenis !== 'angka') return null
    return `beshare-draf.${id}.${jenis}`
  }

  function badanSimpan(penasihat, versi) {
    if (!penasihat || !versiSah(versi)) return null
    return { penasihat, versi }
  }

  function bacaItem(stor, kunci) {
    try {
      return stor.getItem(kunci)
    } catch {
      return null
    }
  }

  function kuarantinLegasi(kekal, sesi) {
    const peta = ASAL.map((item) => ({ ...item, stor: item.tempat === 'sesi' ? sesi : kekal }))
    const ada = []
    for (const item of peta) {
      const nilai = bacaItem(item.stor, item.kunci)
      if (typeof nilai === 'string' && nilai.length) ada.push({ ...item, nilai })
    }
    if (!ada.length) return { siap: true, dipadam: [], kekal: [] }

    const sebelumnya = bacaItem(kekal, KUNCI_KUARANTIN)
    let kuar = null
    if (typeof sebelumnya === 'string') {
      try {
        kuar = JSON.parse(sebelumnya)
      } catch {
        kuar = null
      }
      const bolehGabung = kuar && kuar.tanda === TANDA && kuar.salinan && typeof kuar.salinan === 'object' && !Array.isArray(kuar.salinan)
      if (!bolehGabung) return { siap: false, dipadam: [], kekal: ada.map((item) => item.kunci) }
    } else {
      kuar = { tanda: TANDA, nota: NOTA, salinan: {}, simpanan: [] }
    }
    if (!Array.isArray(kuar.simpanan)) kuar.simpanan = []
    kuar.nota = NOTA
    for (const item of ada) {
      const sedia = kuar.salinan[item.nama]
      if (typeof sedia !== 'string') kuar.salinan[item.nama] = item.nilai
      else if (sedia !== item.nilai && !kuar.simpanan.includes(item.nilai)) kuar.simpanan.push(item.nilai)
    }

    const gagal = () => {
      if (typeof sebelumnya === 'string' && bacaItem(kekal, KUNCI_KUARANTIN) !== sebelumnya) {
        try { kekal.setItem(KUNCI_KUARANTIN, sebelumnya) } catch { /* kuarantin asal kekal jika pemulihan gagal */ }
      }
      return { siap: false, dipadam: [], kekal: ada.map((item) => item.kunci) }
    }
    let json
    try {
      json = JSON.stringify(kuar)
      kekal.setItem(KUNCI_KUARANTIN, json)
    } catch {
      return gagal()
    }
    const semula = bacaItem(kekal, KUNCI_KUARANTIN)
    if (semula !== json) return gagal()
    let sah
    try {
      sah = JSON.parse(semula)
    } catch {
      return gagal()
    }
    for (const item of ada) {
      const dalam = sah && sah.salinan ? sah.salinan[item.nama] : null
      const senarai = sah && Array.isArray(sah.simpanan) && sah.simpanan.includes(item.nilai)
      if (dalam !== item.nilai && !senarai) return gagal()
    }

    const dipadam = []
    for (const item of ada) {
      try {
        item.stor.removeItem(item.kunci)
      } catch {
        /* asal kekal bila pemadaman gagal */
      }
      if (bacaItem(item.stor, item.kunci) == null) dipadam.push(`${item.tempat}:${item.kunci}`)
    }
    const masih = ada.filter((item) => bacaItem(item.stor, item.kunci) != null).map((item) => `${item.tempat}:${item.kunci}`)
    return { siap: masih.length === 0, dipadam, kekal: masih }
  }

  function mesejSama(a, b) {
    if (!a || !b || a.dari !== b.dari || a.teks !== b.teks) return false
    const kiri = Array.isArray(a.langkah) ? a.langkah.join('\n') : ''
    const kanan = Array.isArray(b.langkah) ? b.langkah.join('\n') : ''
    return kiri === kanan
  }

  function ekorBelum(tempatan, pelayan) {
    const asal = tempatan && Array.isArray(tempatan.mesej) ? tempatan.mesej : []
    const jauh = pelayan && Array.isArray(pelayan.mesej) ? pelayan.mesej : []
    let i = 0
    while (i < asal.length && i < jauh.length && mesejSama(asal[i], jauh[i])) i += 1
    return asal.slice(i).filter((item) => item && (item.dari === 'klien' || item.dari === 'penasihat') && typeof item.teks === 'string')
  }

  function susunTeksBelum(sedia, teksKlien, had) {
    const batas = had || 600
    let kotak = String(sedia || '')
    const tinggal = []
    for (const teks of teksKlien) {
      if (typeof teks !== 'string' || !teks.trim() || kotak.includes(teks)) continue
      const seterusnya = kotak.trim() ? `${kotak}\n${teks}` : teks
      if (seterusnya.length <= batas) kotak = seterusnya
      else tinggal.push(teks)
    }
    return { kotak, tinggal }
  }

  return {
    KUNCI_KUARANTIN,
    TANDA,
    idAkaunSah,
    versiSah,
    kunciSkop,
    badanSimpan,
    kuarantinLegasi,
    ekorBelum,
    susunTeksBelum,
  }
})
