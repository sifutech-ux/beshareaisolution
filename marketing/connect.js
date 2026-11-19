(async function () {
  'use strict'
  const returned = window.BeshareOAuthReturn || {}
  delete window.BeshareOAuthReturn
  const status = document.getElementById('status')
  const title = document.getElementById('title')
  const intro = document.getElementById('intro')
  const accounts = document.getElementById('accounts')
  const login = document.getElementById('login')
  const services = typeof BeshareAsal !== 'undefined' ? BeshareAsal.asalPerkhidmatan(location.origin) : null
  const sessionKey = 'beshare-akaun'
  function readSession() {
    try {
      const raw = localStorage.getItem(sessionKey)
      const value = JSON.parse(raw || 'null')
      if (!value || Array.isArray(value) || typeof value.token !== 'string' || !value.token.trim()) return null
      return { raw, token: value.token }
    } catch { return null }
  }
  const initialSession = readSession()
  const initialBearer = initialSession && initialSession.token
  const controllers = new Set()
  const candidates = new Set()
  let candidatePlatform = '', selecting = false, stale = false

  function message(text, error = false) {
    status.textContent = text
    status.dataset.error = String(error)
  }
  function clearReturn() {
    returned.code = ''
    returned.state = ''
    returned.failure = ''
    returned.denied = false
  }
  function clearCandidates() {
    for (const button of accounts.querySelectorAll('button')) button.disabled = true
    accounts.replaceChildren()
    candidates.clear()
    candidatePlatform = ''
  }
  function currentSession() {
    const current = readSession()
    return !stale && Boolean(initialBearer && current && current.token === initialBearer && current.raw === initialSession.raw)
  }
  function stopForSessionChange() {
    if (stale) return
    stale = true
    for (const controller of controllers) controller.abort()
    clearReturn()
    clearCandidates()
    title.textContent = 'Sesi BeShare telah berubah.'
    intro.textContent = 'Sambungan ini dihentikan kerana sesi akaun berubah atau telah tamat.'
    message('Masuk semula dengan akaun BeShare yang memulakan sambungan ini, kemudian mulakan sambungan baharu daripada Marketing.', true)
    login.hidden = false
  }
  function requireSession() {
    if (currentSession()) return
    stopForSessionChange()
    throw new Error('Sesi BeShare telah berubah.')
  }
  window.addEventListener('storage', event => {
    if (event.key !== sessionKey && event.key !== null) return
    if (event.key === null || event.newValue !== initialSession?.raw || !currentSession()) stopForSessionChange()
  })
  async function call(path, payload) {
    requireSession()
    const controller = new AbortController()
    controllers.add(controller)
    const timer = setTimeout(() => controller.abort(), 90000)
    try {
      requireSession()
      const response = await fetch(services.akaun + '/api/akaun/copilot/marketing/connections' + path, {
        method: 'POST', credentials: 'omit', cache: 'no-store', referrerPolicy: 'no-referrer',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + initialBearer },
        body: JSON.stringify(payload), signal: controller.signal,
      })
      requireSession()
      const data = await response.json().catch(() => ({}))
      requireSession()
      if (!response.ok || data.ok === false) {
        if (response.status === 401) login.hidden = false
        throw new Error(typeof data.error === 'string' ? data.error : 'Sambungan tidak dapat diselesaikan.')
      }
      return data
    } finally { clearTimeout(timer); controllers.delete(controller) }
  }
  function done(data) {
    requireSession()
    clearCandidates()
    title.textContent = 'Akaun berjaya disambungkan.'
    intro.textContent = 'Anda boleh kembali ke Marketing untuk menyediakan dan menjadualkan siaran.'
    const name = data.account && typeof data.account.name === 'string' ? data.account.name : 'Akaun platform'
    message(name + ' kini disambungkan kepada akaun BeShare anda.')
  }
  async function selectAccount(candidateId) {
    if (!currentSession()) { stopForSessionChange(); return }
    if (selecting || !candidates.has(candidateId) || !candidatePlatform) return
    selecting = true
    for (const button of accounts.querySelectorAll('button')) button.disabled = true
    message('Menyimpan pilihan akaun…')
    try {
      done(await call('/' + candidatePlatform + '/select', { candidate_id: candidateId }))
    } catch (error) {
      if (!currentSession()) { stopForSessionChange(); return }
      message(error.name === 'AbortError' ? 'Sambungan mengambil masa. Kembali ke Marketing untuk menyemak status.' : error.message, true)
      for (const button of accounts.querySelectorAll('button')) button.disabled = false
    } finally { selecting = false }
  }
  if (!services || !initialBearer) {
    clearReturn()
    title.textContent = 'Sesi BeShare diperlukan.'
    message('Masuk akaun BeShare, kemudian mulakan sambungan semula daripada Marketing.', true)
    login.hidden = false
    return
  }
  const returnedFailure = returned.failure || (returned.denied ? 'cancelled' : '')
  if (returnedFailure) {
    clearReturn()
    const explanations = {
      cancelled: ['Sambungan dibatalkan.', 'Kebenaran tidak diberikan. Kembali ke Marketing dan sambungkan semula apabila bersedia.'],
      tester_required: ['Akses penguji diperlukan.', 'Platform belum membenarkan akaun ini menyambung. Semak dan terima jemputan penguji pada platform, kemudian mulakan sambungan baharu daripada Marketing.'],
      temporary: ['Platform belum dapat menyambung.', 'Platform sedang mengalami gangguan sementara. Kembali ke Marketing dan cuba sambungkan semula sebentar lagi.'],
      provider: ['Sambungan belum berjaya.', 'Platform tidak dapat menyelesaikan sambungan ini. Kembali ke Marketing untuk menyemak panduan saluran sebelum mencuba semula.'],
    }
    const [heading, explanation] = Object.hasOwn(explanations, returnedFailure) ? explanations[returnedFailure] : explanations.provider
    title.textContent = heading
    intro.textContent = 'Akaun belum disambungkan. Kandungan anda dalam BeShare kekal tersedia.'
    message(explanation, true)
    return
  }
  if (typeof returned.code !== 'string' || returned.code.length > 8000 || !returned.code ||
      typeof returned.state !== 'string' || !/^[A-Za-z0-9_-]{40,100}$/.test(returned.state)) {
    clearReturn()
    message('Sesi sambungan tidak lengkap. Mulakan sambungan daripada halaman Marketing.', true)
    return
  }
  try {
    const result = await call('/complete', { code: returned.code, state: returned.state })
    clearReturn()
    requireSession()
    if (!result.selection_required) { done(result); return }
    if (!['facebook', 'instagram', 'threads'].includes(result.platform) || !Array.isArray(result.candidates) || !result.candidates.length) {
      throw new Error('Tiada akaun yang layak ditemui. Semak kebenaran pada platform dan cuba lagi.')
    }
    candidatePlatform = result.platform
    title.textContent = 'Pilih akaun untuk siaran.'
    message('Pilih satu akaun. Kandungan akan menggunakan akaun yang anda pilih apabila dijadualkan.')
    for (const candidate of result.candidates) {
      if (!candidate || typeof candidate.id !== 'string' || !/^[a-f0-9]{32}$/.test(candidate.id)) continue
      const candidateId = candidate.id
      candidates.add(candidateId)
      const button = document.createElement('button')
      button.type = 'button'
      button.className = 'account'
      const label = document.createElement('span')
      const name = document.createElement('strong')
      name.textContent = typeof candidate.name === 'string' ? candidate.name : 'Akaun platform'
      const detail = document.createElement('small')
      detail.textContent = result.platform === 'instagram' ? 'Instagram Professional' : 'Facebook Page'
      const arrow = document.createElement('span')
      arrow.className = 'arrow'
      arrow.textContent = '→'
      arrow.setAttribute('aria-hidden', 'true')
      label.append(name, detail)
      button.append(label, arrow)
      button.addEventListener('click', () => selectAccount(candidateId))
      accounts.append(button)
    }
    if (!candidates.size) throw new Error('Tiada akaun yang layak ditemui. Mulakan sambungan semula daripada Marketing.')
  } catch (error) {
    if (!currentSession()) { stopForSessionChange(); return }
    message(error.name === 'AbortError' ? 'Sambungan mengambil masa. Semak status di Marketing sebelum mencuba semula.' : error.message, true)
  } finally {
    clearReturn()
  }
})()
