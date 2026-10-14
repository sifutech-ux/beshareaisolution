const perkhidmatan = (typeof BeshareAsal !== 'undefined' && BeshareAsal && typeof BeshareAsal.asalPerkhidmatan === 'function')
  ? BeshareAsal.asalPerkhidmatan(location.origin)
  : null
const AKAUN = perkhidmatan ? perkhidmatan.akaun : null
const KUNCI = 'beshare-akaun'

const borang = document.getElementById('borang')
const sudah = document.getElementById('sudah')
const ralat = document.getElementById('ralat')
const hantar = document.getElementById('hantar')
const emel = document.getElementById('email')
const kata = document.getElementById('password')
let daftar = false

try { BeshareDraf.kuarantinLegasi(localStorage, sessionStorage) } catch { /* asal kekal jika kuarantin gagal */ }

function sesi() {
  try {
    const data = JSON.parse(localStorage.getItem(KUNCI) || 'null')
    if (!data || typeof data.token !== 'string' || typeof data.email !== 'string') return null
    return { token: data.token, email: data.email }
  } catch {
    return null
  }
}

function tunjukSesi() {
  const data = sesi()
  if (!data) {
    sudah.hidden = true
    borang.hidden = false
    return
  }
  document.getElementById('emel-semasa').textContent = data.email
  sudah.hidden = false
  borang.hidden = true
}

function setDaftar(nilai) {
  daftar = nilai
  document.getElementById('mod-daftar').classList.toggle('hidup', nilai)
  document.getElementById('mod-masuk').classList.toggle('hidup', !nilai)
  hantar.textContent = nilai ? 'Daftar' : 'Masuk'
  kata.autocomplete = nilai ? 'new-password' : 'current-password'
}

function ralatTeks(mesej) {
  ralat.hidden = false
  ralat.textContent = mesej
}

borang.addEventListener('submit', async (event) => {
  event.preventDefault()
  const email = emel.value.trim().toLowerCase()
  const password = kata.value
  if (!email.includes('@') || password.length < 8) {
    ralatTeks('Guna e-mel yang sah dan kata laluan sekurang-kurangnya 8 aksara.')
    return
  }
  if (!AKAUN) {
    ralatTeks('Laman ini tidak disambungkan ke akaun.')
    return
  }
  ralat.hidden = true
  hantar.disabled = true
  try {
    const balas = await fetch(`${AKAUN}/api/akaun/${daftar ? 'daftar' : 'masuk'}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    })
    const data = await balas.json().catch(() => ({}))
    if (!balas.ok || typeof data.token !== 'string') {
      throw new Error(typeof data.error === 'string' ? data.error : 'Akaun tidak dapat dihubungi. Cuba semula.')
    }
    localStorage.setItem(KUNCI, JSON.stringify({ token: data.token, email: data.email || email }))
    const selepas = new URLSearchParams(location.search).get('selepas')
    location.href = selepas === 'penasihat' ? '../penasihat/' : '../'
  } catch (error) {
    ralatTeks(error instanceof Error && error.message && !error.message.includes('{') ? error.message : 'Akaun tidak dapat dihubungi. Cuba semula.')
    hantar.disabled = false
  }
})

document.getElementById('mod-masuk').addEventListener('click', () => setDaftar(false))
document.getElementById('mod-daftar').addEventListener('click', () => setDaftar(true))
document.getElementById('keluar').addEventListener('click', () => {
  localStorage.removeItem(KUNCI)
  tunjukSesi()
})

tunjukSesi()
