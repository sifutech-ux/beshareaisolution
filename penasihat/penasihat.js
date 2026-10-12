const ASAL = ['localhost', '127.0.0.1'].includes(location.hostname)
  ? 'http://127.0.0.1:3210'
  : 'https://ai-video-saas-ten.vercel.app'
const API = `${ASAL}/api/penasihat`
const DENGAR_API = `${ASAL}/api/penasihat-dengar`
const SUARA_API = `${ASAL}/api/penasihat-suara`
const AKAUN = ['localhost', '127.0.0.1'].includes(location.hostname)
  ? 'http://127.0.0.1:8767'
  : 'https://beshare-ipo-bot.onrender.com'
const AKAUN_KUNCI = 'beshare-akaun'
const SIMPAN = 'beshare-penasihat'
const PELAN = 'beshare-pelan-draf'
const ANGKA = 'beshare-angka-draf'
const TEMA = 'beshare-tema'
const PERINGKAT = {
  mula: 'Baru nak mula',
  jual: 'Sudah jual',
  kembang: 'Nak kembangkan',
}
const SKRIN_MEJA = ['utama', 'nasihat', 'pelan', 'angka', 'profil']
const TAJUK = {
  muat: 'Membuka',
  pintu: 'Masuk',
  mula: 'Mula',
  utama: 'Utama',
  nasihat: 'Nasihat',
  pelan: 'Pelan',
  angka: 'Angka',
  profil: 'Profil',
}

const pintu = document.querySelector('[data-layar="pintu"]')
const borang = document.getElementById('borang')
const log = document.getElementById('log')
const ringkas = document.getElementById('ringkas')
const status = document.getElementById('status')
const ralat = document.getElementById('ralat-borang')
const tulis = document.getElementById('tulis')
const hantar = document.getElementById('hantar')
const cakap = document.getElementById('cakap')
const jalur = document.getElementById('jalur-akaun')

let keadaan = null
let sibuk = false
let media = null
let rakaman = null
let ketulan = []
let masaRakam = 0
let audioCtx = null
let sumber = null
let langkahBor = 1

const MIKROFON = 'Mikrofon tidak dibenarkan. Tekan Cakap sekali lagi dan pilih Benarkan.'
const SUARA_GAGAL = 'Suara belum dapat didengar. Tekan Dengar sekali lagi, atau baca jawapan di skrin.'

function mesejSah(item) {
  if (!item || (item.dari !== 'klien' && item.dari !== 'penasihat')) return null
  if (typeof item.teks !== 'string') return null
  const teks = item.teks.replace(/\s+/g, ' ').trim()
  if (!teks) return null
  const bersih = { dari: item.dari, teks }
  if (Array.isArray(item.langkah)) {
    const langkah = item.langkah.filter((baris) => typeof baris === 'string' && baris.trim()).slice(0, 3)
    if (langkah.length === 3) bersih.langkah = langkah
  }
  return bersih
}

function rekodSah(data) {
  if (!data || typeof data.nama !== 'string' || typeof data.jualan !== 'string') return null
  if (!PERINGKAT[data.peringkat] || !Array.isArray(data.mesej)) return null
  const nama = data.nama.replace(/\s+/g, ' ').trim()
  const jualan = data.jualan.replace(/\s+/g, ' ').trim()
  if (nama.length < 2 || jualan.length < 8) return null
  const mesej = data.mesej.map(mesejSah).filter(Boolean).slice(-40)
  if (!mesej.length) return null
  return { nama, jualan, peringkat: data.peringkat, mesej }
}

function bacaStor(stor) {
  try {
    return rekodSah(JSON.parse(stor.getItem(SIMPAN) || 'null'))
  } catch {
    return null
  }
}

function tulisStor(stor, json) {
  try {
    stor.setItem(SIMPAN, json)
    return true
  } catch {
    return false
  }
}

function baca() {
  const kekal = bacaStor(localStorage)
  if (kekal) return kekal
  const sesi = bacaStor(sessionStorage)
  if (!sesi) return null
  tulisStor(localStorage, JSON.stringify(sesi))
  return sesi
}

function sesiAkaun() {
  try {
    const data = JSON.parse(localStorage.getItem(AKAUN_KUNCI) || 'null')
    if (!data || typeof data.token !== 'string' || typeof data.email !== 'string') return null
    return data
  } catch {
    return null
  }
}

function kepalaAkaun() {
  const sesi = sesiAkaun()
  return {
    'Content-Type': 'application/json',
    ...(sesi ? { Authorization: `Bearer ${sesi.token}` } : {}),
  }
}

function jalurAkaun(teks) {
  jalur.hidden = !teks
  jalur.textContent = teks || ''
}

function salam() {
  const jam = new Date().getHours()
  if (jam < 12) return 'Selamat pagi'
  if (jam < 18) return 'Selamat petang'
  return 'Selamat malam'
}

