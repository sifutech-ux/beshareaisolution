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
const PERINGKAT = {
  mula: 'Baru nak mula',
  jual: 'Sudah jual',
  kembang: 'Nak kembangkan',
}

const pintu = document.getElementById('pintu')
const borang = document.getElementById('borang')
const sembang = document.getElementById('sembang')
const log = document.getElementById('log')
const ringkas = document.getElementById('ringkas')
const status = document.getElementById('status')
const ralat = document.getElementById('ralat-borang')
const tulis = document.getElementById('tulis')
const hantar = document.getElementById('hantar')
const cakap = document.getElementById('cakap')

let keadaan = null
let sibuk = false
let media = null
let rakaman = null
let ketulan = []
let masaRakam = 0
let audioCtx = null
let sumber = null

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

function tunjukPintu() {
  pintu.hidden = false
  borang.hidden = true
  sembang.hidden = true
}

function keluarAkaun() {
  localStorage.removeItem(AKAUN_KUNCI)
  localStorage.removeItem(SIMPAN)
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
  }
}

async function mula() {
  if (!sesiAkaun()) {
    tunjukPintu()
    return
  }
  pintu.hidden = true
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
  } catch {
    keadaan = baca()
    status.textContent = 'Akaun tidak dapat dihubungi. Salinan telefon ditunjukkan dahulu.'
  }
  lukis()
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

function tunjukBorang(buka) {
  pintu.hidden = true
  borang.hidden = !buka
  sembang.hidden = buka
  if (buka && keadaan) {
    document.getElementById('nama').value = keadaan.nama
    document.getElementById('jualan').value = keadaan.jualan
    const radio = borang.querySelector(`input[value="${keadaan.peringkat}"]`)
    if (radio) radio.checked = true
  }
}

function lukis() {
  if (!keadaan) {
    tunjukBorang(true)
    return
  }
  tunjukBorang(false)
  const emel = sesiAkaun()?.email
  ringkas.textContent = `${keadaan.nama} · ${PERINGKAT[keadaan.peringkat] || ''}${emel ? ` · ${emel}` : ''}`
  log.replaceChildren()
  for (const item of keadaan.mesej) {
    const balon = document.createElement('article')
    balon.className = `gelembung gelembung--${item.dari === 'klien' ? 'klien' : 'penasihat'}`
    const teks = document.createElement('p')
    teks.textContent = item.teks
    balon.append(teks)
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
      const butang = document.createElement('button')
      butang.type = 'button'
      butang.className = 'dengar'
      butang.textContent = 'Dengar'
      butang.addEventListener('click', () => {
        bukaAudio()
        mainSuara(item, butang)
      })
      balon.append(butang)
    }
    log.append(balon)
  }
  log.scrollTop = log.scrollHeight
}

function giliran() {
  return keadaan.mesej.map((item) => ({
    dari: item.dari,
    teks: item.langkah?.length ? `${item.teks} Langkah minggu ini: ${item.langkah.join(' ')}` : item.teks,
  }))
}

async function hantarTeks(teks, mainkan) {
  if (!teks || sibuk || !keadaan) return
  keadaan.mesej.push({ dari: 'klien', teks })
  tulis.value = ''
  sibuk = true
  hantar.disabled = true
  cakap.disabled = true
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
    status.textContent = ''
    simpan()
    lukis()
    if (mainkan) await mainSuara(jawapan)
  } catch (error) {
    status.textContent = ayatPelayar(error, 'Penasihat sedang sibuk. Tunggu sebentar, kemudian hantar sekali lagi.')
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
  cakap.textContent = 'Cakap'
  cakap.classList.remove('rakam')
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
    cakap.textContent = 'Cakap'
    cakap.classList.remove('rakam')
    const blob = new Blob(ketulan, { type: rakaman.mimeType || jenis || 'audio/webm' })
    if (blob.size < 800) {
      failCakap('Suara tidak jelas. Cuba cakap sekali lagi, atau tulis mesej.')
      return
    }
    hantarRakaman(blob)
  }
  rakaman.start()
  cakap.textContent = 'Berhenti'
  cakap.classList.add('rakam')
  hantar.disabled = true
  status.textContent = 'Cakap sekarang. Tekan Berhenti bila siap.'
  masaRakam = setTimeout(() => {
    if (rakaman && rakaman.state === 'recording') rakaman.stop()
  }, 20000)
}

borang.addEventListener('submit', async (event) => {
  event.preventDefault()
  const nama = document.getElementById('nama').value.replace(/\s+/g, ' ').trim()
  const jualan = document.getElementById('jualan').value.replace(/\s+/g, ' ').trim()
  const peringkat = borang.querySelector('input[name="peringkat"]:checked')?.value
  if (nama.length < 2 || jualan.length < 8 || !peringkat) {
    ralat.hidden = false
    ralat.textContent = 'Isi nama, apa yang dijual, dan pilih peringkat.'
    return
  }
  ralat.hidden = true
  const sama = keadaan && keadaan.nama === nama && keadaan.jualan === jualan && keadaan.peringkat === peringkat
  keadaan = {
    nama,
    jualan,
    peringkat,
    mesej: sama ? keadaan.mesej : [{ dari: 'penasihat', teks: `Baik. Saya catat ${nama}. Cerita apa yang awak nak susun untuk minggu ini.` }],
  }
  await simpan()
  lukis()
  tulis.focus()
})

document.getElementById('tukar').addEventListener('click', () => tunjukBorang(true))
document.getElementById('keluar').addEventListener('click', () => keluarAkaun())
cakap.addEventListener('click', () => {
  bukaAudio()
  togolCakap()
})

document.getElementById('mesej').addEventListener('submit', (event) => {
  event.preventDefault()
  const teks = tulis.value.replace(/\s+/g, ' ').trim()
  hantarTeks(teks, false)
})

mula()
