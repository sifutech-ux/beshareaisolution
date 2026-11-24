/* Account-owned records. The existing BeShare login token is the only browser-stored data read here. */
(() => {
  'use strict'
  const C = window.MarketingCore, $ = id => document.getElementById(id)
  const services = window.BeshareAsal?.asalPerkhidmatan(location.origin)
  const API = '/api/akaun/copilot/marketing', TOKEN_KEY = 'beshare-akaun'
  const editors = {}, plural = { campaign: 'campaigns', post: 'posts', source: 'sources', result: 'results' }
  const connectionChecks = new Map(), CONNECTION_CHECK_TTL = 60000
  const publicationRequests = new Map()
  const count = n => new Intl.NumberFormat('ms-MY').format(n)
  const state = { owner: null, account: null, token: null, epoch: 0, load: 0, ready: false, campaigns: [], posts: [], sources: [], results: [], connections: [], capabilities: {}, view: 'ringkasan' }
  const today = C.malaysiaDate(), calendar = { year: Number(today.slice(0, 4)), month: Number(today.slice(5, 7)) - 1 }
  let toastTimer, confirmResolve, aiController, aiSignature = '', aiKey = '', aiTarget = null, aiDraftContext = null, telegramSearch
  const headings = {
    ringkasan: ['RUANG PEMASARAN ANDA', 'Idea yang bergerak.', 'Rancang dengan jelas. Terbit dengan yakin. Fahami hasilnya.'],
    kempen: ['SATU MATLAMAT, SATU ARAH', 'Kempen anda.', 'Satukan tawaran, pelanggan sasaran dan rancangan pelaksanaan.'],
    kandungan: ['KANDUNGAN DENGAN TUJUAN', 'Sedia untuk dikongsi.', 'Tulis, semak dan susun kandungan mengikut saluran.'],
    'facebook-ads': ['IKLAN BERBAYAR', 'Facebook Ads', 'Urus kempen dan lihat prestasi akaun iklan Meta anda.'],
    kumpulan: ['DISTRIBUSI BERIZIN', 'Kumpulan & saluran.', 'Urus Telegram dan WhatsApp group dengan kebenaran pentadbir.'],
    kajian: ['FAHAMI SEBELUM MELANGKAH', 'Ruang untuk pemerhatian.', 'Kumpulkan rujukan dan andaian yang mahu anda uji.'],
    hasil: ['DARIPADA ANGKA KEPADA LANGKAH', 'Fahami hasil pemasaran.', 'Catat apa yang berlaku. Bina keputusan daripada rekod sebenar.'],
    sambungan: ['SALURAN PEMASARAN', 'Sambungkan perniagaan.', 'Pilih akaun yang digunakan untuk menerbitkan kandungan anda.']
  }
  function el(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n }
  function icon(name) { const n = document.createElementNS('http://www.w3.org/2000/svg', 'svg'), u = document.createElementNS(n.namespaceURI, 'use'); u.setAttribute('href', '#i-' + name); n.setAttribute('aria-hidden', 'true'); n.append(u); return n }
  function button(text, run, cls = 'button secondary', symbol) { const b = el('button', undefined, cls); b.type = 'button'; if (symbol) b.append(icon(symbol)); b.append(document.createTextNode(text)); b.addEventListener('click', run); return b }
  function statusPill(status, label) { const n = el('span', label || C.STATUS[status] || 'Perlu semakan', 'status-pill'); n.dataset.status = status; return n }
  function platformIcon(platform) { const n = el('span', { facebook: 'f', instagram: '◎', threads: '@', google_ads: 'A', other: '·' }[platform] || '·', 'platform-icon'); n.dataset.platform = platform; n.setAttribute('aria-hidden', 'true'); return n }
  function platformBadge(platform) { const n = el('span', undefined, 'platform-badge'); n.append(platformIcon(platform), document.createTextNode(C.PLATFORM[platform] || platform)); return n }
  function feedback(kind, text = '', level = '') { const n = $(kind + '-feedback'); if (n) { n.textContent = text; n.dataset.kind = level } }
  function notify(text) { clearTimeout(toastTimer); $('toast').textContent = text; $('toast').hidden = false; toastTimer = setTimeout(() => { $('toast').hidden = true }, 5500) }
  function message(text) { $('global-message').textContent = text; $('global-message').hidden = !text }
  function session() { try { const s = JSON.parse(localStorage.getItem(TOKEN_KEY) || 'null'); return typeof s?.token === 'string' && s.token ? s : null } catch { return null } }
  function live(epoch, token) { return epoch === state.epoch && token === session()?.token }
  class RequestError extends Error { constructor(text, status = 0, data = {}) { super(text); this.status = status; this.data = data } }
  async function request(path, { method = 'GET', body, signal, ai = false, key, timeoutMs = ai ? 90000 : 22000 } = {}) {
    const token = session()?.token, epoch = state.epoch
    if (!token || !services) throw new RequestError('Log masuk ke akaun BeShare untuk meneruskan.', 401)
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), timeoutMs)
    const relay = () => controller.abort(); signal?.addEventListener('abort', relay, { once: true })
    try {
      const response = await fetch((ai ? services.nasihat : services.akaun) + path, { method, headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...(key ? { 'Idempotency-Key': key } : {}) }, body: body === undefined ? undefined : JSON.stringify(body), signal: controller.signal, credentials: 'omit', cache: 'no-store' })
      const data = await response.json().catch(() => ({}))
      if (!live(epoch, token)) throw new RequestError('Sesi akaun telah berubah. Buka semula ruang kerja.', 499)
      if (response.status === 401) { expireSession(token); throw new RequestError('Sesi anda telah tamat. Log masuk semula.', 401, data) }
      if (!response.ok || data.ok === false) throw new RequestError(typeof data.error === 'string' ? data.error : 'Permintaan belum berjaya. Cuba lagi.', response.status, data)
      return data
    } catch (error) {
      if (error.name === 'AbortError') throw new RequestError(ai ? 'Permintaan mengambil masa. Cuba Jana draf sekali lagi untuk menyemak permintaan yang sama.' : 'Sambungan mengambil masa. Suntingan anda masih ada; cuba simpan sekali lagi.', 408)
      if (error instanceof RequestError) throw error
      throw new RequestError('Sambungan terputus. Semak internet dan cuba lagi.')
    } finally { clearTimeout(timeout); signal?.removeEventListener('abort', relay) }
  }
  const ads = window.BeShareFacebookAds?.({ state, request, connection, beginConnect, disconnect, el, live, API })
  const postAds = window.BeSharePostAds?.({ state, request, connection, live, API, draftAd(plan, brief) {
    openPost(null, { campaign_id: plan, platform: 'facebook' })
    openAI()
    $('ai-instruction').value = brief
  } })
  function resetMemory() {
    postAds?.reset()
    ads?.reset()
    disposePostMedia(editors.post)
    resetConnectionChecks()
    resetPublicationRequests()
    telegramSearch?.abort()
    $('telegram-discovery-form').reset()
    $('telegram-discovery-results').replaceChildren()
    $('telegram-discovery-status').textContent = ''
    state.epoch++; state.owner = null; state.account = null; state.token = null; state.ready = false
    for (const k of ['campaigns', 'posts', 'sources', 'results', 'connections']) state[k] = []
    state.capabilities = {}; aiController?.abort(); aiController = null; aiSignature = ''; aiKey = ''; aiTarget = null; aiDraftContext = null
    for (const key of Object.keys(editors)) delete editors[key]
    document.querySelectorAll('dialog[open]').forEach(d => d.close())
    document.querySelectorAll('dialog form').forEach(f => f.reset())
    for (const id of ['overview-metrics', 'overview-campaigns', 'next-actions', 'overview-channels', 'campaign-list', 'post-list', 'calendar-grid', 'source-list', 'result-metrics', 'result-funnel', 'result-table', 'connection-list', 'utm-links', 'preview-media', 'campaign-summary', 'post-publication-links']) $(id).replaceChildren()
    for (const kind of ['post', 'source', 'result', 'ai']) $(kind + '-campaign').replaceChildren()
    for (const kind of ['post', 'source', 'result']) $(kind + '-campaign-filter').replaceChildren(new Option('Semua kempen', ''))
    for (const kind of ['campaign', 'post', 'source', 'result', 'ai', 'links']) feedback(kind)
    $('preview-body').textContent = 'Teks kandungan anda akan muncul di sini.'; $('preview-name').textContent = 'Perniagaan anda'; message('')
    $('ai-output').value = ''; $('ai-output-wrap').hidden = true; $('ai-use').disabled = true; $('ai-generate').disabled = false
    $('ai-form').querySelectorAll('input,select,textarea').forEach(n => { n.disabled = false })
    $('account-name').textContent = 'Akaun BeShare'; $('account-detail').textContent = 'Satu akaun, semua peranti'; $('account-avatar').textContent = 'B'
    $('workspace-content').hidden = true; document.querySelectorAll('[data-new-campaign]').forEach(b => { b.disabled = true })
    $('nav-campaign-count').hidden = true
  }
  function gate(title, text, { login = false, retry = false } = {}) {
    $('gate-title').textContent = title; $('gate-text').textContent = text; $('gate').hidden = false; $('workspace-content').hidden = true
    $('gate-login').hidden = !login; $('gate-retry').hidden = !retry
    $('workspace-state').lastChild.textContent = login ? 'Log masuk diperlukan' : retry ? 'Sambungan belum tersedia' : 'Memuat ruang kerja'
    $('workspace-state').dataset.state = retry ? 'error' : 'loading'
    document.querySelectorAll('[data-new-campaign]').forEach(b => { b.disabled = true })
  }
  async function signOutMarketing() {
    const hasUnsaved = Object.values(editors).some(item => item?.dirty || item?.saving)
    if (hasUnsaved && !await confirmAction('Log keluar daripada BeShare?', 'Suntingan yang belum disimpan akan hilang. Rekod yang sudah disimpan kekal dalam akaun BeShare anda.', 'Log keluar')) return
    // Invalidate in-flight account requests and clear account-owned UI before leaving.
    state.load++
    resetMemory()
    try { localStorage.removeItem(TOKEN_KEY) } catch {}
    location.assign('../akaun/?selepas=marketing')
  }
  function expireSession(token) {
    if (session()?.token === token) { try { localStorage.removeItem(TOKEN_KEY) } catch {} }
    resetMemory(); gate('Ruang kerja anda menanti.', 'Log masuk semula untuk membuka kempen, kandungan dan rekod pemasaran yang disimpan dalam akaun anda.', { login: true })
  }
  async function bootstrap() {
    const s = session(), load = ++state.load
    resetConnectionChecks(); resetPublicationRequests(); state.checksSuspended = true
    if (s?.token !== state.token && state.token) resetMemory()
    if (!s) { gate('Semua kerja pemasaran, satu tempat.', 'Log masuk dengan akaun BeShare untuk merancang kempen, menyediakan kandungan dan menyimpan hasil anda pada semua peranti.', { login: true }); return }
    if (!services?.akaun) { gate('Ruang kerja belum dapat disambungkan.', 'Buka Marketing melalui laman BeShare yang disokong. Akaun dan rekod anda belum dimuat.', { retry: true }); return }
    if (!state.ready) gate('Membuka ruang pemasaran anda…', 'Kami sedang menyemak akaun dan memuat rekod anda.')
    $('refresh-workspace').disabled = true
    try {
      const account = await request('/api/akaun/saya')
      if (load !== state.load) return
      if (typeof account.akaun_id !== 'string' || !account.akaun_id) throw Error('Maklumat akaun belum dapat disahkan.')
      const data = await request(API + '/workspace')
      if (load !== state.load) return
      state.owner = account.akaun_id; state.account = account; state.token = s.token
      for (const k of ['campaigns', 'posts', 'sources', 'results', 'connections']) state[k] = Array.isArray(data[k]) ? data[k] : []
      state.capabilities = data.capabilities || {}; state.ready = true
      const name = account.penasihat?.nama || account.nama || s.email?.split('@')[0] || 'Perniagaan anda'
      $('account-name').textContent = name; $('account-avatar').textContent = name.slice(0, 1).toUpperCase(); $('account-detail').textContent = 'Rekod dalam akaun'
      $('gate').hidden = true; $('workspace-content').hidden = false; $('workspace-state').lastChild.textContent = 'Ruang kerja disambungkan'; $('workspace-state').dataset.state = 'ready'
      document.querySelectorAll('[data-new-campaign]').forEach(b => { b.disabled = false })
      state.checksSuspended = false; message(''); renderAll(); navigate(); refreshConnectionChecks()
    } catch (error) {
      if (load !== state.load || error.status === 401 || error.status === 499) return
      if (state.ready) { message('Rekod terkini belum dapat dimuat. Salinan yang dipaparkan mungkin belum dikemas kini. Tekan Muat semula untuk mencuba lagi.'); $('workspace-state').dataset.state = 'error'; $('workspace-state').lastChild.textContent = 'Belum dikemas kini' }
      else gate('Ruang kerja belum dapat dimuat.', error.message || 'Akaun atau sambungan belum tersedia. Cuba lagi sebentar lagi.', { retry: true })
    } finally { if (load === state.load) { state.checksSuspended = false; $('refresh-workspace').disabled = false } }
  }
  function navigate() {
    const view = location.hash.slice(1); state.view = Object.hasOwn(headings, view) ? view : 'ringkasan'
    const h = headings[state.view]; $('page-eyebrow').textContent = h[0]; $('page-title').textContent = h[1]; $('page-description').textContent = h[2]
    document.querySelectorAll('[data-view]').forEach(a => { if (a.dataset.view === state.view) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current') })
    document.querySelectorAll('[data-panel]').forEach(p => { p.hidden = p.dataset.panel !== state.view })
    document.title = state.view[0].toUpperCase() + state.view.slice(1) + ' · Marketing — BeShare AI'
    if (state.view === 'facebook-ads' && state.ready) ads?.show()
    if (state.view === 'sambungan') refreshConnectionChecks()
  }
  function empty(title, text, action, run, symbol = 'target') { const n = el('div', undefined, 'empty-state'); n.append(icon(symbol), el('h3', title), el('p', text)); if (action) n.append(button(action, run, 'button secondary', 'plus')); return n }
  function sorted(rows) { return [...rows].sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || ''))) }
  function campaignName(id) { return state.campaigns.find(c => c.id === id)?.title || 'Kempen' }
  function campaignOptions(select, { emptyLabel, current } = {}) {
    const selected = current === undefined ? select.value : current; select.replaceChildren()
    if (emptyLabel !== undefined) select.add(new Option(emptyLabel, ''))
    sorted(state.campaigns).forEach(c => select.add(new Option(c.title + (c.status === 'archived' ? ' · Arkib' : ''), c.id)))
    if ([...select.options].some(o => o.value === selected)) select.value = selected
  }
  function upsert(kind, record) { const list = state[plural[kind]], i = list.findIndex(x => x.id === record.id); if (i < 0) list.unshift(record); else list[i] = record; renderAll() }
  function renderAll() {
    for (const kind of ['post', 'source', 'result']) campaignOptions($(kind + '-campaign-filter'), { emptyLabel: 'Semua kempen' })
    const active = state.campaigns.filter(x => x.status !== 'archived').length
    $('nav-campaign-count').textContent = active; $('nav-campaign-count').hidden = active === 0
    renderOverview(); renderCampaigns(); renderPosts(); renderSources(); renderResults(); renderConnections()
  }
  function metric(label, value, caption, mint = false) { const n = el('div', undefined, 'metric'), top = el('div', undefined, 'metric-top'); top.append(el('span', label), icon('chart')); n.append(top, el('div', value, 'metric-value' + (mint ? ' mint' : '')), el('p', caption, 'metric-caption')); return n }
  function metricCards(host, rows) {
    const m = C.metrics(rows), has = rows.length > 0
    host.replaceChildren(metric('Belanja dicatat', has ? C.ringgit(m.spend) : '—', has ? count(m.records) + ' rekod manual' : 'Belum ada rekod hasil'), metric('Pertanyaan', has ? count(m.enquiries) : '—', 'Daripada catatan anda'), metric('Jualan dicatat', has ? C.ringgit(m.revenue) : '—', 'Nilai jualan dalam rekod', true), metric('Nisbah jualan / belanja', m.roas === null ? '—' : m.roas.toFixed(2) + '×', m.spend === 0 ? 'Perlu belanja untuk dikira' : 'Nisbah rekod, bukan atribusi'))
  }
  function renderOverview() {
    metricCards($('overview-metrics'), state.results)
    const host = $('overview-campaigns'), rows = sorted(state.campaigns.filter(c => c.status !== 'archived')).slice(0, 3); host.replaceChildren()
    if (!rows.length) host.append(empty('Kempen pertama bermula di sini.', 'Pilih satu tawaran dan tentukan hasil yang anda mahu capai.', 'Cipta kempen pertama', () => openCampaign()))
    rows.forEach(c => { const row = el('div', undefined, 'mini-campaign'), mark = el('span', undefined, 'campaign-mark'); mark.append(icon('target')); const text = el('div', undefined, 'mini-text'), b = button('', () => openCampaign(c), ''); b.append(el('h3', c.title)); text.append(b, el('p', C.GOALS[c.goal] || 'Kempen')); row.append(mark, text, statusPill(c.status)); host.append(row) })
    const tasks = [[state.campaigns.length > 0, 'Tetapkan satu kempen', 'Satu tawaran, pelanggan sasaran dan matlamat yang jelas.', 'Rancang kempen', () => openCampaign()], [state.posts.length > 0, 'Siapkan kandungan pertama', 'Tulis sendiri atau minta Copilot membantu draf anda.', 'Buka kandungan', () => { location.hash = 'kandungan' }], [state.results.length > 0, 'Catat apa yang berlaku', 'Simpan pertanyaan, pesanan dan belanja untuk belajar.', 'Catat hasil', () => openResult()]]
    $('next-actions').replaceChildren(...tasks.map((t, i) => { const n = el('div', undefined, 'next-step'), mark = el('span', t[0] ? undefined : String(i + 1), 'step-check' + (t[0] ? ' done' : '')); if (t[0]) mark.append(icon('check')); const content = el('div'); content.append(el('h3', t[1]), el('p', t[2])); if (!t[0]) content.append(button(t[3] + ' →', t[4], 'text-button')); n.append(mark, content); return n }))
    $('overview-channels').replaceChildren(...['facebook', 'instagram', 'threads'].map(p => { const health = connectionHealth(p), connected = connection(p)?.status === 'connected' && health === 'valid', chip = el('a', undefined, 'channel-chip'); chip.href = '#sambungan'; chip.dataset.connected = String(connected); chip.append(el('span', undefined, 'tiny-dot'), document.createTextNode(C.PLATFORM[p])); chip.title = connected ? 'Disambungkan' : health === 'checking' ? 'Menyemak akses' : health === 'unknown' ? 'Akses belum dapat disahkan' : health === 'invalid' ? 'Sambung semula' : 'Belum disambungkan'; return chip }))
  }
  function renderCampaigns() {
    const term = $('campaign-search').value.toLocaleLowerCase(), status = $('campaign-status').value
    const rows = sorted(state.campaigns).filter(c => (status === 'all' || c.status === status) && (c.title + ' ' + (c.product || '') + ' ' + (c.offer || '')).toLocaleLowerCase().includes(term))
    const host = $('campaign-list'); host.replaceChildren()
    if (!rows.length) { host.append(empty(state.campaigns.length ? 'Tiada kempen sepadan.' : 'Beri tawaran anda satu arah.', state.campaigns.length ? 'Cuba carian atau status yang lain.' : 'Mulakan dengan satu produk atau servis. Simpan rancangan yang boleh anda gunakan semula.', state.campaigns.length ? null : 'Cipta kempen pertama', () => openCampaign())); return }
    rows.forEach(c => {
      const card = el('article', undefined, 'campaign-card'), top = el('div', undefined, 'card-top'), mark = el('span', undefined, 'campaign-mark'); mark.append(icon('target')); top.append(mark, statusPill(c.status))
      card.append(top, el('h3', c.title), el('p', c.offer || c.product || 'Tambah maklumat tawaran untuk membantu menyediakan kandungan.', 'card-description'))
      const meta = el('div', undefined, 'card-meta'); [[C.GOALS[c.goal] || 'Belum dipilih', 'Matlamat'], [C.ringgit(c.budget_total), 'Bajet dirancang']].forEach(([v, label]) => { const cell = el('div'); cell.append(el('small', label), el('strong', v)); meta.append(cell) }); card.append(meta)
      const channels = el('div', undefined, 'card-channels'); (c.channels || []).forEach(p => channels.append(el('span', C.PLATFORM[p] || p))); if (!(c.channels || []).length) channels.append(el('span', 'Saluran belum dipilih')); card.append(channels)
      const actions = el('div', undefined, 'card-actions'); actions.append(button('Sunting', () => openCampaign(c), 'button secondary'), button('Siapkan kandungan', () => openPost(null, { campaign_id: c.id }), 'button quiet'), button('Ringkasan & pautan ↗', () => openLinks(c), 'text-button')); card.append(actions); host.append(card)
    })
  }
  function selectedPosts() { const selected = $('post-campaign-filter').value; return sorted(state.posts).filter(p => !selected || p.campaign_id === selected) }
  function renderPosts() {
    const rows = selectedPosts(), host = $('post-list'); host.replaceChildren()
    if (!rows.length) host.append(empty('Kandungan yang membawa tawaran anda.', 'Tulis draf, pilih saluran dan semak sebelum diterbitkan.', 'Siapkan kandungan pertama', () => openPost(), 'calendar'))
    rows.forEach(p => {
      const card = el('article', undefined, 'post-card'), top = el('div', undefined, 'card-top'); top.append(platformBadge(p.platform), statusPill(p.status)); card.append(top, el('h3', campaignName(p.campaign_id)), el('p', p.body || 'Draf ini belum mempunyai teks.', 'post-copy'))
      card.append(el('p', p.scheduled_at ? C.displayDate(p.scheduled_at, true) + ' · MYT' : 'Masa belum ditetapkan', 'post-time'))
      if (p.status === 'draft') card.append(el('p', 'Draf disimpan · belum diterbitkan', 'hint'))
      if (p.status === 'planned') card.append(el('p', 'Rancangan disimpan · belum dalam giliran penerbitan', 'hint'))
      if (p.last_error) card.append(el('p', safeProviderError(p.last_error), 'post-error'))
      const actions = el('div', undefined, 'card-actions'); actions.append(button(['queued', 'publishing', 'published', 'uncertain'].includes(p.status) ? 'Lihat kandungan' : 'Sunting kandungan', () => openPost(p), 'button secondary')); card.append(actions)
      if (p.status === 'published' && p.platform === 'facebook' && p.provider_post_id && postAds && state.capabilities.ads?.post_whatsapp_ads) {
        actions.append(button('Jadikan iklan', () => postAds.open(p), 'button secondary'))
      }
      if (p.status === 'published' && p.provider_post_id) { const receipt = el('div', undefined, 'publication-receipt'); renderPublication(p, receipt); card.append(receipt) }
      host.append(card)
    }); renderCalendar(rows)
  }
  function safePublicationUrl(value, platform) {
    const hosts = { facebook: ['facebook.com', 'www.facebook.com', 'm.facebook.com'], instagram: ['instagram.com', 'www.instagram.com'], threads: ['threads.com', 'www.threads.com', 'threads.net', 'www.threads.net'] }[platform]
    if (!hosts || typeof value !== 'string' || !value || value.length > 2048 || /[\s\\\x00-\x1f\x7f]/.test(value)) return ''
    try {
      const url = new URL(value)
      if (url.protocol !== 'https:' || url.username || url.password || url.port && url.port !== '443' || !hosts.includes(url.hostname) || url.hash || !url.pathname || url.pathname.startsWith('//')) return ''
      if (['/l.php', '/away.php', '/redirect', '/flx/warn/'].includes(url.pathname.toLowerCase())) return ''
      if ([...url.searchParams.keys()].some(key => /^(access_token|token|client_secret|appsecret_proof|code|state|oauth_token|redirect_uri|next|url|u|href)$/i.test(key))) return ''
      return url.href
    } catch { return '' }
  }
  function publicationReceipt(post) {
    const receipt = post?.publication
    if (post?.status !== 'published' || !post.provider_post_id || !receipt || receipt.platform !== post.platform || typeof receipt.account_id !== 'string' || !/^[0-9]{1,40}$/.test(receipt.account_id) || typeof receipt.account_name !== 'string' || !receipt.account_name.trim() || receipt.account_name.length > 200) return null
    const postUrl = safePublicationUrl(receipt.post_url, post.platform), profileUrl = receipt.profile_url ? safePublicationUrl(receipt.profile_url, post.platform) : ''
    if (!postUrl || receipt.profile_url && !profileUrl) return null
    return { ...receipt, post_url: postUrl, profile_url: profileUrl }
  }
  function publicationLink(label, url) {
    const link = el('a', label, 'button secondary'); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer'; return link
  }
  function renderPublication(post, host) {
    host.replaceChildren(); host.hidden = post.status !== 'published' || !post.provider_post_id
    if (host.hidden) return
    const receipt = publicationReceipt(post), check = publicationRequests.get(post.id), actions = el('div', undefined, 'publication-actions')
    if (receipt) {
      host.append(el('strong', 'Diterbitkan ke ' + receipt.account_name, 'publication-destination'))
      actions.append(publicationLink('Buka hantaran ' + C.PLATFORM[post.platform], receipt.post_url))
      if (receipt.profile_url) actions.append(publicationLink(post.platform === 'facebook' ? 'Buka Page' : 'Buka profil', receipt.profile_url))
      host.append(actions)
      return
    }
    const note = el('p', check?.pending ? 'Mendapatkan pautan asal daripada ' + C.PLATFORM[post.platform] + '…' : check?.error || 'Penerbitan direkod oleh ' + C.PLATFORM[post.platform] + '.', 'hint')
    note.setAttribute('role', 'status'); host.append(note)
    if (state.capabilities.publication_receipts) {
      const action = button(check?.pending ? 'Menyemak pautan…' : check?.error ? 'Semak pautan semula' : 'Semak pautan hantaran', () => resolvePublication(post, { force: true }), 'button secondary', 'link')
      action.disabled = !!check?.pending; actions.append(action); host.append(actions)
    }
  }
  function resetPublicationRequests() { for (const check of publicationRequests.values()) check.controller.abort(); publicationRequests.clear() }
  function currentPublicationRequest(post, check) {
    if (publicationRequests.get(post.id) !== check || state.load !== check.load || state.owner !== check.owner) return false
    if (!live(check.epoch, check.token)) { resetMemory(); bootstrap(); return false }
    const current = state.posts.find(item => item.id === post.id)
    return current?.status === 'published' && current.platform === post.platform && current.provider_post_id === post.provider_post_id
  }
  function renderPublicationState() { renderPosts(); if (editors.post && $('post-dialog').open) renderPostEditor() }
  async function resolvePublication(post, { force = false } = {}) {
    if (!state.ready || !state.capabilities.publication_receipts || post.status !== 'published' || !post.provider_post_id) return null
    const cached = publicationReceipt(post); if (cached) return cached
    const previous = publicationRequests.get(post.id)
    if (previous?.pending) return previous.promise
    if (previous && !force) return null
    const check = { owner: state.owner, epoch: state.epoch, token: state.token, load: state.load, controller: new AbortController(), pending: true, error: '' }
    publicationRequests.set(post.id, check); renderPublicationState()
    check.promise = (async () => {
      try {
        const data = await request(API + '/posts/' + post.id + '/publication', { method: 'POST', body: {}, signal: check.controller.signal })
        if (!currentPublicationRequest(post, check)) return null
        const receipt = publicationReceipt({ ...post, publication: data.publication })
        if (!receipt) throw Error('Pautan penerbitan tidak sah.')
        state.posts = state.posts.map(item => item.id === post.id ? { ...item, publication: receipt } : item)
        if (editors.post?.item.id === post.id && editors.post.item.provider_post_id === post.provider_post_id) editors.post.item.publication = receipt
        return receipt
      } catch {
        if (currentPublicationRequest(post, check)) check.error = 'Penerbitan telah direkodkan. Pautannya belum dapat dimuat; cuba semak pautan sekali lagi.'
        return null
      } finally {
        if (currentPublicationRequest(post, check)) { check.pending = false; renderPublicationState() }
      }
    })()
    return check.promise
  }
  function renderCalendar(rows = selectedPosts()) {
    $('calendar-title').textContent = new Intl.DateTimeFormat('ms-MY', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(calendar.year, calendar.month, 1)))
    const host = $('calendar-grid'); host.replaceChildren()
    C.monthDays(calendar.year, calendar.month).forEach(day => {
      const cell = el('div', undefined, 'calendar-day' + (!day.inMonth ? ' outside' : '') + (day.date === C.malaysiaDate() ? ' today' : ''))
      const b = button(String(day.day), () => openPost(null, { scheduled_at: C.utcFromMalaysia(day.date + 'T09:00'), campaign_id: $('post-campaign-filter').value }), 'day-number'); b.setAttribute('aria-label', 'Tambah kandungan pada ' + C.displayDate(day.date)); cell.append(b)
      rows.filter(p => p.scheduled_at && C.malaysiaDate(p.scheduled_at) === day.date).sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at)).forEach(p => { const n = button('', () => openPost(p), 'calendar-post'); n.append(document.createTextNode((p.body || 'Draf tanpa teks').slice(0, 60)), el('span', C.PLATFORM[p.platform] + ' · ' + (C.STATUS[p.status] || p.status))); cell.append(n) }); host.append(cell)
    })
  }
  function renderSources() {
    const campaignSelect = $('telegram-discovery-campaign')
    campaignOptions(campaignSelect, { emptyLabel: 'Isi sendiri', current: campaignSelect.value })
    const selected = $('source-campaign-filter').value, rows = sorted(state.sources).filter(s => !selected || s.campaign_id === selected), host = $('source-list'); host.replaceChildren()
    if (!rows.length) host.append(empty('Simpan perkara yang anda temui.', 'Pautan, pemerhatian pelanggan dan andaian boleh disimpan bersama kempen.', 'Tambah rujukan pertama', () => openSource(), 'search'))
    rows.forEach(s => {
      const card = el('article', undefined, 'source-card'), top = el('div', undefined, 'card-top'); top.append(el('span', s.source_type === 'manual' ? 'CATATAN SENDIRI' : 'RUJUKAN PAUTAN', 'small-label'), el('span', 'Manual', 'status-pill')); card.append(top, el('h3', s.title))
      if (s.url) { const a = el('a', s.url, 'source-url'); try { a.href = C.https(s.url, true); a.target = '_blank'; a.rel = 'noopener noreferrer'; card.append(a) } catch {} } else card.append(el('p', 'Tiada pautan sumber', 'hint'))
      for (const [title, text, cls] of [['Pemerhatian', s.observation, 'source-section'], ['Andaian untuk diuji', s.hypothesis, 'source-section hypothesis']]) { const n = el('div', undefined, cls); n.append(el('h4', title.toLocaleUpperCase()), el('p', text || 'Belum dicatat.')); card.append(n) }
      const actions = el('div', undefined, 'card-actions'); actions.append(button('Sunting rujukan', () => openSource(s), 'button secondary'), el('span', s.campaign_id ? campaignName(s.campaign_id) : 'Rujukan umum', 'hint')); card.append(actions); host.append(card)
    })
  }
  function selectedResults() { const selected = $('result-campaign-filter').value; return state.results.filter(r => !selected || r.campaign_id === selected).sort((a, b) => b.date.localeCompare(a.date) || String(b.updated_at).localeCompare(String(a.updated_at))) }
  function renderResults() {
    const rows = selectedResults(), m = C.metrics(rows); metricCards($('result-metrics'), rows)
    const scale = Math.max(m.impressions, m.clicks, m.enquiries, m.qualified_leads, m.orders, 1)
    $('result-funnel').replaceChildren(...[['Paparan', m.impressions], ['Klik', m.clicks], ['Pertanyaan', m.enquiries], ['Prospek layak', m.qualified_leads], ['Pesanan', m.orders]].map(([name, value]) => { const n = el('div', undefined, 'funnel-item'), track = el('div', undefined, 'funnel-track'), fill = el('span'); fill.style.width = (rows.length ? value / scale * 100 : 0) + '%'; track.append(fill); n.append(el('div', rows.length ? count(value) : '—', 'funnel-value'), el('div', name, 'funnel-label'), track); return n }))
    $('result-count').textContent = count(rows.length) + ' rekod'; $('export-results').disabled = !rows.length
    const host = $('result-table'); host.replaceChildren()
    if (!rows.length) { host.append(empty('Belum ada hasil dicatat.', 'Tambah rekod harian untuk melihat belanja, pertanyaan dan pesanan yang anda terima.', 'Catat hasil pertama', () => openResult(), 'chart')); return }
    const scroll = el('div', undefined, 'table-scroll'), table = el('table', undefined, 'records-table'), head = el('thead'), tr = el('tr')
    for (const text of ['Tarikh / Kempen', 'Saluran', 'Belanja', 'Pertanyaan', 'Pesanan', 'Jualan', '']) { const th = el('th', text); th.scope = 'col'; if (!text) th.append(el('span', 'Tindakan', 'sr-only')); tr.append(th) }
    head.append(tr); table.append(head); const body = el('tbody')
    rows.forEach(r => { const row = el('tr'), title = el('td'); title.append(el('strong', C.displayDate(r.date)), el('small', campaignName(r.campaign_id))); row.append(title, el('td', C.PLATFORM[r.platform] || r.platform), el('td', C.ringgit(r.spend), 'money'), el('td', count(r.enquiries)), el('td', count(r.orders)), el('td', C.ringgit(r.revenue), 'money')); const action = el('td'); action.append(button('Sunting', () => openResult(r), 'text-button')); row.append(action); body.append(row) }); table.append(body); scroll.append(table); host.append(scroll)
  }
  function connection(platform) { return state.connections.find(c => (c.provider || c.platform) === platform) }
  function connectionFingerprint(value) { return JSON.stringify([value?.status, value?.configured, value?.publishable, value?.accounts || []]) }
  function checkableConnection(value) { return !!value && (['connected', 'expired', 'reconnect_required'].includes(value.status) || value.accounts?.length > 0) }
  function connectionHealth(platform) {
    const current = connection(platform), check = connectionChecks.get(platform)
    if (check?.pending) return 'checking'
    if (['expired', 'reconnect_required'].includes(current?.status)) return 'invalid'
    if (current?.status !== 'connected') return 'disconnected'
    if (!state.capabilities.connection_health_checks) return 'valid'
    return check?.fingerprint === connectionFingerprint(current) ? check.health?.status || 'unknown' : 'unknown'
  }
  function publishReady(platform) { const c = connection(platform); return Boolean(c?.publishable && c.status === 'connected' && connectionHealth(platform) === 'valid' && state.capabilities.worker_enabled && state.capabilities.auto_publish) }
  function safeProviderError(text) { return String(text).replace(/(?:access_token|Bearer)[\s=:]+[^\s]+/gi, '[dilindungi]').slice(0, 500) }
  function resetConnectionChecks() { for (const check of connectionChecks.values()) check.controller?.abort(); connectionChecks.clear() }
  function renderConnectionState() { renderConnections(); renderOverview(); if (editors.post && $('post-dialog').open) renderPostEditor() }
  function currentConnectionCheck(platform, check) {
    if (connectionChecks.get(platform) !== check || state.load !== check.load) return false
    if (!live(check.epoch, check.token)) { resetMemory(); bootstrap(); return false }
    return connection(platform) === check.snapshot
  }
  function refreshConnectionChecks() {
    if (!state.ready || state.checksSuspended || !state.capabilities.connection_health_checks) return
    for (const platform of ['facebook', 'instagram', 'threads']) if (checkableConnection(connection(platform))) checkConnection(platform)
  }
  async function checkConnection(platform, { force = false } = {}) {
    const current = connection(platform), previous = connectionChecks.get(platform)
    if (!state.ready || state.checksSuspended || !state.capabilities.connection_health_checks || !['facebook', 'instagram', 'threads'].includes(platform) || !checkableConnection(current)) return null
    if (!live(state.epoch, state.token)) { resetMemory(); bootstrap(); return null }
    if (previous?.pending) return previous.promise
    const fingerprint = connectionFingerprint(current)
    if (!force && previous?.fingerprint === fingerprint && Date.now() - previous.checkedAt < CONNECTION_CHECK_TTL) return previous.health
    const check = { snapshot: current, fingerprint, epoch: state.epoch, token: state.token, load: state.load, controller: new AbortController(), pending: true, checkedAt: 0, health: null }
    connectionChecks.set(platform, check); renderConnectionState()
    check.promise = (async () => {
      try {
        const data = await request(API + '/connections/' + platform + '/check', { method: 'POST', body: {}, signal: check.controller.signal })
        if (!currentConnectionCheck(platform, check)) return null
        const updated = data.connection, health = data.health_check
        if ((updated?.provider || updated?.platform) !== platform || !['connected', 'expired', 'reconnect_required', 'not_connected', 'not_configured'].includes(updated.status) || !['valid', 'invalid', 'unknown'].includes(health?.status)) throw Error('Jawapan semakan belum dapat disahkan.')
        state.connections = state.connections.map(item => item === current ? updated : item)
        check.snapshot = updated; check.fingerprint = connectionFingerprint(updated); check.health = health
        return health
      } catch (error) {
        if (!currentConnectionCheck(platform, check)) return null
        if (error.status === 404 && error.data?.code === 'not_connected') {
          const updated = { ...current, status: 'not_connected', connected: false, publishable: false, accounts: [], message: 'Akaun belum disambungkan.' }
          state.connections = state.connections.map(item => item === current ? updated : item); check.snapshot = updated; check.fingerprint = connectionFingerprint(updated)
          check.health = { status: 'invalid', code: 'not_connected', message: 'Akaun belum disambungkan. Pilih Sambungkan akaun untuk meneruskan.' }
        } else check.health = { status: 'unknown', code: 'check_unavailable', message: 'Akses belum dapat disahkan. Semak internet atau cuba Semak sambungan sekali lagi. Draf anda masih boleh disimpan.' }
        return check.health
      } finally {
        if (currentConnectionCheck(platform, check)) { check.pending = false; check.checkedAt = Date.now(); renderConnectionState() }
      }
    })()
    return check.promise
  }
  function renderConnections() {
    const host = $('connection-list'); host.replaceChildren()
    for (const p of ['facebook', 'instagram', 'threads', 'google_ads']) {
      const c = connection(p), connected = c?.status === 'connected', configured = c?.configured === true, connectable = configured && c?.connectable !== false && p !== 'google_ads'
      const limitedThreads = p === 'threads' && state.capabilities.threads_customer_access === 'testing'
      const health = connectionHealth(p), check = connectionChecks.get(p), reconnect = ['expired', 'reconnect_required'].includes(c?.status) || health === 'invalid'
      const card = el('article', undefined, 'connection-card'), top = el('div', undefined, 'card-top')
      card.dataset.platform = p
      const label = health === 'checking' ? 'Menyemak akses' : reconnect ? 'Sambung semula' : connected && health === 'unknown' ? 'Belum dapat disahkan' : connected ? 'Disambungkan' : configured ? limitedThreads ? 'Akses terhad' : 'Belum disambungkan' : p === 'google_ads' ? 'Perancangan sahaja' : 'Belum tersedia'
      top.append(platformIcon(p), statusPill(health === 'checking' ? 'checking' : reconnect ? 'failed' : connected && health === 'unknown' ? 'uncertain' : connected ? 'connected' : 'draft', label)); card.append(top, el('h3', p === 'facebook' ? 'Facebook Page' : C.PLATFORM[p]))
      const fallback = p === 'google_ads' ? 'Catat rancangan dan hasil Google Ads dalam kempen. Pengurusan iklan automatik belum tersedia di ruang ini.' : configured ? ({ facebook: 'Sambungkan Page yang anda urus untuk menerbitkan kandungan perniagaan.', instagram: 'Sambungkan akaun Instagram profesional yang anda urus.', threads: 'Sambungkan profil Threads untuk menerbitkan teks dan media.' }[p]) : 'Sambungan saluran ini belum disediakan. Anda boleh menyimpan draf dan rancangan sementara menunggu sambungan tersedia.'
      const healthMessage = health === 'checking' ? 'Sedang menyemak akses dengan ' + C.PLATFORM[p] + '…' : check?.health?.message
      const details = el('p', healthMessage ? safeProviderError(healthMessage) : c?.message || fallback); details.setAttribute('role', 'status'); card.append(details)
      if (configured && limitedThreads && (!connected || reconnect)) card.append(el('p', 'Sambungan Threads untuk pelanggan baharu masih menunggu kelulusan Meta. Buat masa ini, hanya akaun yang telah menerima dan mengesahkan jemputan penguji boleh menyambung.', 'connection-onboarding-note'))
      if (Array.isArray(c?.accounts) && c.accounts.length) { const accounts = el('div', undefined, 'connection-accounts'); if (reconnect) accounts.append(el('small', 'Akaun tersimpan · perlu sambung semula')); c.accounts.forEach(a => { const n = el('div', a.name || 'Akaun dipilih'); n.append(el('small', a.account_id || a.id || C.PLATFORM[p])); accounts.append(n) }); card.append(accounts) }
      if (connected && health === 'valid' && !publishReady(p)) card.append(el('p', 'Akaun disambungkan. Penjadualan automatik belum tersedia; draf masih boleh disimpan.', 'hint'))
      if (connected && health === 'unknown') card.append(el('p', 'Draf boleh disimpan sementara anda menyemak akses.', 'hint'))
      if (connected && publishReady(p) && state.capabilities.worker_health === 'not_checked') card.append(el('p', 'Penerbitan pelayan diaktifkan. Status proses penerbitan belum disemak; status setiap kandungan akan menunjukkan hasilnya.', 'hint'))
      const actions = el('div', undefined, 'actions')
      if (state.capabilities.connection_health_checks && checkableConnection(c)) { const b = button(check?.pending ? 'Sedang menyemak…' : 'Semak sambungan', () => checkConnection(p, { force: true }), 'button secondary', 'refresh'); b.disabled = !!check?.pending; actions.append(b) }
      if (connected && !reconnect) actions.append(button('Putuskan sambungan', () => disconnect(p), 'button quiet'))
      else { const b = button(connectable ? reconnect ? 'Sambung semula akaun' : 'Sambungkan akaun' : p === 'google_ads' ? 'Rancang dalam kempen' : 'Sambungan belum tersedia', () => p === 'google_ads' ? openCampaign() : beginConnect(p), p === 'google_ads' ? 'button quiet' : 'button secondary', p === 'google_ads' ? 'target' : 'link'); b.disabled = !connectable && p !== 'google_ads'; actions.append(b) }
      card.append(actions); host.append(card)
    }
  }
  async function beginConnect(platform) {
    if (location.origin === 'https://www.beshareaisolution.com') {
      message('Sambungan Facebook, Instagram dan Threads dibuat melalui beshareaisolution.com. Masuk dengan akaun BeShare yang sama, kemudian buka Sambungan untuk meneruskan. ')
      const link = el('a', 'Masuk di beshareaisolution.com', 'text-link')
      link.href = 'https://beshareaisolution.com/akaun/?selepas=marketing'
      $('global-message').append(link)
      link.focus()
      return
    }
    if (platform === 'threads' && state.capabilities.threads_customer_access === 'testing' && !await confirmAction('Akses Threads masih terhad', 'Sambungan untuk pelanggan baharu masih menunggu kelulusan Meta. Teruskan hanya jika akaun Threads anda telah menerima dan mengesahkan jemputan penguji. Langkah ini tidak menghantar jemputan.', 'Teruskan sebagai penguji')) return
    try { notify('Menyediakan sambungan akaun…'); const d = await request(API + '/connections/' + platform + '/begin', { method: 'POST', body: {} }), u = new URL(d.authorization_url); if (u.protocol !== 'https:' || !['facebook.com', 'instagram.com', 'threads.net', 'threads.com'].some(host => u.hostname === host || u.hostname.endsWith('.' + host))) throw Error('Pautan sambungan belum dapat disahkan. Cuba lagi.'); location.assign(u.href) } catch (error) { message(error.message) }
  }
  async function disconnect(platform) {
    const explanation = platform === 'facebook_ads' ? 'BeShare akan berhenti mengakses akaun iklan ini. Kempen anda di Meta terus mengikut tetapan Ads Manager.' : 'BeShare akan berhenti menggunakan akses ' + C.PLATFORM[platform] + ' ini. Semak semula kandungan dalam giliran sebelum menjadualkan semula.'
    if (!await confirmAction('Putuskan sambungan?', explanation, 'Putuskan sambungan')) return
    connectionChecks.get(platform)?.controller?.abort(); connectionChecks.delete(platform)
    try { await request(API + '/connections/' + platform, { method: 'DELETE', body: {} }); await bootstrap(); notify('Sambungan BeShare telah diputuskan.') } catch (error) { message(error.message) }
  }
  function showDialog(id) { if (!$(id).open) $(id).showModal() }
  function confirmAction(title, text, accept = 'Teruskan') { if (confirmResolve) return Promise.resolve(false); $('confirm-title').textContent = title; $('confirm-text').textContent = text; $('confirm-accept').textContent = accept; showDialog('confirm-dialog'); return new Promise(resolve => { confirmResolve = resolve }) }
  function finishConfirm(answer) { const resolve = confirmResolve; confirmResolve = null; $('confirm-dialog').close(); resolve?.(answer) }
  async function closeDialog(id) {
    const kind = id.replace('-dialog', ''), editor = editors[kind]
    if (editor?.saving) { feedback(kind, 'Tunggu simpanan selesai dahulu.'); return }
    if (editor?.dirty && !await confirmAction('Tutup tanpa menyimpan?', 'Suntingan yang belum disimpan akan dibuang. Rekod yang sudah disimpan dalam akaun kekal tersedia.', 'Buang suntingan')) return
    if (kind === 'ai') aiController?.abort()
    if (kind === 'post') disposePostMedia(editor)
    $(id).close(); if (editor) editor.dirty = false
  }
  function editorState(kind, item) { editors[kind] = { item: structuredClone(item), dirty: false, saving: false, conflict: null }; $(kind + '-conflict').hidden = true; feedback(kind); $(kind + '-save-state').textContent = item.version ? 'Disimpan dalam akaun' : 'Belum disimpan' }
  function dirty(kind) { const e = editors[kind]; if (e) { e.dirty = true; $(kind + '-save-state').textContent = 'Suntingan belum disimpan'; feedback(kind) } }
  function setValues(prefix, values) { for (const [key, value] of Object.entries(values)) if ($(prefix + '-' + key)) $(prefix + '-' + key).value = value ?? '' }
  function ensureCampaign() { if (state.campaigns.length) return true; notify('Cipta kempen dahulu supaya kandungan dan hasil mempunyai tujuan yang sama.'); location.hash = 'kempen'; openCampaign(); return false }
  function openCampaign(item) {
    if (!state.ready) return
    const c = item || { id: C.id(), version: 0, title: '', product: '', offer: '', audience: '', goal: 'leads', budget_total: '0.00', destination_url: '', start_date: '', end_date: '', channels: [], status: 'draft' }
    editorState('campaign', c); editors.campaign.step = item ? -1 : 0
    setValues('campaign', { title: c.title, product: c.product, offer: c.offer, audience: c.audience, goal: c.goal, budget: c.budget_total, url: c.destination_url, start: c.start_date, end: c.end_date, state: c.status })
    document.querySelectorAll('[name=campaign-channel]').forEach(n => { n.checked = (c.channels || []).includes(n.value) })
    $('campaign-dialog-title').textContent = item ? 'Sunting kempen' : 'Kempen baharu'; showCampaignStep(); showDialog('campaign-dialog')
  }
  function showCampaignStep() {
    const step = editors.campaign.step; $('wizard-progress').hidden = step === -1
    document.querySelectorAll('[data-campaign-step]').forEach(n => { n.hidden = step !== -1 && Number(n.dataset.campaignStep) !== step })
    document.querySelectorAll('[data-step-label]').forEach(n => { n.classList.toggle('current', Number(n.dataset.stepLabel) === step); n.classList.toggle('done', Number(n.dataset.stepLabel) < step) })
    $('campaign-prev').hidden = step <= 0; $('campaign-submit').textContent = step === -1 || step === 2 ? 'Simpan kempen' : 'Seterusnya →'
    $('campaign-summary').replaceChildren(el('strong', $('campaign-title').value || 'Kempen anda'), el('p', C.GOALS[$('campaign-goal').value]), el('p', 'Maklumat ini membentuk asas kandungan dan rekod hasil anda.'))
  }
  function readCampaign() { return C.campaign({ version: editors.campaign.item.version, title: $('campaign-title').value, product: $('campaign-product').value.trim(), offer: $('campaign-offer').value.trim(), audience: $('campaign-audience').value.trim(), goal: $('campaign-goal').value, budget_total: $('campaign-budget').value, destination_url: $('campaign-url').value, start_date: $('campaign-start').value, end_date: $('campaign-end').value, channels: [...document.querySelectorAll('[name=campaign-channel]:checked')].map(n => n.value), status: $('campaign-state').value }) }
  async function saveCampaign(event) {
    event?.preventDefault(); const e = editors.campaign; if (!e || e.saving) return
    if (e.step >= 0 && e.step < 2) { try { if (e.step === 0 && !$('campaign-title').reportValidity()) return; if (e.step === 1) { C.money($('campaign-budget').value, 'Bajet'); C.https($('campaign-url').value) } e.step++; showCampaignStep(); $('campaign-dialog').scrollTop = 0 } catch (err) { feedback('campaign', err.message, 'error') } return }
    if (await save('campaign', readCampaign)) notify('Kempen disimpan. Anda boleh mula menyediakan kandungan.')
  }
  function openPost(item, defaults = {}) {
    if (!state.ready || !ensureCampaign()) return
    disposePostMedia(editors.post)
    const p = item || { id: C.id(), version: 0, campaign_id: defaults.campaign_id || $('post-campaign-filter').value || sorted(state.campaigns)[0]?.id || '', platform: 'facebook', format: 'text', body: '', media_url: '', scheduled_at: defaults.scheduled_at || '', status: defaults.scheduled_at ? 'planned' : 'draft', ...defaults }
    editorState('post', p); campaignOptions($('post-campaign'), { current: p.campaign_id })
    editors.post.media = p.media_id ? { id: p.media_id, url: p.media_url, format: p.format === 'reel' ? 'video' : p.format } : null
    editors.post.mediaNotice = ''; $('post-file').value = ''; $('post-media-url-options').open = !!p.media_url && !p.media_id
    setValues('post', { platform: p.platform, format: p.format === 'reel' ? 'video' : p.format, body: p.body, media: p.media_url, scheduled: C.malaysiaInput(p.scheduled_at), state: p.status === 'planned' ? 'planned' : 'draft' })
    $('post-dialog-title').textContent = p.version ? 'Sunting kandungan' : 'Kandungan baharu'; showDialog('post-dialog'); renderPostEditor(); checkConnection(p.platform); resolvePublication(editors.post.item)
  }
  function readPost() {
    const e = editors.post, body = $('post-body').value.trim(), platform = $('post-platform').value, campaign_id = $('post-campaign').value, format = $('post-format').value
    if (mediaUploading(e)) throw Error('Tunggu muat naik selesai atau batalkan muat naik sebelum menyimpan.')
    if (!state.campaigns.some(c => c.id === campaign_id)) throw Error('Pilih kempen untuk kandungan ini.')
    const media_url = format === 'text' ? '' : C.https($('post-media').value), media_id = media_url && e.media?.url === media_url ? e.media.id : ''
    if (media_id && e.media.format !== format) throw Error('Format tidak sepadan dengan lampiran. Pilih ' + (e.media.format === 'image' ? 'Gambar' : 'Video') + ' atau pilih fail baharu.')
    return { version: e.item.version, campaign_id, platform, format, body, media_id, media_url, scheduled_at: C.utcFromMalaysia($('post-scheduled').value), status: $('post-state').value }
  }
  function renderPostEditor() {
    const e = editors.post; if (!e) return
    const p = e.item, platform = $('post-platform').value, format = $('post-format').value, text = $('post-body').value, locked = ['queued', 'publishing', 'published', 'uncertain'].includes(p.status), ready = publishReady(platform)
    const destination = connection(platform), health = connectionHealth(platform)
    // A published post uses its server-owned receipt, never a newly selected connection.
    const receipt = publicationReceipt(p)
    const accountName = receipt?.account_name || (!locked && !p.provider_post_id && destination?.accounts?.length === 1 ? destination.accounts[0]?.name : '')
    $('preview-name').textContent = typeof accountName === 'string' && accountName.trim() ? accountName : 'Akaun ' + C.PLATFORM[platform]
    $('post-dialog-title').textContent = p.version ? locked ? 'Semak kandungan' : 'Sunting kandungan' : 'Kandungan baharu'
    $('post-state-label').hidden = locked; $('post-status-readonly').hidden = !locked; $('post-status-value').textContent = C.STATUS[p.status] || 'Perlu semakan'
    $('post-destination-note').textContent = 'Kandungan ini untuk ' + C.PLATFORM[platform] + ' sahaja.' + (accountName ? ' ' + (receipt ? 'Destinasi penerbitan: ' : health === 'invalid' ? 'Akaun tersimpan: ' : 'Akaun dipilih: ') + accountName + '.' : '')
    const publicationNote = locked ? ({ queued: 'Kandungan berada dalam giliran dan belum disahkan terbit.', publishing: 'Kandungan sedang dihantar. Semak status penerbitan sebelum membuat pos baharu.', published: 'Penerbitan kandungan ini telah direkod oleh ' + C.PLATFORM[platform] + '.', uncertain: 'Hasil penerbitan belum dapat dipastikan. Semak saluran sebelum mencuba semula.' }[p.status]) : p.status === 'failed' ? 'Percubaan penerbitan gagal. Semak kandungan sebelum menjadualkan semula.' : $('post-state').value === 'planned' ? 'Rancangan ini belum diterbitkan. Pilih Lulus & jadualkan untuk meminta penerbitan.' : 'Draf ini belum diterbitkan. Simpan draf hanya menyimpan kandungan dalam akaun anda.'
    if ($('post-publication-note').textContent !== publicationNote) $('post-publication-note').textContent = publicationNote
    renderPublication(p, $('post-publication-links'))
    $('post-save').hidden = locked; $('schedule-post').hidden = locked
    $('post-save').textContent = $('post-state').value === 'planned' ? 'Simpan rancangan' : 'Simpan draf'
    $('post-count').textContent = platform === 'threads' ? new TextEncoder().encode(text).length + ' / 500 bait UTF-8' : platform === 'instagram' ? text.length + ' / 2,200 aksara' : text.length + ' aksara'
    $('preview-platform').textContent = C.PLATFORM[platform] + ' · Pratonton'; $('preview-body').textContent = text || 'Teks kandungan anda akan muncul di sini.'
    $('post-connection-note').textContent = locked ? 'Status: ' + C.STATUS[p.status] + '. ' + (p.status === 'queued' ? 'Batalkan jadual untuk menyunting kandungan semula.' : p.status === 'published' ? 'Penerbitan telah direkod oleh saluran.' : 'Semak status penerbitan sebelum membuat perubahan.') : health === 'checking' ? 'Sedang menyemak akses ' + C.PLATFORM[platform] + '. Anda boleh terus menyunting dan menyimpan draf.' : health === 'invalid' ? 'Akses ' + C.PLATFORM[platform] + ' perlu disambungkan semula sebelum menjadualkan penerbitan. Draf anda masih boleh disimpan.' : health === 'unknown' ? 'Akses ' + C.PLATFORM[platform] + ' belum dapat disahkan. Semak sambungan sebelum meluluskan jadual baharu.' : ready ? C.PLATFORM[platform] + ' disambungkan. Semak kandungan dan masa, kemudian pilih Lulus & jadualkan.' : destination?.status === 'connected' ? 'Akaun disambungkan. Penjadualan automatik belum tersedia. Anda masih boleh menyimpan rancangan.' : 'Saluran belum disambungkan. Kandungan boleh disimpan sebagai draf atau rancangan; ia belum diterbitkan.'
    if (platform === 'instagram' && format === 'text') $('post-connection-note').textContent += ' Instagram memerlukan gambar atau video.'
    if (!locked && health === 'unknown') $('post-connection-note').append(document.createTextNode(' '), button('Semak sambungan', () => checkConnection(platform, { force: true }), 'text-button'))
    if (!locked && health === 'invalid') { const link = el('a', 'Buka Sambungan', 'text-link'); link.href = '#sambungan'; link.addEventListener('click', async event => { event.preventDefault(); await closeDialog('post-dialog'); if (!$('post-dialog').open) location.hash = 'sambungan' }); $('post-connection-note').append(document.createTextNode(' '), link) }
    $('post-form').querySelectorAll('input,select,textarea').forEach(n => { n.disabled = locked || e.saving })
    $('post-ai').disabled = locked || e.saving; $('post-save').disabled = locked || e.saving || mediaUploading(e); $('schedule-post').disabled = locked || e.saving || mediaUploading(e) || !ready
    $('cancel-post-schedule').hidden = !['queued', 'planned', 'failed'].includes(p.status); $('cancel-post-schedule').disabled = e.saving || mediaUploading(e)
    $('cancel-post-schedule').textContent = p.status === 'queued' ? 'Batalkan jadual' : 'Batalkan rancangan'
    renderMediaEditor(e, locked); previewMedia()
  }
  function previewMedia() {
    const e = editors.post; if (!e) return
    const pending = mediaUploading(e) ? e.upload : null, format = pending?.info.format || $('post-format').value, target = $('preview-media')
    target.hidden = format === 'text'
    let url = pending?.previewUrl || ''
    if (!url && format !== 'text' && !e.previewWait) { try { url = C.https($('post-media').value) } catch {} }
    const key = format + '|' + url
    if (key === e.previewKey) return
    e.previewKey = key; target.replaceChildren()
    if (format === 'text') return
    if (!url) { target.append(el('span', e.previewWait ? 'Mengemas kini pratonton…' : 'Pilih fail atau masukkan pautan untuk melihat pratonton.')); return }
    const media = el(format === 'image' ? 'img' : 'video')
    if (format === 'image') media.alt = pending ? 'Pratonton gambar yang sedang dimuat naik' : 'Gambar yang dilampirkan pada kandungan'
    else { media.controls = true; media.preload = 'metadata'; media.playsInline = true; media.setAttribute('aria-label', pending ? 'Pratonton video yang sedang dimuat naik' : 'Video yang dilampirkan pada kandungan') }
    media.referrerPolicy = 'no-referrer'; media.src = url
    media.addEventListener('error', () => { if (editors.post === e && e.previewKey === key) target.replaceChildren(el('span', 'Media belum dapat dipratonton dalam pelayar ini. Semak fail atau pautan media.'), button('Cuba pratonton lagi', () => { e.previewKey = ''; previewMedia() }, 'button quiet')) })
    target.replaceChildren(media)
  }
  function mediaUploading(editor) { return !!editor?.upload && ['uploading', 'checking'].includes(editor.upload.status) }
  function mediaEditable(editor) { return !!editor && state.ready && !editor.saving && !['queued', 'publishing', 'published', 'uncertain'].includes(editor.item.status) }
  function releasePreview(upload) { if (upload?.previewUrl) { URL.revokeObjectURL(upload.previewUrl); upload.previewUrl = '' } }
  function cleanupMedia(id, epoch = state.epoch, token = state.token) {
    // Cleanup is best effort; the server also expires abandoned uploads. Never use a new account's token.
    if (id && live(epoch, token)) request(API + '/media/' + id, { method: 'DELETE' }).catch(() => {})
  }
  function abandonUpload(upload) {
    if (!upload) return
    upload.cancelled = true; upload.run++; upload.xhr?.abort(); releasePreview(upload); cleanupMedia(upload.id, upload.epoch, upload.token)
  }
  function disposePostMedia(editor) {
    if (!editor) return
    clearTimeout(editor.previewTimer); abandonUpload(editor.upload)
    if (editor.media?.id && editor.media.id !== editor.item.media_id) cleanupMedia(editor.media.id, editor.mediaEpoch, editor.mediaToken)
    editor.upload = null; editor.media = null; editor.previewKey = ''; editor.previewWait = false
  }
  function uploadCurrent(editor, upload, run) {
    if (editors.post !== editor || editor.upload !== upload || upload.run !== run || upload.cancelled || !$('post-dialog').open) return false
    if (!live(upload.epoch, upload.token)) { resetMemory(); bootstrap(); return false }
    return true
  }
  function renderMediaEditor(editor, locked) {
    const capability = state.capabilities.media_upload || {}, rules = C.mediaRules(capability), upload = editor.upload, busy = mediaUploading(editor), disabled = locked || editor.saving
    const accepted = rules.accepted.map(type => C.MEDIA_TYPES[type].label), images = rules.accepted.filter(type => C.MEDIA_TYPES[type].format === 'image').map(type => C.MEDIA_TYPES[type].label), videos = rules.accepted.filter(type => C.MEDIA_TYPES[type].format === 'video').map(type => C.MEDIA_TYPES[type].label)
    $('post-media-rules').textContent = images.join('/') + ' sehingga ' + C.fileSize(rules.image) + '. ' + videos.join('/') + ' sehingga ' + C.fileSize(rules.video) + ', ' + (capability.min_video_duration_seconds || 3) + '–' + (capability.max_video_duration_seconds || 300) + ' saat. Video H.264 atau HEVC; audio AAC jika ada. Satu fail bagi setiap kandungan.'
    $('post-file').accept = rules.accepted.concat(rules.accepted.flatMap(type => C.MEDIA_TYPES[type].extensions.map(ext => '.' + ext))).join(',')
    $('post-file').disabled = disabled || busy || !capability.enabled
    $('post-file-choose').disabled = $('post-file').disabled
    $('post-file-choose').textContent = $('post-media').value ? 'Pilih fail pengganti' : 'Pilih gambar atau video'
    $('post-media-drop').setAttribute('aria-disabled', String($('post-file').disabled))
    $('post-media-drop').dataset.uploading = String(busy)
    $('post-media-drop').setAttribute('aria-busy', String(busy))
    $('post-format').disabled = disabled || busy; $('post-media').disabled = disabled || busy
    const file = upload?.info || editor.media
    $('post-media-filename').textContent = file?.name ? file.name + (file.size_bytes ? ' · ' + C.fileSize(file.size_bytes) : '') : $('post-media').value ? 'Satu lampiran media dipilih' : 'Belum ada fail dipilih'
    $('post-media-remove').hidden = !$('post-media').value && !upload; $('post-media-remove').disabled = disabled || busy
    $('post-media-cancel').hidden = !busy; $('post-media-cancel').disabled = disabled
    $('post-media-retry').hidden = !upload || !['error', 'cancelled'].includes(upload.status) || !upload.retryable
    $('post-media-retry').disabled = disabled || busy || !capability.enabled
    $('post-media-progress-wrap').hidden = !busy
    const progress = $('post-media-progress')
    if (upload?.status === 'checking' || upload?.progress === null) progress.removeAttribute('value')
    else progress.value = upload?.progress || 0
    $('post-media-progress-label').textContent = upload?.status === 'checking' ? 'Menyemak…' : (upload?.progress || 0) + '%'
    const status = $('post-media-feedback')
    status.dataset.kind = upload?.status === 'error' ? 'error' : editor.mediaNoticeKind || ''
    status.textContent = busy ? upload.status === 'checking' ? 'Menyemak muat naik sebelumnya. Suntingan anda masih ada.' : upload.progress === 100 ? 'Fail telah dihantar. Menyediakan media untuk pratonton…' : 'Sedang memuat naik. Anda boleh terus menyunting teks.' : editor.mediaNotice || upload?.error || (!capability.enabled && !locked ? 'Muat naik belum tersedia. Anda masih boleh menggunakan pautan media sedia ada.' : '')
    $('post-file').setAttribute('aria-label', 'Pilih satu fail ' + accepted.join(', '))
  }
  function uploadMediaBytes(upload, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest(); upload.xhr = xhr
      xhr.open('PUT', services.akaun + API + '/media/' + upload.id + '?name=' + encodeURIComponent(upload.file.name))
      xhr.timeout = 300000; xhr.withCredentials = false
      xhr.setRequestHeader('Authorization', 'Bearer ' + upload.token); xhr.setRequestHeader('Content-Type', upload.info.mime_type)
      xhr.upload.onprogress = event => onProgress(event.lengthComputable && event.total > 0 ? Math.min(100, Math.round(event.loaded / event.total * 100)) : null)
      xhr.onload = () => {
        let data = {}; try { data = JSON.parse(xhr.responseText) } catch {}
        if (xhr.status < 200 || xhr.status >= 300 || data.ok !== true) reject(new RequestError(typeof data.error === 'string' ? data.error : 'Muat naik belum dapat disahkan. Cuba lagi untuk menyemak fail yang sama.', xhr.status, data))
        else resolve(data)
      }
      xhr.onerror = () => reject(new RequestError('Sambungan terputus semasa muat naik. Semak internet dan cuba lagi.'))
      xhr.ontimeout = () => reject(new RequestError('Muat naik mengambil masa terlalu lama. Cuba lagi untuk menyemak fail yang sama.', 408))
      xhr.onabort = () => reject(new RequestError('Muat naik dibatalkan.', 499))
      xhr.send(upload.file)
    })
  }
  function finishMediaUpload(editor, upload, data) {
    const media = data.media
    if (!media || media.id !== upload.id || media.format !== upload.info.format || !Number.isSafeInteger(media.size_bytes) || media.size_bytes < 1) throw new RequestError('Media belum dapat disahkan. Cuba lagi untuk menyemak muat naik yang sama.')
    const url = C.https(media.url, true), previous = editor.media
    editor.media = { ...media, url }; editor.mediaEpoch = upload.epoch; editor.mediaToken = upload.token
    editor.upload = null; releasePreview(upload)
    $('post-media').value = url; $('post-format').value = media.format; $('post-media-url-options').open = false
    clearTimeout(editor.previewTimer); editor.previewWait = false
    dirty('post'); editor.mediaNotice = 'Muat naik selesai. Semak pratonton, kemudian simpan atau jadualkan.'; editor.mediaNoticeKind = 'success'
    if (previous?.id && previous.id !== media.id && previous.id !== editor.item.media_id) cleanupMedia(previous.id, upload.epoch, upload.token)
    renderPostEditor()
  }
  async function runMediaUpload(editor, upload, reconcile = false) {
    const run = ++upload.run
    editor.mediaNotice = ''; editor.mediaNoticeKind = ''
    upload.cancelled = false; upload.status = reconcile ? 'checking' : 'uploading'; upload.error = ''; upload.progress = 0
    upload.previousFormat = $('post-format').value; $('post-format').value = upload.info.format
    dirty('post'); renderPostEditor()
    try {
      if (reconcile) {
        try {
          const existing = await request(API + '/media/' + upload.id)
          if (uploadCurrent(editor, upload, run)) finishMediaUpload(editor, upload, existing)
          return
        } catch (error) {
          if (!uploadCurrent(editor, upload, run)) return
          if (error.status === 410 && error.data?.code === 'media_cancelled') upload.id = C.id()
          else if (!(error.status === 404 && error.data?.code === 'media_not_found') && !(error.status === 409 && error.data?.code === 'upload_failed')) throw error
        }
      }
      if (!uploadCurrent(editor, upload, run)) return
      upload.status = 'uploading'; renderPostEditor()
      const data = await uploadMediaBytes(upload, progress => { if (uploadCurrent(editor, upload, run)) { upload.progress = progress; renderMediaEditor(editor, false) } })
      if (uploadCurrent(editor, upload, run)) finishMediaUpload(editor, upload, data)
    } catch (error) {
      if (!uploadCurrent(editor, upload, run)) return
      if (error.status === 401) { expireSession(upload.token); return }
      upload.status = 'error'; upload.retryable = ![413, 415, 422].includes(error.status)
      upload.error = (error.data?.code === 'upload_in_progress' ? 'Muat naik sebelumnya masih diproses. Tunggu sebentar, kemudian cuba lagi.' : error.message) + ($('post-media').value ? ' Lampiran sebelumnya masih digunakan.' : ' Teks anda masih ada.')
      $('post-format').value = upload.previousFormat
      renderPostEditor()
    }
  }
  async function choosePostFiles(files) {
    const editor = editors.post
    if (!mediaEditable(editor) || mediaUploading(editor) || !files?.length) return
    if (!live(state.epoch, state.token)) { resetMemory(); bootstrap(); return }
    try {
      if (!state.capabilities.media_upload?.enabled) throw Error('Muat naik belum tersedia. Gunakan pautan media sedia ada buat masa ini.')
      if (files.length !== 1) throw Error('Pilih satu gambar atau video bagi setiap kandungan.')
      const file = files[0], info = C.mediaFile(file, state.capabilities.media_upload), previewUrl = URL.createObjectURL(file)
      abandonUpload(editor.upload)
      const upload = { id: C.id(), file, info, previewUrl, epoch: state.epoch, token: state.token, run: 0, retryable: true }
      editor.upload = upload; editor.mediaNotice = ''; editor.mediaNoticeKind = ''
      await runMediaUpload(editor, upload)
    } catch (error) { if (editors.post === editor) { editor.mediaNotice = error.message; editor.mediaNoticeKind = 'error'; renderPostEditor() } }
  }
  async function retryMediaUpload() {
    const editor = editors.post, upload = editor?.upload
    if (!mediaEditable(editor) || mediaUploading(editor) || !upload?.retryable || !state.capabilities.media_upload?.enabled) return
    if (!live(upload.epoch, upload.token)) { resetMemory(); bootstrap(); return }
    const reconcile = upload.status === 'error'
    if (!reconcile) upload.id = C.id()
    if (!upload.previewUrl) upload.previewUrl = URL.createObjectURL(upload.file)
    await runMediaUpload(editor, upload, reconcile)
  }
  function cancelMediaUpload() {
    const editor = editors.post, upload = editor?.upload
    if (!mediaEditable(editor) || !mediaUploading(editor)) return
    abandonUpload(upload); upload.id = ''; upload.status = 'cancelled'; upload.retryable = true
    upload.error = 'Muat naik dibatalkan.' + ($('post-media').value ? ' Lampiran sebelumnya masih digunakan.' : ' Teks anda masih ada.')
    $('post-format').value = upload.previousFormat; renderPostEditor()
  }
  function removePostMedia() {
    const editor = editors.post
    if (!mediaEditable(editor) || mediaUploading(editor)) return
    disposePostMedia(editor); $('post-media').value = ''; $('post-file').value = ''; $('post-format').value = 'text'
    dirty('post'); editor.mediaNotice = 'Lampiran dibuang daripada suntingan ini. Simpan untuk mengemas kini kandungan.'; editor.mediaNoticeKind = ''
    renderPostEditor()
  }
  function postMediaInput(event) {
    const editor = editors.post; if (!editor) return
    if (event.target?.id === 'post-media') {
      if (editor.upload && !mediaUploading(editor)) { abandonUpload(editor.upload); editor.upload = null }
      if (editor.media && $('post-media').value !== editor.media.url) {
        if (editor.media.id !== editor.item.media_id) cleanupMedia(editor.media.id, editor.mediaEpoch, editor.mediaToken)
        editor.media = null
      }
      editor.mediaNotice = ''; editor.mediaNoticeKind = ''; editor.previewWait = true
      clearTimeout(editor.previewTimer); editor.previewTimer = setTimeout(() => { if (editors.post === editor) { editor.previewWait = false; previewMedia() } }, 450)
    }
    if (event.target?.id === 'post-format' && $('post-format').value === 'text' && ($('post-media').value || editor.upload)) removePostMedia()
  }
  async function schedulePost() {
    const e = editors.post; if (!e || e.saving || mediaUploading(e)) return
    try {
      const p = readPost()
      if (state.capabilities.connection_health_checks) {
        await checkConnection(p.platform)
        if (editors.post !== e || !state.ready || !$('post-dialog').open) return
        if (!payloadMatches(readPost(), p)) throw Error('Kandungan berubah semasa semakan sambungan. Suntingan anda dikekalkan; semak semula dan pilih Lulus & jadualkan sekali lagi.')
      }
      if (!publishReady(p.platform)) throw Error('Sambungan penerbitan belum tersedia. Simpan kandungan sebagai rancangan dahulu.')
      if (!p.scheduled_at || new Date(p.scheduled_at).getTime() < Date.now() + 60000) throw Error('Pilih masa penerbitan sekurang-kurangnya satu minit dari sekarang.')
      if (!p.body && p.format === 'text') throw Error('Tulis kandungan sebelum menjadualkan penerbitan.')
      if (p.platform === 'instagram' && p.format === 'text') throw Error('Pilih gambar atau video untuk penerbitan Instagram.')
      if (p.format !== 'text' && !p.media_url) throw Error('Muat naik gambar atau video, atau masukkan pautan media sebelum menjadualkan penerbitan.')
      if (p.platform === 'threads' && new TextEncoder().encode(p.body).length > 500) throw Error('Ringkaskan teks Threads kepada maksimum 500 bait UTF-8.')
      if (p.platform === 'instagram' && p.body.length > 2200) throw Error('Ringkaskan kapsyen Instagram kepada maksimum 2,200 aksara.')
      if (!await confirmAction('Luluskan penerbitan ini?', C.PLATFORM[p.platform] + '\n' + C.displayDate(p.scheduled_at, true) + ' · waktu Malaysia\n\nKandungan yang diluluskan akan dihantar secara automatik pada waktu ini jika sambungan tersedia.', 'Lulus & jadualkan')) return
      $('post-state').value = 'planned'; e.dirty = true
      if (!await save('post', readPost)) return
      e.saving = true; renderPostEditor(); feedback('post', 'Menyemak kelulusan dan sambungan penerbitan…')
      const data = await request(API + '/posts/' + e.item.id + '/schedule', { method: 'POST', body: { version: e.item.version, approved: true, scheduled_at: e.item.scheduled_at } })
      if (!data.post) throw Error('Status jadual belum dapat disahkan. Muat semula ruang kerja untuk menyemak.')
      upsert('post', data.post); e.item = structuredClone(data.post); e.dirty = false
      const queued = data.post.status === 'queued'; feedback('post', queued ? 'Diluluskan dan dimasukkan ke dalam giliran. Status akhir akan dikemas kini selepas dihantar.' : 'Rancangan disimpan. Kandungan belum berada dalam giliran penerbitan.', queued ? 'success' : '')
      notify(queued ? 'Kandungan dijadualkan. Ia belum diterbitkan.' : 'Rancangan disimpan; penerbitan belum bermula.')
    } catch (error) { if (error.status === 409 && error.data?.current) showConflict('post', error.data.current); feedback('post', error.message, 'error') }
    finally { if (editors.post === e) { e.saving = false; renderPostEditor() } }
  }
  async function cancelPost() {
    const e = editors.post; if (!e || e.saving || mediaUploading(e)) return
    const action = e.item.status === 'queued' ? 'Batalkan jadual' : 'Batalkan rancangan'
    if (!await confirmAction(action + ' kandungan?', 'Kandungan akan dikeluarkan daripada rancangan atau giliran jika penerbitan belum bermula.', action)) return
    try { e.saving = true; renderPostEditor(); const d = await request(API + '/posts/' + e.item.id + '/cancel', { method: 'POST', body: { version: e.item.version } }); upsert('post', d.post); openPost(d.post); feedback('post', 'Jadual dibatalkan. Anda boleh menyunting dan menyimpan sebagai draf.', 'success') } catch (error) { if (error.status === 409 && error.data?.current) showConflict('post', error.data.current); feedback('post', error.message, 'error') } finally { if (editors.post === e) e.saving = false; renderPostEditor() }
  }
  function openSource(item) {
    if (!state.ready) return
    const s = item || { id: C.id(), version: 0, campaign_id: $('source-campaign-filter').value, title: '', url: '', observation: '', hypothesis: '', source_type: 'reference' }; editorState('source', s)
    campaignOptions($('source-campaign'), { emptyLabel: 'Rujukan umum', current: s.campaign_id }); setValues('source', { title: s.title, url: s.url, observation: s.observation, hypothesis: s.hypothesis, type: s.source_type }); $('source-dialog-title').textContent = item ? 'Sunting rujukan' : 'Tambah rujukan'; $('source-url').required = s.source_type === 'reference'; showDialog('source-dialog')
  }
  async function discoverTelegram(event) {
    event.preventDefault()
    if (!state.ready || telegramSearch) return
    const { epoch, token } = state
    const form = event.currentTarget, submit = form.querySelector('button[type="submit"]')
    const status = $('telegram-discovery-status'), host = $('telegram-discovery-results')
    const controller = new AbortController()
    telegramSearch = controller
    submit.disabled = true
    host.replaceChildren()
    status.textContent = 'Mencari komuniti awam…'
    try {
      const body = Object.fromEntries(new FormData(form))
      const data = await request(API + '/telegram-discovery', { method: 'POST', body, signal: controller.signal, timeoutMs: 45000 })
      for (const row of data.results) {
        const card = el('article', undefined, 'group-card')
        card.append(el('h3', row.name), el('p', row.kind + ' · ' + row.handle), el('p', row.reason),
          el('p', 'Peraturan promosi: ' + row.rule))
        if (row.rule_evidence) card.append(el('p', row.rule_evidence))
        for (const [index, url] of [...new Set([row.url, row.rule_source, ...row.sources].filter(Boolean))].entries()) {
          const link = el('a', index === 0 ? 'Buka di Telegram' : 'Sumber ' + index, 'button quiet')
          link.href = url
          link.target = '_blank'
          link.rel = 'noopener noreferrer'
          card.append(link)
        }
        card.append(button('Simpan sebagai rujukan', () => {
          if (!live(epoch, token)) return
          openSource()
          const campaign = $('telegram-discovery-campaign').value
          if (campaign) $('source-campaign').value = campaign
          $('source-title').value = row.name
          $('source-url').value = row.url
          $('source-observation').value = [row.reason, row.rule, row.rule_evidence, row.rule_source, ...row.sources].filter(Boolean).join('\n')
        }))
        card.append(button('Isi borang destinasi', () => {
          if (!live(epoch, token)) return
          location.hash = 'kumpulan'
          navigate()
          $('group-platform').value = 'telegram'
          $('group-platform').dispatchEvent(new Event('change'))
          $('group-ownership').value = 'partner'
          $('group-name').value = row.name
          $('group-chat').value = row.handle
          $('group-approved').checked = false
          $('group-name').focus()
        }))
        host.append(card)
      }
      status.textContent = data.results.length ? data.results.length + ' komuniti ditemui.' : 'Tiada komuniti dengan sumber awam yang sesuai. Cuba istilah atau kawasan lain.'
    } catch (error) {
      if (live(epoch, token)) status.textContent = error.message
    } finally {
      if (telegramSearch === controller) telegramSearch = null
      submit.disabled = false
    }
  }
  $('telegram-discovery-form').addEventListener('submit', discoverTelegram)
  $('telegram-discovery-campaign').addEventListener('change', event => {
    const campaign = state.campaigns.find(item => item.id === event.target.value)
    if (!campaign) return
    const fields = $('telegram-discovery-form').elements
    fields.business.value = (campaign.product || campaign.title).slice(0, 160)
    fields.audience.value = (campaign.audience || '').slice(0, 160)
  })

  function readSource() { const source_type = $('source-type').value; return { version: editors.source.item.version, campaign_id: $('source-campaign').value, title: $('source-title').value.trim(), url: C.https($('source-url').value, source_type === 'reference'), observation: $('source-observation').value.trim(), hypothesis: $('source-hypothesis').value.trim(), source_type } }
  function openResult(item) {
    if (!state.ready || !ensureCampaign()) return
    const r = item || { id: C.id(), version: 0, campaign_id: $('result-campaign-filter').value || sorted(state.campaigns)[0]?.id || '', date: C.malaysiaDate(), platform: 'facebook', spend: '0.00', revenue: '0.00', impressions: 0, clicks: 0, enquiries: 0, qualified_leads: 0, orders: 0, note: '' }; editorState('result', r); campaignOptions($('result-campaign'), { current: r.campaign_id }); setValues('result', r); $('result-dialog-title').textContent = item ? 'Sunting hasil harian' : 'Catat hasil harian'; showDialog('result-dialog')
  }
  function readResult() { const r = { version: editors.result.item.version, campaign_id: $('result-campaign').value, date: $('result-date').value, platform: $('result-platform').value, note: $('result-note').value.trim() }; for (const k of ['spend', 'revenue', 'impressions', 'clicks', 'enquiries', 'qualified_leads', 'orders']) r[k] = $('result-' + k).value; return C.result(r) }
  function payloadMatches(record, payload) { return Object.keys(payload).filter(k => k !== 'version').every(k => JSON.stringify(record[k] ?? '') === JSON.stringify(payload[k] ?? '')) }
  function showConflict(kind, current) { const e = editors[kind]; if (!e) return; e.conflict = current; $(kind + '-conflict').hidden = false }
  async function save(kind, read) {
    const e = editors[kind]; if (!e || e.saving || !state.ready) return false
    let payload; try { payload = read() } catch (error) { feedback(kind, error.message, 'error'); return false }
    if (!$(kind + '-form').reportValidity()) return false
    if (kind === 'result') {
      const duplicate = state.results.find(r => r.id !== e.item.id && r.campaign_id === payload.campaign_id && r.date === payload.date && r.platform === payload.platform)
      if (duplicate) { feedback(kind, 'Rekod untuk kempen, tarikh dan saluran ini sudah ada. Sunting rekod tersebut untuk mengelakkan kiraan berulang.', 'error'); $('result-feedback').append(document.createTextNode(' '), button('Buka rekod sedia ada', async () => { if (!e.dirty || await confirmAction('Buka rekod sedia ada?', 'Suntingan semasa yang belum disimpan akan dibuang.', 'Buka rekod')) openResult(duplicate) }, 'text-button')); return false }
    }
    e.saving = true; feedback(kind, 'Menyimpan dalam akaun…'); const saveButton = $(kind === 'campaign' ? 'campaign-submit' : kind + '-save'); saveButton.disabled = true
    if (kind === 'post') renderPostEditor()
    let record
    try {
      try { const data = await request(API + '/' + plural[kind] + '/' + e.item.id, { method: 'PUT', body: payload }); record = data[kind] }
      catch (error) { if (error.status === 409 && error.data?.current) { if (payloadMatches(error.data.current, payload)) record = error.data.current; else showConflict(kind, error.data.current) } if (!record) throw error }
      if (editors[kind] !== e || !state.ready) return false
      if (!record?.id || !Number.isInteger(record.version)) throw Error('Simpanan belum dapat disahkan. Cuba simpan lagi menggunakan draf yang sama.')
      let unchanged = false; try { unchanged = payloadMatches(read(), payload) } catch {}
      const previousMedia = kind === 'post' ? e.item.media_id : ''
      e.item = structuredClone(record); e.dirty = !unchanged; e.conflict = null; $(kind + '-conflict').hidden = true
      if (previousMedia && previousMedia !== record.media_id) cleanupMedia(previousMedia)
      upsert(kind, record); $(kind + '-save-state').textContent = unchanged ? 'Disimpan dalam akaun' : 'Suntingan baharu belum disimpan'
      const savedMessage = kind === 'post' ? record.status === 'planned' ? 'Rancangan disimpan. Kandungan belum berada dalam giliran penerbitan.' : 'Draf disimpan. Kandungan belum diterbitkan.' : 'Disimpan. Rekod ini boleh dibuka semula pada peranti lain.'
      feedback(kind, unchanged ? savedMessage : 'Versi tadi disimpan. Suntingan baharu masih belum disimpan.', unchanged ? 'success' : '')
      if (kind === 'campaign') { e.step = -1; showCampaignStep(); $('campaign-dialog-title').textContent = 'Sunting kempen' }
      return unchanged
    } catch (error) { if (editors[kind] === e && error.status !== 401 && error.status !== 499) feedback(kind, error.status === 409 ? 'Salinan akaun telah berubah. Suntingan anda dikekalkan; semak salinan terkini di atas.' : error.message, 'error'); return false }
    finally { if (editors[kind] === e) { e.saving = false; saveButton.disabled = false; if (kind === 'post') renderPostEditor() } }
  }
  function loadConflict(kind) { const c = editors[kind]?.conflict; if (!c) return; upsert(kind, c); ({ campaign: openCampaign, post: openPost, source: openSource, result: openResult })[kind](c); feedback(kind, 'Salinan terkini telah dimuat. Anda boleh menyunting semula.', 'success') }
  async function copy(text, statusId, field) {
    try { if (!navigator.clipboard?.writeText) throw Error(); await navigator.clipboard.writeText(text); if (statusId) $(statusId).textContent = 'Disalin. Anda boleh tampal pada saluran pilihan.'; else notify('Disalin ke papan klip.') }
    catch { if (field) { field.focus(); field.select(); if (statusId) $(statusId).textContent = 'Teks dipilih. Gunakan menu Salin pada peranti anda.' } else notify('Salinan automatik tidak tersedia. Gunakan butang Muat turun.') }
  }
  function download(name, text, type) { const url = URL.createObjectURL(new Blob([text], { type })), a = el('a'); a.href = url; a.download = name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000) }
  function openLinks(c) {
    const host = $('utm-links'); host.replaceChildren(); $('links-dialog-title').textContent = 'Ringkasan & pautan kempen'; feedback('links')
    const summary = el('section', undefined, 'utm-row'), label = el('label', 'Ringkasan kempen'), text = el('textarea'); text.rows = 7; text.readOnly = true; text.value = C.brief(c); label.append(text); const actions = el('div', undefined, 'actions'); actions.append(button('Salin ringkasan', () => copy(text.value, 'links-feedback', text), 'button secondary'), button('Muat turun .txt', () => download('beshare-kempen-' + c.id.slice(0, 8) + '.txt', text.value, 'text/plain;charset=utf-8'), 'button quiet', 'download')); summary.append(label, actions); host.append(summary)
    if (!c.destination_url) host.append(el('p', 'Tambah pautan destinasi dalam kempen untuk membina pautan UTM mengikut saluran.', 'hint'))
    else for (const platform of c.channels?.length ? c.channels : ['facebook', 'instagram', 'threads']) { try { const link = C.utm(c.destination_url, platform, c.id), row = el('div', undefined, 'utm-row'), lab = el('label', C.PLATFORM[platform]), field = el('input'); field.value = link; field.readOnly = true; lab.append(field); const act = el('div', undefined, 'actions'); act.append(button('Salin pautan', () => copy(link, 'links-feedback', field), 'button secondary')); row.append(lab, act); host.append(row) } catch { host.append(el('p', 'Pautan destinasi perlu disemak sebelum pautan UTM boleh dibina.', 'hint')) } }
    showDialog('links-dialog')
  }
  function openAI() {
    const p = editors.post; if (!p || p.saving) return
    aiTarget = p.item.id; campaignOptions($('ai-campaign'), { current: $('post-campaign').value }); $('ai-platform').value = $('post-platform').value
    aiDraftContext = null; $('ai-output-wrap').hidden = true; $('ai-output').value = ''; $('ai-use').disabled = true; feedback('ai'); showDialog('ai-dialog')
  }
  async function generateAI(event) {
    event.preventDefault(); if (aiController || !state.ready || !$('ai-form').reportValidity()) return
    if (!live(state.epoch, state.token)) { resetMemory(); bootstrap(); return }
    const c = state.campaigns.find(x => x.id === $('ai-campaign').value), platform = $('ai-platform').value
    if (!c) { feedback('ai', 'Pilih kempen yang sudah disimpan.', 'error'); return }
    const prompt = 'Bantu siapkan satu draf kandungan pemasaran dalam Bahasa Melayu untuk ' + C.PLATFORM[platform] + '. Gunakan hanya fakta kempen yang diberikan. Jangan reka harga, diskaun, testimoni, hasil terjamin atau stok. Jika fakta tidak cukup, nyatakan maklumat yang diperlukan. Berikan teks kandungan yang boleh disemak dan digunakan, tanpa menerbitkan apa-apa. ' + (platform === 'threads' ? 'Pastikan teks pendek, maksimum 450 aksara.' : platform === 'instagram' ? 'Kapsyen maksimum 2000 aksara.' : '') + '\n\n' + C.brief(c) + '\n\nArahan tambahan: ' + ($('ai-instruction').value.trim() || 'Nada jelas, mesra dan bersahaja. Satu ajakan tindakan.')
    if (aiSignature !== prompt || !aiKey) { aiSignature = prompt; aiKey = C.id() }
    const controller = new AbortController(); aiController = controller; const epoch = state.epoch, token = state.token
    $('ai-generate').disabled = true; $('ai-use').disabled = true
    $('ai-form').querySelectorAll('input,select,textarea').forEach(n => { n.disabled = true }); feedback('ai', 'Copilot sedang menyediakan draf berdasarkan kempen anda…' + (aiDraftContext ? ' Draf sebelumnya kekal dipaparkan.' : ''))
    try {
      const data = await request('/api/marketing/draf', { ai: true, method: 'POST', body: { giliran: C.promptTurns(prompt) }, signal: controller.signal, key: aiKey })
      if (epoch !== state.epoch || aiController !== controller || controller.signal.aborted) return
      if (data.ok !== true || data.bukan_model !== false || typeof data.jawapan !== 'string' || !data.jawapan.trim() || !['openai', 'gemini', 'tersimpan'].includes(data.sumber) || (data.sumber === 'tersimpan' && data.main_semula !== true)) throw Error('Draf AI belum dapat disahkan. Anda boleh terus menulis kandungan sendiri.')
      const output = [data.jawapan, ...(Array.isArray(data.langkah) ? data.langkah.filter(x => typeof x === 'string') : [])].join('\n\n')
      $('ai-output').value = output.slice(0, 5000); $('ai-output-wrap').hidden = false; aiDraftContext = { campaignId: c.id, platform }
      aiSignature = ''; aiKey = ''
      feedback('ai', output.length > 5000 ? 'Draf diterima. Paparan dihadkan kepada 5,000 aksara; ringkaskan dan semak sebelum digunakan.' : 'Draf AI siap. Semak dan sunting sebelum menggunakannya.', 'success')
    } catch (error) {
      if (epoch !== state.epoch || aiController !== controller || controller.signal.aborted) return
      if (!live(epoch, token)) { resetMemory(); bootstrap(); return }
      const reason = error.data?.ralat
      let text = error.message
      if (reason === 'menunggu') text = 'Draf masih diproses. Tunggu sebentar, kemudian tekan Jana draf untuk menyemak permintaan yang sama.'
      else if (reason === 'tidak_pasti') text = 'Hasil permintaan belum dapat disahkan. Tunggu sebentar, kemudian tekan Jana draf untuk menyemak permintaan yang sama.'
      else if (reason === 'provider_failed' && error.data.retryable === true) {
        aiSignature = ''; aiKey = ''
        text = 'Penjanaan draf tadi tidak berjaya. Tekan Jana draf untuk membuat percubaan baharu.'
      }
      feedback('ai', text + (aiDraftContext ? ' Draf sebelumnya masih tersedia untuk ' + C.PLATFORM[aiDraftContext.platform] + '.' : ''), 'error')
      if (reason === 'profil') { const link = el('a', 'Lengkapkan profil dalam Copilot', 'text-link'); link.href = '../penasihat/#profil'; $('ai-feedback').append(document.createElement('br'), link) }
    } finally { if (epoch === state.epoch && aiController === controller) { aiController = null; $('ai-generate').disabled = false; $('ai-use').disabled = !aiDraftContext || !$('ai-output').value.trim(); $('ai-form').querySelectorAll('input,select,textarea').forEach(n => { n.disabled = false }) } }
  }
  function useAI() {
    if ($('ai-use').disabled || !aiDraftContext || !$('ai-output').value.trim()) return
    if (!live(state.epoch, state.token)) { resetMemory(); bootstrap(); return }
    const c = aiDraftContext.campaignId, platform = aiDraftContext.platform, body = $('ai-output').value.trim(); $('ai-dialog').close()
    if (!editors.post || editors.post.item.id !== aiTarget) openPost(null, { campaign_id: c, platform, body })
    else { $('post-campaign').value = c; $('post-platform').value = platform; $('post-body').value = body }
    dirty('post'); renderPostEditor(); feedback('post', 'Draf AI dimasukkan. Semak kandungan dan tekan Simpan apabila sedia.')
  }
  document.querySelectorAll('[data-new-campaign]').forEach(b => b.addEventListener('click', () => openCampaign()))
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => closeDialog(b.dataset.close)))
  document.querySelectorAll('[data-load-conflict]').forEach(b => b.addEventListener('click', () => loadConflict(b.dataset.loadConflict)))
  document.querySelectorAll('dialog:not(#confirm-dialog)').forEach(d => d.addEventListener('cancel', e => { e.preventDefault(); closeDialog(d.id) }))
  $('confirm-cancel').addEventListener('click', () => finishConfirm(false)); $('confirm-accept').addEventListener('click', () => finishConfirm(true)); $('confirm-dialog').addEventListener('cancel', e => { e.preventDefault(); finishConfirm(false) })
  $('confirm-dialog').addEventListener('close', () => { if (confirmResolve) { const r = confirmResolve; confirmResolve = null; r(false) } })
  for (const kind of Object.keys(plural)) $(kind + '-form').addEventListener('input', event => { if (kind === 'post' && event.target?.id === 'post-file') return; dirty(kind); if (kind === 'post') { postMediaInput(event); renderPostEditor() } if (kind === 'campaign' && editors.campaign?.step === 2) showCampaignStep() })
  $('campaign-form').addEventListener('submit', saveCampaign); $('campaign-prev').addEventListener('click', () => { if (editors.campaign.step > 0) { editors.campaign.step--; showCampaignStep() } })
  $('post-form').addEventListener('submit', e => { e.preventDefault(); save('post', readPost) }); $('source-form').addEventListener('submit', e => { e.preventDefault(); save('source', readSource) }); $('result-form').addEventListener('submit', e => { e.preventDefault(); save('result', readResult) })
  $('new-post').addEventListener('click', () => openPost()); $('new-source').addEventListener('click', () => openSource()); $('new-result').addEventListener('click', () => openResult())
  $('campaign-search').addEventListener('input', renderCampaigns); $('campaign-status').addEventListener('change', renderCampaigns)
  $('post-campaign-filter').addEventListener('change', renderPosts); $('source-campaign-filter').addEventListener('change', renderSources); $('result-campaign-filter').addEventListener('change', renderResults)
  $('show-calendar').addEventListener('click', () => { $('calendar-panel').hidden = false; $('post-list').hidden = true; $('show-calendar').setAttribute('aria-pressed', 'true'); $('show-post-list').setAttribute('aria-pressed', 'false'); renderCalendar() })
  $('show-post-list').addEventListener('click', () => { $('calendar-panel').hidden = true; $('post-list').hidden = false; $('show-calendar').setAttribute('aria-pressed', 'false'); $('show-post-list').setAttribute('aria-pressed', 'true') })
  function moveMonth(delta) { const next = new Date(Date.UTC(calendar.year, calendar.month + delta, 1)); calendar.year = next.getUTCFullYear(); calendar.month = next.getUTCMonth(); renderCalendar() }
  $('calendar-prev').addEventListener('click', () => moveMonth(-1)); $('calendar-next').addEventListener('click', () => moveMonth(1)); $('calendar-today').addEventListener('click', () => { const date = C.malaysiaDate(); calendar.year = Number(date.slice(0, 4)); calendar.month = Number(date.slice(5, 7)) - 1; renderCalendar() })
  $('source-type').addEventListener('change', () => { $('source-url').required = $('source-type').value === 'reference' })
  $('schedule-post').addEventListener('click', schedulePost); $('cancel-post-schedule').addEventListener('click', cancelPost)
  $('post-file-choose').addEventListener('click', () => { if (!$('post-file').disabled) $('post-file').click() })
  $('post-file').addEventListener('change', event => { const files = [...(event.target?.files || $('post-file').files || [])]; $('post-file').value = ''; return choosePostFiles(files) })
  $('post-platform').addEventListener('change', () => checkConnection($('post-platform').value))
  $('post-media-cancel').addEventListener('click', cancelMediaUpload); $('post-media-retry').addEventListener('click', retryMediaUpload); $('post-media-remove').addEventListener('click', removePostMedia)
  $('post-dialog').addEventListener('close', () => { if (!$('post-dialog').open) disposePostMedia(editors.post) })
  const mediaDrop = $('post-media-drop')
  mediaDrop.addEventListener('dragover', event => { event.preventDefault(); if (!$('post-file').disabled) mediaDrop.classList.add('dragging') })
  mediaDrop.addEventListener('dragleave', () => mediaDrop.classList.remove('dragging'))
  mediaDrop.addEventListener('drop', event => { event.preventDefault(); mediaDrop.classList.remove('dragging'); if (!$('post-file').disabled) return choosePostFiles([...(event.dataTransfer?.files || [])]) })
  $('copy-post').addEventListener('click', () => copy($('post-body').value, 'post-feedback', $('post-body')))
  $('post-ai').addEventListener('click', openAI); $('ai-form').addEventListener('submit', generateAI); $('ai-use').addEventListener('click', useAI)
  $('export-results').addEventListener('click', () => { const rows = selectedResults(); if (rows.length) download('beshare-hasil-' + C.malaysiaDate() + '.csv', C.csv(rows, state.campaigns), 'text/csv;charset=utf-8') })
  $('refresh-workspace').addEventListener('click', bootstrap); $('gate-retry').addEventListener('click', bootstrap)
  $('marketing-logout').addEventListener('click', signOutMarketing)
  window.addEventListener('hashchange', navigate)
  window.addEventListener('storage', e => { if (e.key === TOKEN_KEY || e.key === null) { resetMemory(); state.load++; bootstrap() } })
  window.addEventListener('beforeunload', e => { if (state.ready && Object.values(editors).some(x => x.dirty || x.saving)) { e.preventDefault(); e.returnValue = '' } })
  window.addEventListener('pageshow', e => { if (e.persisted && session()?.token !== state.token) { resetMemory(); bootstrap() } })
  navigate(); bootstrap()
})()
