'use strict'
const params = new URLSearchParams(location.search)
const marketing = params.get('produk') === 'marketing'
const source = marketing ? 'marketing' : 'penasihat'
const byId = id => document.getElementById(id)
const name = marketing ? 'BeShare AI Marketing' : 'Business Copilot'
byId('title').textContent = 'Akses '+name
try { byId('account').textContent = JSON.parse(localStorage.getItem('beshare-akaun'))?.email || '' } catch {}
byId('check').href = marketing ? '../marketing/' : '../penasihat/'
byId('pay').href = marketing ? 'https://buy.stripe.com/4gMdR167H7rD9Tg9sb8EM01' : 'https://buy.stripe.com/3cIaEP9jT7rD5D0gUD8EM00'
byId('pay').textContent = 'Langgan '+(marketing ? 'RM69' : 'RM49')+' sebulan'
if(params.get('status')==='suspended'){
  byId('pay').hidden=true
  byId('note').textContent='Akses produk ini digantung. Hubungi BeShare untuk menyemak akaun anda.'
}
byId('switch').onclick=()=>{ localStorage.removeItem('beshare-akaun'); location.assign('./?selepas='+source) }
