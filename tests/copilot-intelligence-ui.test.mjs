import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import test from 'node:test'

function harness({ micFail = false, fetchFail = false, deferMic = false } = {}) {
  class Element {
    constructor() { this.children = []; this.listeners = {}; this.classList = { add() {} }; this.disabled = false; this.value = ''; this.textContent = ''; this.isConnected = true }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn) }
    emit(name, value = {}) { this.listeners[name]?.forEach(fn => fn(value)) }
    append(...nodes) { this.children.push(...nodes) }
    replaceChildren(...nodes) { this.children = nodes }
    setAttribute() {} scrollIntoView() {} close() { this.closed = true } focus() {} play() { return Promise.resolve() }
  }
  const nodes = new Map(), get = id => { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id) }
  const tracks = [{ stopped: false, stop() { this.stopped = true } }]
  const media = { getTracks: () => tracks }
  let resolveMic, peer, token = 'fixture-token'
  const requests = [], timers = new Set()
  class Peer extends Element {
    constructor() { super(); peer = this }
    addTrack() {} createDataChannel() { this.channel = new Element(); return this.channel }
    async createOffer() { return { sdp: 'v=0\r\nfixture' } }
    async setLocalDescription() {} async setRemoteDescription(data) { this.remote = data }
  }
  const sandbox = { document: { getElementById: get, createElement: () => new Element(), addEventListener() {} },
    window: { RTCPeerConnection: Peer, addEventListener() {} }, RTCPeerConnection: Peer, MediaStream: class {},
    navigator: { mediaDevices: { getUserMedia: () => micFail ? Promise.reject(Object.assign(new Error(), { name: 'NotAllowedError' }))
      : deferMic ? new Promise(resolve => { resolveMic = resolve }) : Promise.resolve(media) }, clipboard: { writeText: async () => {} } },
    AbortController, queueMicrotask, setTimeout: fn => { timers.add(fn); return fn }, clearTimeout: fn => timers.delete(fn),
    sesiAkaun: () => ({ token }), akaunId: 'a'.repeat(32), keadaan: { nama: 'Fixture' }, sibuk: false,
    kepalaAkaun: () => ({ Authorization: `Bearer ${token}` }), NASIHAT: 'https://nasihat.example', AKAUN_KUNCI: 'fixture',
    fetch: async (url, options) => { requests.push({ url, options }); if (fetchFail) throw new Error('offline'); return { ok: true, json: async () => ({ ok: true, sesi: 'voice-fixture', sdp: 'v=0\r\nanswer', had_saat: 300 }) } },
    mintaAkaun: async () => ({ status: 503, data: {} }), sesiTamat: () => false, tulis: new Element(), status: new Element(), location: { reload() {} },
  }
  vm.createContext(sandbox)
  vm.runInContext(readFileSync(new URL('../penasihat/bijak.js', import.meta.url), 'utf8') + '\nthis.api = BeshareBijak', sandbox)
  return { api: sandbox.api, get, tracks, requests, timers, sandbox, peer: () => peer, releaseMic: () => resolveMic(media) }
}

test('voice opens via authenticated SDP, transcript stays in screen and stop closes every track', async () => {
  const h = harness(); await h.api.startVoice()
  assert.equal(h.requests[0].options.headers.Authorization, 'Bearer fixture-token')
  assert.equal(h.peer().remote.type, 'answer')
  const event = { data: JSON.stringify({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'item1', transcript: 'Catat jualan RM350' }) }
  h.peer().channel.emit('message', event); h.peer().channel.emit('message', event)
  assert.equal(h.get('suara-transkrip').children.length, 1)
  h.api.closeVoice()
  assert.ok(h.tracks[0].stopped); assert.ok(h.peer().closed); assert.ok(h.peer().channel.closed)
  assert.equal(h.timers.size, 0)
  assert.equal(JSON.parse(h.requests[1].options.body).henti, 'voice-fixture')
})

test('microphone denied leaves controls usable and makes no paid call', async () => {
  const h = harness({ micFail: true }); await h.api.startVoice()
  assert.equal(h.requests.length, 0); assert.equal(h.get('suara-mula').disabled, false)
  assert.match(h.get('suara-status').textContent, /belum dibenarkan/)
})

test('network failure stops microphone and connection', async () => {
  const h = harness({ fetchFail: true }); await h.api.startVoice()
  assert.ok(h.tracks[0].stopped); assert.ok(h.peer().closed)
  assert.equal(h.get('suara-mula').disabled, false)
})

test('cancel during microphone prompt stops late stream and never starts provider call', async () => {
  const h = harness({ deferMic: true }); const started = h.api.startVoice()
  h.api.closeVoice(); h.releaseMic(); await started
  assert.ok(h.tracks[0].stopped); assert.equal(h.requests.length, 0)
})

test('logout clears transcript and memory from the prior account', async () => {
  const h = harness(); await h.api.startVoice()
  h.get('ingatan-produk').value = 'Account A private product'; h.get('suara-transkrip').append({ textContent: 'private transcript' })
  h.api.reset()
  assert.equal(h.get('ingatan-produk').value, ''); assert.equal(h.get('suara-transkrip').children.length, 0)
  assert.ok(h.tracks[0].stopped)
})
