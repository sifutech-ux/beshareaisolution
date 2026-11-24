'use strict'
window.BeSharePromos = function ({ state, request, campaignOptions, el, API }) {
  const $ = id => document.getElementById(id), C = window.MarketingCore
  const select = $('promo-campaign'), search = $('search-form')
  const fields = ['business', 'area', 'title', 'offer', 'cta', 'image']
  const statuses = { baru: 'Baharu', dihubungi: 'Sudah dihubungi', tempahan: 'Tempahan disahkan' }
  const sources = { direct: 'Pautan biasa', google: 'Google', telegram: 'Telegram', qr: 'Kod QR', other: 'Sumber lain', whatsapp: 'WhatsApp', facebook: 'Facebook', instagram: 'Instagram', threads: 'Threads' }
  let active = '', version = 0, loaded = null, after = '', listVersion = 0, writing = false
  let keys = {}, generation = null
  const endpoint = () => API + '/promos/' + active
  const plan = () => state.campaigns.find(item => item.id === active)
  const valid = ticket => ticket === version && state.ready
  const read = () => Object.fromEntries(fields.map(f => [f, $('promo-' + f).value.trim()]))

  function options() {
    campaignOptions(select, { emptyLabel: 'Pilih kempen', current: select.value })
    if (state.ready && state.view === 'promosi' && !active && !select.value && select.options.length === 2) select.selectedIndex = 1
    if (state.ready && state.view === 'promosi' && active !== select.value) show()
  }
  function reset() {
    version++
    listVersion++
    generation?.abort()
    generation = null
    keys = {}
    loaded = null
    active = ''
    after = ''
    writing = false
    $('promo-editor').reset()
    search.reset()
    delete search.dataset.edited
    $('promo-leads').replaceChildren()
    for (const id of ['promo-count', 'promo-status', 'promo-feedback', 'search-feedback', 'promo-link']) $(id).textContent = ''
    for (const id of ['promo-link', 'promo-qr']) $(id).removeAttribute('href')
    $('promo-tools').hidden = $('promo-share').hidden = $('promo-more').hidden = true
    $('promo-edit-fields').disabled = $('search-fields').disabled = false
    for (const b of document.querySelectorAll('#promo-editor button,#search-form button,#promo-refresh,#promo-more')) b.disabled = false
  }
  function url(source) {
    const u = new URL('promosi.html', location.href)
    u.searchParams.set('p', active)
    if (source) {
      u.searchParams.set('utm_source', source)
      u.searchParams.set('utm_medium', source === 'google' ? 'cpc' : source === 'qr' ? 'print' : 'social')
      u.searchParams.set('utm_campaign', active)
    }
    return u.href
  }
  function share() {
    const published = loaded?.published && plan()?.status !== 'archived'
    $('promo-share').hidden = !published
    $('promo-hide').hidden = !loaded?.published
    $('promo-draft').hidden = !!loaded?.published
    $('promo-editor').querySelector('[value=live]').textContent = loaded?.published ? 'Kemas kini halaman' : 'Terbitkan halaman'
    if (!published) return
    const target = url($('promo-source').value)
    $('promo-link').href = target
    $('promo-link').textContent = target
    const qr = window.qrcode(0, 'M')
    qr.addData(url('qr'))
    qr.make()
    $('promo-qr').href = qr.createDataURL(7, 28)
  }
  function defaults(page, campaign) {
    const short = (s, n) => Array.from((s || '').replace(/\s+/g, ' ').trim()).slice(0, n).join('').trim()
    const product = short(campaign.product || page.title, 50)
    search.elements.group.value = product
    search.elements.keywords.value = [product, short(product + ' ' + page.area, 80)].filter((x,i,a) => x && a.indexOf(x) === i).join('\n')
    search.elements.headlines.value = [short(page.title, 30), short(page.cta, 30), 'Hubungi ' + short(page.business, 22)].join('\n')
    search.elements.descriptions.value = [short(page.offer, 90), short('Hantar pertanyaan kepada ' + page.business + ' melalui borang promosi ini.', 90)].join('\n')
  }
  async function show() {
    if (active && active === select.value) return
    reset()
    active = select.value
    if (!active) {
      $('promo-status').textContent = state.campaigns.length ? 'Pilih kempen untuk membuka halaman dan pertanyaannya.' : 'Cipta kempen melalui butang Kempen baharu untuk bermula.'
      return
    }
    const ticket = version
    $('promo-status').textContent = 'Memuat halaman…'
    try {
      const result = await request(endpoint())
      if (!valid(ticket)) return
      loaded = result.page
      const campaign = plan()
      const page = loaded || { title: campaign.title.slice(0, 120), business: '', area: '', offer: campaign.offer || campaign.product || '', cta: 'Minta sebut harga', image: '' }
      for (const f of fields) $('promo-' + f).value = page[f]
      defaults(page, campaign)
      $('promo-tools').hidden = false
      share()
      $('promo-status').textContent = campaign.status === 'archived' ? 'Kempen diarkibkan. Halaman disembunyikan.' : loaded?.published ? 'Halaman diterbitkan.' : 'Halaman belum diterbitkan.'
      await inbox()
    } catch (error) {
      if (valid(ticket)) { active = ''; $('promo-status').textContent = error.message + ' Muat semula ruang kerja untuk mencuba lagi.' }
    }
  }
  async function save(published, data) {
    if (writing || generation || !plan()) return
    const ticket = version
    writing = true
    $('promo-edit-fields').disabled = true
    try {
      const result = await request(endpoint(), { method: 'PUT', body: { ...data, published, revision: loaded?.revision || 0 } })
      if (!valid(ticket)) return
      const first = !loaded
      loaded = result.page
      for (const f of fields) $('promo-' + f).value = loaded[f]
      if (first && !search.dataset.edited) defaults(loaded, plan())
      share()
      $('promo-status').textContent = published ? 'Halaman diterbitkan.' : 'Halaman disimpan sebagai draf.'
      $('promo-feedback').textContent = $('promo-status').textContent
    } catch (error) {
      if (valid(ticket)) $('promo-feedback').textContent = error.message
    } finally {
      if (valid(ticket)) { writing = false; $('promo-edit-fields').disabled = false }
    }
  }
  function leadRow(item) {
    const row = el('div', undefined, 'lead-row')
    const time = new Date(item.submitted * 1000).toLocaleString('ms-MY', { timeZone: 'Asia/Kuala_Lumpur' })
    row.append(el('strong', item.name), el('span', item.contact), el('small', sources[item.source] + ' · ' + time))
    if (item.note) row.append(el('p', item.note))
    const label = el('label', 'Status pertanyaan'), status = el('select')
    for (const [value, text] of Object.entries(statuses)) status.add(new Option(text, value))
    status.value = item.status
    status.onchange = async () => {
      const ticket = version, previous = item.status, updated = status.value
      status.disabled = true
      try {
        await request(endpoint() + '/enquiries/' + item.id, { method: 'PUT', body: { previous, status: updated } })
        if (valid(ticket)) await inbox()
      } catch (error) {
        if (valid(ticket)) { status.value = previous; $('promo-count').textContent = error.message }
      } finally { if (valid(ticket)) status.disabled = false }
    }
    label.append(status)
    row.append(label)
    return row
  }
  async function inbox(more = false) {
    const ticket = version, seq = ++listVersion
    $('promo-more').disabled = true
    try {
      const result = await request(endpoint() + '/enquiries' + (more && after ? '/' + after : ''))
      if (!valid(ticket) || seq !== listVersion) return
      const host = $('promo-leads')
      if (!more) host.replaceChildren()
      host.append(...result.leads.map(leadRow))
      const totals = result.totals.map(s => sources[s.source] + ' · ' + s.enquiries + ' pertanyaan, ' + s.orders + ' tempahan')
      $('promo-count').textContent = totals.join(' | ') || 'Belum ada pertanyaan untuk kempen ini.'
      after = result.after
      $('promo-more').hidden = !after
    } catch (error) {
      if (valid(ticket) && seq === listVersion) $('promo-count').textContent = error.message
    } finally {
      if (valid(ticket) && seq === listVersion) $('promo-more').disabled = false
    }
  }
  async function ai(kind) {
    if (generation || writing || !plan()) return
    const ticket = version, page = read(), isPage = kind === 'page'
    const out = $(isPage ? 'promo-feedback' : 'search-feedback')
    if (!page.business || !page.area || !page.offer) { out.textContent = 'Isi nama perniagaan, kawasan dan tawaran dahulu.'; return }
    const ask = isPage ? 'Tulis teks tawaran untuk halaman promosi, maksimum 2000 aksara. Beri teks akhir sahaja.'
      : 'Sediakan satu kumpulan iklan Google Search. Jawab dengan objek JSON sahaja: keywords (senarai frasa carian), negatives (senarai kata kunci pengecualian yang sesuai), headlines (3 tajuk berbeza, maks 30 aksara setiap satu), descriptions (2 perihalan berbeza, maks 90 aksara setiap satu).'
    const prompt = ask + '\nDalam Bahasa Melayu. Gunakan fakta yang diberikan sahaja. Jangan reka harga, diskaun, jaminan atau statistik.\n' + JSON.stringify({ ...page, image: undefined, product: plan().product, audience: plan().audience })
    if (keys[kind]?.prompt !== prompt) keys[kind] = { prompt, id: C.id() }
    const controller = new AbortController()
    generation = controller
    $('promo-edit-fields').disabled = $('search-fields').disabled = true
    out.textContent = 'AI sedang menyediakan cadangan…'
    try {
      const result = await request('/api/marketing/draf', { ai: true, method: 'POST', signal: controller.signal, key: keys[kind].id, body: { giliran: C.promptTurns(prompt) } })
      if (!valid(ticket)) return
      if (result.ok !== true || result.bukan_model !== false || !['openai', 'gemini', 'tersimpan'].includes(result.sumber) || (result.sumber === 'tersimpan' && !result.main_semula) || !result.jawapan?.trim()) throw Error('Draf AI belum tersedia.')
      delete keys[kind]
      if (isPage) $('promo-offer').value = result.jawapan.trim().slice(0, 2000)
      else {
        const answer = result.jawapan.trim().replace(/^\x60{3}(?:json)?\s*|\s*\x60{3}$/gi, '')
        let draft
        try { draft = JSON.parse(answer) } catch { throw Error('Format cadangan AI tidak lengkap. Cuba jana semula atau sunting medan sendiri.') }
        for (const field of ['keywords', 'negatives', 'headlines', 'descriptions']) {
          if (!Array.isArray(draft?.[field]) || !draft[field].every(x => typeof x === 'string')) throw Error('Cadangan AI tidak lengkap. Cuba jana semula.')
        }
        for (const field of ['keywords', 'negatives', 'headlines', 'descriptions']) search.elements[field].value = draft[field].join('\n')
        search.dataset.edited = '1'
      }
      out.textContent = 'Draf AI siap. Semak dan sunting sebelum digunakan.'
    } catch (error) {
      if (valid(ticket)) {
        if (error.data?.retryable) delete keys[kind]
        out.textContent = error.message
      }
    } finally {
      if (valid(ticket)) { generation = null; $('promo-edit-fields').disabled = $('search-fields').disabled = false }
    }
  }

  function exportSearch(event) {
    event.preventDefault()
    const out = $('search-feedback')
    if (generation || !plan()) return
    if (!loaded?.published || plan().status === 'archived') { out.textContent = 'Terbitkan halaman promosi untuk menyediakan pautan destinasi iklan.'; return }
    try {
      const lines = field => [...new Set(search.elements[field].value.split(/\r?\n/).map(v => v.trim()).filter(Boolean))]
      const headlines = lines('headlines'), descriptions = lines('descriptions')
      if (headlines.length !== 3 || headlines.some(h => [...h].length > 30)) throw Error('Masukkan tiga tajuk berbeza, sehingga 30 aksara setiap satu.')
      if (descriptions.length !== 2 || descriptions.some(d => [...d].length > 90)) throw Error('Masukkan dua perihalan berbeza, sehingga 90 aksara setiap satu.')
      const keywords = lines('keywords'), negatives = lines('negatives')
      if (!keywords.length || [...keywords, ...negatives].some(v => v.length > 80 || v.split(/\s+/).length > 10)) throw Error('Semak kata kunci. Gunakan sehingga 80 aksara dan 10 perkataan setiap frasa.')
      const group = search.elements.group.value.trim()
      if (!group) throw Error('Masukkan nama kumpulan iklan.')
      const campaign = plan().title + ' · BeShare ' + active.slice(-8)
      const headers = ['Campaign', 'Campaign type', 'Campaign Status', 'Daily budget', 'Bid strategy type', 'Location', 'Ad group', 'Keyword', 'Type', 'Ad type', 'Headline 1', 'Headline 2', 'Headline 3', 'Description 1', 'Description 2', 'Final URL']
      const rows = [{ 'Campaign type': 'Search', 'Campaign Status': 'Paused', 'Daily budget': search.elements.budget.value, 'Bid strategy type': 'Maximize clicks' },
        { Location: loaded.area }, { 'Ad group': group }]
      for (const kw of keywords) rows.push({ 'Ad group': group, Keyword: kw, Type: 'Phrase' })
      for (const kw of negatives) rows.push({ 'Ad group': group, Keyword: kw, Type: 'Negative' })
      rows.push({ 'Ad group': group, 'Ad type': 'Responsive search ad', 'Headline 1': headlines[0], 'Headline 2': headlines[1], 'Headline 3': headlines[2], 'Description 1': descriptions[0], 'Description 2': descriptions[1], 'Final URL': url('google') })
      const csv = '\ufeff' + [headers, ...rows.map(row => headers.map(h => h === 'Campaign' ? campaign : row[h] || ''))].map(row => row.map(C.csvCell).join(',')).join('\r\n')
      const object = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
      const link = el('a'); link.href = object; link.download = 'google-search-' + active.slice(-8) + '.csv'; link.click()
      setTimeout(() => URL.revokeObjectURL(object), 1000)
      out.textContent = 'CSV disediakan. Import melalui Account → Import dalam Google Ads Editor. Sahkan padanan lokasi, bajet dan iklan sebelum menerbitkan.'
    } catch (error) { out.textContent = error.message }
  }
  select.onchange = show
  $('promo-refresh').onclick = () => inbox()
  $('promo-more').onclick = () => inbox(true)
  $('promo-source').onchange = share
  $('promo-copy').onclick = async () => {
    try { await navigator.clipboard.writeText($('promo-link').href); $('promo-feedback').textContent = 'Pautan disalin.' }
    catch { $('promo-feedback').textContent = 'Salin alamat melalui pautan yang dipaparkan.' }
  }
  $('promo-editor').onsubmit = event => {
    event.preventDefault()
    const live = event.submitter?.value === 'live' || !!loaded?.published
    if (live && plan()?.status === 'archived') { $('promo-feedback').textContent = 'Aktifkan semula kempen yang diarkibkan sebelum menerbitkan halaman.'; return }
    save(live, read())
  }
  $('promo-hide').onclick = () => loaded && save(false, Object.fromEntries(fields.map(f => [f, loaded[f]])))
  $('promo-ai').onclick = () => ai('page')
  $('search-ai').onclick = () => ai('search')
  search.oninput = () => { search.dataset.edited = '1' }
  search.onsubmit = exportSearch
  return { reset, options, show }
}