function langkahTerkini() {
  if (!keadaan) return null
  for (let i = keadaan.mesej.length - 1; i >= 0; i -= 1) {
    const item = keadaan.mesej[i]
    if (item.dari === 'penasihat' && Array.isArray(item.langkah) && item.langkah.length === 3) return item.langkah
  }
  return null
}

function bacaPelan() {
  try {
    const data = JSON.parse(localStorage.getItem(PELAN) || 'null')
    if (!data || !Array.isArray(data.tugasan)) return []
    return data.tugasan.map((item) => {
      if (!item || typeof item.teks !== 'string' || typeof item.id !== 'string') return null
      const teks = item.teks.replace(/\s+/g, ' ').trim().slice(0, 160)
      if (!teks) return null
      return { id: item.id.slice(0, 40), teks, siap: Boolean(item.siap) }
    }).filter(Boolean).slice(0, 30)
  } catch {
    return []
  }
}

function idBaru() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  return `tugas-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

function tulisPelan(tugasan) {
  try {
    localStorage.setItem(PELAN, JSON.stringify({ tugasan, nota: 'draf telefon, bukan pelayan' }))
  } catch {
    status.textContent = 'Draf tidak muat pada telefon ini.'
  }
}

function bacaAngka() {
  try {
    const data = JSON.parse(localStorage.getItem(ANGKA) || 'null')
    if (!data || typeof data !== 'object') return { hasil: '', kos: '' }
    const bersih = (nilai) => (typeof nilai === 'string' ? nilai.replace(/[^\d.]/g, '').slice(0, 12) : '')
    return { hasil: bersih(data.hasil), kos: bersih(data.kos) }
  } catch {
    return { hasil: '', kos: '' }
  }
}

function nomborAngka(nilai) {
  if (!nilai || !/^\d+(\.\d{1,2})?$/.test(nilai)) return null
  const nombor = Number(nilai)
  return Number.isFinite(nombor) ? nombor : null
}

function salinLangkah(langkah) {
  const ada = new Set(bacaPelan().map((item) => item.teks))
  const tambahan = langkah.filter((teks) => !ada.has(teks)).map((teks) => ({
    id: idBaru(),
    teks,
    siap: false,
  }))
  if (!tambahan.length) return
  tulisPelan([...bacaPelan(), ...tambahan].slice(0, 30))
}

function letakCincin(id, siap, jumlah) {
  const el = document.getElementById(id)
  if (!el) return
  const nisbah = jumlah ? Math.round((siap / jumlah) * 100) : 0
  el.style.setProperty('--sudah', String(nisbah))
  const tengah = el.querySelector('span')
  if (tengah) tengah.textContent = jumlah ? `${siap}/${jumlah}` : '0'
}

function hurufNama(teks) {
  const bahagian = String(teks || '').trim().split(/\s+/).filter(Boolean).slice(0, 2)
  const huruf = bahagian.map((item) => item[0]).join('').toUpperCase()
  return huruf || 'B'
}

function tandaSiap(teks, siap) {
  const senarai = bacaPelan()
  const ada = senarai.find((item) => item.teks === teks)
  if (ada) {
    tulisPelan(senarai.map((item) => item.teks === teks ? { ...item, siap } : item))
    return
  }
  if (siap) tulisPelan([...senarai, { id: idBaru(), teks, siap: true }].slice(0, 30))
}

function gunaTema(nilai) {
  const tema = nilai === 'gelap' || nilai === 'cerah' ? nilai : 'sistem'
  try { localStorage.setItem(TEMA, tema) } catch { /* tema kekal untuk sesi ini */ }
  document.documentElement.dataset.tema = tema
  const cerah = tema === 'cerah' || (tema === 'sistem' && window.matchMedia('(prefers-color-scheme: light)').matches)
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.content = cerah ? '#F3F6FB' : '#0B1120'
  const radio = document.querySelector(`input[name="tema"][value="${tema}"]`)
  if (radio) radio.checked = true
  document.getElementById('tema-pantas').setAttribute('aria-pressed', tema === 'cerah' ? 'true' : 'false')
  const label = document.getElementById('label-tema')
  if (label) label.textContent = tema === 'gelap' ? 'Tema: Gelap' : tema === 'cerah' ? 'Tema: Cerah' : 'Tema: Sistem'
  document.querySelectorAll('[data-pilih-tema]').forEach((butang) => {
    butang.setAttribute('aria-pressed', butang.dataset.pilihTema === tema ? 'true' : 'false')
  })
}

function pergi(nama, fokus = false) {
  if (!sesiAkaun()) nama = 'pintu'
  else if (!keadaan && nama !== 'mula' && nama !== 'muat') nama = 'mula'
  document.body.dataset.skrin = nama
  document.body.classList.toggle('siap', Boolean(sesiAkaun() && keadaan))
  document.getElementById('tajuk-skrin').textContent = TAJUK[nama] || 'Meja'
  document.querySelectorAll('[data-pergi]').forEach((butang) => {
    butang.setAttribute('aria-current', butang.dataset.pergi === nama ? 'page' : 'false')
  })
  const namaSisi = document.getElementById('nama-sisi')
  if (keadaan) {
    namaSisi.hidden = false
    namaSisi.textContent = keadaan.nama
  }
  if (nama === 'utama') lukisUtama()
  if (nama === 'pelan') lukisPelan()
  if (nama === 'angka') lukisAngka()
  if (nama === 'profil') lukisProfil()
  if (SKRIN_MEJA.includes(nama)) history.replaceState(null, '', `#${nama}`)
  const kepala = document.querySelector(`[data-layar="${nama}"] h1`)
  if (fokus && kepala) {
    kepala.tabIndex = -1
    kepala.focus({ preventScroll: true })
  }
}

