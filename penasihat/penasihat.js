const ASAL = ['localhost', '127.0.0.1'].includes(location.hostname)
  ? 'http://127.0.0.1:3210'
  : 'https://ai-video-saas-ten.vercel.app'
const NASIHAT_API = `${ASAL}/api/copilot/nasihat`
const DENGAR_API = `${ASAL}/api/penasihat-dengar`
const SUARA_API = `${ASAL}/api/penasihat-suara`
const AKAUN = ['localhost', '127.0.0.1'].includes(location.hostname)
  ? 'http://127.0.0.1:8767'
  : 'https://beshare-ipo-bot.onrender.com'
const AKAUN_KUNCI = 'beshare-akaun'
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
let logTertambat = true
const ringkas = document.getElementById('ringkas')
const status = document.getElementById('status')
const ralat = document.getElementById('ralat-borang')
const tulis = document.getElementById('tulis')
const hantar = document.getElementById('hantar')
const cakap = document.getElementById('cakap')
const jalur = document.getElementById('jalur-akaun')

let keadaan = null
let akaunId = null
let mesejVersi = null
let konflikVersi = false
let salinanKonflik = null
let pelanAkaun = { versi: 0, tugasan: [] }
let pelanDimuat = false
let pelanGagal = false
let pelanKonflik = null
let angkaAkaun = []
let angkaDimuat = false
let angkaGagal = false
let angkaKonflik = null
let angkaSunting = null
let angkaPendua = null
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

