/* Account-scoped intelligence UI. All DOM content from AI is text, never HTML. */
const BeshareBijak = (() => {
  const $ = id => document.getElementById(id)
  let memoryVersion = null, dirty = false, generation = 0, context = null
  let voice = null, voiceGeneration = 0, starting = false
  const voiceReady = 'Anda boleh bercakap seperti biasa. Copilot akan menjawab dengan suara.'
  const proposalCache = new Map()
  function button(label, action) {
    const el = document.createElement('button')
    el.type = 'button'; el.className = 'butang butang-senyap'; el.textContent = label
    el.addEventListener('click', action)
    return el
  }
  function text(tag, value) { const el = document.createElement(tag); el.textContent = value; return el }
  const fields = ['produk', 'pelanggan', 'lokasi', 'saluran', 'bajet', 'matlamat']
  function showMemory(data, force = false) {
    if (dirty && !force) return
    memoryVersion = data.versi
    fields.forEach(key => { $(`ingatan-${key}`).value = data.ingatan[key] || '' })
    dirty = false; $('ingatan-simpan').disabled = false
  }
  function focus(data) {
    const host = $('fokus-hari'); host.replaceChildren()
    const task = data.pelan?.tugasan?.find(x => !x.siap)
    const goal = data.ingatan.ingatan.matlamat || data.matlamat
    const summary = data.angka.minggu
    host.append(text('p', `Dikemas daripada akaun • ${data.hari}`))
    if (goal) host.append(text('p', `Matlamat: ${goal}`))
    const title = task ? task.teks : !goal ? 'Tetapkan satu matlamat perniagaan' : 'Pilih satu langkah untuk matlamat anda'
    host.append(text('h3', title))
    host.append(text('p', task ? 'Ini tugasan pertama yang belum selesai dalam pelan minggu ini.' : 'Copilot boleh bantu pecahkan matlamat kepada langkah yang boleh dibuat hari ini.'))
    const actionRow = document.createElement('div'); actionRow.className = 'fokus-aksi'
    actionRow.append(button(task ? 'Bantu saya mulakan' : 'Rancang langkah saya', () => {
      pergi('nasihat', true)
      hantarTeks(task ? `Bantu saya mulakan tugasan ini: ${task.teks}` : 'Berdasarkan profil dan matlamat saya, apakah satu fokus paling berguna hari ini?', false)
    }))
    if (summary.hasil !== null) host.append(text('p', `Hasil direkod minggu ini: RM${summary.hasil}. ${summary.kos === null ? 'Kos belum direkod.' : `Kos direkod: RM${summary.kos}.`}`))
    else host.append(text('p', 'Belum ada hasil direkod untuk minggu ini.'))
    actionRow.append(button(task ? 'Buka pelan' : 'Lengkapkan ingatan', () => pergi(task ? 'pelan' : 'profil', true)))
    host.append(actionRow)
  }
  async function refresh(force = false) {
    if (!sesiAkaun() || !akaunId) return
    const owner = akaunId, seq = ++generation
    try {
      const r = await mintaAkaun('/api/akaun/copilot/konteks')
      if (owner !== akaunId || seq !== generation) return
      if (sesiTamat(r.status)) return
      if (r.status !== 200 || !r.data.ok) throw new Error()
      context = r.data; showMemory(context.ingatan, force); focus(context)
      if (force) $('ingatan-status').textContent = 'Ingatan akaun dimuat semula.'
    } catch {
      if (owner !== akaunId || seq !== generation) return
      $('fokus-hari').replaceChildren(text('p', 'Fokus belum dapat dimuat. Cuba lagi.'), button('Muat semula', () => refresh()))
      $('ingatan-status').textContent = 'Ingatan belum dapat dimuat. Draf anda dikekalkan.'
    }
  }
  async function saveMemory(event) {
    event.preventDefault()
    if (memoryVersion === null) return
    const owner = akaunId
    const data = Object.fromEntries(fields.map(key => [key, $(`ingatan-${key}`).value.trim()]))
    $('ingatan-simpan').disabled = true
    try {
      const r = await mintaAkaun('/api/akaun/copilot/ingatan', { method: 'PUT', badan: { ingatan: data, versi: memoryVersion } })
      if (owner !== akaunId || sesiTamat(r.status)) return
      if (r.status !== 200) throw new Error(r.data.error || 'Ingatan belum disimpan.')
      showMemory(r.data, true); $('ingatan-status').textContent = 'Ingatan disimpan. Jawapan seterusnya akan menggunakan butiran ini.'
      await refresh()
    } catch (e) { if (owner === akaunId) $('ingatan-status').textContent = e.message || 'Sambungan terputus. Cuba simpan lagi.' }
    finally { if (owner === akaunId) $('ingatan-simpan').disabled = false }
  }
  async function proposal(item, host) {
    if (!/^[a-f0-9]{32}$/.test(item.cadangan_id || '')) return
    const owner = akaunId, key = `${owner}:${item.cadangan_id}`
    let data = proposalCache.get(key)
    try {
      if (!data) {
        const r = await mintaAkaun(`/api/akaun/copilot/tindakan/${item.cadangan_id}`)
        if (owner !== akaunId || !host.isConnected || r.status !== 200) return
        data = r.data; proposalCache.set(key, data)
      }
      if (!data.cadangan?.length || owner !== akaunId) return
      const card = document.createElement('section'); card.className = 'kad-cadangan'
      card.append(text('h3', 'Semak sebelum simpan'))
      data.cadangan.forEach(p => card.append(text('p', p.jenis === 'angka'
        ? `${p.kategori === 'hasil' ? 'Hasil' : 'Kos'} RM${p.nilai} · ${p.tarikh} — ${p.teks}`
        : `Tugasan minggu ${p.tarikh} — ${p.teks}`)))
      const note = text('p', data.disimpan ? 'Sudah disimpan dalam akaun.' : 'Cadangan ini belum mengubah rekod anda.'); note.setAttribute('role', 'status')
      card.append(note)
      if (!data.disimpan) {
        const confirm = button('Sahkan & simpan', async () => {
          if (confirm.disabled) return
          confirm.disabled = true
          try {
            const r = await mintaAkaun('/api/akaun/copilot/tindakan', { method: 'POST', badan: { cadangan_id: item.cadangan_id, sahkan: true } })
            if (owner !== akaunId || sesiTamat(r.status)) return
            if (r.status !== 200 || !r.data.ok) throw new Error(r.data.error || 'Tindakan belum disimpan.')
            data.disimpan = true; note.textContent = 'Sudah disimpan dalam akaun.'
            confirm.remove(); cancel.remove(); await muatMeja()
          } catch (e) { note.textContent = e.message || 'Sambungan terputus. Tekan sekali lagi untuk semak simpanan.'; confirm.disabled = false }
        })
        confirm.classList.add('butang-utama')
        const cancel = button('Tutup cadangan', () => card.remove())
        card.append(confirm, cancel)
      }
      host.append(card)
    } catch { if (owner === akaunId && host.isConnected) host.append(text('p', 'Cadangan belum dapat dimuat. Muat semula untuk menyemaknya.')) }
  }
  function decorate(item, host, actions, summaryOnly = false) {
    if (item.dari !== 'penasihat') return
    BeshareHasil.decorate(item, host, actions)
    const full = [item.teks, ...(item.langkah || [])].join('\n')
    actions.append(button('Salin', async e => {
      const control = e.currentTarget
      try { await navigator.clipboard.writeText(full); control.textContent = 'Disalin' }
      catch { status.textContent = 'Pilih teks jawapan untuk menyalinnya.' }
    }))
    if (!summaryOnly) actions.append(button('Ringkaskan', () => hantarTeks('Ringkaskan jawapan berikut kepada dua ayat sahaja:\n' + item.teks.slice(0, 3300), false)))
    if (item.format === 'pemasaran') {
      actions.append(button('Tambah ke pelan', async e => {
        const control = e.currentTarget; control.disabled = true
        try { await tambahTugas(`Semak dan terbitkan draf pemasaran: ${item.teks.replace(/\s+/g, ' ').slice(0, 115)}`, null) }
        finally { control.disabled = false }
      }))
      actions.append(button('Nada lebih santai', () => hantarTeks('Tulis semula draf berikut dengan nada santai. Kekalkan fakta dan harga:\n' + item.teks.slice(0, 3300), false)))
      actions.append(button('Sunting draf', () => {
        const editor = document.createElement('textarea'); editor.className = 'editor-pemasaran'; editor.value = item.teks
        editor.setAttribute('aria-label', 'Sunting draf pemasaran'); editor.maxLength = 4000
        const copy = button('Salin draf suntingan', async () => { try { await navigator.clipboard.writeText(editor.value); copy.textContent = 'Disalin' } catch { editor.select() } })
        host.append(editor, copy, button('Simpan draf suntingan', () => BeshareHasil.newDraft(editor.value))); editor.focus()
      }))
    }
    // Defer until the bubble has been attached to the document.
    queueMicrotask(() => proposal(item, host))
  }
  function voiceState(state, message) {
    $('suara-panel').dataset.state = state
    const labels = {
      idle: ['SEDIA UNTUK BERBUAL', 'Idea bermula dengan perbualan.'],
      connecting: ['MENYAMBUNG', 'Sebentar, kita sambungkan.'],
      active: ['SESI SUARA AKTIF', 'Mari berbual.'],
      ended: ['SESI TAMAT', 'Sambung bila anda bersedia.'],
      error: ['SAMBUNGAN TERGANGGU', 'Jom cuba sekali lagi.'],
    }
    const [label, title] = labels[state] || labels.idle
    $('suara-keadaan').textContent = label; $('suara-tajuk').textContent = title
    if (message) $('suara-status').textContent = message
  }
  function openVoice() {
    if (!keadaan || !sesiAkaun()) return
    const panel = $('suara-panel')
    if (!panel.open) panel.showModal()
  }
  function closeVoicePanel() {
    if (voice || starting) closeVoice()
    if ($('suara-panel').open) $('suara-panel').close()
  }
  // Prime this same media element inside the Start click, before requesting microphone access.
  function enableVoicePlayback() {
    const audio = $('audio-bijak')
    audio.src = 'data:audio/wav;base64,UklGRnQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YVAAAACAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgA=='
    audio.play().catch(() => {})
  }
  function releaseClip(speech) {
    if (!speech.url) return
    const audio = $('audio-bijak')
    audio.onended = null
    audio.onerror = null
    audio.pause()
    audio.removeAttribute('src')
    audio.load()
    URL.revokeObjectURL(speech.url)
    speech.url = null
  }
  function stopVoiceAudio(current) {
    const speech = current.speech
    if (!speech) return
    current.speech = null
    speech.abort.abort()
    releaseClip(speech)
    $('suara-main').hidden = true
  }
  function audioAction(label, message) {
    $('suara-main').textContent = label
    $('suara-main').hidden = false
    $('suara-status').textContent = message
  }
  async function playVoiceAudio(current, speech) {
    const url = speech.url
    try {
      await $('audio-bijak').play()
      if (voice === current && current.speech === speech && speech.url === url) {
        $('suara-main').hidden = true
        $('suara-status').textContent = 'Copilot sedang menjawab…'
      }
    } catch {
      if (voice === current && current.speech === speech && speech.url === url) {
        audioAction('Dengar audio Copilot', 'Tekan Dengar audio Copilot untuk mendengar jawapan.')
      }
    }
  }
  async function fetchVoiceClip(current, speech, text) {
    try {
      const response = await fetch(SUARA_API, {
        method: 'POST', headers: current.headers,
        body: JSON.stringify({ jawapan: text, langkah: [] }), signal: speech.abort.signal,
      })
      if (!response.ok) return { status: response.status }
      const data = await response.json()
      if (data.audioProvider !== 'elevenlabs' || data.mime !== 'audio/mpeg' || typeof data.audio !== 'string' || !data.audio) return {}
      const bytes = Uint8Array.from(atob(data.audio), char => char.charCodeAt(0))
      return { blob: new Blob([bytes], { type: data.mime }) }
    } catch { return {} }
  }
  async function nextVoiceClip(current, speech) {
    if (voice !== current || current.speech !== speech || speech.busy) return
    const clip = speech.clips[0]
    if (!clip) {
      if (speech.complete) {
        stopVoiceAudio(current)
        $('suara-status').textContent = voiceReady
      } else $('suara-status').textContent = 'Copilot sedang menyediakan sambungan jawapan…'
      return
    }
    speech.busy = true
    $('suara-main').hidden = true
    $('suara-status').textContent = 'Menyediakan suara Copilot…'
    const { blob, status } = await clip.result
    if (voice !== current || current.speech !== speech) return
    const failed = () => {
      releaseClip(speech)
      clip.result = null
      speech.busy = false
      audioAction('Cuba semula audio', status === 429 ? 'Had suara sementara dicapai. Tunggu seminit sebelum mencuba lagi.'
        : 'Audio belum dapat dimainkan. Cuba semula atau baca transkrip.')
    }
    if (!blob) { failed(); return }
    const audio = $('audio-bijak')
    speech.url = URL.createObjectURL(blob)
    const sameClip = () => voice === current && current.speech === speech && speech.clips[0] === clip
    audio.onerror = () => { if (sameClip()) failed() }
    audio.onended = () => {
      if (!sameClip() || !audio.ended) return
      releaseClip(speech)
      speech.clips.shift()
      speech.busy = false
      void nextVoiceClip(current, speech)
    }
    audio.src = speech.url
    await playVoiceAudio(current, speech)
  }
  function queueVoiceText(current, reply, text, complete) {
    if (!reply.speech) {
      stopVoiceAudio(current)
      current.speech = reply.speech = { abort: new AbortController(), clips: [], busy: false }
    }
    const speech = reply.speech
    // The speech endpoint accepts at most 4,000 UTF-16 units per request.
    let rest = text.trim()
    while (rest) {
      let end = Math.min(4000, rest.length)
      if (end < rest.length) {
        const space = rest.lastIndexOf(' ', end)
        if (space > 0) end = space
        else if (/[\uD800-\uDBFF]/.test(rest[end - 1])) end--
      }
      const part = rest.slice(0, end)
      speech.clips.push({ text: part, result: fetchVoiceClip(current, speech, part) })
      rest = rest.slice(end).trimStart()
    }
    speech.complete = complete
    // A failed clip waits for an explicit retry, even if the rest of the text arrives.
    if (!speech.clips.length || speech.clips[0].result) void nextVoiceClip(current, speech)
  }
  function appendVoiceTurn(current, user, transcript) {
    const line = document.createElement('article'); line.className = 'baris-transkrip'
    current.entries.push({ speaker: user ? 'user' : 'assistant', text: transcript })
    line.append(text('strong', user ? 'Anda' : 'Copilot'), text('p', transcript))
    if (user) line.append(button('Jadikan arahan dalam sembang', () => {
      closeVoicePanel(); tulis.value = transcript.slice(0, 600); tulis.focus()
      status.textContent = 'Semak transkrip, kemudian tekan Hantar. Tindakan akan memerlukan pengesahan.'
    }))
    $('suara-transkrip').append(line)
    $('suara-catatan').hidden = false; $('suara-bilangan').textContent = `${current.entries.length} mesej`
    $('suara-salin').textContent = 'Salin transkrip'
    if ($('suara-catatan').open) line.scrollIntoView({ block: 'nearest' })
  }
  function handleVoiceEvent(current, data) {
    if (data.type === 'error') {
      if (data.error?.code === 'session_expired') { closeVoice('Sesi suara telah tamat. Anda boleh mulakan sesi baharu.'); return }
      $('suara-status').textContent = 'Permintaan suara terganggu. Anda boleh terus bercakap.'
      return
    }
    if (data.type === 'input_audio_buffer.speech_started') {
      current.userSpeaking = true
      current.reply = null
      stopVoiceAudio(current)
      $('suara-status').textContent = 'Copilot sedang mendengar…'
    } else if (data.type === 'input_audio_buffer.speech_stopped') {
      current.userSpeaking = false
    } else if (data.type === 'conversation.item.input_audio_transcription.completed') {
      const transcript = typeof data.transcript === 'string' && data.transcript.trim()
      const id = data.item_id || data.event_id
      if (!transcript || current.seen.has(id)) return
      current.seen.add(id)
      appendVoiceTurn(current, true, transcript)
    } else if (data.type === 'response.created' && !current.userSpeaking && data.response?.id) {
      current.reply = { id: data.response.id, parts: new Map(), prefix: '', delta: '' }
    } else if (data.type === 'response.output_text.delta' && current.reply && current.reply.id === data.response_id
      && data.output_index === 0 && data.content_index === 0 && typeof data.delta === 'string' && !current.reply.prefix) {
      const reply = current.reply
      reply.delta += data.delta
      // Start the first complete sentence of a longer answer while the model writes the rest.
      const first = reply.delta.match(/^([\s\S]{24,300}?[.!?])\s+(?=\S[\s\S]{11})/u)
      if (first) {
        reply.prefix = first[1]
        queueVoiceText(current, reply, reply.prefix, false)
      }
    } else if (data.type === 'response.output_text.done' && current.reply && current.reply.id === data.response_id && typeof data.text === 'string') {
      current.reply.parts.set(`${data.output_index}:${data.content_index}`, data.text)
    } else if (data.type === 'response.done' && current.reply && current.reply.id === data.response?.id) {
      const reply = current.reply
      current.reply = null
      const finalParts = (data.response.output || []).filter(item => item.type === 'message' && item.role === 'assistant')
        .flatMap(item => (item.content || []).filter(part => part.type === 'output_text').map(part => part.text))
      const transcript = (finalParts.length ? finalParts : [...reply.parts.values()]).join('\n').trim()
      if (data.response.status !== 'completed' || !transcript) {
        if (reply.speech) stopVoiceAudio(current)
        if (!current.userSpeaking) $('suara-status').textContent = voiceReady
        return
      }
      appendVoiceTurn(current, false, transcript)
      const prefix = reply.prefix.trim()
      if (prefix && !transcript.startsWith(prefix)) {
        stopVoiceAudio(current)
        reply.speech = null
      }
      queueVoiceText(current, reply, reply.speech ? transcript.slice(prefix.length) : transcript, true)
    }
  }
  function closeVoice(message = 'Perbualan suara ditamatkan.', state = 'ended', suppressSummary = false) {
    voiceGeneration += 1; starting = false
    const current = voice; voice = null
    if (current) {
      if (!suppressSummary && current.owner === akaunId) BeshareHasil.offerVoice(current.entries, state === 'error')
      current.abort?.abort(); clearTimeout(current.timer); clearTimeout(current.disconnectTimer)
      current.media?.getTracks().forEach(t => t.stop()); current.channel?.close(); current.peer?.close()
      stopVoiceAudio(current)
      if (current.id) fetch(`${NASIHAT}/api/copilot/suara`, { method: 'POST', headers: current.headers,
        body: JSON.stringify({ henti: current.id }), keepalive: true }).catch(() => {})
    }
    $('suara-mula').disabled = false; $('suara-mula').hidden = false
    if (document.activeElement === $('suara-henti')) $('suara-mula').focus()
    $('suara-henti').disabled = true; $('suara-henti').hidden = true; $('suara-main').hidden = true
    voiceState(state, message)
  }
  async function startVoice() {
    if (voice || starting || !keadaan || !sesiAkaun()) return
    if (sibuk) { voiceState('idle', 'Copilot sedang menyiapkan jawapan teks. Mulakan suara selepas jawapan diterima.'); return }
    if (!window.RTCPeerConnection || !navigator.mediaDevices?.getUserMedia) { voiceState('error', 'Pelayar ini belum menyokong perbualan suara. Gunakan sembang teks.'); return }
    starting = true; const seq = ++voiceGeneration; const owner = akaunId
    $('suara-mula').disabled = true; $('suara-mula').hidden = true
    $('suara-henti').disabled = false; $('suara-henti').hidden = false; $('suara-henti').focus()
    voiceState('connecting', 'Membuka mikrofon…'); $('suara-transkrip').replaceChildren()
    $('suara-catatan').hidden = true; $('suara-catatan').open = false; $('suara-salin').textContent = 'Salin transkrip'
    const current = { owner, entries: [], seen: new Set(), headers: kepalaAkaun(), abort: new AbortController() }; voice = current
    const alive = () => seq === voiceGeneration && owner === akaunId && voice === current
    try {
      enableVoicePlayback()
      const media = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } })
      if (!alive()) { media.getTracks().forEach(t => t.stop()); return }
      current.media = media
      const peer = new RTCPeerConnection(); current.peer = peer
      media.getTracks().forEach(track => peer.addTrack(track, media))
      const channel = peer.createDataChannel('oai-events'); current.channel = channel
      channel.addEventListener('open', () => { if (alive()) voiceState('active', voiceReady) })
      channel.addEventListener('message', event => {
        if (!alive()) return
        let data; try { data = JSON.parse(event.data) } catch { return }
        if (data && typeof data === 'object') handleVoiceEvent(current, data)
      })
      channel.addEventListener('close', () => { if (alive()) closeVoice('Sambungan suara ditutup.') })
      peer.addEventListener('connectionstatechange', () => {
        if (!alive()) return
        if (peer.connectionState === 'disconnected') {
          if (current.disconnectTimer) return
          current.statusBeforeDisconnect = $('suara-status').textContent
          voiceState('connecting', 'Rangkaian terganggu seketika. Sedang menyambung semula…')
          current.disconnectTimer = setTimeout(() => {
            if (alive() && peer.connectionState === 'disconnected') closeVoice('Sambungan suara terputus. Cuba semula.', 'error')
          }, 12000)
        } else if (peer.connectionState === 'failed' || peer.connectionState === 'closed') {
          closeVoice('Sambungan suara terputus. Cuba semula.', 'error')
        } else if (peer.connectionState === 'connected' && current.disconnectTimer) {
          clearTimeout(current.disconnectTimer)
          current.disconnectTimer = null
          if (channel.readyState === 'open') {
            const restore = $('suara-status').textContent === 'Rangkaian terganggu seketika. Sedang menyambung semula…'
            voiceState('active', restore ? current.statusBeforeDisconnect || voiceReady : undefined)
          }
        }
      })
      const offer = await peer.createOffer(); await peer.setLocalDescription(offer)
      if (!alive()) return
      voiceState('connecting', 'Menyambung kepada Copilot…')
      const response = await fetch(`${NASIHAT}/api/copilot/suara`, { method: 'POST', headers: current.headers,
        body: JSON.stringify({ sdp: offer.sdp, audioProvider: 'elevenlabs' }), signal: current.abort.signal })
      const data = await response.json()
      if (!response.ok || !data.ok) throw new Error(data.error || 'Suara belum dapat disambungkan.')
      current.id = data.sesi
      if (!alive()) { fetch(`${NASIHAT}/api/copilot/suara`, { method: 'POST', headers: current.headers, body: JSON.stringify({ henti: data.sesi }) }).catch(() => {}); return }
      if (data.audioProvider !== 'elevenlabs') throw new Error('Suara ElevenLabs belum tersedia. Muat semula halaman atau cuba lagi nanti.')
      await peer.setRemoteDescription({ type: 'answer', sdp: data.sdp })
      if (!alive()) return
      current.timer = setTimeout(() => closeVoice('Had lima minit dicapai. Anda boleh mulakan sesi baharu.'), data.had_saat * 1000)
    } catch (e) {
      if (alive()) closeVoice(e.name === 'NotAllowedError' ? 'Mikrofon belum dibenarkan. Benarkan mikrofon untuk bercakap.' : e.message || 'Suara belum dapat disambungkan.', 'error')
    } finally { if (alive()) starting = false }
  }
  function reset() {
    closeVoice(undefined, 'ended', true); closeVoicePanel(); generation += 1; context = null; memoryVersion = null; dirty = false; proposalCache.clear()
    fields.forEach(key => { $(`ingatan-${key}`).value = '' })
    $('ingatan-simpan').disabled = true; $('suara-transkrip').replaceChildren(); $('suara-catatan').hidden = true; $('fokus-hari').replaceChildren()
    voiceState('idle', 'Ceritakan apa yang anda fikirkan. Copilot sedia membantu.')
  }
  function init() {
    $('ingatan-borang').addEventListener('input', () => { dirty = true })
    $('ingatan-borang').addEventListener('submit', saveMemory)
    $('ingatan-muat').addEventListener('click', () => refresh(true))
    $('suara-buka').addEventListener('click', openVoice)
    $('suara-tutup').addEventListener('click', closeVoicePanel)
    $('suara-panel').addEventListener('cancel', event => { event.preventDefault(); closeVoicePanel() })
    $('suara-panel').addEventListener('close', () => { if (voice || starting) closeVoice() })
    // Only a complete click on the backdrop dismisses the sheet, not a drag from its contents.
    let backdropDown = false
    $('suara-panel').addEventListener('pointerdown', event => { backdropDown = event.target === $('suara-panel') })
    $('suara-panel').addEventListener('click', event => { if (backdropDown && event.target === $('suara-panel')) closeVoicePanel(); backdropDown = false })
    $('suara-mula').addEventListener('click', startVoice)
    $('suara-henti').addEventListener('click', () => closeVoice())
    $('suara-main').addEventListener('click', () => {
      const current = voice
      const speech = current?.speech
      if (!speech) return
      if (speech.url) { void playVoiceAudio(current, speech); return }
      const clip = speech.clips[0]
      if (clip && !clip.result) {
        enableVoicePlayback()
        clip.result = fetchVoiceClip(current, speech, clip.text)
        void nextVoiceClip(current, speech)
      }
    })
    $('suara-salin').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('suara-transkrip').innerText); $('suara-salin').textContent = 'Transkrip disalin' } catch { $('suara-status').textContent = 'Pilih transkrip untuk menyalin.' } })
    window.addEventListener('pagehide', () => closeVoice())
    window.addEventListener('storage', e => { if (e.key === AKAUN_KUNCI) { reset(); location.reload() } })
    document.addEventListener('visibilitychange', () => { if (document.hidden && voice) closeVoice('Suara dihentikan apabila aplikasi ditinggalkan.') })
  }
  return { init, refresh, decorate, reset, openVoice, closeVoicePanel, startVoice, closeVoice }
})()