function tunjukPintu() {
  pergi('pintu')
}

function jenisTerpilih() {
  return borang.querySelector('input[name="jenis"]:checked')?.value || ''
}

function tunjukLangkah(n) {
  const jenis = jenisTerpilih()
  const akhir = jenis === 'baru' ? 2 : 3
  langkahBor = Math.min(Math.max(n, 1), akhir)
  document.querySelectorAll('[data-langkah]').forEach((bahagian) => {
    bahagian.hidden = Number(bahagian.dataset.langkah) !== langkahBor
  })
  document.querySelectorAll('[data-kemajuan]').forEach((item) => {
    const nombor = Number(item.dataset.kemajuan)
    item.classList.toggle('lepas', nombor < langkahBor)
    if (nombor === langkahBor) item.setAttribute('aria-current', 'step')
    else item.removeAttribute('aria-current')
    item.hidden = jenis === 'baru' && nombor === 3
  })
  document.getElementById('borang-kembali').hidden = langkahBor === 1
  document.getElementById('borang-terus').hidden = langkahBor === akhir
  document.getElementById('borang-siap').hidden = langkahBor !== akhir
  const labelMula = borang.querySelector('input[name="peringkat"][value="mula"]')?.closest('label')
  if (labelMula) labelMula.hidden = jenis === 'sedia'
  const keterangan = document.getElementById('keterangan-langkah')
  if (keterangan) keterangan.textContent = `Langkah ${langkahBor} daripada ${akhir}`
  const tajuk = document.getElementById('tajuk-mula')
  if (jenis === 'sedia') tajuk.textContent = 'Mari susun perniagaan yang sudah berjalan'
  else if (jenis === 'baru') tajuk.textContent = 'Mari mulakan perniagaan anda'
  else tajuk.textContent = 'Mari kenali perniagaan anda'
}

function tunjukBorang(buka) {
  if (!buka) return
  if (keadaan) {
    document.getElementById('nama').value = keadaan.nama
    document.getElementById('jualan').value = keadaan.jualan
    const jenis = keadaan.peringkat === 'mula' ? 'baru' : 'sedia'
    const jenisRadio = borang.querySelector(`input[name="jenis"][value="${jenis}"]`)
    if (jenisRadio) jenisRadio.checked = true
    const radio = borang.querySelector(`input[name="peringkat"][value="${keadaan.peringkat}"]`)
    if (radio) radio.checked = true
  }
  ralat.hidden = true
  tunjukLangkah(1)
  pergi('mula')
}

function wawasanTerkini() {
  if (!keadaan) return null
  for (let i = keadaan.mesej.length - 1; i >= 0; i -= 1) {
    const item = keadaan.mesej[i]
    if (item.dari === 'penasihat' && Array.isArray(item.langkah) && item.langkah.length === 3) return item
  }
  return null
}

