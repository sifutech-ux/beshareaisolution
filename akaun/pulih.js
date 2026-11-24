'use strict'
const by = id => document.getElementById(id)
const token = location.hash.slice(1)
const changing = Boolean(token)
if (changing) history.replaceState(null, '', location.pathname)
by('mail-label').hidden = changing
by('mail').required = !changing
by('new-label').hidden = by('confirm-label').hidden = !changing
by('new-password').required = by('confirmation').required = changing
if (changing) by('send').textContent = 'Simpan kata laluan'
by('recovery').onsubmit = async e => {
  e.preventDefault()
  if (changing && by('new-password').value !== by('confirmation').value) {
    by('feedback').textContent = 'Kedua-dua kata laluan perlu sama.'; return
  }
  by('send').disabled = true
  try {
    const url = changing ? BeshareAsal.asalPerkhidmatan(location.origin).akaun+'/api/akaun/pulih' : 'lupa.php'
    const body = changing ? {token,password:by('new-password').value} : {email:by('mail').value}
    const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body),cache:'no-store'})
    const result = await r.json()
    if (!r.ok || !result.ok) throw Error(result.error || 'Permintaan belum berjaya. Cuba lagi sebentar.')
    if(changing) localStorage.removeItem('beshare-akaun')
    by('recovery').reset()
    by('feedback').textContent = changing ? 'Kata laluan telah ditukar. Kembali ke akaun untuk masuk semula.' : 'Jika e-mel ini berdaftar, pautan akan dihantar. Semak juga folder spam.'
  } catch(error) { by('feedback').textContent = error.message }
  finally { by('send').disabled = false }
}
