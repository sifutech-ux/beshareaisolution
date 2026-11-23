import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const html = readFileSync(new URL('../index.html', import.meta.url), 'utf8')
const marketing = readFileSync(new URL('../marketing.js', import.meta.url), 'utf8')
const group = readFileSync(new URL('../group-manager.js', import.meta.url), 'utf8')
const groupCss = readFileSync(new URL('../group-manager.css', import.meta.url), 'utf8')

test('new Group Manager navigation is wired in both HTML and marketing view registry', () => {
  assert.match(html, /<a href="#kumpulan" data-view="kumpulan">/)
  assert.match(html, /<section data-panel="kumpulan" hidden/)
  assert.match(marketing, /kumpulan:\s*\['DISTRIBUSI BERIZIN'/)
  assert.match(group, /active\s*=\s*location\.hash\s*===\s*'#kumpulan'/)
})

test('Marketing navigation bundle is cache-busted for the new Group Manager release', () => {
  const match = html.match(/<script src="marketing\.js\?v=([^"]+)" defer><\/script>/)
  assert.ok(match, 'Marketing bundle must have a versioned script URL')
  assert.notEqual(match[1], '20261120directauth1', 'Legacy cached code does not recognize #kumpulan')
  const jsLocation = html.indexOf('marketing.js?v=')
  const groupLocation = html.indexOf('group-manager.js?v=')
  assert.ok(jsLocation !== -1 && groupLocation > jsLocation, 'Load view controller before Group Manager')
  assert.match(html, /group-manager\.css\?v=20261123groupnavfix1/)
})

test('mobile bottom navigation can scroll without obscuring seventh destination', () => {
  assert.match(groupCss, /@media\(max-width:680px\)/)
  assert.match(groupCss, /\.sidebar \.nav\{display:flex;flex-direction:row;/)
  assert.match(groupCss, /overflow-x:auto/)
})
