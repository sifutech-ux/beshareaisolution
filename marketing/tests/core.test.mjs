import test from 'node:test'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
const require = createRequire(import.meta.url)
const C = require('../core.js')
const id = 'a'.repeat(32)

test('money is canonical and refuses ambiguous, negative and exponential inputs', () => {
  assert.equal(C.money('003.2'), '3.20')
  assert.equal(C.money(''), '0.00')
  for (const bad of ['-1', '1,000', '1e3', '0.001', 'Infinity', '10000000000.00']) assert.throws(() => C.money(bad))
  assert.equal(C.cents('9999999999.99'), 999999999999)
})

test('metrics add decimal currency exactly and do not invent denominator-based rates', () => {
  const zero = C.metrics([])
  assert.equal(zero.roas, null)
  assert.equal(zero.ctr, null)
  assert.equal(zero.cost_per_enquiry, null)
  const x = C.metrics([{ spend: '0.10', revenue: '0.20', enquiries: 1, impressions: 3, clicks: 1 }, { spend: '0.20', revenue: '0.40', enquiries: 2, impressions: 7, clicks: 1 }])
  assert.equal(x.spend, 0.3)
  assert.equal(x.revenue, 0.6)
  assert.equal(x.roas, 2)
  assert.equal(x.ctr, 20)
  assert.equal(C.metrics([{ spend: '0', revenue: '20' }]).roas, null)
})

test('Malaysia scheduling crosses dates independently of the computer timezone', () => {
  assert.equal(C.utcFromMalaysia('2026-11-18T00:15'), '2026-11-17T16:15:00Z')
  assert.equal(C.malaysiaInput('2026-11-17T16:15:00Z'), '2026-11-18T00:15')
  assert.equal(C.malaysiaDate('2026-12-31T17:00:00Z'), '2027-01-01')
  for (const invalid of ['2026-02-30T12:00', '2026-11-17T25:00', '2026-11-17T24:00']) assert.throws(() => C.utcFromMalaysia(invalid))
})

test('campaign dates reject invalid or reversed windows and normalize channels', () => {
  const c = { title: 'Kempen', goal: 'leads', channels: ['facebook', 'facebook', 'invalid'], start_date: '2026-11-20', end_date: '2026-11-19' }
  assert.throws(() => C.campaign(c))
  assert.throws(() => C.date('2026-02-29', true))
  assert.equal(C.date('2028-02-29', true), '2028-02-29')
  assert.deepEqual(C.campaign({ ...c, end_date: '2026-11-20' }).channels, ['facebook'])
})

test('HTTPS links reject active schemes, credentials and private hosts', () => {
  for (const bad of ['javascript:alert(1)', 'http://example.com', 'https://u:p@example.com', 'https://127.0.0.1', 'https://192.168.1.3', 'https://172.16.1.2', 'https://localhost', 'https://[::1]', 'https://app.internal']) assert.throws(() => C.https(bad, true), bad)
  assert.equal(C.https('https://example.com/path?q=ok'), 'https://example.com/path?q=ok')
})

test('UTM construction preserves destinations while replacing duplicate attribution parameters', () => {
  const url = new URL(C.utm('https://example.com/order?utm_source=old&utm_source=other&item=7#pay', 'facebook', id))
  assert.deepEqual(url.searchParams.getAll('utm_source'), ['facebook'])
  assert.equal(url.searchParams.get('utm_medium'), 'organic_social')
  assert.equal(url.searchParams.get('utm_campaign'), id)
  assert.equal(url.searchParams.get('item'), '7')
  assert.equal(url.hash, '#pay')
  assert.equal(new URL(C.utm('https://example.com', 'google_ads', id)).searchParams.get('utm_medium'), 'cpc')
})

test('CSV neutralizes spreadsheet formulas and escapes embedded quotes and newlines', () => {
  assert.equal(C.csvCell('=1+1'), "\"'=1+1\"")
  assert.equal(C.csvCell('  +SUM(1,2)'), "\"'  +SUM(1,2)\"")
  assert.equal(C.csvCell('\tformula'), "\"'\tformula\"")
  assert.equal(C.csvCell('say "hello"'), '"say ""hello"""')
  const csv = C.csv([{ campaign_id: id, date: '2026-11-17', platform: 'facebook', note: 'line one\nline "two"' }], [{ id, title: '@risk' }])
  assert.ok(csv.startsWith('\ufeff'))
  assert.ok(csv.includes("\"'@risk\""))
  assert.ok(csv.includes('"line one\nline ""two"""'))
})

test('daily results require a campaign and nonnegative whole counts', () => {
  assert.throws(() => C.result({ campaign_id: '', date: '2026-11-17', platform: 'facebook' }))
  assert.throws(() => C.result({ campaign_id: id, date: '2026-11-17', platform: 'facebook', orders: 0.5 }))
  const r = C.result({ campaign_id: id, date: '2026-11-17', platform: 'facebook', spend: '10', enquiries: '2' })
  assert.equal(r.spend, '10.00')
  assert.equal(r.orders, 0)
  assert.equal(r.enquiries, 2)
})

test('calendar starts Mondays and handles leap years and year boundaries', () => {
  const days = C.monthDays(2028, 1)
  assert.equal(new Date(days[0].date).getUTCDay(), 1)
  assert.equal(days.filter(d => d.inMonth).length, 29)
  assert.ok(C.monthDays(2027, 0).some(d => d.date.startsWith('2026-12')))
})

test('long AI context keeps every fact within Copilot turn limits and preserves emoji', () => {
  const prompt = 'x'.repeat(3399) + '😀' + 'Maklumat tawaran penting. '.repeat(180)
  const turns = C.promptTurns(prompt)
  assert.equal(turns.map(t => t.teks).join(''), prompt)
  assert.ok(turns.every(t => t.teks.length <= 3400 && t.dari === 'klien'))
  assert.ok(!turns[0].teks.endsWith('\ud83d'))
  assert.throws(() => C.promptTurns('x'.repeat(3400 * 9)))
})
