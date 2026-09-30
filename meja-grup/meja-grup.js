const KUNCI = 'beshare-meja-grup'
const namaGrup = document.getElementById('nama-grup')
const ralatGrup = document.getElementById('ralat-grup')
const senarai = document.getElementById('senarai')
const teks = document.getElementById('teks')
const gambar = document.getElementById('gambar')
const pratonton = document.getElementById('pratonton')
const ralatIklan = document.getElementById('ralat-iklan')
const ringkas = document.getElementById('ringkas')
const ringkasTeks = document.getElementById('ringkas-teks')
const ringkasGrup = document.getElementById('ringkas-grup')

let draf = baca()

function baca() {
  try {
    const data = JSON.parse(localStorage.getItem(KUNCI) || 'null')
    if (!data || !Array.isArray(data.grup)) return { grup: [], teks: '', gambar: '' }
    data.grup = data.grup
      .filter((item) => item && typeof item.nama === 'string' && !pautan(item.nama))
      .slice(0, 30)
      .map((item) => ({ nama: item.nama.slice(0, 80), tanda: Boolean(item.tanda) }))
    data.teks = typeof data.teks === 'string' ? data.teks.slice(0, 700) : ''
    data.gambar = typeof data.gambar === 'string' && data.gambar.startsWith('data:image/') ? data.gambar : ''
    return data
  } catch {
    return { grup: [], teks: '', gambar: '' }
  }
}

function simpanTempatan() {
  localStorage.setItem(KUNCI, JSON.stringify(draf))
}

function pautan(nilai) {
  return /chat\.whatsapp\.com|wa\.me\/|https?:\/\//i.test(nilai)
}

function ralat(kotak, mesej) {
  kotak.hidden = !mesej
  kotak.textContent = mesej || ''
}

function lukis() {
  senarai.replaceChildren()
  for (const item of draf.grup) {
    const baris = document.createElement('li')
    const label = document.createElement('label')
    const kotak = document.createElement('input')
    kotak.type = 'checkbox'
    kotak.checked = item.tanda
    kotak.addEventListener('change', () => {
      item.tanda = kotak.checked
      simpanTempatan()
    })
    label.append(kotak, document.createTextNode(` ${item.nama}`))
    const buang = document.createElement('button')
    buang.type = 'button'
    buang.className = 'buang'
    buang.textContent = 'Buang'
    buang.addEventListener('click', () => {
      draf.grup = draf.grup.filter((grup) => grup !== item)
      simpanTempatan()
      lukis()
    })
    baris.append(label, buang)
    senarai.append(baris)
  }
  teks.value = draf.teks
  if (draf.gambar) {
    pratonton.src = draf.gambar
    pratonton.hidden = false
  }
}

document.getElementById('tambah-grup').addEventListener('submit', (event) => {
  event.preventDefault()
  const nama = namaGrup.value.replace(/\s+/g, ' ').trim()
  if (nama.length < 2) {
    ralat(ralatGrup, 'Tulis nama grup yang nombor ini sudah sertai.')
    return
  }
  if (pautan(nama)) {
    ralat(ralatGrup, 'Letakkan nama grup sahaja. Pautan jemputan tidak diterima.')
    return
  }
  if (draf.grup.some((item) => item.nama.toLowerCase() === nama.toLowerCase())) {
    ralat(ralatGrup, 'Grup ini sudah ada dalam senarai.')
    return
  }
  if (draf.grup.length >= 30) {
    ralat(ralatGrup, 'Senarai sudah penuh. Buang satu grup dahulu.')
    return
  }
  ralat(ralatGrup, '')
  draf.grup.push({ nama, tanda: true })
  namaGrup.value = ''
  simpanTempatan()
  lukis()
})

teks.addEventListener('input', () => {
  draf.teks = teks.value.slice(0, 700)
})

gambar.addEventListener('change', () => {
  const fail = gambar.files && gambar.files[0]
  if (!fail) return
  if (!/^image\/(jpeg|png|webp)$/.test(fail.type) || fail.size > 400000) {
    ralat(ralatIklan, 'Guna gambar JPG, PNG atau WEBP di bawah 400 KB.')
    gambar.value = ''
    return
  }
  const bacaFail = new FileReader()
  bacaFail.onload = () => {
    draf.gambar = String(bacaFail.result || '')
    pratonton.src = draf.gambar
    pratonton.hidden = false
    ralat(ralatIklan, '')
  }
  bacaFail.readAsDataURL(fail)
})

document.getElementById('simpan').addEventListener('click', () => {
  const bersih = teks.value.replace(/\s+/g, ' ').trim()
  const dipilih = draf.grup.filter((item) => item.tanda)
  if (bersih.length < 8 || !dipilih.length) {
    ralat(ralatIklan, 'Tulis iklan dan tandakan sekurang-kurangnya satu grup yang sudah disertai.')
    return
  }
  draf.teks = bersih
  simpanTempatan()
  ralat(ralatIklan, '')
  ringkas.hidden = false
  ringkasTeks.textContent = draf.teks
  ringkasGrup.textContent = `Grup ditanda: ${dipilih.map((item) => item.nama).join(', ')}. Draf ini tersimpan pada telefon. Ia tidak dihantar ke WhatsApp.`
})

lukis()
if (draf.teks && draf.grup.some((item) => item.tanda)) {
  document.getElementById('simpan').click()
}
