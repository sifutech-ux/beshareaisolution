/* Additive Group Manager for BeShare AI Marketing. No tokens or group data in browser storage. */
(() => {
  'use strict'
  const $ = id => document.getElementById(id)
  const BASE = '/api/akaun/copilot/marketing/groups'
  const sessions = 'beshare-akaun'
  const connected = window.BeshareAsal?.asalPerkhidmatan(location.origin)
  let groups = [], jobs = [], loading = false, active = false, generation = 0, telegramConfigured = false, publishingEnabled = false

  function element(tag, value, cls) {
    const node = document.createElement(tag)
    if (value != null) node.textContent = value
    if (cls) node.className = cls
    return node
  }
  function button(label, action, cls = 'button secondary') {
    const node = element('button', label, cls)
    node.type = 'button'
    node.addEventListener('click', action)
    return node
  }
  function session() {
    try {
      const value = JSON.parse(localStorage.getItem(sessions) || 'null')
      return typeof value?.token === 'string' && value.token ? value.token : ''
    } catch { return '' }
  }
  async function api(path = '', method = 'GET', data) {
    const token = session()
    if (!connected?.akaun || !token) throw Error('Log masuk ke akaun BeShare terlebih dahulu.')
    const abort = new AbortController(), timer = setTimeout(() => abort.abort(), 18000)
    try {
      const response = await fetch(connected.akaun + BASE + path, {
        method, headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
        body: data == null ? undefined : JSON.stringify(data),
        signal: abort.signal, credentials: 'omit', cache: 'no-store'
      })
      const result = await response.json().catch(() => ({}))
      if (token !== session()) throw Error('Sesi akaun berubah. Sila muat semula.')
      if (response.status === 404 && !path) throw Error('Backend Kumpulan & Saluran belum diterbitkan pada pelayan BeShare. Paparan sudah tersedia, tetapi fungsi simpan belum diaktifkan.')
      if (!response.ok || result.ok === false) throw Error(result.error || 'Operasi tidak berjaya.')
      return result
    } finally { clearTimeout(timer) }
  }
  function uuid() {
    const b = new Uint8Array(16)
    crypto.getRandomValues(b)
    return [...b].map(v => v.toString(16).padStart(2, '0')).join('')
  }
  function feedback(id, message, fail = false) {
    $(id).textContent = message
    $(id).dataset.error = String(fail)
  }
  function pick() {
    const option = $('group-platform').value === 'telegram'
    $('group-chat-wrap').hidden = !option
    $('group-chat').required = false
    if (!option) $('group-chat').value = ''
  }
  async function refresh() {
    if (loading || !session()) {
      if (!session()) feedback('group-status', 'Log masuk ke BeShare untuk mengurus kumpulan.')
      return
    }
    loading = true
    const epoch = ++generation
    feedback('group-status', 'Memuat kumpulan…')
    try {
      const result = await api()
      if (epoch !== generation || !active) return
      groups = Array.isArray(result.groups) ? result.groups : []
      jobs = Array.isArray(result.jobs) ? result.jobs : []
      telegramConfigured = result.telegram_configured === true
      publishingEnabled = result.auto_publish_enabled === true
      const readiness = !telegramConfigured ? 'Bot Telegram belum dikonfigurasi pada pelayan.' :
                        !publishingEnabled ? 'Penerbitan Telegram masih dimatikan; pendaftaran group boleh disediakan.' :
                        'Penerbitan Telegram tersedia untuk destinasi disahkan.'
      feedback('group-status', (groups.length ? groups.length + ' destinasi tersimpan. ' : 'Belum ada kumpulan. ') + readiness)
      render()
    } catch (error) { if (epoch === generation) feedback('group-status', error.message, true) }
    finally { loading = false }
  }
  function render() {
    const target = $('group-list')
    target.replaceChildren()
    for (const group of groups) {
      const card = element('article', null, 'group-card')
      const head = element('div', null, 'group-card-head')
      const title = element('strong', group.name), meta = element('span',
        (group.platform === 'telegram' ? 'Telegram' : 'WhatsApp') + ' · ' + (group.ownership === 'partner' ? 'Group pihak ketiga' : 'Group sendiri'),
        'group-meta')
      head.append(title, meta)
      card.append(head, element('p',
        group.platform === 'whatsapp' ? 'Manual sahaja · Anda memilih penerima dalam WhatsApp.' :
        group.status === 'verified' ? 'Disahkan pentadbir · Auto Publish tersedia selepas worker diaktifkan.' :
        'Belum disahkan · Pentadbir perlu menghubungkan bot.', 'group-note'))
      const actions = element('div', null, 'group-actions')
      if (group.platform === 'telegram' && group.status === 'pending') {
        actions.append(button(group.chat_ref ? 'Sahkan pentadbir Telegram' : 'Pilih group di Telegram', async () => {
          try {
            const result = await api('/' + group.id + '/verify', 'POST', {})
            const url = new URL(result.verification_url)
            if (url.protocol !== 'https:' || url.hostname !== 't.me') throw Error('Pautan pengesahan tidak sah.')
            feedback('group-status', result.verification_mode === 'group_picker'
              ? 'Dalam Telegram pilih group yang anda tadbir, berikan kebenaran bot sebagai admin dan sahkan pautan. Kemudian muat semula senarai.'
              : 'Buka Telegram dan tekan Start sebagai pentadbir group/channel berkenaan. Kemudian muat semula senarai.')
            const link = element('a', 'Buka pautan pengesahan Telegram', 'text-link')
            link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer'
            $('group-status').append(document.createTextNode(' '), link)
          } catch (e) { feedback('group-status', e.message, true) }
        }))
      }
      if (group.platform === 'whatsapp') {
        actions.append(button('Sediakan perkongsian', () => {
          const message = $('group-share-body').value.trim()
          if (!message) {
            feedback('group-share-feedback', 'Tulis teks promosi WhatsApp dahulu.', true)
            $('group-share-body').focus()
            return
          }
          const url = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(message)
          window.open(url, '_blank', 'noopener,noreferrer')
          feedback('group-share-feedback', 'Pilih group yang membenarkan promosi dan tekan Send dalam WhatsApp. BeShare tidak menghantar mesej secara automatik.')
        }))
      }
      actions.append(button('Nyahaktif', async () => {
        if (!window.confirm('Nyahaktifkan ' + group.name + '? Semua penerbitan Telegram yang masih dalam giliran akan dibatalkan.')) return
        try { await api('/' + group.id, 'DELETE'); await refresh() }
        catch (e) { feedback('group-status', e.message, true) }
      }))
      card.append(actions)
      target.append(card)
    }
    if (!groups.length) target.append(element('p', 'Destinasi yang didaftarkan akan muncul di sini.', 'group-note'))
    const select = $('group-job-destination')
    const old = select.value
    select.replaceChildren(new Option('Pilih Telegram disahkan', ''))
    groups.filter(g => g.platform === 'telegram' && g.status === 'verified').forEach(g => select.add(new Option(g.name, g.id)))
    if ([...select.options].some(o => o.value === old)) select.value = old
    $('group-jobs').replaceChildren(...jobs.map(job => {
      const article = element('article', null, 'group-card')
      article.append(element('strong', job.destination_name), element('p', (job.status === 'queued' ? 'Dijadualkan' : job.status === 'sent' ? 'Dihantar' : job.status === 'uncertain' ? 'Perlu disemak' : job.status) + ' · ' + new Intl.DateTimeFormat('ms-MY', { timeZone: 'Asia/Kuala_Lumpur', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(job.scheduled_at * 1000)), 'group-note'))
      if (job.last_error) article.append(element('p', job.last_error, 'group-error'))
      return article
    }))
    if (!jobs.length) $('group-jobs').append(element('p', 'Belum ada penghantaran Telegram.', 'group-note'))
    const submit = $('group-job-form').querySelector('button[type="submit"]')
    submit.disabled = !publishingEnabled
    submit.title = publishingEnabled ? '' : 'Auto Publish Telegram belum diaktifkan di pelayan.'
  }
  async function addGroup(event) {
    event.preventDefault()
    const button = event.submitter
    if (button) button.disabled = true
    try {
      const data = { id: uuid(), platform: $('group-platform').value,
        ownership: $('group-ownership').value, name: $('group-name').value.trim(),
        chat_ref: $('group-platform').value === 'telegram' ? $('group-chat').value.trim() : '',
        approved: $('group-approved').checked }
      await api('', 'POST', data)
      $('group-add-form').reset(); pick()
      feedback('group-feedback', 'Destinasi disimpan. Telegram memerlukan pengesahan pentadbir sebelum auto-post.')
      await refresh()
    } catch (e) { feedback('group-feedback', e.message, true) }
    finally { if (button) button.disabled = false }
  }
  async function schedule(event) {
    event.preventDefault()
    const button = event.submitter
    if (button) button.disabled = true
    try {
      const local = $('group-job-at').value
      if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) throw Error('Pilih tarikh dan masa.')
      const at = new Date(local + ':00+08:00')
      if (!Number.isFinite(at.getTime())) throw Error('Tarikh tidak sah.')
      await api('/jobs', 'POST', {
        id: uuid(), destination_id: $('group-job-destination').value,
        body: $('group-job-body').value.trim(),
        scheduled_at: Math.floor(at.getTime() / 1000), approved: $('group-job-approved').checked
      })
      $('group-job-form').reset()
      feedback('group-job-feedback', 'Kempen masuk ke giliran. Penghantaran sebenar bergantung pada worker Telegram yang telah disahkan dan diaktifkan.')
      await refresh()
    } catch (e) { feedback('group-job-feedback', e.message, true) }
    finally { if (button) button.disabled = false }
  }
  function navigation() {
    active = location.hash === '#kumpulan'
    if (active && session()) refresh()
    if (!active) { generation++; groups = []; jobs = []; telegramConfigured = false; publishingEnabled = false; $('group-list').replaceChildren(); $('group-jobs').replaceChildren() }
  }
  $('group-platform').addEventListener('change', pick)
  $('group-add-form').addEventListener('submit', addGroup)
  $('group-job-form').addEventListener('submit', schedule)
  $('group-refresh').addEventListener('click', refresh)
  window.addEventListener('hashchange', navigation)
  window.addEventListener('storage', e => { if (e.key === sessions || e.key === null) {
    generation++; groups = []; jobs = []; if (active) refresh()
  } })
  pick(); navigation()
})()