function lukisUtama() {
  if (!keadaan) return
  document.getElementById('salam-utama').textContent = 'Selamat datang'
  document.getElementById('nama-utama').textContent = keadaan.nama
  document.getElementById('ayat-utama').textContent = 'Teruskan langkah seterusnya untuk capai matlamat perniagaan anda.'
  document.getElementById('peringkat-utama').textContent = PERINGKAT[keadaan.peringkat] || ''
  document.getElementById('emel-utama').textContent = sesiAkaun()?.email || ''
  document.getElementById('jualan-utama').textContent = keadaan.jualan
  const wawasan = document.getElementById('wawasan-utama')
  wawasan.replaceChildren()
  const nasihat = wawasanTerkini()
  const ayat = document.createElement('p')
  ayat.textContent = nasihat ? nasihat.teks : 'Belum ada wawasan. Mulakan dengan satu soalan.'
  wawasan.append(ayat)
  const bekas = document.getElementById('langkah-utama')
  bekas.replaceChildren()
  const langkah = nasihat?.langkah || null
  if (!langkah) {
    const kosong = document.createElement('p')
    kosong.textContent = 'Belum ada tindakan. Tanya Copilot untuk tiga langkah minggu ini.'
    bekas.append(kosong)
  } else {
    const senarai = document.createElement('ul')
    senarai.className = 'senarai-tugas'
    for (const baris of langkah) {
      const item = document.createElement('li')
      const lab = document.createElement('label')
      const kotak = document.createElement('input')
      kotak.type = 'checkbox'
      kotak.checked = bacaPelan().some((tugas) => tugas.teks === baris && tugas.siap)
      kotak.addEventListener('change', () => {
        tandaSiap(baris, kotak.checked)
        lukisUtama()
        if (document.body.dataset.skrin === 'pelan') lukisPelan()
      })
      const span = document.createElement('span')
      span.textContent = baris
      lab.append(kotak, span)
      item.append(lab)
      if (kotak.checked) item.classList.add('tugas-siap')
      senarai.append(item)
    }
    bekas.append(senarai)
  }
  const tugasan = bacaPelan()
  const siap = tugasan.filter((item) => item.siap).length
  letakCincin('cincin-utama', siap, tugasan.length)
  document.getElementById('kira-draf').textContent = tugasan.length
    ? `${siap} daripada ${tugasan.length} tugas pada telefon ini`
    : '0 tugasan draf'
  const draf = bacaAngka()
  const hasil = nomborAngka(draf.hasil)
  const kos = nomborAngka(draf.kos)
  const tajukAngka = document.getElementById('tajuk-angka-utama')
  const ayatAngka = document.getElementById('ayat-angka')
  if (hasil === null && kos === null) {
    tajukAngka.textContent = 'Angka jualan belum dimasukkan'
    ayatAngka.textContent = 'Paparan ini bukan jualan sebenar dan tidak datang dari pelayan.'
  } else {
    tajukAngka.textContent = 'Draf angka pada telefon ini'
    ayatAngka.textContent = `Hasil ${draf.hasil || '—'} · Kos ${draf.kos || '—'}. Ini bukan jualan sebenar.`
  }
}

function lukisAngka() {
  const draf = bacaAngka()
  const hasil = document.getElementById('hasil-angka')
  const kos = document.getElementById('kos-angka')
  if (document.activeElement !== hasil) hasil.value = draf.hasil
  if (document.activeElement !== kos) kos.value = draf.kos
  const nHasil = nomborAngka(draf.hasil)
  const nKos = nomborAngka(draf.kos)
  const margin = document.getElementById('margin-angka')
  margin.textContent = nHasil !== null && nKos !== null ? `RM ${(nHasil - nKos).toFixed(2)}` : '—'
}

function lukisPelan() {
  const rujukan = document.getElementById('rujukan-pelan')
  rujukan.replaceChildren()
  const label = document.createElement('p')
  label.className = 'kad__label'
  label.textContent = 'Langkah daripada penasihat'
  rujukan.append(label)
  const langkah = langkahTerkini()
  if (!langkah) {
    const kosong = document.createElement('p')
    kosong.textContent = 'Belum ada tiga langkah tersimpan pada akaun.'
    rujukan.append(kosong)
  } else {
    const senarai = document.createElement('ol')
    senarai.className = 'langkah'
    for (const baris of langkah) {
      const item = document.createElement('li')
      item.textContent = baris
      senarai.append(item)
    }
    rujukan.append(senarai)
    const salin = document.createElement('button')
    salin.type = 'button'
    salin.className = 'butang butang-senyap'
    salin.textContent = 'Salin ke draf telefon'
    salin.addEventListener('click', () => {
      salinLangkah(langkah)
      lukisPelan()
      lukisUtama()
    })
    rujukan.append(salin)
  }
  const senarai = document.getElementById('senarai-pelan')
  const tugasan = bacaPelan()
  senarai.replaceChildren()
  document.getElementById('pelan-kosong').hidden = tugasan.length > 0
  for (const tugas of tugasan) {
    const li = document.createElement('li')
    li.className = tugas.siap ? 'tugas-siap' : ''
    const lab = document.createElement('label')
    const kotak = document.createElement('input')
    kotak.type = 'checkbox'
    kotak.checked = tugas.siap
    kotak.addEventListener('change', () => {
      tulisPelan(bacaPelan().map((item) => item.id === tugas.id ? { ...item, siap: kotak.checked } : item))
      lukisPelan()
      lukisUtama()
    })
    const span = document.createElement('span')
    span.textContent = tugas.teks
    lab.append(kotak, span)
    const buang = document.createElement('button')
    buang.type = 'button'
    buang.className = 'buang'
    buang.textContent = 'Buang'
    buang.addEventListener('click', () => {
      tulisPelan(bacaPelan().filter((item) => item.id !== tugas.id))
      lukisPelan()
      lukisUtama()
    })
    li.append(lab, buang)
    senarai.append(li)
  }
  const siap = tugasan.filter((item) => item.siap).length
  const cincin = document.getElementById('cincin-pelan')
  const ringkasPelan = document.getElementById('pelan-ringkas')
  if (!tugasan.length) {
    cincin.classList.add('cincin-kosong')
    cincin.style.setProperty('--sudah', '0')
    cincin.querySelector('span').textContent = '—'
    ringkasPelan.textContent = 'Belum ada tugasan'
  } else {
    cincin.classList.remove('cincin-kosong')
    letakCincin('cincin-pelan', siap, tugasan.length)
    ringkasPelan.textContent = `${siap} daripada ${tugasan.length} selesai pada telefon ini`
  }
}