function sesiAkaun() {
  try {
    const data = JSON.parse(localStorage.getItem(AKAUN_KUNCI) || 'null')
    if (!data || typeof data.token !== 'string' || typeof data.email !== 'string') return null
    return { token: data.token, email: data.email }
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
  const kunci = BeshareDraf.kunciSkop(akaunId, 'pelan')
  if (!kunci) return []
  try {
    const data = JSON.parse(localStorage.getItem(kunci) || 'null')
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
  const kunci = BeshareDraf.kunciSkop(akaunId, 'pelan')
  if (!kunci) {
    status.textContent = 'Draf tidak disimpan kerana akaun belum disahkan.'
    return
  }
  try {
    localStorage.setItem(kunci, JSON.stringify({ tugasan, nota: 'draf telefon, bukan pelayan' }))
  } catch {
    status.textContent = 'Draf tidak muat pada telefon ini.'
  }
}

function bacaAngka() {
  const kunci = BeshareDraf.kunciSkop(akaunId, 'angka')
  if (!kunci) return { hasil: '', kos: '' }
  try {
    const data = JSON.parse(localStorage.getItem(kunci) || 'null')
    if (!data || typeof data !== 'object') return { hasil: '', kos: '' }
    const bersih = (nilai) => (typeof nilai === 'string' ? nilai.replace(/[^\d.]/g, '').slice(0, 12) : '')
    return { hasil: bersih(data.hasil), kos: bersih(data.kos) }
  } catch {
    return { hasil: '', kos: '' }
  }
}

function tulisAngka(hasil, kos) {
  const kunci = BeshareDraf.kunciSkop(akaunId, 'angka')
  if (!kunci) return false
  localStorage.setItem(kunci, JSON.stringify({ hasil, kos, nota: 'draf telefon, bukan pelayan' }))
  return true
}

function nomborAngka(nilai) {
  if (!nilai || !/^\d+(\.\d{1,2})?$/.test(nilai)) return null
  const nombor = Number(nilai)
  return Number.isFinite(nombor) ? nombor : null
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

function gunaTema(nilai) {
  const tema = nilai === 'gelap' || nilai === 'cerah' ? nilai : 'sistem'
  try { localStorage.setItem(TEMA, tema) } catch { /* tema kekal untuk sesi ini */ }
  document.documentElement.dataset.tema = tema
  const cerah = tema === 'cerah' || (tema === 'sistem' && window.matchMedia('(prefers-color-scheme: light)').matches)
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.content = cerah ? '#F4F0FA' : '#090716'
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
  if (sesiAkaun() && keadaan && ['utama', 'pelan', 'angka', 'profil'].includes(nama)) muatMeja()
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
    kosong.textContent = 'Belum ada cadangan. Tanya Copilot untuk tiga langkah minggu ini.'
    bekas.append(kosong)
  } else {
    const senarai = document.createElement('ul')
    senarai.className = 'senarai-tugas'
    for (const baris of langkah) {
      const item = document.createElement('li')
      const span = document.createElement('span')
      span.textContent = baris
      const tambah = document.createElement('button')
      tambah.type = 'button'
      tambah.className = 'butang butang-senyap'
      tambah.textContent = 'Tambah ke pelan akaun'
      tambah.addEventListener('click', () => tambahTugas(baris, null))
      item.append(span, tambah)
      senarai.append(item)
    }
    bekas.append(senarai)
  }
  lukisCincinPelan('cincin-utama', 'kira-draf')
  const tajukAngka = document.getElementById('tajuk-angka-utama')
  const ayatAngka = document.getElementById('ayat-angka')
  const minggu = mingguSemasa()
  const hasil = angkaDimuat ? MejaAkaun.jumlahHasilMinggu(angkaAkaun, minggu.isnin, minggu.ahad) : null
  if (!angkaDimuat) {
    tajukAngka.textContent = angkaGagal ? 'Angka akaun tidak dapat dihubungi' : 'Angka jualan belum dimasukkan'
    ayatAngka.textContent = angkaGagal
      ? 'Rekod terakhir tidak ditunjukkan sebagai jualan semasa.'
      : 'Tiada rekod hasil yang anda masukkan untuk minggu ini.'
  } else if (hasil === null) {
    tajukAngka.textContent = 'Angka jualan belum dimasukkan'
    ayatAngka.textContent = `Tiada hasil MYR untuk minggu ${minggu.isnin} hingga ${minggu.ahad}. ${MejaAkaun.LABEL}`
  } else {
    tajukAngka.textContent = `RM ${hasil}`
    ayatAngka.textContent = `Hasil minggu ${minggu.isnin} hingga ${minggu.ahad}. Kos tidak dicampurkan. ${MejaAkaun.LABEL}`
  }
}

function lencanaSimpan(nama, konflik, gagal, dimuat) {
  if (konflik) return 'Konflik — semak salinan pelayan'
  if (!dimuat && gagal) return `${nama} tidak dapat dihubungi`
  if (!dimuat) return `${nama} belum dimuat`
  if (gagal) return 'Belum berjaya disimpan'
  return 'Disimpan dalam akaun'
}

function lukisAngka() {
  const jenis = document.getElementById('jenis-angka')
  const unit = document.getElementById('unit-angka')
  const unitPilihan = unit.querySelector('option[value="unit"]')
  if (unitPilihan) unitPilihan.disabled = jenis.value !== 'lain'
  if (jenis.value !== 'lain') unit.value = 'MYR'
  document.getElementById('angka-lencana').textContent = lencanaSimpan('Angka akaun', angkaKonflik, angkaGagal, angkaDimuat)
  const minggu = mingguSemasa()
  const dari = MejaAkaun.tolakHari(minggu.hari, 90)
  const tempoh = document.getElementById('tempoh-angka')
  const jumlah = MejaAkaun.jumlahKewangan(angkaAkaun, dari, minggu.hari)
  if (tempoh) {
    tempoh.textContent = angkaDimuat && jumlah
      ? MejaAkaun.labelTempoh(dari, minggu.hari, jumlah)
      : (angkaGagal ? 'Tempoh angka tidak dapat dihubungi.' : 'Tempoh angka belum dimuat.')
  }
  const senarai = document.getElementById('senarai-angka')
  senarai.replaceChildren()
  const dilihat = new Set()
  const kelihatan = angkaAkaun.filter((rekod) => {
    if (!rekod || !MejaAkaun.idSah(rekod.id) || dilihat.has(rekod.id)) return false
    dilihat.add(rekod.id)
    if (rekod.status !== 'dimasukkan_pengguna') return false
    return rekod.tarikh >= dari && rekod.tarikh <= minggu.hari
  })
  document.getElementById('angka-kosong').hidden = angkaDimuat && kelihatan.length > 0
  if (!angkaDimuat) {
    document.getElementById('angka-kosong').textContent = angkaGagal
      ? 'Angka akaun tidak dapat dihubungi. Nilai dalam borang tidak dipadam.'
      : 'Belum ada rekod dalam akaun.'
  }
  for (const rekod of kelihatan) {
    const li = document.createElement('li')
    const span = document.createElement('span')
    span.textContent = `${rekod.tarikh} · ${rekod.jenis} · ${rekod.nilai} ${rekod.unit}`
    const sunting = document.createElement('button')
    sunting.type = 'button'
    sunting.className = 'butang butang-senyap'
    sunting.textContent = 'Sunting'
    sunting.addEventListener('click', () => isiAngka(rekod))
    const tarik = document.createElement('button')
    tarik.type = 'button'
    tarik.className = 'buang'
    tarik.textContent = 'Tarik balik'
    tarik.addEventListener('click', () => tarikAngka(rekod))
    li.append(span, sunting, tarik)
    senarai.append(li)
  }
}

function lukisPelan() {
  const minggu = mingguSemasa()
  document.getElementById('minggu-pelan').textContent = `Minggu Isnin ${minggu.isnin}`
  document.getElementById('pelan-lencana').textContent = lencanaSimpan('Pelan akaun', pelanKonflik, pelanGagal, pelanDimuat)
  const konflik = document.getElementById('pelan-konflik')
  konflik.hidden = !pelanKonflik
  if (pelanKonflik) {
    document.getElementById('pelan-konflik-teks').textContent = 'Tab atau simpanan lain sudah berubah. Tugasan anda tidak ditulis semula.'
    const senaraiKonflik = document.getElementById('pelan-konflik-senarai')
    senaraiKonflik.replaceChildren()
    for (const item of pelanKonflik.tugasan || []) {
      const li = document.createElement('li')
      li.textContent = typeof item === 'string' ? item : item.teks
      senaraiKonflik.append(li)
    }
  }
  const rujukan = document.getElementById('rujukan-pelan')
  rujukan.replaceChildren()
  const label = document.createElement('p')
  label.className = 'kad__label'
  label.textContent = 'Cadangan penasihat, bukan tugasan akaun'
  rujukan.append(label)
  const langkah = langkahTerkini()
  if (!langkah) {
    const kosong = document.createElement('p')
    kosong.textContent = 'Belum ada tiga langkah tersimpan pada akaun.'
    rujukan.append(kosong)
  } else {
    const cadangan = document.createElement('ol')
    cadangan.className = 'langkah'
    for (const baris of langkah) {
      const item = document.createElement('li')
      item.textContent = baris
      cadangan.append(item)
    }
    rujukan.append(cadangan)
    const salin = document.createElement('button')
    salin.type = 'button'
    salin.className = 'butang butang-senyap'
    salin.textContent = 'Tambah cadangan ke pelan akaun'
    salin.addEventListener('click', () => tambahCadangan(langkah))
    rujukan.append(salin)
  }
  const senarai = document.getElementById('senarai-pelan')
  const tugasan = pelanDimuat ? pelanAkaun.tugasan : []
  senarai.replaceChildren()
  const kosongPelan = document.getElementById('pelan-kosong')
  kosongPelan.hidden = tugasan.length > 0
  kosongPelan.textContent = pelanGagal && !pelanDimuat
    ? 'Pelan akaun tidak dapat dihubungi. Teks dalam kotak tidak dipadam.'
    : 'Belum ada tugasan dalam akaun untuk minggu ini.'
  for (const tugas of tugasan) {
    const li = document.createElement('li')
    li.className = tugas.siap ? 'tugas-siap' : ''
    const lab = document.createElement('label')
    const kotak = document.createElement('input')
    kotak.type = 'checkbox'
    kotak.checked = Boolean(tugas.siap)
    kotak.addEventListener('change', () => kemasTugas(tugas, kotak.checked))
    const span = document.createElement('span')
    span.textContent = tugas.teks
    lab.append(kotak, span)
    const buang = document.createElement('button')
    buang.type = 'button'
    buang.className = 'buang'
    buang.textContent = 'Buang'
    buang.addEventListener('click', () => padamTugas(tugas))
    li.append(lab, buang)
    senarai.append(li)
  }
  lukisCincinPelan('cincin-pelan', 'pelan-ringkas')
}

function lukisProfil() {
  const sesi = sesiAkaun()
  document.getElementById('emel-profil').textContent = sesi?.email || ''
  document.getElementById('nama-profil').textContent = keadaan?.nama || ''
  document.getElementById('jualan-profil').textContent = keadaan?.jualan || ''
  document.getElementById('peringkat-profil').textContent = PERINGKAT[keadaan?.peringkat] || ''
  document.getElementById('huruf-profil').textContent = hurufNama(keadaan?.nama || sesi?.email)
  gunaTema(localStorage.getItem(TEMA) || 'sistem')
  lukisDraf()
}

function logDiHujung() {
  if (log.clientHeight < 1) return logTertambat
  return log.scrollHeight - log.scrollTop - log.clientHeight <= 12
}

function lukis() {
  if (!keadaan) return
  const diHujung = logDiHujung()
  const kedudukan = log.scrollTop
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
        salin.textContent = 'Tambah ke pelan akaun'
        salin.addEventListener('click', async () => {
          const bilang = await tambahCadangan(item.langkah)
          if (bilang > 0) salin.textContent = 'Ditambah ke pelan akaun'
        })
        aksi.append(salin)
      }
      balon.append(aksi)
    }
    log.append(balon)
  }
  if (diHujung) log.scrollTop = log.scrollHeight
  else log.scrollTop = kedudukan
  if (document.body.dataset.skrin === 'utama') lukisUtama()
}

function lupusSesi() {
  try { localStorage.removeItem(AKAUN_KUNCI) } catch { /* sesi sudah tiada */ }
  keadaan = null
  akaunId = null
  mesejVersi = null
  konflikVersi = false
  salinanKonflik = null
  pelanAkaun = { versi: 0, tugasan: [] }
  pelanDimuat = false
  pelanGagal = false
  pelanKonflik = null
  angkaAkaun = []
  angkaDimuat = false
  angkaGagal = false
  angkaKonflik = null
  angkaSunting = null
  angkaPendua = null
}

function keluarAkaun() {
  lupusSesi()
  location.href = '../akaun/'
}

