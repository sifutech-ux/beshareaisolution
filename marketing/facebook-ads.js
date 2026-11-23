'use strict'
/* Facebook Ads uses the parent workspace's authenticated request and session lifecycle. */
window.BeShareFacebookAds = function ({ state, request, connection, beginConnect, disconnect, el, live, API }) {
  const $ = id => document.getElementById('ads-' + id)
  const manager = 'https://adsmanager.facebook.com/adsmanager/manage/campaigns'
  const statusLabels = { ACTIVE: 'Aktif', PAUSED: 'Dijeda', ARCHIVED: 'Diarkibkan', DELETED: 'Dipadam', IN_PROCESS: 'Sedang diproses', WITH_ISSUES: 'Perlu semakan' }
  const number = new Intl.NumberFormat('ms-MY')
  let reportVersion = 0, createVersion = 0, accountId = '', after = '', creating = false
  const source = () => connection('facebook_ads')
  const plans = () => state.campaigns.filter(plan => !['completed', 'archived'].includes(plan.status))
  function reset() {
    reportVersion++; createVersion++
    accountId = ''; after = ''; creating = false
    $('rows').replaceChildren(); $('plan').replaceChildren(); $('form').reset()
    $('account').textContent = ''; $('report-status').textContent = ''; $('create-status').textContent = ''
    $('work').hidden = true; $('table').hidden = true; $('more').hidden = true
    $('manager').href = manager
  }
  function show() {
    const grant = source(), account = grant?.connected && grant.accounts?.[0]
    const id = account?.account_id || ''
    if (id !== accountId) reset()
    accountId = id
    const available = state.capabilities.ads?.meta_paused_campaigns === true
    $('account').textContent = accountId ? account.name + ' · ID ' + accountId
      : !available ? 'Modul Facebook Ads belum tersedia pada pelayan akaun ini.'
      : grant?.status === 'expired' || grant?.status === 'reconnect_required' ? 'Akses telah luput atau ditarik balik. Sambungkan semula akaun iklan.'
      : grant?.configured ? 'Sambungkan akaun iklan Meta anda untuk melihat prestasi dan menyediakan kempen.'
      : 'Sambungan Facebook Ads belum dikonfigurasi pada pelayan.'
    $('connect').disabled = !available || !grant?.configured
    $('connect').textContent = accountId ? 'Tukar akaun iklan' : 'Sambungkan akaun iklan'
    $('disconnect').hidden = !grant?.accounts?.length
    $('manager').href = accountId ? manager + '?act=' + encodeURIComponent(accountId) : manager
    $('work').hidden = !available || !accountId
    if ($('work').hidden) return
    const selected = $('plan').value, options = plans()
    $('plan').replaceChildren(new Option(options.length ? 'Pilih pelan kempen' : 'Cipta pelan di bahagian Kempen dahulu', ''))
    for (const plan of options) $('plan').add(new Option(plan.title, plan.id))
    if (options.some(plan => plan.id === selected)) $('plan').value = selected
    $('create').disabled = creating || !options.length
    report()
  }
  function metric(stats, field) {
    const value = stats?.[field]
    if (typeof value !== 'string' || !/^\d+(\.\d+)?$/.test(value)) return '—'
    if (field !== 'spend') return number.format(Number(value))
    if (!/^[A-Z]{3}$/.test(stats.account_currency)) return value
    return new Intl.NumberFormat('ms-MY', { style: 'currency', currency: stats.account_currency, currencyDisplay: 'code' }).format(Number(value))
  }
  async function report(more = false) {
    if (!accountId || (more && !after)) return
    const version = ++reportVersion, epoch = state.epoch, token = state.token
    const current = () => version === reportVersion && live(epoch, token)
    const body = { account_id: accountId, period: $('period').value }
    if (more) body.after = after
    else { $('rows').replaceChildren(); after = ''; $('table').hidden = true }
    $('more').hidden = true; $('refresh').disabled = true
    $('report-status').textContent = 'Memuat laporan Meta…'
    try {
      const data = await request(API + '/facebook-ads/report', { method: 'POST', body })
      if (!current()) return
      for (const item of data.campaigns) {
        const tr = el('tr')
        const values = [item.name, statusLabels[item.status] || item.status || '—',
          metric(item.metrics, 'spend'), metric(item.metrics, 'impressions'), metric(item.metrics, 'clicks')]
        for (const value of values) tr.append(el('td', value))
        $('rows').append(tr)
      }
      after = data.after
      const empty = !$('rows').children.length
      $('table').hidden = empty
      $('report-status').textContent = empty ? 'Tiada kempen dipulangkan untuk akaun iklan ini.' : 'Tanda — bermaksud Meta belum memberikan angka untuk tempoh ini.'
    } catch (error) {
      if (current()) $('report-status').textContent = error.message
    } finally {
      if (current()) { $('refresh').disabled = false; $('more').hidden = !after }
    }
  }
  async function create(event) {
    event.preventDefault()
    const plan = plans().find(p => p.id === $('plan').value)
    if (creating || !accountId || !plan || !$('ordinary').checked) return
    const epoch = state.epoch, token = state.token, version = ++createVersion
    const current = () => version === createVersion && live(epoch, token)
    creating = true; $('create').disabled = true
    $('create-status').textContent = 'Menghantar kempen kepada Meta…'
    try {
      const data = await request(API + '/facebook-ads/campaigns', { method: 'POST', body: {
        account_id: accountId, plan_id: plan.id, version: plan.version, objective: $('objective').value, ordinary_ad: true,
      } })
      if (!current()) return
      $('create-status').textContent = (data.existing ? 'Pelan ini sudah mempunyai kempen Meta' : 'Kempen dijeda berjaya dicipta') + ' · ID ' + data.campaign_id + '. Buka Ads Manager untuk menyemaknya.'
      $('ordinary').checked = false
      report()
    } catch (error) {
      if (current()) $('create-status').textContent = error.status === 408 ? 'Status belum disahkan. Semak Ads Manager atau semak semula menggunakan pelan yang sama.' : error.message
    } finally {
      if (current()) { creating = false; $('create').disabled = !plans().length }
    }
  }
  $('connect').addEventListener('click', () => beginConnect('facebook_ads'))
  $('disconnect').addEventListener('click', () => disconnect('facebook_ads'))
  $('period').addEventListener('change', () => report())
  $('refresh').addEventListener('click', () => report())
  $('more').addEventListener('click', () => report(true))
  $('form').addEventListener('submit', create)
  return { reset, show }
}