function lukisProfil() {
  const sesi = sesiAkaun()
  document.getElementById('emel-profil').textContent = sesi?.email || ''
  document.getElementById('nama-profil').textContent = keadaan?.nama || ''
  document.getElementById('jualan-profil').textContent = keadaan?.jualan || ''
  document.getElementById('peringkat-profil').textContent = PERINGKAT[keadaan?.peringkat] || ''
  document.getElementById('huruf-profil').textContent = hurufNama(keadaan?.nama || sesi?.email)
  gunaTema(localStorage.getItem(TEMA) || 'sistem')
}

function lukis() {
  if (!keadaan) return
  const emel = sesiAkaun()?.email
  ringkas.textContent = `${keadaan.nama} · ${PERINGKAT[keadaan.peringkat] || ''}${emel ? ` · ${emel}` : ''}`
  log.replaceChildren()
  for (const item of keadaan.mesej) {
    const balon = document.createElement('article')
    balon.className = `gelembung gelembung--${item.dari === 'klien' ? 'klien' : 'penasihat'}`
    const teks = document.createElement('p')
    teks.textContent = item.teks
    balon.append(teks)
    if (item.dari === 'penasihat') {
      const tanda = document.createElement('p')
      tanda.className = 'penasihat-tanda'
      tanda.textContent = 'Penasihat'
      balon.prepend(tanda)
    }
    if (Array.isArray(item.langkah) && item.langkah.length === 3) {
      const senarai = document.createElement('ol')
      senarai.className = 'langkah'
      const tajuk = document.createElement('p')
      tajuk.textContent = 'Langkah minggu ini'
      balon.append(tajuk)
      for (const langkah of item.langkah) {
        const baris = document.createElement('li')
        baris.textContent = langkah
        senarai.append(baris)
      }
      balon.append(senarai)
    }
    if (item.dari === 'penasihat') {
      const aksi = document.createElement('div')
      aksi.className = 'gelembung-aksi'
      const butang = document.createElement('button')
      butang.type = 'button'
      butang.className = 'dengar'
      butang.textContent = 'Dengar'
      butang.addEventListener('click', () => {
        bukaAudio()
        mainSuara(item, butang)
      })
      aksi.append(butang)
      if (Array.isArray(item.langkah) && item.langkah.length === 3) {
        const salin = document.createElement('button')
        salin.type = 'button'
        salin.className = 'salin-pelan'
        salin.textContent = 'Salin ke pelan telefon'
        salin.addEventListener('click', () => {
          salinLangkah(item.langkah)
          salin.textContent = 'Sudah disalin pada telefon'
          lukisUtama()
        })
        aksi.append(salin)
      }
      balon.append(aksi)
    }
    log.append(balon)
  }
  log.scrollTop = log.scrollHeight
  if (document.body.dataset.skrin === 'utama') lukisUtama()
}

function keluarAkaun() {
  localStorage.removeItem(AKAUN_KUNCI)
  localStorage.removeItem(SIMPAN)
  localStorage.removeItem(PELAN)
  localStorage.removeItem(ANGKA)
  try { sessionStorage.removeItem(SIMPAN) } catch { /* salinan sesi kosong */ }
  keadaan = null
  location.href = '../akaun/'
}

async function simpanAkaun() {
  if (!sesiAkaun() || !keadaan) return
  const balas = await fetch(`${AKAUN}/api/akaun/penasihat`, {
    method: 'POST',
    headers: kepalaAkaun(),
    body: JSON.stringify({ penasihat: keadaan }),
  })
  if (balas.status === 401) {
    keluarAkaun()
    return
  }
  if (!balas.ok) throw new Error('gagal')
}

async function simpan() {
  if (!keadaan) return
  keadaan.mesej = keadaan.mesej.slice(-40)
  const json = JSON.stringify(keadaan)
  if (!tulisStor(localStorage, json)) tulisStor(sessionStorage, json)
  try {
    await simpanAkaun()
  } catch {
    status.textContent = 'Perubahan belum disimpan ke akaun. Semak talian, kemudian cuba lagi.'
    jalurAkaun('Perubahan belum disimpan ke akaun. Semak talian, kemudian cuba lagi.')
  }
}