function tunjukKonflik(belum, tinggal, bolehMuat) {
  const kotak = document.getElementById('konflik-versi')
  const teks = document.getElementById('konflik-teks')
  const senarai = document.getElementById('konflik-senarai')
  const butang = document.getElementById('konflik-muat')
  kotak.hidden = false
  const adaKlien = (belum || []).some((item) => item.dari === 'klien')
  const adaJawapan = (belum || []).some((item) => item.dari === 'penasihat')
  let ayat = 'Tab lain sudah menyimpan salinan baharu. Teks anda tidak ditulis semula ke pelayan.'
  if (adaKlien) ayat += ' Mesej anda masih di kotak tulis.'
  if (adaJawapan) ayat += ' Jawapan yang belum disimpan disenaraikan di sini.'
  if (tinggal && tinggal.length) ayat += ' Teks yang tidak muat di kotak juga disenaraikan di sini.'
  teks.textContent = ayat
  senarai.replaceChildren()
  for (const item of belum || []) {
    const baris = document.createElement('li')
    baris.textContent = item.dari === 'klien' ? item.teks : `Jawapan belum disimpan: ${item.teks}`
    senarai.append(baris)
  }
  for (const item of tinggal || []) {
    const baris = document.createElement('li')
    baris.textContent = item
    senarai.append(baris)
  }
  butang.hidden = !bolehMuat
  jalurAkaun('')
  status.textContent = ''
}

function tanganiKonflik(data) {
  const profil = rekodSah(data && data.penasihat)
  const versi = BeshareDraf.versiSah(data && data.mesej_versi) ? data.mesej_versi : null
  const belum = profil ? BeshareDraf.ekorBelum(keadaan, profil) : []
  const klien = belum.filter((item) => item.dari === 'klien').map((item) => item.teks)
  const susun = BeshareDraf.susunTeksBelum(tulis.value, klien, 600)
  tulis.value = susun.kotak
  salinanKonflik = profil && versi ? { profil, versi } : null
  konflikVersi = true
  tunjukKonflik(belum, susun.tinggal, Boolean(salinanKonflik))
}

function muatSalinanPelayan() {
  if (!salinanKonflik) return
  const belum = BeshareDraf.ekorBelum(keadaan, salinanKonflik.profil)
  const klien = belum.filter((item) => item.dari === 'klien').map((item) => item.teks)
  tulis.value = BeshareDraf.susunTeksBelum(tulis.value, klien, 600).kotak
  keadaan = salinanKonflik.profil
  mesejVersi = salinanKonflik.versi
  konflikVersi = false
  salinanKonflik = null
  document.getElementById('konflik-muat').hidden = true
  const ayat = 'Salinan pelayan dimuatkan. Teks yang belum dihantar kekal di kotak tulis dan tidak ditulis semula sendiri.'
  status.textContent = ayat
  jalurAkaun(ayat)
  lukis()
}

async function simpanAkaun() {
  if (!sesiAkaun() || !keadaan || !BeshareDraf.idAkaunSah(akaunId)) return false
  if (konflikVersi) return false
  const badan = BeshareDraf.badanSimpan(keadaan, mesejVersi)
  if (!badan) {
    jalurAkaun('Versi akaun belum disahkan. Muat semula sebelum menyimpan.')
    return false
  }
  const balas = await fetch(`${AKAUN}/api/akaun/penasihat`, {
    method: 'POST',
    headers: kepalaAkaun(),
    body: JSON.stringify(badan),
  })
  const data = await balas.json().catch(() => ({}))
  if (balas.status === 401) {
    lupusSesi()
    tunjukPintu()
    return false
  }
  if (balas.status === 409) {
    tanganiKonflik(data)
    return false
  }
  if (!balas.ok || !BeshareDraf.versiSah(data.mesej_versi)) throw new Error('gagal')
  mesejVersi = data.mesej_versi
  return true
}

async function simpan() {
  if (!keadaan) return false
  keadaan.mesej = keadaan.mesej.slice(-40)
  if (konflikVersi) {
    document.getElementById('konflik-versi').hidden = false
    return false
  }
  try {
    return await simpanAkaun()
  } catch {
    const ayat = 'Perubahan belum disimpan ke akaun. Semak talian, kemudian cuba lagi.'
    status.textContent = ayat
    jalurAkaun(ayat)
    return false
  }
}

