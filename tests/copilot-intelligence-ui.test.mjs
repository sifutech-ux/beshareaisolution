import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import test from 'node:test'

const speech = { audioProvider: 'elevenlabs', mime: 'audio/mpeg', audio: 'YXVkaW8=' }
const flush = () => new Promise(resolve => setImmediate(resolve))

function harness({ micFail = false, fetchFail = false, deferMic = false, playFail = false,
  liveProvider = 'elevenlabs', synth = async () => speech } = {}) {
  const effects = { primed: 0, played: 0, paused: 0, revoked: 0, reviewed: null }
  class Element {
    constructor() { this.children = []; this.listeners = {}; this.dataset = {}; this.classList = { add() {} }; this.disabled = false; this.value = ''; this.textContent = ''; this.isConnected = true; this.open = false }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn) }
    emit(name, value = {}) { this.listeners[name]?.forEach(fn => fn(value)) }
    append(...nodes) { this.children.push(...nodes) }
    replaceChildren(...nodes) { this.children = nodes }
    setAttribute() {} scrollIntoView() {} showModal() { this.open = true } close() { this.closed = true; this.open = false; this.emit('close') } focus() {}
    async play() {
      if (this.src?.startsWith('data:audio/wav')) { effects.primed++; return }
      effects.played++
      if (playFail && effects.played === 1) throw new Error('blocked')
    }
    pause() { effects.paused++ } removeAttribute(name) { delete this[name] } load() {}
  }
  const nodes = new Map(), get = id => { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id) }
  const tracks = [{ stopped: false, stop() { this.stopped = true } }]
  const media = { getTracks: () => tracks }
  let resolveMic, peer, token = 'fixture-token', micRequests = 0
  const requests = [], timers = new Set()
  class Peer extends Element {
    constructor() { super(); peer = this }
    addTrack() {} createDataChannel() { this.channel = new Element(); return this.channel }
    async createOffer() { return { sdp: 'v=0\r\nfixture' } }
    async setLocalDescription() {} async setRemoteDescription(data) { this.remote = data }
  }
  const sandbox = { document: { getElementById: get, createElement: () => new Element(), addEventListener() {} },
    window: { RTCPeerConnection: Peer, addEventListener() {} }, RTCPeerConnection: Peer,
    navigator: { mediaDevices: { getUserMedia: () => { micRequests++; return micFail ? Promise.reject(Object.assign(new Error(), { name: 'NotAllowedError' }))
      : deferMic ? new Promise(resolve => { resolveMic = resolve }) : Promise.resolve(media) } }, clipboard: { writeText: async () => {} } },
    AbortController, queueMicrotask, setTimeout: fn => { timers.add(fn); return fn }, clearTimeout: fn => timers.delete(fn),
    atob, Uint8Array, Blob, URL: { createObjectURL: () => 'blob:demo', revokeObjectURL: () => effects.revoked++ },
    sesiAkaun: () => ({ token }), akaunId: 'a'.repeat(32), keadaan: { nama: 'Fixture' }, sibuk: false,
    kepalaAkaun: () => ({ Authorization: `Bearer ${token}` }), NASIHAT: 'https://nasihat.example', AKAUN_KUNCI: 'fixture',
    SUARA_API: 'https://nasihat.example/api/penasihat-suara',
    BeshareHasil: { offerVoice: entries => { effects.reviewed = JSON.parse(JSON.stringify(entries)) } },
    fetch: async (url, options) => {
      requests.push({ url, options })
      if (fetchFail) throw new Error('offline')
      const data = url.endsWith('penasihat-suara') ? await synth() : { ok: true, sesi: 'voice-fixture', sdp: 'v=0\r\nanswer', had_saat: 300, audioProvider: liveProvider }
      return { ok: true, json: async () => data }
    },
    mintaAkaun: async () => ({ status: 503, data: {} }), sesiTamat: () => false, tulis: new Element(), status: new Element(), location: { reload() {} },
  }
  vm.createContext(sandbox)
  vm.runInContext(readFileSync(new URL('../penasihat/bijak.js', import.meta.url), 'utf8') + '\nthis.api = BeshareBijak', sandbox)
  const emit = data => peer.channel.emit('message', { data: JSON.stringify(data) })
  const reply = (id, status = 'completed') => {
    emit({ type: 'response.created', response: { id } })
    emit({ type: 'response.output_text.done', response_id: id, output_index: 0, content_index: 0, text: 'Cadangan Copilot' })
    emit({ type: 'response.done', response: { id, status } })
  }
  return { api: sandbox.api, get, tracks, requests, timers, sandbox, effects, emit, reply, peer: () => peer,
    micRequests: () => micRequests, releaseMic: () => resolveMic(media) }
}