async function mula() {
  pergi('muat')
  if (!sesiAkaun()) {
    tunjukPintu()
    return
  }
  try {
    const balas = await fetch(`${AKAUN}/api/akaun/saya`, { headers: kepalaAkaun() })
    const data = await balas.json().catch(() => ({}))
    if (balas.status === 401) {
      localStorage.removeItem(AKAUN_KUNCI)
      tunjukPintu()
      return
    }
    if (!balas.ok) throw new Error(typeof data.error === 'string' ? data.error : 'gagal')
    const jauh = rekodSah(data.penasihat)
    if (jauh) {
      keadaan = jauh
      const json = JSON.stringify(keadaan)
      if (!tulisStor(localStorage, json)) tulisStor(sessionStorage, json)
    } else {
      const telefon = baca()
      if (telefon) {
        keadaan = telefon
        await simpanAkaun()
      }
    }
    jalurAkaun('')
  } catch {
    keadaan = baca()
    jalurAkaun('Akaun tidak dapat dihubungi. Salinan telefon ditunjukkan dahulu.')
    status.textContent = 'Akaun tidak dapat dihubungi. Salinan telefon ditunjukkan dahulu.'
  }
  if (!keadaan) {
    tunjukBorang(true)
    return
  }
  lukis()
  const hash = location.hash.slice(1)
  pergi(SKRIN_MEJA.includes(hash) ? hash : 'utama')
}

function bukaAudio() {
  const Konteks = window.AudioContext || window.webkitAudioContext
  if (!Konteks) return null
  if (!audioCtx) audioCtx = new Konteks()
  if (audioCtx.state === 'suspended') audioCtx.resume()
  return audioCtx
}

function ayatPelayar(error, sandaran) {
  const text = error instanceof Error ? error.message : ''
  if (!text || text === 'gagal') return sandaran
  if (/not allowed|permission|user agent|denied|NotAllowed|NotFound|NotReadable|request|failed to fetch|load failed|networkerror/i.test(text)) return sandaran
  if (text.includes('{')) return sandaran
  return text
}

function giliran() {
  return keadaan.mesej.map((item) => ({
    dari: item.dari,
    teks: item.langkah?.length ? `${item.teks} Langkah minggu ini: ${item.langkah.join(' ')}` : item.teks,
  }))
}

function setCakap(rakam) {
  cakap.classList.toggle('rakam', rakam)
  cakap.setAttribute('aria-label', rakam ? 'Berhenti' : 'Cakap')
}

function tunjukUlang(boleh) {
  document.getElementById('cuba-lagi').hidden = !boleh
}

async function hantarTeks(teks, mainkan, ulang = false) {
  if ((!teks && !ulang) || sibuk || !keadaan) return
  if (!ulang) {
    keadaan.mesej.push({ dari: 'klien', teks })
    tulis.value = ''
  }
  sibuk = true
  hantar.disabled = true
  cakap.disabled = true
  tunjukUlang(false)
  status.classList.add('sedang')
  status.textContent = 'Penasihat sedang menulis…'
  simpan()
  lukis()
  try {
    const balas = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        nama: keadaan.nama,
        jualan: keadaan.jualan,
        peringkat: keadaan.peringkat,
        giliran: giliran(),
      }),
    })
    const data = await balas.json()
    if (!balas.ok || typeof data.jawapan !== 'string' || !Array.isArray(data.langkah) || data.langkah.length < 3) {
      throw new Error(typeof data.error === 'string' ? data.error : 'gagal')
    }
    const jawapan = { dari: 'penasihat', teks: data.jawapan, langkah: data.langkah.slice(0, 3) }
    keadaan.mesej.push(jawapan)
    status.classList.remove('sedang')
    status.textContent = ''
    tunjukUlang(false)
    simpan()
    lukis()
    if (mainkan) await mainSuara(jawapan)
  } catch (error) {
    status.classList.remove('sedang')
    status.textContent = ayatPelayar(error, 'Penasihat sedang sibuk. Tunggu sebentar, kemudian hantar sekali lagi.')
    tunjukUlang(true)
    simpan()
    lukis()
  }
  sibuk = false
  hantar.disabled = false
  cakap.disabled = false
}

async function mainSuara(item, butang) {
  if (butang) butang.disabled = true
  status.textContent = 'Menyediakan suara…'
  try {
    const balas = await fetch(SUARA_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ jawapan: item.teks, langkah: item.langkah || [] }),
    })
    const data = await balas.json()
    if (!balas.ok || typeof data.audio !== 'string') {
      throw new Error(typeof data.error === 'string' ? data.error : 'gagal')
    }
    const ctx = bukaAudio()
    if (!ctx) throw new Error(SUARA_GAGAL)
    if (ctx.state === 'suspended') await ctx.resume()
    const bersih = data.audio.replace(/\s/g, '')
    const binary = atob(bersih)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i)
    const buffer = await ctx.decodeAudioData(bytes.buffer.slice(0))
    if (sumber) {
      try { sumber.stop() } catch { /* rakaman lama sudah tamat */ }
    }
    sumber = ctx.createBufferSource()
    sumber.buffer = buffer
    sumber.connect(ctx.destination)
    sumber.start()
    status.textContent = ''
  } catch (error) {
    status.textContent = ayatPelayar(error, SUARA_GAGAL)
  }
  if (butang) butang.disabled = false
}

function failCakap(mesej) {
  status.textContent = mesej
  setCakap(false)
  cakap.disabled = false
  hantar.disabled = false
}

