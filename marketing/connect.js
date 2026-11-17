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
  let session
  try { session = JSON.parse(localStorage.getItem('beshare-akaun') || 'null') } catch { session = null }

  function message(text, error = false) {
    status.textContent = text
    status.dataset.error = String(error)
  }
  async function call(path, payload) {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), 90000)
    try {
      const response = await fetch(services.akaun + '/api/akaun/copilot/marketing/connections' + path, {
        method: 'POST', credentials: 'omit', cache: 'no-store', referrerPolicy: 'no-referrer',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + session.token },
        body: JSON.stringify(payload), signal: controller.signal,
      })
      const data = await response.json().catch(() => ({}))
      if (!response.ok) {
        if (response.status === 401) login.hidden = false
        throw new Error(typeof data.error === 'string' ? data.error : 'Sambungan tidak dapat diselesaikan.')
      }
      return data
    } finally { clearTimeout(timer) }
  }
  function done(data) {
    accounts.replaceChildren()
    title.textContent = 'Akaun berjaya disambungkan.'
    intro.textContent = 'Anda boleh kembali ke Marketing untuk menyediakan dan menjadualkan siaran.'
    const name = data.account && typeof data.account.name === 'string' ? data.account.name : 'Akaun platform'
    message(name + ' kini disambungkan kepada akaun BeShare anda.')
  }
  if (!services || !session || typeof session.token !== 'string' || !session.token) {
    title.textContent = 'Sesi BeShare diperlukan.'
    message('Masuk akaun BeShare, kemudian mulakan sambungan semula daripada Marketing.', true)
    login.hidden = false
    return
  }
  if (returned.denied) {
    message('Kebenaran tidak diberikan. Anda boleh kembali dan menyambungkan semula apabila bersedia.', true)
    return
  }
  if (typeof returned.code !== 'string' || returned.code.length > 8000 || !returned.code ||
      typeof returned.state !== 'string' || !/^[A-Za-z0-9_-]{40,100}$/.test(returned.state)) {
    message('Sesi sambungan tidak lengkap. Mulakan sambungan daripada halaman Marketing.', true)
    return
  }
  try {
    const result = await call('/complete', { code: returned.code, state: returned.state })
    returned.code = ''
    returned.state = ''
    if (!result.selection_required) { done(result); return }
    if (!['facebook', 'instagram', 'threads'].includes(result.platform) || !Array.isArray(result.candidates) || !result.candidates.length) {
      throw new Error('Tiada akaun yang layak ditemui. Semak kebenaran pada platform dan cuba lagi.')
    }
    title.textContent = 'Pilih akaun untuk siaran.'
    message('Pilih satu akaun. Kandungan akan menggunakan akaun yang anda pilih apabila dijadualkan.')
    for (const candidate of result.candidates) {
      if (!candidate || typeof candidate.id !== 'string' || !/^[a-f0-9]{32}$/.test(candidate.id)) continue
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
      button.addEventListener('click', async () => {
        for (const item of accounts.querySelectorAll('button')) item.disabled = true
        message('Menyimpan pilihan akaun…')
        try {
          done(await call('/' + result.platform + '/select', { candidate_id: candidate.id }))
        } catch (error) {
          message(error.name === 'AbortError' ? 'Sambungan mengambil masa. Kembali ke Marketing untuk menyemak status.' : error.message, true)
          for (const item of accounts.querySelectorAll('button')) item.disabled = false
        }
      })
      accounts.append(button)
    }
  } catch (error) {
    message(error.name === 'AbortError' ? 'Sambungan mengambil masa. Semak status di Marketing sebelum mencuba semula.' : error.message, true)
  } finally {
    returned.code = ''
    returned.state = ''
  }
})()
