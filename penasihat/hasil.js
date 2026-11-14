/* Saved work, guided starts and voice review. Account data stays off localStorage. */
const BeshareHasil = (() => {
  const $ = id => document.getElementById(id), base = '/api/akaun/copilot/hasil'
  let epoch = 0, loadSeq = 0, draftSeq = 0, reviewSeq = 0, owner = null
  let drafts = [], selected = null, draftDirty = false, draftConflict = null, draftSaving = false
  let review = null, reviewDirty = false, reviewConflict = null, reviewSaving = false, reviews = []
  let startKind = 'pemasaran', voiceAbort = null, voiceKey = null, voiceInput = '', voiceResult = null, voiceSeq = 0
  const ids = () => crypto.randomUUID().replaceAll('-', '')
  const live = (who, version) => who && who === akaunId && version === epoch && sesiAkaun()
  const note = (id, message) => { $(id).textContent = message }
  const node = (tag, value) => { const el = document.createElement(tag); el.textContent = value; return el }
  const button = (label, run) => { const el = node('button', label); el.type = 'button'; el.className = 'butang butang-senyap'; el.addEventListener('click', run); return el }
  function open(id) { if (sesiAkaun() && !$(id).open) $(id).showModal() }
  async function request(path, options) {
    const r = await mintaAkaun(base + path, options)
    return r
  }
  function readDraft() {
    return { versi: selected.versi, jenis: selected.jenis, tajuk: $('hasil-editor-tajuk').value.trim(), saluran: $('hasil-editor-saluran').value,
      status: $('hasil-editor-status').value, kandungan: $('hasil-editor-kandungan').value.trim() }
  }
  function showDraft(item) {
    selected = structuredClone(item); draftDirty = false; draftConflict = null
    $('hasil-editor-kosong').hidden = true; $('hasil-editor').hidden = false; $('hasil-editor-konflik').hidden = true
    ;['tajuk','saluran','status','kandungan'].forEach(k => { $('hasil-editor-' + k).value = item[k] })
    note('hasil-editor-jenis', item.jenis === 'suara' ? 'RINGKASAN SUARA' : 'DRAF PEMASARAN')
    note('hasil-editor-nota', item.versi ? 'Disimpan dalam akaun. Suntingan seterusnya perlu disimpan.' : 'Draf ini belum disimpan dalam akaun.')
    renderList()
  }
  function canSwitch() {
    if (draftSaving) { note('hasil-editor-nota', 'Tunggu simpanan selesai dahulu.'); return false }
    if (draftDirty || (selected && !selected.versi)) { note('hasil-editor-nota', 'Simpan atau pilih Batalkan suntingan sebelum membuka draf lain.'); return false }
    return true
  }
  function newDraft(content = '', kind = 'pemasaran') {
    open('hasil-koleksi-dialog'); loadDrafts()
    if (!canSwitch()) return
    showDraft({ id: ids(), versi: 0, jenis: kind, tajuk: kind === 'suara' ? 'Ringkasan sesi suara' : (content.split('\n').find(x => x.trim()) || 'Promosi baharu').slice(0,100),
      saluran: kind === 'suara' ? 'Lain' : 'WhatsApp', status: 'draf', kandungan: content })
    draftDirty = true; $('hasil-editor-tajuk').focus()
  }
  async function loadDrafts() {
    if (!sesiAkaun()) return
    const who = akaunId, version = epoch, seq = ++loadSeq
    note('hasil-koleksi-status', 'Memuat koleksi…')
    try {
      const r = await request('/draf')
      if (!live(who, version) || seq !== loadSeq || sesiTamat(r.status)) return
      if (r.status !== 200 || !r.data.ok) throw Error(r.data.error || 'Koleksi belum dapat dimuat.')
      drafts = r.data.draf; renderList(); note('hasil-koleksi-status', `${drafts.length} / 200 simpanan dalam akaun.`)
    } catch(e) { if (live(who, version) && seq === loadSeq) note('hasil-koleksi-status', e.message || 'Sambungan terputus. Cuba muat semula.') }
  }
  function renderList() {
    const list = $('hasil-draf-senarai'); list.replaceChildren()
    const search = $('hasil-cari').value.toLocaleLowerCase(), filter = $('hasil-tapis').value
    const rows = drafts.filter(d => (filter === 'arkib' ? d.status === 'arkib' : d.status !== 'arkib' && (filter === 'aktif' || d.jenis === filter || d.status === filter))
      && `${d.tajuk} ${d.ringkasan}`.toLocaleLowerCase().includes(search))
    rows.forEach(d => {
      const li = document.createElement('li'), control = button('', () => selectDraft(d.id))
      control.className = 'hasil-draf-pilih'; control.setAttribute('aria-current', String(selected?.id === d.id))
      control.append(node('strong', d.tajuk), node('span', `${d.jenis === 'suara' ? 'Suara' : d.saluran} · ${d.status === 'digunakan' ? 'Sudah digunakan' : d.status === 'arkib' ? 'Arkib' : 'Draf'}`), node('span', d.ringkasan))
      li.append(control); list.append(li)
    })
    if (!rows.length) list.append(node('li', 'Belum ada simpanan dalam paparan ini.'))
  }
  async function selectDraft(id) {
    if (!canSwitch()) return
    const who = akaunId, version = epoch, seq = ++draftSeq
    try {
      const r = await request('/draf/' + id)
      if (!live(who, version) || seq !== draftSeq || sesiTamat(r.status)) return
      if (r.status !== 200) throw Error(r.data.error || 'Draf belum dapat dimuat.')
      // A new unsaved draft may have been opened while this request was pending.
      if (!canSwitch()) return
      showDraft(r.data.draf)
    } catch(e) { if (live(who, version) && seq === draftSeq) note('hasil-koleksi-status', e.message || 'Cuba muat semula.') }
  }
  async function saveDraft(event) {
    event?.preventDefault()
    if (!selected || draftSaving || !$('hasil-editor').reportValidity()) return
    const who = akaunId, version = epoch, id = selected.id, data = readDraft()
    draftSaving = true; $('hasil-editor-simpan').disabled = true
    note('hasil-editor-nota', 'Menyimpan…')
    try {
      const r = await request('/draf/' + id, { method: 'PUT', badan: data })
      if (!live(who, version) || sesiTamat(r.status)) return
      if (r.status === 409 && r.data.draf) {
        draftConflict = r.data.draf; $('hasil-editor-konflik').hidden = false
        note('hasil-editor-perbandingan', `${draftConflict.tajuk}\n${draftConflict.kandungan}`)
      }
      if (r.status !== 200 || !r.data.ok) throw Error(r.data.error || 'Draf belum disimpan.')
      const unchanged = JSON.stringify(readDraft()) === JSON.stringify(data)
      if (unchanged) showDraft(r.data.draf)
      else { selected = r.data.draf; draftDirty = true; note('hasil-editor-nota', 'Versi tadi disimpan. Suntingan semasa masih belum disimpan.') }
      if (unchanged) note('hasil-editor-nota', 'Disimpan dalam akaun. Anda boleh buka semula pada peranti lain.')
      await loadDrafts()
    } catch(e) { if (live(who, version)) note('hasil-editor-nota', e.message || 'Sambungan terputus. Draf masih ada; tekan Simpan untuk mencuba semula.') }
    finally { if (live(who, version)) { draftSaving = false; $('hasil-editor-simpan').disabled = false } }
  }
  function emptyReview(week) { return { minggu: week, versi: 0, dibuat: '', hasil: '', halangan: '', tugasan_id: '' } }
  function readReview() {
    return { versi: review.versi, dibuat: $('hasil-semakan-dibuat').value.trim(), hasil: $('hasil-semakan-hasil').value.trim(),
      halangan: $('hasil-semakan-halangan').value.trim(), tugasan_id: $('hasil-semakan-tugas').value }
  }
  function tasks(initial) {
    if (!review) return
    const select = $('hasil-semakan-tugas'), value = typeof initial === 'string' ? initial : select.value
    select.replaceChildren(new Option('Keseluruhan minggu ini', ''))
    if (pelanDimuat && pelanAkaun?.minggu === review.minggu) (pelanAkaun.tugasan || []).forEach(t => select.add(new Option(`${t.siap ? '✓ ' : ''}${t.teks}`, t.id)))
    if (value && ![...select.options].some(o => o.value === value)) select.add(new Option('Tugasan asal (sila semak jika sudah dipadam)', value))
    select.value = value
  }
  function showReview(item) {
    review = structuredClone(item); reviewDirty = false; reviewConflict = null
    ;['dibuat','hasil','halangan'].forEach(k => { $('hasil-semakan-' + k).value = item[k] })
    tasks(item.tugasan_id)
    $('hasil-semakan-konflik').hidden = true
    note('hasil-semakan-minggu', `Minggu bermula ${item.minggu} · waktu Malaysia`)
    note('hasil-semakan-ringkas', item.versi ? 'Semakan minggu ini sudah disimpan. Boleh kemas kini apabila ada perkembangan.' : 'Catat kemajuan, hasil dan halangan anda.')
  }
  async function refresh(force = false) {
    if (!sesiAkaun() || !akaunId) return
    if (owner && owner !== akaunId) reset()
    owner = akaunId
    const who = owner, version = epoch, seq = ++reviewSeq
    try {
      const r = await request('/semakan')
      if (!live(who, version) || seq !== reviewSeq || sesiTamat(r.status)) return
      if (r.status !== 200 || !r.data.ok) throw Error(r.data.error || 'Semakan belum dapat dimuat.')
      reviews = r.data.semakan
      const current = reviews.find(x => x.minggu === mingguSemasa().isnin) || emptyReview(mingguSemasa().isnin)
      if (!reviewSaving && (!reviewDirty || force)) showReview(current)
      else if (review && review.minggu !== current.minggu) note('hasil-semakan-status', `Draf ini untuk minggu ${review.minggu}. Simpan dahulu sebelum memuat minggu baharu.`)
      tasks(); renderReviews()
      if (force) note('hasil-semakan-status', 'Salinan akaun dimuat.')
    } catch(e) { if (live(who, version) && seq === reviewSeq) note('hasil-semakan-status', e.message || 'Semakan belum dimuat. Cuba lagi.') }
  }
  function renderReviews() {
    const host = $('hasil-semakan-sejarah'); host.replaceChildren()
    reviews.filter(x => x.minggu !== review?.minggu).forEach(item => {
      const detail = document.createElement('details'); detail.className = 'hasil-sejarah'; detail.append(node('summary', `Minggu ${item.minggu}`), node('p', HasilCore.reviewText(item))); host.append(detail)
    })
  }
  async function saveReview(event) {
    event?.preventDefault()
    if (!review || reviewSaving || !$('hasil-semakan-form').reportValidity()) return false
    const who = akaunId, version = epoch, week = review.minggu, data = readReview()
    reviewSaving = true; $('hasil-semakan-simpan').disabled = true
    note('hasil-semakan-status', 'Menyimpan semakan…')
    try {
      const r = await request('/semakan/' + week, { method: 'PUT', badan: data })
      if (!live(who, version) || sesiTamat(r.status)) return false
      if (r.status === 409 && r.data.semakan) {
        reviewConflict = r.data.semakan; $('hasil-semakan-konflik').hidden = false
        note('hasil-semakan-perbandingan', HasilCore.reviewText(reviewConflict))
      }
      if (r.status !== 200 || !r.data.ok) throw Error(r.data.error || 'Semakan belum disimpan.')
      const unchanged = JSON.stringify(readReview()) === JSON.stringify(data)
      if (unchanged) showReview(r.data.semakan)
      else { review = r.data.semakan; reviewDirty = true }
      note('hasil-semakan-status', unchanged ? 'Semakan disimpan. Copilot akan menggunakannya dalam nasihat seterusnya.' : 'Versi tadi disimpan. Suntingan semasa belum disimpan.')
      BeshareBijak.refresh(); return unchanged
    } catch(e) { if (live(who, version)) note('hasil-semakan-status', e.message || 'Sambungan terputus. Cuba simpan lagi.'); return false }
    finally { if (live(who, version)) { reviewSaving = false; $('hasil-semakan-simpan').disabled = false } }
  }
  function offerVoice(entries, interrupted = false) {
    if (!sesiAkaun() || !entries?.length) return
    // Keep the previous result until a new session finishes; closing dialogs does not discard it.
    voiceAbort?.abort(); voiceAbort = null; $('hasil-suara-transkrip').readOnly = false; voiceSeq++; voiceKey = null; voiceResult = null; voiceInput = ''
    $('hasil-suara-transkrip').value = entries.map(x => `${x.speaker === 'user' ? 'Anda' : 'Copilot'}: ${x.text}`).join('\n\n')
    $('hasil-suara-jawapan').replaceChildren(); $('hasil-suara-jana').disabled = false
    note('hasil-suara-status', interrupted ? 'Sesi terganggu. Semak bahagian yang sempat ditranskripsikan sebelum menjana.' : 'Transkrip sementara tersedia. Semak sebelum menjana ringkasan.')
    $('hasil-suara-buka').hidden = false; $('hasil-suara-sambung').hidden = false
  }
  function openVoiceReview() { BeshareBijak.closeVoicePanel(); open('hasil-suara-dialog') }
  async function generateVoice() {
    if (!sesiAkaun() || voiceAbort) return
    const transcript = $('hasil-suara-transkrip').value
    let turns
    try { turns = HasilCore.voiceTurns(transcript) } catch(e) { note('hasil-suara-status', e.message); return }
    const who = akaunId, version = epoch, seq = ++voiceSeq
    if (voiceInput !== transcript || !voiceKey) { voiceInput = transcript; voiceKey = ids() }
    const controller = new AbortController(); voiceAbort = controller
    $('hasil-suara-jana').disabled = true; $('hasil-suara-transkrip').readOnly = true
    note('hasil-suara-status', 'Menjana ringkasan untuk semakan anda…')
    const timeout = setTimeout(() => controller.abort(), 90000)
    try {
      if (!NASIHAT_API) throw Error('Perkhidmatan AI belum tersedia.')
      const response = await fetch(NASIHAT_API, { method: 'POST', headers: { ...kepalaAkaun(), 'Idempotency-Key': voiceKey }, body: JSON.stringify({ giliran: turns }), signal: controller.signal })
      const data = await response.json()
      if (!live(who, version) || seq !== voiceSeq || sesiTamat(response.status)) return
      if (!response.ok || !data.ok || data.bukan_model !== false || typeof data.jawapan !== 'string' || !['openai','tersimpan'].includes(data.sumber)) throw Error(data.error || 'Ringkasan belum dapat dijana. Cuba sekali lagi.')
      voiceResult = [data.jawapan, ...(Array.isArray(data.langkah) ? data.langkah : [])].join('\n')
      const host = $('hasil-suara-jawapan'); host.replaceChildren(node('p', voiceResult))
      const actions = document.createElement('div'); actions.className = 'hasil-aksi'; host.append(actions)
      actions.append(button('Simpan ringkasan', () => { $('hasil-suara-dialog').close(); newDraft(voiceResult, 'suara') }))
      BeshareBijak.decorate({ dari: 'penasihat', teks: data.jawapan, langkah: data.langkah, cadangan_id: data.cadangan_id, format: 'ringkas' }, host, actions)
      note('hasil-suara-status', 'Ringkasan siap. Semak fakta; cadangan tindakan hanya disimpan selepas anda sahkan.')
    } catch(e) { if (live(who, version) && seq === voiceSeq) note('hasil-suara-status', e.name === 'AbortError' ? 'Permintaan mengambil masa. Tekan Jana sekali lagi untuk menyemak hasil permintaan yang sama.' : e.message || 'Sambungan terputus. Cuba sekali lagi.') }
    finally { clearTimeout(timeout); if (live(who, version) && seq === voiceSeq) { voiceAbort = null; $('hasil-suara-jana').disabled = false; $('hasil-suara-transkrip').readOnly = false } }
  }
  function reset() {
    epoch++; loadSeq++; draftSeq++; reviewSeq++; voiceSeq++; voiceAbort?.abort(); voiceAbort = null
    owner = null; drafts = []; selected = null; draftDirty = false; draftConflict = null; draftSaving = false
    review = null; reviewDirty = false; reviewConflict = null; reviewSaving = false; reviews = []; voiceKey = null; voiceInput = ''; voiceResult = null
    document.querySelectorAll('.hasil-dialog').forEach(d => { if (d.open) d.close() })
    ;['hasil-editor','hasil-semakan-form','hasil-mula-form'].forEach(id => $(id).reset())
    $('hasil-suara-transkrip').value = ''; $('hasil-suara-transkrip').readOnly = false
    ;['hasil-draf-senarai','hasil-semakan-sejarah','hasil-suara-jawapan'].forEach(id => $(id).replaceChildren())
    ;['hasil-suara-buka','hasil-suara-sambung','hasil-editor','hasil-editor-konflik','hasil-semakan-konflik'].forEach(id => { $(id).hidden = true })
    $('hasil-editor-kosong').hidden = false
    ;['hasil-editor-simpan','hasil-semakan-simpan','hasil-suara-jana'].forEach(id => { $(id).disabled = false })
    ;['hasil-koleksi-status','hasil-editor-nota','hasil-semakan-status','hasil-semakan-minggu','hasil-suara-status','hasil-editor-perbandingan','hasil-semakan-perbandingan'].forEach(id => note(id, ''))
  }
  function decorate(item, host, actions) {
    if (item.format === 'pemasaran') actions.append(button('Simpan draf', () => newDraft([item.teks, ...(item.langkah || [])].join('\n'))))
  }
  function init() {
    document.querySelectorAll('[data-hasil-tutup]').forEach(b => b.addEventListener('click', () => $(b.dataset.hasilTutup).close()))
    document.querySelectorAll('[data-hasil-koleksi]').forEach(b => b.addEventListener('click', () => { open('hasil-koleksi-dialog'); loadDrafts() }))
    document.querySelectorAll('[data-hasil-mula]').forEach(b => b.addEventListener('click', () => {
      startKind = b.dataset.hasilMula
      const copy = { pemasaran: ['Siapkan promosi','Nyatakan satu tawaran. Copilot bantu menghasilkan draf untuk anda semak.','Apa yang mahu dipromosikan?','Jana draf pertama'],
        pelan: ['Susun minggu ini','Mulakan dengan maksimum tiga tindakan yang sesuai dengan matlamat anda.','Apa keutamaan atau kekangan masa anda?','Bantu susun pelan'],
        angka: ['Fahami angka','Copilot gunakan rekod dalam Angka. Rekod yang belum ada akan dinyatakan dengan jelas.','Apa yang mahu anda fahami?','Semak rekod saya'] }[startKind]
      ;['heading','keterangan','label','hantar'].forEach((key,i) => note('hasil-mula-' + key, copy[i]))
      $('hasil-mula-saluran-label').hidden = startKind !== 'pemasaran'; $('hasil-mula-input').value = ''; open('hasil-mula-dialog')
    }))
    $('hasil-mula-form').addEventListener('submit', e => { e.preventDefault(); if (sibuk) return; const prompt = HasilCore.startPrompt(startKind, $('hasil-mula-input').value, $('hasil-mula-saluran').value); $('hasil-mula-dialog').close(); pergi('nasihat', true); hantarTeks(prompt, false) })
    $('hasil-buka-semakan').addEventListener('click', () => { pergi('pelan', true); $('hasil-semakan-panel').open = true; $('hasil-semakan-panel').scrollIntoView({ block: 'start' }) })
    $('hasil-cari').addEventListener('input', renderList); $('hasil-tapis').addEventListener('change', renderList)
    $('hasil-draf-baru').addEventListener('click', () => newDraft()); $('hasil-draf-muat').addEventListener('click', loadDrafts)
    $('hasil-editor').addEventListener('input', () => { draftDirty = true; note('hasil-editor-nota', 'Suntingan belum disimpan.') })
    $('hasil-editor').addEventListener('submit', saveDraft)
    $('hasil-editor-batal').addEventListener('click', () => { if (draftSaving) return; if (selected?.versi) showDraft(selected); else { selected = null; draftDirty = false; $('hasil-editor').hidden = true; $('hasil-editor-kosong').hidden = false } })
    $('hasil-editor-guna').addEventListener('click', () => { if (draftConflict && !draftSaving) showDraft(draftConflict) })
    $('hasil-editor-salin').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('hasil-editor-kandungan').value); note('hasil-editor-nota', 'Kandungan disalin. Status simpanan tidak berubah.') } catch { $('hasil-editor-kandungan').focus(); $('hasil-editor-kandungan').select(); note('hasil-editor-nota', 'Teks dipilih. Salin menggunakan menu peranti anda.') } })
    $('hasil-semakan-form').addEventListener('input', () => { reviewDirty = true; note('hasil-semakan-status', 'Suntingan semakan belum disimpan.') })
    $('hasil-semakan-form').addEventListener('submit', saveReview)
    $('hasil-semakan-muat').addEventListener('click', () => { if (reviewSaving) return; if (reviewDirty) { reviewConflict = reviews.find(x => x.minggu === mingguSemasa().isnin) || emptyReview(mingguSemasa().isnin); $('hasil-semakan-konflik').hidden = false; note('hasil-semakan-perbandingan', HasilCore.reviewText(reviewConflict)); note('hasil-semakan-status', 'Draf anda dikekalkan. Pilih Guna salinan akaun untuk menggantikannya.'); } else refresh(true) })
    $('hasil-semakan-guna').addEventListener('click', () => { if (reviewConflict && !reviewSaving) { showReview(reviewConflict); note('hasil-semakan-status', 'Salinan yang dipaparkan telah dimuat.'); refresh() } })
    $('hasil-semakan-nasihat').addEventListener('click', async () => { if (sibuk || !review) return; const who = akaunId, version = epoch; if ((reviewDirty || !review.versi) && !(await saveReview())) return; if (!live(who, version)) return; pergi('nasihat', true); hantarTeks(`Berdasarkan semakan minggu ${review.minggu} yang saya simpan, bantu pilih satu langkah seterusnya. Bezakan hasil yang direkod dengan andaian. Jangan anggap perubahan jualan disebabkan satu tindakan sahaja.`, false) })
    $('hasil-suara-buka').addEventListener('click', openVoiceReview); $('hasil-suara-sambung').addEventListener('click', openVoiceReview)
    $('hasil-suara-jana').addEventListener('click', generateVoice)
    $('hasil-suara-transkrip').addEventListener('input', () => { voiceKey = null; voiceResult = null; $('hasil-suara-jawapan').replaceChildren(); note('hasil-suara-status', 'Transkrip diubah. Jana semula untuk ringkasan yang sepadan.') })
    window.addEventListener('beforeunload', e => { if (sesiAkaun() && (draftDirty || reviewDirty)) { e.preventDefault(); e.returnValue = '' } })
    window.addEventListener('storage', e => { if (e.key === 'beshare-akaun') reset() })
  }
  return { init, refresh, reset, decorate, newDraft, offerVoice, tasks }
})()
