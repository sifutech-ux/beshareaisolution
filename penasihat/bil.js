/* Billing status comes from the authenticated account service, never the return URL. */
(() => {
  const card = document.getElementById('copilot-billing')
  if (!card) return
  const note = document.getElementById('copilot-billing-note')
  const actions = document.getElementById('copilot-billing-actions')
  let busy = false
  const labels = { none: 'Belum melanggan', trialing: 'Percubaan aktif', active: 'Aktif', paused: 'Percubaan tamat — tambah kaedah bayaran', past_due: 'Bayaran perlu dikemas kini', canceled: 'Dibatalkan', unpaid: 'Belum dibayar', incomplete: 'Menunggu bayaran', incomplete_expired: 'Checkout tamat' }
  const button = (label, action) => {
    const b = document.createElement('button')
    b.type = 'button'; b.className = 'butang butang-senyap'; b.dataset.bill = action; b.textContent = label
    actions.appendChild(b)
  }
  async function request(action) {
    if (busy) return
    busy = true
    const account = localStorage.getItem('beshare-akaun')
    actions.querySelectorAll('button').forEach(b => { b.disabled = true })
    note.textContent = 'Sedang menyemak…'
    try {
      const response = await mintaAkaun('/api/akaun/bil' + (action === 'refresh' ? '' : '/' + action),
        action === 'refresh' ? {} : { method: 'POST', badan: {} })
      if (localStorage.getItem('beshare-akaun') !== account) return
      const data = response.data
      if (response.status !== 200 || data.ok !== true) throw new Error(data.error || 'Pembayaran sedang disediakan.')
      if (action !== 'refresh') {
        const url = new URL(data.url)
        if (url.protocol !== 'https:' || !['checkout.stripe.com', 'billing.stripe.com'].includes(url.hostname) || url.username || url.password || url.port) throw new Error('Pautan pembayaran tidak sah.')
        location.assign(url.href)
        return
      }
      const status = ['active', 'trialing'].includes(data.status) && !data.active ? 'Tempoh akses belum disahkan atau sudah tamat' : labels[data.status] || 'Sedang disahkan'
      document.getElementById('copilot-billing-summary').textContent = `RM${(data.amount / 100).toFixed(0)} / bulan · ${status}`
      document.getElementById('copilot-billing-mode').textContent = data.mode === 'test' ? 'Mod ujian — tiada caj sebenar.' : ''
      actions.replaceChildren()
      if (['none', 'canceled', 'incomplete_expired'].includes(data.status)) button(data.trial_available ? 'Cuba 7 hari' : 'Mulakan langganan', 'checkout')
      if (data.has_customer) button('Urus langganan & invois', 'portal')
      button('Muat semula status', 'refresh')
      note.textContent = data.cancel_at_period_end ? 'Pembatalan dijadualkan pada akhir tempoh.' : data.access_until ? `Akses sehingga ${new Date(data.access_until * 1000).toLocaleDateString('ms-MY')}.` : 'Status dikemas kini selepas pengesahan Stripe.'
    } catch (error) { note.textContent = error.message || 'Cuba semula sebentar lagi.' }
    finally { busy = false; actions.querySelectorAll('button').forEach(b => { b.disabled = false }) }
  }
  card.addEventListener('click', event => {
    const action = event.target.closest('[data-bill]')?.dataset.bill
    if (['refresh', 'checkout', 'portal'].includes(action)) request(action)
  })
  window.addEventListener('hashchange', () => { if (location.hash === '#profil') request('refresh') })
  if (location.hash === '#profil') request('refresh')
})()
