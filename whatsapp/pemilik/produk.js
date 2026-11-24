'use strict'
;(() => {
  const el = id => document.getElementById('product-' + id)
  const products = { copilot: 'Business Copilot', marketing: 'AI Marketing', os: 'AI OS' }
  const labels = { pending: 'Menunggu bayaran', free: 'Percuma', paid: 'Berbayar', suspended: 'Digantung', setup: 'Ujian berpandu' }
  let page = 1, sequence = 0, selected
  function terms() { el('billing').hidden = el('state').value !== 'paid' }
  function edit(source, user, product, grant) {
    selected = {source, id: user.id, product, revision: grant.revision}
    el('target').textContent = user.email + ' · ' + products[product]
    el('state').querySelector('option[value="setup"]').hidden = source !== 'os'
    el('state').value = grant.state
    el('until').value = ''; el('invoice').value = ''; el('message').textContent = ''
    terms(); el('edit').showModal()
  }
  async function refresh() {
    const n = ++sequence, source = el('source').value
    el('users').replaceChildren(); el('status').textContent = 'Memuatkan pengguna…'
    el('prev').disabled = true; el('next').disabled = true
    try {
      const data = await api('produk', {source, q: el('query').value, page})
      if (n !== sequence) return
      for (const user of data.users) {
        for (const product of source === 'os' ? ['os'] : ['copilot','marketing']) {
          const grant = user.grants[product] || {state:'pending', revision:0, until:0}
          const row = el('users').insertRow()
          row.insertCell().textContent = [user.name, user.email].filter(Boolean).join(' · ')
          row.insertCell().textContent = products[product]
          const expiry = grant.until ? new Date((grant.until-1)*1000).toLocaleString('ms-MY',{timeZone:'Asia/Kuala_Lumpur'}) : ''
          row.insertCell().textContent = (expiry && grant.until <= Date.now()/1000 ? 'Tamat' : labels[grant.state]) + (expiry ? ' · '+expiry : '')
          const b = document.createElement('button'); b.type = 'button'; b.className='button button-small'
          b.textContent='Ubah akses'; b.onclick=()=>edit(source,user,product,grant); row.insertCell().append(b)
        }
      }
      el('status').textContent = data.users.length ? 'Halaman '+page : 'Tiada pengguna ditemui.'
      el('prev').disabled = page <= 1; el('next').disabled = !data.next
    } catch(e) { if(n === sequence) el('status').textContent=e.message }
  }
  el('search').onsubmit=e=>{e.preventDefault();page=1;refresh()}
  el('source').onchange=()=>{page=1;refresh()}
  el('prev').onclick=()=>{page--;refresh()}; el('next').onclick=()=>{page++;refresh()}
  el('cancel').onclick=()=>el('edit').close(); el('state').onchange=terms
  el('form').onsubmit=async e=>{
    e.preventDefault(); el('save').disabled=true
    try {
      await api('izin',{...selected,state:el('state').value,receipt:el('invoice').value.trim(),until:el('until').value,confirmed:true})
      el('edit').close(); await refresh()
    } catch(error) { el('message').textContent=error.message }
    finally { el('save').disabled=false }
  }
  ownerBoot.then(()=>{ if(state.admin) refresh() })
})()
