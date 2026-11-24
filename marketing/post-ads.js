'use strict'
window.BeSharePostAds = function ({ state, request, connection, live, API, draftAd }) {
  const $ = id => document.getElementById('boost-' + id)
  let session = null
  function updateDestination() {
    const channel = $('channel').value
    const link = channel === 'link'
    $('link-field').hidden = !link
    $('url').disabled = !link
    $('url').required = link
    const hints = {
      whatsapp: 'Pelanggan boleh menghantar mesej ke nombor WhatsApp Business yang dipautkan pada Page.',
      messenger: 'Pelanggan boleh menghantar mesej ke peti masuk Messenger Page ini.',
      link: 'Iklan ini dioptimumkan untuk klik ke pautan pilihan anda.'
    }
    $('channel-hint').textContent = hints[channel] || ''
    $('channel-hint').hidden = !channel
    const primary = channel === 'link' ? 'Klik pautan' : 'Perbualan bermula'
    $('goal').options[0].textContent = channel ? primary : 'Mengikut destinasi'
    $('goal-hint').textContent = channel && $('goal').value !== 'primary'
      ? 'Anda mengukur hasil ini. Penghantaran iklan masih dioptimumkan untuk ' + primary.toLowerCase() + '.'
      : 'Masukkan sasaran untuk melihat simulasi dalam pratonton.'
  }
  function lockSettings(locked) {
    for (const id of ['channel', 'budget', 'country', 'days', 'goal', 'target', 'preview']) $(id).disabled = locked
    $('url').disabled = locked || $('channel').value !== 'link'
  }
  function reset() {
    session = null
    $('settings').reset()
    updateDestination()
    lockSettings(false)
    $('approval').reset()
    $('simulation').hidden = true
    $('settings').hidden = false
    $('review').hidden = true
    $('manage').hidden = true
    $('status').textContent = ''
    for (const id of ['page', 'copy', 'summary', 'destination']) $(id).textContent = ''
    $('picture').removeAttribute('src')
    $('picture').hidden = true
    $('original').removeAttribute('href')
    $('preview').disabled = false
    $('launch').disabled = false
    $('edit').disabled = false
    $('rewrite').disabled = false
    $('scenario').disabled = false
  }
  function open(post) {
    reset()
    const grant = connection('facebook_ads')
    const account = grant?.connected && grant.accounts?.[0]
    session = { account: account?.account_id, post: post.id, plan: post.campaign_id, epoch: state.epoch, token: state.token }
    if (!account) {
      $('settings').hidden = true
      $('status').textContent = 'Sambungkan akaun iklan Meta untuk menggunakan hantaran ini.'
      $('manage').href = '#facebook-ads'
      $('manage').hidden = false
    }
    $('dialog').showModal()
  }
  function current(s) {
    return session === s && $('dialog').open && live(s.epoch, s.token)
  }
  function submitted(id) {
    $('review').hidden = true
    $('settings').hidden = true
    $('status').textContent = 'Kempen telah dihantar kepada Meta. ID ' + id + '. Semak status semakan dan penyampaian di Facebook Ads.'
    $('manage').href = '#facebook-ads'
    $('manage').hidden = false
  }
  async function preview(event) {
    event.preventDefault()
    const s = session
    if (!s?.account || $('preview').disabled) return
    const destination = $('channel').value
    $('status').textContent = 'Menyemak hantaran dan destinasi iklan dengan Meta…'
    const body = { account_id: s.account, post_id: s.post, days: Number($('days').value),
      budget: $('budget').value, country: $('country').value, destination }
    if ($('target').value) {
      body.goal_metric = $('goal').value === 'primary' ? (destination === 'link' ? 'clicks' : 'messages') : $('goal').value
      body.goal_count = Number($('target').value)
    }
    if (destination === 'link') body.destination_url = $('url').value.trim()
    lockSettings(true)
    try {
      const result = await request(API + '/facebook-ads/post-preview', { method: 'POST', body, timeoutMs: 80000 })
      if (!current(s)) return
      if (result.already_submitted) { submitted(result.campaign_id); return }
      const p = result.preview
      if (p.destination !== destination || (destination === 'link' && p.destination_url !== body.destination_url)) {
        throw new Error('Destinasi dalam pratonton berbeza daripada pilihan anda. Semak semula tetapan.')
      }
      if (body.goal_count && (p.simulation?.target !== body.goal_count || p.simulation?.metric !== body.goal_metric)) {
        throw new Error('Simulasi belum dapat disahkan. Muat semula ruang kerja dan cuba lagi.')
      }
      s.review = result.review_id
      $('rewrite').disabled = false
      $('scenario').disabled = false
      s.simulation = p.simulation
      s.budget = p.budget_minor / 100
      s.currency = p.currency
      $('simulation').hidden = !p.simulation
      if (p.simulation) {
        const sim = p.simulation, unit = window.BeShareAdSimulation.units[sim.metric]
        const cap = p.budget_minor / 100 / sim.target
        const cost = new Intl.NumberFormat('ms-MY', { style: 'currency', currency: p.currency, currencyDisplay: 'code',
          minimumFractionDigits: 2, maximumFractionDigits: cap < 0.01 ? 6 : 2 }).format(cap)
        $('threshold').textContent = 'Untuk mencapai ' + sim.target.toLocaleString('ms-MY') + ' ' + unit + ', purata kos perlu sekitar ' + cost + ' atau kurang bagi setiap hasil.'
        $('estimate').textContent = window.BeShareAdSimulation.range(sim)
        $('audience').hidden = !sim.audience
        $('audience').textContent = sim.audience ? 'Saiz audiens bulanan yang memenuhi tetapan negara dan penempatan Facebook menurut Meta · ' +
          sim.audience.map(n => n.toLocaleString('ms-MY')).join('–') + '. Jumlah yang melihat iklan bergantung pada penyampaian sebenar.' : ''
        $('rate').value = ''
        $('rate').closest('details').open = false
        $('whatif').textContent = 'Masukkan kos dalam ' + p.currency + ' untuk mengira senario anda sendiri.'
        $('advice').textContent = sim.advice
        $('rewrite').hidden = !draftAd || !state.campaigns.some(c => c.id === s.plan)
      }
      $('page').textContent = p.page_name
      $('copy').textContent = p.text
      $('original').href = p.post_url
      $('picture').hidden = true
      $('picture').removeAttribute('src')
      if (p.picture && /^https:\/\//i.test(p.picture)) {
        $('picture').referrerPolicy = 'no-referrer'
        $('picture').src = p.picture
        $('picture').hidden = false
      }
      const labels = {
        whatsapp: 'WhatsApp Business · ' + p.phone,
        messenger: 'Messenger · ' + p.page_name,
        link: 'Pautan · ' + p.destination_url
      }
      $('destination').textContent = labels[destination]
      const name = new Intl.DisplayNames(['ms'], { type: 'region' }).of(p.country)
      const total = new Intl.NumberFormat('ms-MY', { style: 'currency', currency: p.currency, currencyDisplay: 'code' }).format(p.budget_minor / 100)
      $('summary').textContent = 'Akaun ' + p.account_name + ' (' + p.account_id + ') · Facebook · ' + name + ' · ' + p.days + ' hari · Jumlah bajet ' + total
      $('ordinary').checked = false
      $('settings').hidden = true
      $('review').hidden = false
      $('status').textContent = 'Semak hantaran, destinasi dan jumlah belanja sebelum meluluskan.'
    } catch (err) {
      if (current(s)) $('status').textContent = err.message
    } finally { if (current(s)) lockSettings(false) }
  }
  async function launch(event) {
    event.preventDefault()
    const s = session
    if (!s?.review || $('launch').disabled || !$('ordinary').checked) return
    $('launch').disabled = true
    $('edit').disabled = true
    $('rewrite').disabled = true
    $('scenario').disabled = true
    $('status').textContent = 'Menghantar iklan yang diluluskan kepada Meta…'
    try {
      const body = { account_id: s.account, post_id: s.post, review_id: s.review, approve_spend: true, ordinary_ad: true }
      const result = await request(API + '/facebook-ads/post-launch', { method: 'POST', body, timeoutMs: 150000 })
      if (current(s)) submitted(result.campaign_id)
    } catch (err) {
      if (!current(s)) return
      s.review = null
      $('status').textContent = err.status === 408 ? 'Keputusan belum diterima. Semak kempen dalam Meta Ads Manager.' : err.message
      $('edit').disabled = false
    }
  }
  $('manage').addEventListener('click', () => $('dialog').close())
  $('dialog').addEventListener('close', () => { if (!$('dialog').open) reset() })
  $('edit').addEventListener('click', () => {
    session.review = null
    $('review').hidden = true
    $('launch').disabled = false
    $('settings').hidden = false
    $('status').textContent = ''
    $('channel').focus()
  })
  $('scenario').addEventListener('click', () => { $('edit').click(); $('budget').focus(); $('budget').select() })
  $('rewrite').addEventListener('click', () => {
    const s = session
    if (!s?.review || !current(s)) return
    const brief = s.simulation.advice + ' Matlamat: ' + window.BeShareAdSimulation.units[s.simulation.metric] + '. Hantaran asal: ' + $('copy').textContent
    $('dialog').close()
    draftAd(s.plan, brief.slice(0, 1000))
  })
  $('rate').addEventListener('input', () => {
    const rate = Number($('rate').value), s = session
    $('whatif').textContent = s?.simulation && rate > 0 && $('rate').validity.valid
      ? 'Jika kos sebenar ' + s.currency + ' ' + rate.toFixed(2) + ', bajet ini bersamaan kira-kira ' +
        Math.floor(s.budget / rate + 1e-9).toLocaleString('ms-MY') + ' ' + window.BeShareAdSimulation.units[s.simulation.metric] + '. Ini andaian anda.'
      : 'Masukkan kos positif dengan sehingga dua tempat perpuluhan.'
  })
  $('goal').addEventListener('change', updateDestination)
  $('channel').addEventListener('change', updateDestination)
  $('settings').addEventListener('submit', preview)
  $('approval').addEventListener('submit', launch)
  return { open, reset }
}