async function mula() {
  try { BeshareDraf.kuarantinLegasi(localStorage, sessionStorage) } catch { /* asal kekal jika kuarantin gagal */ }
  pergi('muat')
  if (!sesiAkaun()) {
    tunjukPintu()
    return
  }
  try {
    const balas = await fetch(`${AKAUN}/api/akaun/saya`, { headers: kepalaAkaun() })
    const data = await balas.json().catch(() => ({}))
    if (balas.status === 401) {
      lupusSesi()
      tunjukPintu()
      return
    }
    if (!balas.ok) throw new Error(typeof data.error === 'string' ? data.error : 'gagal')
    if (!BeshareDraf.idAkaunSah(data.akaun_id) || !BeshareDraf.versiSah(data.mesej_versi)) throw new Error('gagal')
    akaunId = data.akaun_id
    mesejVersi = data.mesej_versi
    keadaan = rekodSah(data.penasihat)
    jalurAkaun('')
  } catch {
    akaunId = null
    keadaan = null
    const ayat = 'Akaun tidak dapat dihubungi. Draf akaun lain tidak ditunjukkan.'
    jalurAkaun(ayat)
    status.textContent = ayat
    pergi('muat')
    return
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

function giliranNasihat() {
  if (!keadaan) return []
  const giliran = []
  for (const item of keadaan.mesej) {
    if (!item || (item.dari !== 'klien' && item.dari !== 'penasihat') || typeof item.teks !== 'string') continue
    const teks = item.teks.replace(/\s+/g, ' ').trim().slice(0, 600)
    if (!teks) continue
    giliran.push({ dari: item.dari, teks })
  }
  return giliran.slice(-8)
}

function storNasihat() {
  return BeshareDraf.idAkaunSah(akaunId) ? `beshare-nasihat.${akaunId}` : null
}

function kunciNasihat(giliran) {
  const cap = JSON.stringify(giliran)
  const nama = storNasihat()
  let tunda = null
  if (nama) {
    try { tunda = JSON.parse(localStorage.getItem(nama) || 'null') } catch { tunda = null }
  }
  if (tunda && tunda.cap === cap && typeof tunda.kunci === 'string' && /^[A-Za-z0-9_-]{16,64}$/.test(tunda.kunci)) {
    return tunda.kunci
  }
  const kunci = MejaAkaun.kunciIdem()
  if (nama) localStorage.setItem(nama, JSON.stringify({ kunci, cap }))
  return kunci
}

function buangKunciNasihat() {
  const nama = storNasihat()
  if (!nama) return
  try { localStorage.removeItem(nama) } catch { /* kunci sudah tiada */ }
}

function jawapanSama(jawapan, langkah) {
  const akhir = keadaan.mesej[keadaan.mesej.length - 1]
  if (!akhir || akhir.dari !== 'penasihat' || akhir.teks !== jawapan) return false
  const ada = Array.isArray(akhir.langkah) ? akhir.langkah : []
  return ada.length === langkah.length && ada.every((baris, indeks) => baris === langkah[indeks])
}

function ayatNasihat(kod, data) {
  if (data && typeof data.error === 'string' && !data.error.includes('{')) return data.error
  if (kod === 400) return 'Permintaan tidak sah. Semak mesej, kemudian cuba lagi.'
  if (kod === 409) return 'Permintaan ini masih diproses.'
  if (kod === 422) return 'Lengkapkan profil perniagaan dahulu.'
  if (kod === 429) return 'Kuota nasihat penuh. Cuba lagi kemudian.'
  if (kod === 404 || kod === 405) return 'Nasihat akaun belum dipasang pada pelayan. Jawapan tidak diterima dan tidak disimpan.'
  if (kod === 503) return 'Keputusan permintaan ini belum pasti.'
  return 'Nasihat akaun tidak dapat dihubungi. Jawapan tidak diterima dan tidak disimpan.'
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
  if (konflikVersi) {
    tunjukKonflik(BeshareDraf.ekorBelum(keadaan, salinanKonflik && salinanKonflik.profil), [], Boolean(salinanKonflik))
    return
  }
  if (!ulang) {
    keadaan.mesej.push({ dari: 'klien', teks })
    tulis.value = ''
  }
  sibuk = true
  hantar.disabled = true
  cakap.disabled = true
  tunjukUlang(false)
  status.dataset.nasihat = ''
  try {
    const tersimpan = await simpan()
    lukis()
    if (!sesiAkaun() || konflikVersi) return
    if (!tersimpan) {
      if (!ulang && teks && keadaan.mesej.at(-1)?.dari === 'klien' && keadaan.mesej.at(-1)?.teks === teks) {
        keadaan.mesej.pop()
        tulis.value = teks
        lukis()
      }
      status.textContent = 'Mesej belum disimpan ke akaun. Nasihat tidak diminta, dan jawapan tidak disimpan.'
      status.dataset.nasihat = 'ralat'
      return
    }
    const giliran = giliranNasihat()
    const akhir = giliran[giliran.length - 1]
    if (!akhir || akhir.dari !== 'klien') {
      status.textContent = 'Sila tulis mesej.'
      status.dataset.nasihat = 'ralat'
      return
    }
    const sesi = sesiAkaun()
    status.classList.add('sedang')
    status.textContent = 'Penasihat sedang menulis…'
    const balas = await fetch(NASIHAT_API, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${sesi.token}`,
        'Idempotency-Key': kunciNasihat(giliran),
      },
      body: JSON.stringify({ giliran }),
    })
    const data = await balas.json().catch(() => ({}))
    status.classList.remove('sedang')
    if (balas.status === 401) {
      lupusSesi()
      tunjukPintu()
      return
    }
    const langkah = Array.isArray(data.langkah) ? data.langkah.filter((baris) => typeof baris === 'string').slice(0, 3) : []
    const modelSah = balas.ok
      && data.ok === true
      && data.sumber === 'gemini'
      && data.bukan_model === false
      && typeof data.jawapan === 'string'
      && langkah.length === 3
    if (modelSah) {
      const baharu = !jawapanSama(data.jawapan, langkah)
      if (baharu) keadaan.mesej.push({ dari: 'penasihat', teks: data.jawapan, langkah })
      const jawapanTersimpan = baharu ? await simpan() : true
      if (baharu && !jawapanTersimpan) {
        keadaan.mesej.pop()
        lukis()
        status.textContent = 'Jawapan model diterima, tetapi belum disimpan ke akaun. Ia tidak dimasukkan ke rekod.'
        status.dataset.nasihat = 'ralat'
        tunjukUlang(true)
        return
      }
      buangKunciNasihat()
      status.textContent = ''
      status.dataset.nasihat = 'model'
      tunjukUlang(false)
      lukis()
      if (mainkan) await mainSuara(keadaan.mesej[keadaan.mesej.length - 1])
      return
    }
    if (balas.status === 502 && data.bukan_model === true) {
      const label = typeof data.label === 'string' ? data.label : 'Panduan tempatan, bukan jawapan model.'
      status.textContent = `${label} Ini bukan jawapan model dan tidak disimpan.`
      status.dataset.nasihat = 'tempatan'
      tunjukUlang(true)
      lukis()
      return
    }
    status.textContent = ayatNasihat(balas.status, data)
    status.dataset.nasihat = 'ralat'
    tunjukUlang(balas.status !== 400 && balas.status !== 422 && balas.status !== 404 && balas.status !== 405)
    lukis()
  } catch (error) {
    status.classList.remove('sedang')
    status.textContent = ayatPelayar(error, 'Nasihat akaun tidak dapat dihubungi. Jawapan tidak diterima dan tidak disimpan.')
    status.dataset.nasihat = 'ralat'
    tunjukUlang(true)
    lukis()
  } finally {
    sibuk = false
    hantar.disabled = false
    cakap.disabled = false
    status.classList.remove('sedang')
  }
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

function mingguSemasa() {
  const hari = MejaAkaun.hariMalaysia()
  const isnin = MejaAkaun.isninMinggu(hari)
  return { hari, isnin, ahad: MejaAkaun.ahadMinggu(isnin) }
}

function lukisCincinPelan(cincinId, teksId) {
  const cincin = document.getElementById(cincinId)
  const el = document.getElementById(teksId)
  const tugasan = pelanDimuat ? pelanAkaun.tugasan : []
  const siap = tugasan.filter((item) => item.siap).length
  if (!pelanDimuat || !tugasan.length) {
    cincin.classList.add('cincin-kosong')
    cincin.style.setProperty('--sudah', '0')
    cincin.querySelector('span').textContent = '—'
    el.textContent = !pelanDimuat
      ? (pelanGagal ? 'Pelan akaun tidak dapat dihubungi' : 'Pelan akaun belum dimuat')
      : 'Belum ada tugasan dalam akaun'
    return
  }
  cincin.classList.remove('cincin-kosong')
  letakCincin(cincinId, siap, tugasan.length)
  el.textContent = `${siap} daripada ${tugasan.length} tugasan dalam akaun`
}

async function mintaAkaun(laluan, pilihan = {}) {
  const balas = await fetch(`${AKAUN}${laluan}`, {
    method: pilihan.method || 'GET',
    headers: {
      ...kepalaAkaun(),
      ...(pilihan.kunci ? { 'Idempotency-Key': pilihan.kunci } : {}),
    },
    body: pilihan.badan ? JSON.stringify(pilihan.badan) : undefined,
  })
  const data = await balas.json().catch(() => ({}))
  return { status: balas.status, data }
}

function sesiTamat(status) {
  if (status !== 401) return false
  lupusSesi()
  tunjukPintu()
  return true
}

let capMeja = 0
function usangMuat() {
  capMeja += 1
}

async function muatMeja() {
  if (!sesiAkaun() || !BeshareDraf.idAkaunSah(akaunId)) return
  const cap = ++capMeja
  const minggu = mingguSemasa()
  const dari = MejaAkaun.tolakHari(minggu.hari, 90)
  let pelanBalas
  let angkaBalas
  try {
    ;[pelanBalas, angkaBalas] = await Promise.all([
      mintaAkaun(`/api/akaun/pelan?minggu=${minggu.isnin}`),
      mintaAkaun(`/api/akaun/angka?dari=${dari}&hingga=${minggu.hari}`),
    ])
  } catch {
    if (cap !== capMeja) return
    pelanGagal = true
    angkaGagal = true
    lukisMeja()
    return
  }
  if (cap !== capMeja) return
  if (sesiTamat(pelanBalas.status) || sesiTamat(angkaBalas.status)) return
  if (!pelanKonflik && pelanBalas.status === 200 && pelanBalas.data.ok) {
    const pelan = pelanBalas.data.pelan
    pelanAkaun = pelan
      ? { id: pelan.id, minggu: pelan.minggu_mula, versi: pelan.versi, tugasan: pelan.tugasan || [] }
      : { versi: pelanBalas.data.versi, tugasan: [] }
    pelanDimuat = true
    pelanGagal = false
  } else if (pelanBalas.status !== 200) pelanGagal = true
  if (!angkaKonflik && angkaBalas.status === 200 && angkaBalas.data.ok) {
    angkaAkaun = Array.isArray(angkaBalas.data.rekod) ? angkaBalas.data.rekod : []
    angkaDimuat = true
    angkaGagal = false
  } else if (angkaBalas.status !== 200) angkaGagal = true
  lukisMeja()
}

function lukisMeja() {
  const skrin = document.body.dataset.skrin
  if (skrin === 'utama') lukisUtama()
  if (skrin === 'pelan') lukisPelan()
  if (skrin === 'angka') lukisAngka()
  if (skrin === 'profil') lukisDraf()
}

function gunaPelan(pelan) {
  usangMuat()
  pelanAkaun = { id: pelan.id, minggu: pelan.minggu_mula, versi: pelan.versi, tugasan: pelan.tugasan || [] }
  pelanDimuat = true
  pelanGagal = false
  pelanKonflik = null
}

function salinanPelan(data) {
  if (data && data.pelan && Array.isArray(data.pelan.tugasan) && Number.isInteger(data.pelan.versi)) {
    return { id: data.pelan.id, versi: data.pelan.versi, tugasan: data.pelan.tugasan }
  }
  if (data && Array.isArray(data.pelayan) && Number.isInteger(data.versi)) {
    return {
      versi: data.versi,
      tugasan: data.pelayan.map((item) => (typeof item === 'string' ? { teks: item, siap: false } : item)),
    }
  }
  return null
}

function setPelanKeadaan(teks) {
  const el = document.getElementById('pelan-keadaan')
  if (el) el.textContent = teks || ''
}

async function tambahTugas(teks, medan) {
  if (pelanKonflik) {
    setPelanKeadaan('Konflik — semak salinan pelayan')
    lukisPelan()
    return false
  }
  const badan = MejaAkaun.badanTugas(mingguSemasa().isnin, teks, pelanDimuat ? pelanAkaun.versi : 0)
  if (!badan) {
    setPelanKeadaan('Tugasan perlu 2 hingga 160 aksara.')
    return false
  }
  let balas
  try {
    balas = await mintaAkaun('/api/akaun/pelan/tugasan', { method: 'POST', badan })
  } catch {
    pelanGagal = true
    setPelanKeadaan('Belum berjaya disimpan')
    return false
  }
  if (sesiTamat(balas.status)) return false
  if (balas.status === 409) {
    pelanKonflik = salinanPelan(balas.data)
    setPelanKeadaan('Konflik — semak salinan pelayan')
    lukisPelan()
    lukisUtama()
    return false
  }
  if (!balas.status || balas.status !== 200 || !balas.data.pelan) {
    setPelanKeadaan(balas.data && balas.data.ralat === 'had_tugas'
      ? 'Pelan minggu ini sudah mempunyai 30 tugasan.'
      : 'Belum berjaya disimpan')
    return false
  }
  gunaPelan(balas.data.pelan)
  if (medan) medan.value = ''
  setPelanKeadaan('')
  lukisPelan()
  lukisUtama()
  return true
}

async function tambahCadangan(langkah) {
  let bilang = 0
  for (const teks of langkah || []) {
    const ada = (pelanAkaun.tugasan || []).some((item) => MejaAkaun.capTeks(item.teks) === MejaAkaun.capTeks(teks))
    if (ada) continue
    const ok = await tambahTugas(teks, null)
    if (!ok) break
    bilang += 1
  }
  return bilang
}

async function kemasTugas(tugas, siap) {
  if (pelanKonflik) {
    setPelanKeadaan('Konflik — semak salinan pelayan')
    lukisPelan()
    return
  }
  const badan = MejaAkaun.badanSiap(siap, pelanAkaun.versi)
  if (!badan || !tugas.id) return
  let balas
  try {
    balas = await mintaAkaun(`/api/akaun/pelan/tugasan/${tugas.id}`, { method: 'PATCH', badan })
  } catch {
    pelanGagal = true
    setPelanKeadaan('Belum berjaya disimpan')
    lukisPelan()
    return
  }
  if (sesiTamat(balas.status)) return
  if (balas.status === 409) {
    pelanKonflik = salinanPelan(balas.data) || { versi: pelanAkaun.versi, tugasan: pelanAkaun.tugasan }
    setPelanKeadaan(`Konflik — semak salinan pelayan. Tanda yang belum disimpan: ${tugas.teks}`)
    lukisPelan()
    return
  }
  if (balas.status !== 200 || !balas.data.pelan) {
    setPelanKeadaan('Belum berjaya disimpan')
    lukisPelan()
    return
  }
  gunaPelan(balas.data.pelan)
  setPelanKeadaan('')
  lukisPelan()
  lukisUtama()
}

async function padamTugas(tugas) {
  if (pelanKonflik) {
    setPelanKeadaan('Konflik — semak salinan pelayan')
    return
  }
  const badan = MejaAkaun.badanPadam(pelanAkaun.versi)
  if (!badan || !tugas.id) return
  let balas
  try {
    balas = await mintaAkaun(`/api/akaun/pelan/tugasan/${tugas.id}`, { method: 'DELETE', badan })
  } catch {
    setPelanKeadaan('Belum berjaya disimpan')
    return
  }
  if (sesiTamat(balas.status)) return
  if (balas.status === 409) {
    pelanKonflik = salinanPelan(balas.data)
    setPelanKeadaan('Konflik — semak salinan pelayan')
    lukisPelan()
    return
  }
  if (balas.status !== 200 || !balas.data.pelan) {
    setPelanKeadaan('Belum berjaya disimpan')
    return
  }
  gunaPelan(balas.data.pelan)
  setPelanKeadaan('')
  lukisPelan()
  lukisUtama()
}

function isiAngka(rekod) {
  angkaSunting = { id: rekod.id, versi: rekod.versi }
  document.getElementById('tarikh-angka').value = rekod.tarikh
  document.getElementById('jenis-angka').value = rekod.jenis
  document.getElementById('unit-angka').value = rekod.unit
  document.getElementById('nilai-angka').value = rekod.nilai
  document.getElementById('nota-angka').value = rekod.nota || ''
  lukisAngka()
}

function setAngkaKeadaan(teks) {
  const el = document.getElementById('angka-keadaan')
  if (el) el.textContent = teks || ''
}

async function hantarAngka(sahkan) {
  if (angkaKonflik && sahkan !== true) {
    setAngkaKeadaan('Konflik — semak salinan pelayan')
    return false
  }
  const input = {
    tarikh: document.getElementById('tarikh-angka').value,
    jenis: document.getElementById('jenis-angka').value,
    unit: document.getElementById('unit-angka').value,
    nilai: document.getElementById('nilai-angka').value.trim(),
    nota: document.getElementById('nota-angka').value,
    hariIni: mingguSemasa().hari,
    sahkan: sahkan === true,
    versi: angkaSunting ? angkaSunting.versi : undefined,
  }
  const badan = MejaAkaun.badanAngka(input)
  const ralatAngka = document.getElementById('ralat-angka')
  if (!badan) {
    const pasangan = MejaAkaun.sahPasangan(input.jenis, input.unit, input.nilai)
    ralatAngka.hidden = false
    ralatAngka.textContent = pasangan.ralat === 'unit_tidak_sah'
      ? 'Jenis dan unit ini tidak boleh digandingkan.'
      : 'Isi tarikh yang sah dan nilai yang tepat. Hasil serta kos guna MYR.'
    return false
  }
  ralatAngka.hidden = true
  const laluan = angkaSunting ? `/api/akaun/angka/${angkaSunting.id}` : '/api/akaun/angka'
  let balas
  try {
    balas = await mintaAkaun(laluan, { method: angkaSunting ? 'PATCH' : 'POST', badan })
  } catch {
    angkaGagal = true
    setAngkaKeadaan('Belum berjaya disimpan')
    return false
  }
  if (sesiTamat(balas.status)) return false
  if (balas.status === 409 && balas.data.ralat === 'pendua') {
    angkaPendua = true
    document.getElementById('angka-konflik').hidden = false
    document.getElementById('angka-pendua').hidden = false
    document.getElementById('angka-muat').hidden = true
    const sedia = balas.data.rekod_sedia || {}
    document.getElementById('angka-konflik-teks').textContent = `Rekod yang sama sudah ada pada ${sedia.tarikh || 'pelayan'}. Medan anda tidak dipadam.`
    setAngkaKeadaan('Konflik — semak salinan pelayan')
    return false
  }
  if (balas.status === 409) {
    angkaKonflik = balas.data.rekod || null
    document.getElementById('angka-konflik').hidden = false
    document.getElementById('angka-pendua').hidden = true
    document.getElementById('angka-muat').hidden = !angkaKonflik
    document.getElementById('angka-konflik-teks').textContent = 'Salinan pelayan sudah berubah. Medan anda tidak ditulis semula.'
    setAngkaKeadaan('Konflik — semak salinan pelayan')
    return false
  }
  if (balas.status !== 200 || !balas.data.rekod) {
    setAngkaKeadaan('Belum berjaya disimpan')
    return false
  }
  const rekod = balas.data.rekod
  usangMuat()
  angkaAkaun = angkaAkaun.filter((item) => item.id !== rekod.id)
  if (rekod.status === 'dimasukkan_pengguna') angkaAkaun.push(rekod)
  angkaDimuat = true
  angkaGagal = false
  angkaKonflik = null
  angkaPendua = null
  angkaSunting = null
  document.getElementById('angka-konflik').hidden = true
  document.getElementById('angka-pendua').hidden = true
  document.getElementById('borang-angka').reset()
  document.getElementById('jenis-angka').value = 'hasil'
  document.getElementById('unit-angka').value = 'MYR'
  setAngkaKeadaan('')
  lukisAngka()
  lukisUtama()
  return true
}

async function tarikAngka(rekod) {
  const badan = MejaAkaun.badanPadam(rekod.versi)
  if (!badan) return
  let balas
  try {
    balas = await mintaAkaun(`/api/akaun/angka/${rekod.id}`, { method: 'DELETE', badan })
  } catch {
    setAngkaKeadaan('Belum berjaya disimpan')
    return
  }
  if (sesiTamat(balas.status)) return
  if (balas.status === 409) {
    angkaKonflik = balas.data.rekod || rekod
    document.getElementById('angka-konflik').hidden = false
    document.getElementById('angka-muat').hidden = false
    document.getElementById('angka-pendua').hidden = true
    document.getElementById('angka-konflik-teks').textContent = 'Salinan pelayan sudah berubah. Rekod ini kekal sehingga anda memuatkan salinan pelayan.'
    setAngkaKeadaan('Konflik — semak salinan pelayan')
    return
  }
  if (balas.status !== 200) {
    setAngkaKeadaan('Belum berjaya disimpan')
    return
  }
  usangMuat()
  angkaAkaun = angkaAkaun.filter((item) => item.id !== rekod.id)
  setAngkaKeadaan('')
  lukisAngka()
  lukisUtama()
}

function sumberDraf() {
  const hasil = []
  let kuar = null
  try { kuar = JSON.parse(localStorage.getItem(BeshareDraf.KUNCI_KUARANTIN) || 'null') } catch { kuar = null }
  if (kuar && kuar.tanda === BeshareDraf.TANDA && kuar.salinan) {
    const pelan = MejaAkaun.bacaTugasanDraf(kuar.salinan.pelan)
    if (pelan.length) hasil.push({ slot: 'pelan-kuarantin', jenis: 'pelan', tajuk: 'Draf lama tidak dipercayai', tugasan: pelan })
    const angka = MejaAkaun.bacaAngkaDraf(kuar.salinan.angka)
    if (angka.length) hasil.push({ slot: 'angka-kuarantin', jenis: 'angka', tajuk: 'Angka lama tidak dipercayai', angka })
    if (typeof kuar.salinan.profil === 'string') hasil.push({ jenis: 'profil', tajuk: 'Profil lama tidak diimport di sini' })
  }
  if (BeshareDraf.idAkaunSah(akaunId)) {
    const pelan = MejaAkaun.bacaTugasanDraf(localStorage.getItem(BeshareDraf.kunciSkop(akaunId, 'pelan')))
    if (pelan.length) hasil.push({ slot: 'pelan-telefon', jenis: 'pelan', tajuk: 'Draf pada peranti ini', tugasan: pelan })
    const angka = MejaAkaun.bacaAngkaDraf(localStorage.getItem(BeshareDraf.kunciSkop(akaunId, 'angka')))
    if (angka.length) hasil.push({ slot: 'angka-telefon', jenis: 'angka', tajuk: 'Draf angka pada peranti ini', angka })
  }
  return hasil
}

function tangkapDraf() {
  return {
    tarikh: new Map([...document.querySelectorAll('[data-tarikh]')].map((el) => [el.dataset.tarikh, el.value])),
    unit: new Map([...document.querySelectorAll('[data-unit]')].map((el) => [el.dataset.unit, el.value])),
    slot: new Set([...document.querySelectorAll('#senarai-draf input[data-slot]:checked')].map((el) => el.dataset.slot)),
  }
}

function lukisDraf(simpan) {
  const bekas = document.getElementById('senarai-draf')
  if (!bekas) return
  bekas.replaceChildren()
  const sumber = sumberDraf()
  if (!sumber.length) {
    const kosong = document.createElement('p')
    kosong.className = 'ingat'
    kosong.textContent = 'Tiada draf untuk disemak.'
    bekas.append(kosong)
    return
  }
  const pelayan = document.createElement('p')
  pelayan.className = 'ingat'
  const teksPelayan = (pelanAkaun.tugasan || []).map((item) => item.teks)
  pelayan.textContent = teksPelayan.length
    ? `Dalam akaun minggu ini: ${teksPelayan.join(', ')}`
    : 'Dalam akaun minggu ini: tiada tugasan.'
  bekas.append(pelayan)
  const angkaPelayan = document.createElement('p')
  angkaPelayan.className = 'ingat'
  angkaPelayan.textContent = angkaAkaun.length
    ? `Rekod akaun: ${angkaAkaun.map((item) => `${item.tarikh} ${item.jenis} ${item.nilai} ${item.unit}`).join(', ')}`
    : 'Rekod akaun: tiada angka.'
  bekas.append(angkaPelayan)
  for (const item of sumber) {
    const kotak = document.createElement('div')
    kotak.className = 'draf-baris'
    const tajuk = document.createElement('p')
    tajuk.textContent = item.tajuk
    kotak.append(tajuk)
    if (item.jenis === 'profil') {
      bekas.append(kotak)
      continue
    }
    const lab = document.createElement('label')
    const input = document.createElement('input')
    input.type = 'checkbox'
    input.dataset.slot = item.slot
    input.checked = !!(simpan && simpan.slot && simpan.slot.has(item.slot))
    lab.append(input, document.createTextNode(item.jenis === 'pelan' ? ' Pilih pelan ini' : ' Pilih angka ini'))
    kotak.append(lab)
    if (item.jenis === 'pelan') {
      const senarai = document.createElement('ul')
      for (const tugas of item.tugasan) {
        const li = document.createElement('li')
        li.textContent = tugas.teks
        senarai.append(li)
      }
      kotak.append(senarai)
    } else {
      for (const angka of item.angka) {
        const baris = document.createElement('label')
        baris.textContent = `${angka.jenis} ${angka.nilai}. Pilih tarikh dan unit.`
        const tarikh = document.createElement('input')
        tarikh.type = 'date'
        tarikh.dataset.tarikh = `${item.slot}:${angka.jenis}`
        const unit = document.createElement('select')
        unit.dataset.unit = `${item.slot}:${angka.jenis}`
        unit.append(new Option('Pilih unit', ''))
        unit.append(new Option('MYR', 'MYR'))
        if (angka.jenis === 'lain') unit.append(new Option('Unit', 'unit'))
        if (simpan && simpan.tarikh && simpan.tarikh.get(tarikh.dataset.tarikh)) tarikh.value = simpan.tarikh.get(tarikh.dataset.tarikh)
        if (simpan && simpan.unit && simpan.unit.get(unit.dataset.unit)) unit.value = simpan.unit.get(unit.dataset.unit)
        baris.append(tarikh, unit)
        kotak.append(baris)
      }
    }
    bekas.append(kotak)
  }
}

function capSumberSlot(slot) {
  try {
    if (slot.endsWith('telefon')) {
      const jenis = slot.startsWith('pelan') ? 'pelan' : 'angka'
      const kunci = BeshareDraf.kunciSkop(akaunId, jenis)
      return kunci ? localStorage.getItem(kunci) : null
    }
    const data = JSON.parse(localStorage.getItem(BeshareDraf.KUNCI_KUARANTIN) || 'null')
    if (!data || data.tanda !== BeshareDraf.TANDA || !data.salinan) return null
    if (slot === 'pelan-kuarantin') return typeof data.salinan.pelan === 'string' ? data.salinan.pelan : ''
    if (slot === 'angka-kuarantin') return typeof data.salinan.angka === 'string' ? data.salinan.angka : ''
  } catch {
    return null
  }
  return null
}

function tanpaKunci(badan) {
  const salin = { ...badan }
  delete salin.kunci_idem
  return salin
}

async function importSatu(slot, tindakan) {
  const item = sumberDraf().find((sumber) => sumber.slot === slot)
  const keadaan = document.getElementById('draf-keadaan')
  if (!item) return false
  const milik = document.getElementById('draf-milik').checked === true
  const persetujuan = document.getElementById('draf-setuju').checked === true
  let badan
  if (item.jenis === 'pelan') {
    badan = MejaAkaun.badanImportPelan({
      persetujuan,
      milik,
      kunci: 'a'.repeat(16),
      minggu: mingguSemasa().isnin,
      versi: pelanDimuat ? pelanAkaun.versi : 0,
      tindakan: tindakan || '',
      tugasan: item.tugasan,
    })
  } else {
    const rekod = []
    for (const angka of item.angka) {
      const tarikh = document.querySelector(`[data-tarikh="${slot}:${angka.jenis}"]`)
      const unit = document.querySelector(`[data-unit="${slot}:${angka.jenis}"]`)
      rekod.push({
        tarikh: tarikh ? tarikh.value : '',
        jenis: angka.jenis,
        nilai: angka.nilai,
        unit: unit ? unit.value : '',
        nota: '',
      })
    }
    badan = MejaAkaun.badanImportAngka({
      persetujuan,
      milik,
      kunci: 'a'.repeat(16),
      hariIni: mingguSemasa().hari,
      sahkan: tindakan === 'sahkan',
      rekod,
    })
  }
  if (!badan) {
    keadaan.textContent = milik && persetujuan
      ? 'Lengkapkan tarikh dan unit sebelum memindahkan angka.'
      : 'Import memerlukan pengesahan milik dan persetujuan.'
    return false
  }
  const capSumber = capSumberSlot(slot)
  const idSebelum = item.jenis === 'pelan' && pelanDimuat
    ? (pelanAkaun.tugasan || []).map((tugas) => tugas.id)
    : null
  const storKunci = MejaAkaun.kunciTunda(akaunId, slot)
  let tunda = null
  try { tunda = JSON.parse(localStorage.getItem(storKunci) || 'null') } catch { tunda = null }
  const kunci = tunda && tunda.kunci && MejaAkaun.samaBadan(tanpaKunci(tunda.badan), tanpaKunci(badan))
    ? tunda.kunci
    : MejaAkaun.kunciIdem()
  badan.kunci_idem = kunci
  localStorage.setItem(storKunci, JSON.stringify({ kunci, badan, capSumber }))
  let balas
  try {
    balas = await mintaAkaun('/api/akaun/import', { method: 'POST', badan, kunci })
  } catch {
    keadaan.textContent = 'Belum berjaya disimpan'
    return false
  }
  if (sesiTamat(balas.status)) return false
  if (balas.status === 409 && balas.data.ralat === 'konflik') {
    const pelayan = (balas.data.pelayan || []).join(', ') || 'tiada'
    keadaan.textContent = `Konflik — semak salinan pelayan. Dalam akaun: ${pelayan}`
    document.getElementById('draf-tambah').hidden = item.jenis !== 'pelan'
    return false
  }
  if (balas.status === 409 && balas.data.ralat === 'pendua') {
    keadaan.textContent = 'Konflik — semak salinan pelayan. Rekod yang sama sudah ada. Draf kekal.'
    document.getElementById('draf-tambah').hidden = false
    document.getElementById('draf-tambah').dataset.angka = slot
    document.getElementById('draf-tambah').textContent = 'Simpan juga rekod yang sama'
    return false
  }
  if (balas.status !== 200 || !balas.data.ok) {
    keadaan.textContent = 'Belum berjaya disimpan'
    return false
  }
  const idResit = item.jenis === 'pelan'
    ? MejaAkaun.idImportPelan(idSebelum, balas.data.pelan, badan.tugasan)
    : MejaAkaun.idImportAngka(balas.data, badan.rekod)
  if (!idResit) {
    keadaan.textContent = balas.data.sudah_ada
      ? 'Pelayan sudah mempunyai rekod ini, tetapi resit tidak mengenal pasti ID baharu. Draf kekal.'
      : 'Jawapan tidak mengenal pasti rekod yang diimport. Draf kekal.'
    return false
  }
  const sah = await sahkanResit(item.jenis, badan, idResit)
  if (!sah) {
    keadaan.textContent = 'Belum berjaya disimpan. Jawapan belum disahkan, draf kekal.'
    return false
  }
  if (!MejaAkaun.bolehBuangDraf(capSumber, capSumberSlot(slot))) {
    keadaan.textContent = 'Draf pada peranti ini berubah semasa simpan. Salinan baharu dikekalkan.'
    return false
  }
  if (!buangSumber(slot, item)) {
    keadaan.textContent = 'Akaun sudah mempunyai rekod ini. Draf kekal kerana salinan tempatan tidak dapat dikemas kini.'
    return false
  }
  localStorage.removeItem(storKunci)
  document.getElementById('draf-tambah').hidden = true
  if (item.jenis === 'pelan' && balas.data.pelan) gunaPelan(balas.data.pelan)
  return true
}

async function sahkanResit(jenis, badan, idResit) {
  try {
    if (jenis === 'pelan') {
      const balas = await mintaAkaun(`/api/akaun/pelan?minggu=${badan.minggu_mula}`)
      if (balas.status !== 200 || !MejaAkaun.sahkanIdPelan(balas.data.pelan, idResit)) return false
      if (balas.data.pelan) gunaPelan(balas.data.pelan)
      return true
    }
    const minggu = mingguSemasa()
    const paparan = MejaAkaun.tolakHari(minggu.hari, 90)
    const tarikh = badan.rekod.map((item) => item.tarikh).sort()
    const dari = tarikh[0] < paparan ? tarikh[0] : paparan
    const hingga = tarikh[tarikh.length - 1] > minggu.hari ? tarikh[tarikh.length - 1] : minggu.hari
    const balas = await mintaAkaun(`/api/akaun/angka?dari=${dari}&hingga=${hingga}`)
    if (balas.status !== 200 || !MejaAkaun.sahkanIdAngka(balas.data.rekod, idResit)) return false
    usangMuat()
    angkaAkaun = (balas.data.rekod || []).filter((item) => item.tarikh >= paparan && item.tarikh <= minggu.hari)
    angkaDimuat = true
    angkaGagal = false
    return true
  } catch {
    return false
  }
}

function buangSumber(slot, item) {
  try {
    if (slot.endsWith('telefon')) {
      const jenis = slot.startsWith('pelan') ? 'pelan' : 'angka'
      const kunci = BeshareDraf.kunciSkop(akaunId, jenis)
      if (!kunci) return false
      const baharu = jenis === 'pelan'
        ? MejaAkaun.buangTugasan(localStorage.getItem(kunci), item.tugasan)
        : MejaAkaun.buangAngka(localStorage.getItem(kunci), item.angka.map((angka) => angka.jenis))
      if (baharu) localStorage.setItem(kunci, baharu)
      else localStorage.removeItem(kunci)
      return localStorage.getItem(kunci) === baharu
    }
    const mentah = localStorage.getItem(BeshareDraf.KUNCI_KUARANTIN)
    const data = JSON.parse(mentah || 'null')
    if (!data || data.tanda !== BeshareDraf.TANDA || !data.salinan) return false
    if (slot === 'pelan-kuarantin') {
      const baharu = MejaAkaun.buangTugasan(data.salinan.pelan, item.tugasan)
      if (baharu) data.salinan.pelan = baharu
      else delete data.salinan.pelan
    } else if (slot === 'angka-kuarantin') {
      const baharu = MejaAkaun.buangAngka(data.salinan.angka, item.angka.map((angka) => angka.jenis))
      if (baharu) data.salinan.angka = baharu
      else delete data.salinan.angka
    } else return false
    const json = JSON.stringify(data)
    localStorage.setItem(BeshareDraf.KUNCI_KUARANTIN, json)
    return localStorage.getItem(BeshareDraf.KUNCI_KUARANTIN) === json
  } catch {
    return false
  }
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
  const disimpan = await simpan()
  if (!disimpan) return
  lukis()
  pergi('nasihat')
  muatMeja()
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
document.getElementById('konflik-muat').addEventListener('click', () => muatSalinanPelayan())
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
  hantarAngka(false)
})
document.getElementById('jenis-angka').addEventListener('change', () => lukisAngka())
document.getElementById('angka-muat').addEventListener('click', () => {
  if (angkaKonflik) isiAngka(angkaKonflik)
  angkaKonflik = null
  document.getElementById('angka-konflik').hidden = true
  setAngkaKeadaan('')
})
document.getElementById('angka-pendua').addEventListener('click', () => hantarAngka(true))
document.getElementById('pelan-muat').addEventListener('click', () => {
  if (!pelanKonflik) return
  usangMuat()
  pelanAkaun = {
    id: pelanKonflik.id || pelanAkaun.id,
    minggu: mingguSemasa().isnin,
    versi: pelanKonflik.versi,
    tugasan: pelanKonflik.tugasan || [],
  }
  pelanDimuat = true
  pelanGagal = false
  pelanKonflik = null
  setPelanKeadaan('')
  lukisPelan()
  lukisUtama()
})
document.getElementById('draf-hantar').addEventListener('click', async () => {
  const dipilih = [...document.querySelectorAll('#senarai-draf input[data-slot]:checked')]
  const keadaan = document.getElementById('draf-keadaan')
  if (!document.getElementById('draf-milik').checked || !document.getElementById('draf-setuju').checked) {
    keadaan.textContent = 'Import memerlukan pengesahan milik dan persetujuan.'
    return
  }
  if (!dipilih.length) {
    keadaan.textContent = 'Pilih draf yang mahu dipindahkan.'
    return
  }
  const slot = dipilih.map((kotak) => kotak.dataset.slot)
  let gagal = false
  for (const nama of slot) {
    const ok = await importSatu(nama)
    if (!ok) {
      gagal = true
      break
    }
  }
  lukisDraf(tangkapDraf())
  lukisPelan()
  lukisUtama()
  if (!gagal) keadaan.textContent = 'Disimpan dalam akaun'
})
document.getElementById('draf-tambah').addEventListener('click', async () => {
  const pelan = document.querySelector('#senarai-draf input[data-slot^="pelan"]:checked')
  const angka = document.getElementById('draf-tambah').dataset.angka
  const slot = pelan ? pelan.dataset.slot : angka
  if (!slot) return
  const ok = await importSatu(slot, pelan ? 'tambah_baharu' : 'sahkan')
  if (ok) {
    document.getElementById('draf-keadaan').textContent = ''
    lukisDraf()
    lukisUtama()
  }
})
document.querySelectorAll('[data-pilih-tema]').forEach((butang) => {
  butang.addEventListener('click', () => gunaTema(butang.dataset.pilihTema))
})
document.getElementById('borang-tugas').addEventListener('submit', (event) => {
  event.preventDefault()
  const medan = document.getElementById('tulis-tugas')
  tambahTugas(medan.value, medan)
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

let tinggiLog = 0
let abaikanSkrol = false
log.addEventListener('scroll', () => {
  if (abaikanSkrol || log.clientHeight < 1) return
  if (tinggiLog !== 0 && log.clientHeight !== tinggiLog) {
    tinggiLog = log.clientHeight
    return
  }
  tinggiLog = log.clientHeight
  logTertambat = logDiHujung()
}, { passive: true })
if (typeof ResizeObserver !== 'undefined') {
  const pemerhatiLog = new ResizeObserver(() => {
    tinggiLog = log.clientHeight
    if (!logTertambat || log.clientHeight < 1) return
    abaikanSkrol = true
    log.scrollTop = log.scrollHeight
    abaikanSkrol = false
  })
  pemerhatiLog.observe(log)
}

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