async function hantarRakaman(blob) {
  sibuk = true
  cakap.disabled = true
  hantar.disabled = true
  status.textContent = 'Menyusun apa yang awak cakap…'
  try {
    const dataUrl = await new Promise((resolve, reject) => {
      const bacaFail = new FileReader()
      bacaFail.onload = () => resolve(String(bacaFail.result || ''))
      bacaFail.onerror = () => reject(new Error('Rakaman tidak lengkap. Cuba cakap sekali lagi.'))
      bacaFail.readAsDataURL(blob)
    })
    const padan = dataUrl.match(/^data:([^;]+);base64,(.+)$/)
    if (!padan) throw new Error('Rakaman tidak lengkap. Cuba cakap sekali lagi.')
    const balas = await fetch(DENGAR_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ audio: padan[2], mime: padan[1] }),
    })
    const data = await balas.json()
    if (!balas.ok || typeof data.teks !== 'string') {
      throw new Error(typeof data.error === 'string' ? data.error : 'Suara tidak jelas. Cuba cakap sekali lagi, atau tulis mesej.')
    }
    sibuk = false
    cakap.disabled = false
    hantar.disabled = false
    status.textContent = ''
    await hantarTeks(data.teks, true)
  } catch (error) {
    sibuk = false
    failCakap(ayatPelayar(error, 'Suara tidak jelas. Cuba cakap sekali lagi, atau tulis mesej.'))
  }
}

async function togolCakap() {
  if (sibuk) return
  if (rakaman && rakaman.state === 'recording') {
    rakaman.stop()
    return
  }
  bukaAudio()
  if (!navigator.mediaDevices || typeof MediaRecorder === 'undefined') {
    failCakap('Pelayar ini belum boleh merakam. Tulis mesej dahulu.')
    return
  }
  try {
    media = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch {
    failCakap(MIKROFON)
    return
  }
  const jenis = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'].find((item) => {
    try {
      return MediaRecorder.isTypeSupported(item)
    } catch {
      return false
    }
  })
  try {
    rakaman = jenis ? new MediaRecorder(media, { mimeType: jenis }) : new MediaRecorder(media)
  } catch {
    media.getTracks().forEach((track) => track.stop())
    failCakap('Pelayar ini belum boleh merakam. Tulis mesej dahulu.')
    return
  }
  ketulan = []
  rakaman.ondataavailable = (event) => {
    if (event.data && event.data.size) ketulan.push(event.data)
  }
  rakaman.onstop = () => {
    clearTimeout(masaRakam)
    if (media) media.getTracks().forEach((track) => track.stop())
    setCakap(false)
    const blob = new Blob(ketulan, { type: rakaman.mimeType || jenis || 'audio/webm' })
    if (blob.size < 800) {
      failCakap('Suara tidak jelas. Cuba cakap sekali lagi, atau tulis mesej.')
      return
    }
    hantarRakaman(blob)
  }
  rakaman.start()
  setCakap(true)
  hantar.disabled = true
  status.textContent = 'Cakap sekarang. Tekan Berhenti bila siap.'
  masaRakam = setTimeout(() => {
    if (rakaman && rakaman.state === 'recording') rakaman.stop()
  }, 20000)
}

function ralatBorang(mesej) {
  ralat.hidden = !mesej
  ralat.textContent = mesej || ''
}

function terusBorang() {
  ralatBorang('')
  if (langkahBor === 1 && !jenisTerpilih()) {
    ralatBorang('Pilih sama ada anda baru nak mula atau sudah ada perniagaan.')
    return
  }
  if (langkahBor === 1 && jenisTerpilih() === 'baru') {
    const radio = borang.querySelector('input[name="peringkat"][value="mula"]')
    if (radio) radio.checked = true
  }
  if (langkahBor === 2) {
    const nama = document.getElementById('nama').value.replace(/\s+/g, ' ').trim()
    const jualan = document.getElementById('jualan').value.replace(/\s+/g, ' ').trim()
    if (nama.length < 2 || jualan.length < 8) {
      ralatBorang('Isi nama, dan tulis apa yang dijual sekurang-kurangnya lapan aksara.')
      return
    }
  }
  tunjukLangkah(langkahBor + 1)
}

borang.addEventListener('submit', async (event) => {
  event.preventDefault()
  if (langkahBor < (jenisTerpilih() === 'baru' ? 2 : 3)) {
    terusBorang()
    return
  }
  if (jenisTerpilih() === 'baru') {
    const radioMula = borang.querySelector('input[name="peringkat"][value="mula"]')
    if (radioMula) radioMula.checked = true
  }
  const nama = document.getElementById('nama').value.replace(/\s+/g, ' ').trim()
  const jualan = document.getElementById('jualan').value.replace(/\s+/g, ' ').trim()
  const peringkat = borang.querySelector('input[name="peringkat"]:checked')?.value
  if (nama.length < 2 || jualan.length < 8 || !peringkat) {
    ralatBorang('Isi nama, apa yang dijual, dan pilih peringkat.')
    return
  }
  ralatBorang('')
  const sama = keadaan && keadaan.nama === nama && keadaan.jualan === jualan && keadaan.peringkat === peringkat
  keadaan = {
    nama,
    jualan,
    peringkat,
    mesej: sama ? keadaan.mesej : [{ dari: 'penasihat', teks: `Baik. Saya catat ${nama}. Cerita apa yang awak nak susun untuk minggu ini.` }],
  }
  await simpan()
  lukis()
  pergi('nasihat')
  tulis.focus()
})

