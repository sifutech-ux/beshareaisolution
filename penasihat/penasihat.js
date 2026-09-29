const API = ['localhost', '127.0.0.1'].includes(location.hostname)
  ? 'http://127.0.0.1:3210/api/penasihat'
  : 'https://ai-video-saas-ten.vercel.app/api/penasihat'

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

let keadaan = baca()
let sibuk = false

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
    log.append(balon)
  }
  log.scrollTop = log.scrollHeight
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

document.getElementById('mesej').addEventListener('submit', async (event) => {
  event.preventDefault()
  const teks = tulis.value.replace(/\s+/g, ' ').trim()
  if (!teks || sibuk || !keadaan) return
  keadaan.mesej.push({ dari: 'klien', teks })
  tulis.value = ''
  sibuk = true
  hantar.disabled = true
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
        giliran: keadaan.mesej.map((item) => ({
          dari: item.dari,
          teks: item.langkah?.length ? `${item.teks} Langkah minggu ini: ${item.langkah.join(' ')}` : item.teks,
        })),
      }),
    })
    const data = await balas.json()
    if (!balas.ok || typeof data.jawapan !== 'string' || !Array.isArray(data.langkah) || data.langkah.length < 3) {
      throw new Error(typeof data.error === 'string' ? data.error : 'gagal')
    }
    keadaan.mesej.push({
      dari: 'penasihat',
      teks: data.jawapan,
      langkah: data.langkah.slice(0, 3),
    })
    status.textContent = ''
  } catch (error) {
    const mesej = error instanceof Error && error.message && error.message !== 'gagal'
      ? error.message
      : 'Penasihat sedang sibuk. Tunggu sebentar, kemudian hantar sekali lagi.'
    status.textContent = mesej
  }
  sibuk = false
  hantar.disabled = false
  simpan()
  lukis()
})

lukis()
