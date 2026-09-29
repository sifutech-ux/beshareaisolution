const ASAL = ['localhost', '127.0.0.1'].includes(location.hostname)
  ? 'http://127.0.0.1:3210'
  : 'https://ai-video-saas-ten.vercel.app'
const API = `${ASAL}/api/penasihat`
const DENGAR_API = `${ASAL}/api/penasihat-dengar`
const SUARA_API = `${ASAL}/api/penasihat-suara`

const SIMPAN = 'beshare-penasihat'
const PERINGKAT = {
  mula: 'Baru nak mula',
  jual: 'Sudah jual',
  kembang: 'Nak kembangkan',
}

const borang = document.getElementById('borang')
const sembang = document.getElementById('sembang')
const log = document.getElementById('log')
const ringkas = document.getElementById('ringkas')
const status = document.getElementById('status')
const ralat = document.getElementById('ralat-borang')
const tulis = document.getElementById('tulis')
const hantar = document.getElementById('hantar')
const cakap = document.getElementById('cakap')

let keadaan = baca()
let sibuk = false
let media = null
let rakaman = null
let ketulan = []
let masaRakam = 0
let audioCtx = null
let sumber = null

const MIKROFON = 'Mikrofon tidak dibenarkan. Tekan Cakap sekali lagi dan pilih Benarkan.'
const SUARA_GAGAL = 'Suara belum dapat didengar. Tekan Dengar sekali lagi, atau baca jawapan di skrin.'

function baca() {
  try {
    const data = JSON.parse(sessionStorage.getItem(SIMPAN) || 'null')
    if (!data || typeof data.nama !== 'string' || !Array.isArray(data.mesej)) return null
    return data
  } catch {
    return null
  }
}

function simpan() {
  sessionStorage.setItem(SIMPAN, JSON.stringify(keadaan))
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
  ringkas.textContent = `${keadaan.nama} · ${PERINGKAT[keadaan.peringkat] || ''}`
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

borang.addEventListener('submit', (event) => {
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
  simpan()
  lukis()
  tulis.focus()
})

document.getElementById('tukar').addEventListener('click', () => tunjukBorang(true))
cakap.addEventListener('click', () => {
  bukaAudio()
  togolCakap()
})

document.getElementById('mesej').addEventListener('submit', (event) => {
  event.preventDefault()
  const teks = tulis.value.replace(/\s+/g, ' ').trim()
  hantarTeks(teks, false)
})

lukis()
