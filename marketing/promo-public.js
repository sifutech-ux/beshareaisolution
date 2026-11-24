(() => {
  'use strict'
  const $ = id => document.getElementById(id)
  const query = new URLSearchParams(location.search)
  const id = query.get('p') || ''
  const base = window.BeshareAsal.asalPerkhidmatan(location.origin)?.akaun
  const endpoint = base + '/api/akaun/promosi/' + id
  const sources = ['google', 'telegram', 'qr', 'facebook', 'instagram', 'threads', 'whatsapp']
  const tag = (query.get('utm_source') || '').toLowerCase()
  const source = sources.includes(tag) ? tag : tag ? 'other' : 'direct'
  const submission = Array.from(crypto.getRandomValues(new Uint8Array(16)), v => v.toString(16).padStart(2, '0')).join('')

  async function load() {
    if (!base || !/^[a-f0-9]{32}$/.test(id)) throw Error('Pautan promosi tidak sah.')
    const response = await fetch(endpoint, { credentials: 'omit', cache: 'no-store' })
    if (!response.ok) throw Error(response.status === 404 ? 'Halaman promosi tidak tersedia.' : 'Halaman gagal dimuatkan. Cuba muat semula.')
    const { page } = await response.json()
    for (const field of ['business', 'area', 'title', 'offer', 'cta']) $(field).textContent = page[field]
    $('recipient').textContent = page.business
    document.title = page.title + ' · ' + page.business
    if (page.image) {
      $('cover').onerror = () => { $('cover').hidden = true }
      $('cover').src = page.image
      $('cover').hidden = false
    }
    $('loading').hidden = true
    $('promotion').hidden = false
  }

  $('enquiry').addEventListener('submit', async event => {
    event.preventDefault()
    const form = event.target, button = form.querySelector('button')
    if (button.disabled) return
    button.disabled = true
    $('receipt').textContent = 'Menghantar pertanyaan…'
    const fields = new FormData(form)
    try {
      const response = await fetch(endpoint + '/enquiries', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'omit',
        body: JSON.stringify({ id: submission, name: fields.get('name'), contact: fields.get('contact'),
          note: fields.get('note'), website: fields.get('website'), consent: fields.has('consent'), source })
      })
      const result = await response.json()
      if (!response.ok || !result.ok) throw Error(result.error || 'Pertanyaan gagal dihantar.')
      form.reset()
      form.hidden = true
      $('receipt').textContent = 'Pertanyaan anda sudah diterima oleh ' + $('recipient').textContent + '.'
      $('receipt').focus()
    } catch (error) {
      $('receipt').textContent = error instanceof TypeError ? 'Sambungan terputus. Cuba hantar semula.' : error.message
      button.disabled = false
    }
  })
  load().catch(error => { $('loading').textContent = error instanceof TypeError ? 'Sambungan terputus. Sila muat semula.' : error.message })
})()