test('voice opens via authenticated SDP, transcript stays in screen and stop closes every track', async () => {
  const h = harness(); await h.api.startVoice()
  assert.equal(h.requests[0].options.headers.Authorization, 'Bearer fixture-token')
  assert.equal(JSON.parse(h.requests[0].options.body).audioProvider, 'elevenlabs')
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

test('opening the voice dialog never opens the microphone or calls the provider', () => {
  const h = harness(); h.api.init(); h.get('suara-buka').emit('click')
  assert.equal(h.get('suara-panel').open, true)
  assert.equal(h.micRequests(), 0); assert.equal(h.requests.length, 0)
  h.get('suara-tutup').emit('click')
  assert.equal(h.get('suara-panel').open, false)
})

test('Escape dismisses the dialog and stops an active call exactly once', async () => {
  const h = harness(); h.api.init(); h.api.openVoice(); await h.api.startVoice()
  let prevented = false
  h.get('suara-panel').emit('cancel', { preventDefault() { prevented = true } })
  assert.ok(prevented); assert.equal(h.get('suara-panel').open, false)
  assert.ok(h.tracks[0].stopped); assert.ok(h.peer().closed)
  assert.equal(h.requests.filter(r => JSON.parse(r.options.body).henti).length, 1)
})

test('dismissing during microphone permission stops a late stream without provider startup', async () => {
  const h = harness({ deferMic: true }); h.api.init(); h.api.openVoice(); const started = h.api.startVoice()
  h.get('suara-tutup').emit('click'); h.releaseMic(); await started
  assert.equal(h.get('suara-panel').open, false); assert.ok(h.tracks[0].stopped)
  assert.equal(h.requests.length, 0)
})

test('autoplay failure reveals a deliberate audio playback control and cleanup hides it', async () => {
  const h = harness({ playFail: true }); h.api.init(); await h.api.startVoice()
  h.reply('autoplay'); await flush()
  assert.equal(h.get('suara-main').hidden, false)
  h.get('suara-main').emit('click'); await flush()
  assert.equal(h.effects.played, 2)
  h.api.closeVoice(); assert.equal(h.get('suara-main').hidden, true)
})

test('transcripts stay collapsed until requested and transferring an instruction closes the call', async () => {
  const h = harness(); h.api.init(); h.api.openVoice(); await h.api.startVoice()
  assert.equal(h.get('suara-catatan').hidden, true)
  h.peer().channel.emit('message', { data: JSON.stringify({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'one', transcript: 'Catat jualan RM350' }) })
  assert.equal(h.get('suara-catatan').hidden, false); assert.equal(h.get('suara-catatan').open, false)
  h.get('suara-transkrip').children[0].children[2].emit('click')
  assert.equal(h.sandbox.tulis.value, 'Catat jualan RM350'); assert.ok(h.tracks[0].stopped)
  assert.equal(h.get('suara-panel').open, false)
})

test('live rejects unmarked sessions before accepting remote audio', async () => {
  const h = harness({ liveProvider: null }); await h.api.startVoice()
  assert.equal(h.peer().remote, undefined)
  assert.equal(JSON.parse(h.requests[1].options.body).henti, 'voice-fixture')
  assert.ok(h.tracks[0].stopped)
})

test('completed text speaks through ElevenLabs and preserves the voice transcript', async () => {
  const h = harness(); await h.api.startVoice()
  h.emit({ type: 'response.output_audio_transcript.done', transcript: 'OpenAI audio' })
  h.emit({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'user', transcript: 'Idea promosi?' })
  h.reply('discarded', 'cancelled')
  h.reply('completed'); await flush()
  assert.equal(h.requests.length, 2)
  assert.deepEqual(JSON.parse(h.requests[1].options.body), { jawapan: 'Cadangan Copilot', langkah: [] })
  assert.equal(h.requests[1].options.headers.Authorization, 'Bearer fixture-token')
  assert.equal(h.effects.played, 1)
  h.api.closeVoice()
  assert.equal(h.effects.paused, 1)
  assert.equal(h.effects.revoked, 1)
  assert.deepEqual(h.effects.reviewed, [
    { speaker: 'user', text: 'Idea promosi?' }, { speaker: 'assistant', text: 'Cadangan Copilot' },
  ])
})

test('interrupting a spoken turn aborts synthesis and discards late audio', async () => {
  let complete
  const pending = new Promise(resolve => { complete = resolve })
  const h = harness({ synth: () => pending }); await h.api.startVoice()
  h.reply('interrupted')
  h.emit({ type: 'input_audio_buffer.speech_started' })
  assert.equal(h.requests[1].options.signal.aborted, true)
  complete(speech); await flush()
  assert.equal(h.effects.played, 0)
})

test('unmarked speech cannot play even after an acknowledged live session starts', async () => {
  const h = harness({ synth: async () => ({ ...speech, audioProvider: 'openai' }) }); await h.api.startVoice()
  h.peer().channel.emit('open')
  h.reply('wrong-source'); await flush()
  assert.equal(h.effects.played, 0)
  assert.equal(h.get('suara-panel').dataset.state, 'active')
  assert.equal(h.get('suara-transkrip').children.length, 1)
  assert.match(h.get('suara-status').textContent, /baca transkrip/)
})


test('playback is primed by Start before the microphone permission prompt resolves', async () => {
  const h = harness({ deferMic: true })
  const starting = h.api.startVoice()
  assert.equal(h.effects.primed, 1)
  assert.equal(h.requests.length, 0)
  h.releaseMic(); await starting
})

test('synthesis failure retains the active turn and retries the same text only on demand', async () => {
  let calls = 0
  const h = harness({ synth: async () => { if (++calls === 1) throw new Error('offline'); return speech } })
  h.api.init(); await h.api.startVoice()
  h.reply('retry'); await flush()
  assert.equal(h.get('suara-main').textContent, 'Cuba semula audio')
  assert.equal(h.get('suara-main').hidden, false)
  h.get('suara-main').emit('click'); h.get('suara-main').emit('click'); await flush()
  assert.equal(calls, 2)
  assert.equal(h.effects.played, 1)
  assert.equal(h.get('suara-transkrip').children.length, 1)
})

function connection(h, state) {
  h.peer().connectionState = state
  h.peer().emit('connectionstatechange')
}

test('temporary disconnect recovers within the same call and retains microphone and transcript', async () => {
  const h = harness(); await h.api.startVoice()
  h.peer().channel.readyState = 'open'; h.peer().channel.emit('open')
  h.reply('one'); await flush()
  connection(h, 'disconnected')
  const grace = [...h.timers].at(-1)
  connection(h, 'disconnected')
  assert.equal(h.timers.size, 2)
  connection(h, 'connected')
  assert.equal(h.timers.has(grace), false)
  grace()
  assert.equal(h.get('suara-panel').dataset.state, 'active')
  assert.equal(h.tracks[0].stopped, false)
  assert.equal(h.get('suara-transkrip').children.length, 1)
  assert.equal(h.requests.length, 2)
})

test('sustained disconnection closes the microphone and releases the session once', async () => {
  const h = harness(); await h.api.startVoice()
  connection(h, 'disconnected'); [...h.timers].at(-1)()
  assert.ok(h.tracks[0].stopped)
  assert.equal(h.timers.size, 0)
  assert.equal(h.get('suara-panel').dataset.state, 'error')
  assert.equal(h.requests.filter(x => JSON.parse(x.options.body).henti).length, 1)
})

test('a recoverable Realtime error leaves the data channel open for the next turn', async () => {
  const h = harness(); await h.api.startVoice()
  h.emit({ type: 'error', error: { type: 'invalid_request_error' } })
  assert.equal(h.tracks[0].stopped, false)
  h.reply('next'); await flush()
  assert.equal(h.effects.played, 1)
  h.emit({ type: 'error', error: { code: 'session_expired' } })
  assert.ok(h.tracks[0].stopped)
})

const firstSentence = 'Mulakan dengan pelanggan yang selalu datang.'
const restOfAnswer = 'Tanya produk yang mereka suka dan catat jawapannya.'
function beginLongReply(h) {
  h.emit({ type: 'response.created', response: { id: 'long' } })
  h.emit({ type: 'response.output_text.delta', response_id: 'long', output_index: 0, content_index: 0, delta: firstSentence + ' ' + restOfAnswer })
}
function finishLongReply(h, status = 'completed') {
  h.emit({ type: 'response.done', response: { id: 'long', status, output: [
    { type: 'message', role: 'assistant', content: [{ type: 'output_text', text: firstSentence + ' ' + restOfAnswer }] },
  ] } })
}
function finishClip(h) {
  const player = h.get('audio-bijak')
  player.ended = true
  player.onended()
}

test('long answers can start before response.done and play each part in order', async () => {
  const h = harness(); await h.api.startVoice()
  beginLongReply(h); await flush()
  assert.equal(h.effects.played, 1)
  assert.equal(JSON.parse(h.requests[1].options.body).jawapan, firstSentence)
  finishLongReply(h); await flush()
  assert.equal(JSON.parse(h.requests[2].options.body).jawapan, restOfAnswer)
  assert.equal(h.effects.played, 1)
  finishClip(h); await flush()
  assert.equal(h.effects.played, 2)
  finishClip(h)
  assert.equal(h.effects.revoked, 2)
  assert.equal(h.get('suara-transkrip').children.length, 1)
  assert.match(h.get('suara-status').textContent, /Anda boleh bercakap/)
})

test('a cancelled long response discards preloaded audio and never speaks a late result', async () => {
  let complete
  const h = harness({ synth: () => new Promise(resolve => { complete = resolve }) }); await h.api.startVoice()
  beginLongReply(h); finishLongReply(h, 'cancelled')
  assert.equal(h.requests[1].options.signal.aborted, true)
  complete(speech); await flush()
  assert.equal(h.effects.played, 0)
  assert.equal(h.get('suara-transkrip').children.length, 0)
})
