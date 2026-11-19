/* Shared, dependency-free rules for the Marketing workspace. */
(function (root, factory) {
  const api = factory()
  if (typeof module === 'object' && module.exports) module.exports = api
  root.MarketingCore = api
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict'
  const CHANNELS = ['facebook', 'instagram', 'threads', 'google_ads']
  const GOALS = { awareness: 'Kenalkan perniagaan', leads: 'Dapatkan pertanyaan', sales: 'Dapatkan pesanan', repeat: 'Galakkan pembelian semula' }
  const PLATFORM = { facebook: 'Facebook', instagram: 'Instagram', threads: 'Threads', google_ads: 'Google Ads', other: 'Lain-lain' }
  const STATUS = { draft: 'Draf', ready: 'Sedia digunakan', active: 'Aktif', completed: 'Selesai', archived: 'Arkib', planned: 'Dirancang', queued: 'Dalam giliran', publishing: 'Sedang dihantar', published: 'Diterbitkan', failed: 'Gagal dihantar', cancelled: 'Dibatalkan', uncertain: 'Perlu semakan' }
  function id() {
    const bytes = new Uint8Array(16)
    globalThis.crypto.getRandomValues(bytes)
    return Array.from(bytes, x => x.toString(16).padStart(2, '0')).join('')
  }
  function money(value, label = 'Jumlah') {
    const s = String(value ?? '').trim() || '0'
    if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(s)) throw Error(`${label} perlu angka positif, dengan maksimum dua tempat perpuluhan.`)
    const [whole, fraction = ''] = s.split('.')
    return `${Number(whole)}.${fraction.padEnd(2, '0')}`
  }
  function cents(value) { const [a, b] = money(value).split('.'); return Number(a) * 100 + Number(b) }
  function ringgit(value) { return new Intl.NumberFormat('ms-MY', { style: 'currency', currency: 'MYR', minimumFractionDigits: 2 }).format(Number(value || 0)) }
  function integer(value, label = 'Bilangan') {
    const s = String(value ?? '').trim() || '0'
    if (!/^\d+$/.test(s) || Number(s) > 1000000000) throw Error(`${label} perlu nombor bulat antara 0 hingga 1,000,000,000.`)
    return Number(s)
  }
  function https(value, required = false) {
    const s = String(value || '').trim()
    if (!s && !required) return ''
    if (s.length > 2048) throw Error('Pautan terlalu panjang.')
    let u
    try { u = new URL(s) } catch { throw Error('Masukkan pautan lengkap yang bermula dengan https://.') }
    const h = u.hostname.toLowerCase().replace(/\.$/, '')
    if (u.protocol !== 'https:' || u.username || u.password || !h.includes('.') || h === 'localhost' || /\.(local|localhost|internal|test)$/.test(h) || /^\[/.test(h) || /^(0|10|127|169\.254|192\.168|172\.(1[6-9]|2\d|3[01]))\./.test(h)) throw Error('Gunakan pautan HTTPS awam tanpa nama pengguna atau kata laluan.')
    return u.href
  }
  const MEDIA_TYPES = {
    'image/jpeg': { format: 'image', extensions: ['jpg', 'jpeg'], label: 'JPEG' },
    'image/png': { format: 'image', extensions: ['png'], label: 'PNG' },
    'video/mp4': { format: 'video', extensions: ['mp4'], label: 'MP4' },
    'video/quicktime': { format: 'video', extensions: ['mov'], label: 'MOV' }
  }
  function mediaRules(capability = {}) {
    const accepted = Array.isArray(capability.accepted_mime_types) ? capability.accepted_mime_types.filter(type => MEDIA_TYPES[type]) : Object.keys(MEDIA_TYPES)
    const limit = (value, fallback) => Number.isSafeInteger(value) && value > 0 ? value : fallback
    return { accepted, image: limit(capability.max_image_bytes, 10 * 1024 * 1024), video: limit(capability.max_video_bytes, 100 * 1024 * 1024) }
  }
  function fileSize(bytes) { return bytes < 1024 * 1024 ? Math.max(1, Math.ceil(bytes / 1024)) + ' KB' : Number((bytes / (1024 * 1024)).toFixed(1)) + ' MB' }
  function mediaFile(file, capability = {}) {
    const rules = mediaRules(capability), name = String(file?.name || ''), extension = name.split('.').pop().toLowerCase()
    const supplied = String(file?.type || '').toLowerCase().split(';')[0].trim()
    const mime_type = supplied || Object.keys(MEDIA_TYPES).find(type => MEDIA_TYPES[type].extensions.includes(extension))
    const info = MEDIA_TYPES[mime_type]
    if (!info || !rules.accepted.includes(mime_type) || !info.extensions.includes(extension)) throw Error('Pilih fail ' + rules.accepted.map(type => MEDIA_TYPES[type].label).join(', ') + ' yang disokong.')
    if (!Number.isSafeInteger(file.size) || file.size <= 0) throw Error('Fail ini kosong atau saiznya tidak dapat dibaca. Pilih fail lain.')
    const maximum = rules[info.format]
    if (file.size > maximum) throw Error((info.format === 'image' ? 'Gambar' : 'Video') + ' melebihi had ' + fileSize(maximum) + '. Pilih fail yang lebih kecil.')
    return { name, format: info.format, mime_type, size_bytes: file.size }
  }
  function date(value, required = false) {
    const s = String(value || '')
    if (!s && !required) return ''
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) throw Error('Tarikh perlu lengkap.')
    const d = new Date(`${s}T00:00:00Z`)
    if (!Number.isFinite(d.getTime()) || d.toISOString().slice(0, 10) !== s) throw Error('Tarikh tidak sah.')
    return s
  }
  function malaysiaDate(value = new Date()) {
    const d = new Date(value)
    if (!Number.isFinite(d.getTime())) return ''
    return new Date(d.getTime() + 8 * 3600000).toISOString().slice(0, 10)
  }
  function malaysiaInput(value) {
    if (!value) return ''
    const d = new Date(value)
    if (!Number.isFinite(d.getTime())) return ''
    return new Date(d.getTime() + 8 * 3600000).toISOString().slice(0, 16)
  }
  function utcFromMalaysia(value) {
    if (!value) return ''
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) throw Error('Lengkapkan tarikh dan masa penerbitan.')
    date(value.slice(0, 10), true)
    const time = new Date(`${value}:00+08:00`)
    if (!Number.isFinite(time.getTime()) || malaysiaInput(time) !== value) throw Error('Masa penerbitan tidak sah.')
    return time.toISOString().replace('.000Z', 'Z')
  }
  function displayDate(value, withTime = false) {
    if (!value) return 'Belum ditetapkan'
    const d = new Date(value.length === 10 ? `${value}T12:00:00+08:00` : value)
    if (!Number.isFinite(d.getTime())) return 'Tarikh tidak tersedia'
    return new Intl.DateTimeFormat('ms-MY', { timeZone: 'Asia/Kuala_Lumpur', day: 'numeric', month: 'short', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : { year: 'numeric' }) }).format(d)
  }
  function campaign(input) {
    const x = { ...input }
    x.title = String(x.title || '').trim()
    if (!x.title || x.title.length > 160) throw Error('Namakan kempen anda, maksimum 160 aksara.')
    if (!(x.goal in GOALS)) throw Error('Pilih matlamat kempen.')
    x.budget_total = money(x.budget_total, 'Bajet')
    x.destination_url = https(x.destination_url)
    x.start_date = date(x.start_date); x.end_date = date(x.end_date)
    if (x.start_date && x.end_date && x.start_date > x.end_date) throw Error('Tarikh tamat perlu selepas atau pada tarikh mula.')
    x.channels = [...new Set((x.channels || []).filter(p => CHANNELS.includes(p)))].sort()
    return x
  }
  function result(input) {
    const x = { ...input }
    if (!/^[a-f0-9]{32}$/.test(x.campaign_id || '')) throw Error('Pilih kempen untuk rekod ini.')
    x.date = date(x.date, true)
    if (!(x.platform in PLATFORM)) throw Error('Pilih saluran rekod.')
    x.spend = money(x.spend, 'Belanja'); x.revenue = money(x.revenue, 'Nilai jualan')
    for (const k of ['impressions', 'clicks', 'enquiries', 'qualified_leads', 'orders']) x[k] = integer(x[k])
    return x
  }
  function metrics(rows) {
    const total = { records: rows.length, spend_cents: 0, revenue_cents: 0, impressions: 0, clicks: 0, enquiries: 0, qualified_leads: 0, orders: 0 }
    for (const row of rows) {
      total.spend_cents += cents(row.spend); total.revenue_cents += cents(row.revenue)
      for (const k of ['impressions', 'clicks', 'enquiries', 'qualified_leads', 'orders']) total[k] += integer(row[k])
    }
    return { ...total, spend: total.spend_cents / 100, revenue: total.revenue_cents / 100, ctr: total.impressions ? total.clicks / total.impressions * 100 : null, cost_per_enquiry: total.enquiries ? total.spend_cents / 100 / total.enquiries : null, roas: total.spend_cents ? total.revenue_cents / total.spend_cents : null }
  }
  function utm(destination, platform, campaignId, medium = 'organic_social') {
    if (!CHANNELS.includes(platform)) throw Error('Saluran pautan tidak disokong.')
    if (!/^[a-f0-9]{32}$/.test(campaignId || '')) throw Error('Simpan kempen sebelum membina pautan.')
    const u = new URL(https(destination, true))
    u.searchParams.set('utm_source', platform === 'google_ads' ? 'google' : platform)
    u.searchParams.set('utm_medium', platform === 'google_ads' ? 'cpc' : medium)
    u.searchParams.set('utm_campaign', campaignId)
    return u.href
  }
  function csvCell(value) {
    let s = String(value ?? '')
    if (/^[\s\u0000-\u001f]*[=+\-@]/.test(s) || /^[\t\r\n]/.test(s)) s = `'${s}`
    return `"${s.replaceAll('"', '""')}"`
  }
  function csv(rows, campaigns = []) {
    const names = new Map(campaigns.map(c => [c.id, c.title]))
    const keys = ['date', 'campaign', 'platform', 'spend', 'impressions', 'clicks', 'enquiries', 'qualified_leads', 'orders', 'revenue', 'note']
    const output = [['Tarikh', 'Kempen', 'Saluran', 'Belanja MYR', 'Paparan', 'Klik', 'Pertanyaan', 'Prospek layak', 'Pesanan', 'Jualan MYR', 'Catatan']]
    for (const r of rows) output.push(keys.map(k => k === 'campaign' ? names.get(r.campaign_id) || r.campaign_id : k === 'platform' ? PLATFORM[r.platform] || r.platform : r[k]))
    return '\ufeff' + output.map(row => row.map(csvCell).join(',')).join('\r\n')
  }
  function brief(c) {
    return [`BESHARE AI MARKETING`, c.title, '', `Matlamat: ${GOALS[c.goal] || c.goal}`, `Produk / servis: ${c.product || 'Belum diisi'}`, `Tawaran: ${c.offer || 'Belum diisi'}`, `Sasaran pelanggan: ${c.audience || 'Belum diisi'}`, `Bajet dirancang: ${ringgit(c.budget_total)}`, `Tempoh: ${displayDate(c.start_date)} — ${displayDate(c.end_date)}`, `Saluran: ${(c.channels || []).map(p => PLATFORM[p]).join(', ') || 'Belum dipilih'}`, `Destinasi: ${c.destination_url || 'Belum diisi'}`, '', 'Bajet ini ialah perancangan. Ia tidak mengaktifkan atau membelanjakan wang untuk iklan.'].join('\n')
  }
  function monthDays(year, month) {
    const first = new Date(Date.UTC(year, month, 1))
    const offset = (first.getUTCDay() + 6) % 7
    const count = new Date(Date.UTC(year, month + 1, 0)).getUTCDate()
    return Array.from({ length: Math.ceil((offset + count) / 7) * 7 }, (_, i) => {
      const d = new Date(Date.UTC(year, month, i - offset + 1))
      return { date: d.toISOString().slice(0, 10), day: d.getUTCDate(), inMonth: d.getUTCMonth() === month }
    })
  }
  function promptTurns(prompt) {
    const turns = []
    let remaining = String(prompt || '')
    while (remaining) {
      let end = Math.min(3400, remaining.length)
      const last = remaining.charCodeAt(end - 1)
      if (last >= 0xd800 && last <= 0xdbff && end < remaining.length) end--
      turns.push({ dari: 'klien', teks: remaining.slice(0, end) })
      remaining = remaining.slice(end)
    }
    if (!turns.length || turns.length > 8) throw Error('Maklumat terlalu panjang untuk satu permintaan. Ringkaskan kempen dahulu.')
    return turns
  }
  return { CHANNELS, GOALS, PLATFORM, STATUS, MEDIA_TYPES, id, money, cents, ringgit, integer, https, mediaRules, mediaFile, fileSize, date, malaysiaDate, malaysiaInput, utcFromMalaysia, displayDate, campaign, result, metrics, utm, csvCell, csv, brief, monthDays, promptTurns }
})