document.getElementById('borang-terus').addEventListener('click', terusBorang)
document.getElementById('borang-kembali').addEventListener('click', () => {
  ralatBorang('')
  tunjukLangkah(langkahBor - 1)
})
borang.querySelectorAll('input[name="jenis"]').forEach((input) => {
  input.addEventListener('change', () => tunjukLangkah(1))
})
document.getElementById('edit-matlamat').addEventListener('click', () => tunjukBorang(true))
document.getElementById('tukar').addEventListener('click', () => tunjukBorang(true))
document.getElementById('tukar-profil').addEventListener('click', () => tunjukBorang(true))
document.getElementById('keluar').addEventListener('click', () => keluarAkaun())
document.getElementById('keluar-profil').addEventListener('click', () => keluarAkaun())
cakap.addEventListener('click', () => {
  bukaAudio()
  togolCakap()
})
document.getElementById('mesej').addEventListener('submit', (event) => {
  event.preventDefault()
  const teks = tulis.value.replace(/\s+/g, ' ').trim()
  hantarTeks(teks, false)
})
document.getElementById('cuba-lagi').addEventListener('click', () => {
  const akhir = keadaan?.mesej?.[keadaan.mesej.length - 1]
  if (akhir?.dari === 'klien') hantarTeks(akhir.teks, false, true)
})
document.getElementById('borang-angka').addEventListener('submit', (event) => {
  event.preventDefault()
  const hasil = document.getElementById('hasil-angka').value.trim()
  const kos = document.getElementById('kos-angka').value.trim()
  const ralatAngka = document.getElementById('ralat-angka')
  const hasilOk = hasil === '' || nomborAngka(hasil) !== null
  const kosOk = kos === '' || nomborAngka(kos) !== null
  if (!hasilOk || !kosOk) {
    ralatAngka.hidden = false
    ralatAngka.textContent = 'Guna nombor sahaja, contoh 120.50. Ruang kosong dibenarkan.'
    return
  }
  ralatAngka.hidden = true
  try {
    localStorage.setItem(ANGKA, JSON.stringify({ hasil, kos, nota: 'draf telefon, bukan pelayan' }))
  } catch {
    ralatAngka.hidden = false
    ralatAngka.textContent = 'Draf angka tidak muat pada telefon ini.'
    return
  }
  lukisAngka()
  lukisUtama()
})
document.querySelectorAll('[data-pilih-tema]').forEach((butang) => {
  butang.addEventListener('click', () => gunaTema(butang.dataset.pilihTema))
})
document.getElementById('borang-tugas').addEventListener('submit', (event) => {
  event.preventDefault()
  const medan = document.getElementById('tulis-tugas')
  const teks = medan.value.replace(/\s+/g, ' ').trim()
  if (teks.length < 2) return
  tulisPelan([...bacaPelan(), { id: idBaru(), teks, siap: false }].slice(0, 30))
  medan.value = ''
  lukisPelan()
  lukisUtama()
})
document.querySelectorAll('[data-pergi]').forEach((butang) => {
  butang.addEventListener('click', () => pergi(butang.dataset.pergi, true))
})
document.querySelectorAll('input[name="tema"]').forEach((input) => {
  input.addEventListener('change', () => {
    if (input.checked) gunaTema(input.value)
  })
})
document.getElementById('tema-pantas').addEventListener('click', () => {
  const sekarang = document.documentElement.dataset.tema || 'sistem'
  const cerah = sekarang === 'cerah' || (sekarang === 'sistem' && window.matchMedia('(prefers-color-scheme: light)').matches)
  gunaTema(cerah ? 'gelap' : 'cerah')
})

function larasPapan() {
  const vv = window.visualViewport
  if (!vv) return
  const tertutup = Math.max(0, window.innerHeight - vv.height - vv.offsetTop)
  document.documentElement.style.setProperty('--papan', `${Math.round(tertutup)}px`)
  document.body.classList.toggle('papan-buka', tertutup > 80 && document.activeElement === tulis)
}
if (window.visualViewport) {
  visualViewport.addEventListener('resize', larasPapan)
  visualViewport.addEventListener('scroll', larasPapan)
}
tulis.addEventListener('focus', larasPapan)
tulis.addEventListener('blur', () => {
  document.body.classList.remove('papan-buka')
  document.documentElement.style.setProperty('--papan', '0px')
})

gunaTema(localStorage.getItem(TEMA) || 'sistem')
window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
  if ((localStorage.getItem(TEMA) || 'sistem') === 'sistem') gunaTema('sistem')
})
mula()
