'use strict';

const API = '/whatsapp/pemilik/pintu.php';
const state = {
  csrf: '', admin: null, filter: 'all', query: '', page: 1, pages: 1,
  selected: '', detail: null, listRevision: 0, detailRevision: 0,
  pending: null, writing: false, searchTimer: null, starting: false
};
const byId = id => document.getElementById(id);
const h = value => String(value ?? '').replace(/[&<>"']/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
})[character]);
const count = value => Number.isSafeInteger(Number(value)) && Number(value) >= 0 ? Number(value) : 0;
const formatCount = value => new Intl.NumberFormat('ms-MY').format(count(value));
const successStates = new Set(['succeeded', 'ready', 'registered', 'already_registered', 'refreshed', 'completed', 'updated', 'active', 'suspended', 'enabled', 'disabled']);
const attentionStates = new Set(['unknown', 'blocked', 'pending', 'checking', 'registering', 'in_progress']);
const readinessLabels = {
  ready: ['Sedia digunakan', 'green'], pending: ['Menunggu pengaktifan', 'amber'],
  attention: ['Perlu perhatian', 'red'], none: ['Belum disambungkan', 'neutral']
};

class ApiError extends Error {
  constructor(message, status = 0) { super(message); this.status = status; }
}

async function api(action, body = null, query = {}) {
  const params = new URLSearchParams({ tindakan: action });
  for (const [name, value] of Object.entries(query)) params.set(name, String(value));
  const options = { method: body === null ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' } };
  if (body !== null) {
    if (!state.csrf) throw new ApiError('Sesi belum tersedia. Muat semula panel.', 403);
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = state.csrf;
    options.body = JSON.stringify(body);
  }
  let response;
  try { response = await fetch(API + '?' + params.toString(), options); }
  catch { throw new ApiError(body === null ? 'Sambungan ke pelayan terputus. Cuba muat semula.' : 'Keputusan tindakan belum diterima. Kemas kini status sebelum mencuba tindakan itu semula.'); }
  let data;
  try { data = await response.json(); }
  catch { throw new ApiError(body === null ? 'Maklum balas pelayan tidak dapat dibaca.' : 'Keputusan tindakan belum dapat dipastikan. Kemas kini status sambungan dahulu.', response.status); }
  if (data && typeof data.csrf === 'string' && data.csrf) state.csrf = data.csrf;
  if (!response.ok || !data || data.ok !== true) {
    throw new ApiError(typeof data?.error === 'string' && data.error ? data.error : 'Permintaan tidak dapat diselesaikan.', response.status);
  }
  return data;
}

function badge(label, tone = 'neutral') {
  const safeTone = ['green', 'amber', 'red', 'neutral', 'violet'].includes(tone) ? tone : 'neutral';
  return `<span class="badge badge-${safeTone}">${h(label)}</span>`;
}

function readiness(value) {
  const pair = readinessLabels[value] || ['Belum dapat dipastikan', 'neutral'];
  return badge(pair[0], pair[1]);
}

function initials(value) {
  return String(value || 'B').trim().split(/\s+/).slice(0, 2).map(part => Array.from(part)[0] || '').join('').toUpperCase();
}

function dateValue(value) {
  if (typeof value !== 'string' || !value) return null;
  // All server dates are UTC. Legacy SQL datetimes are explicitly interpreted as UTC.
  const normalized = /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value) ? value.replace(' ', 'T') + 'Z' : value;
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function dateText(value, withTime = true) {
  const parsed = dateValue(value);
  if (!parsed) return 'Belum direkodkan';
  return new Intl.DateTimeFormat('ms-MY', {
    timeZone: 'Asia/Kuala_Lumpur', day: 'numeric', month: 'short', year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {})
  }).format(parsed);
}

function notice(message = '', tone = '', target = 'notice') {
  const node = byId(target);
  node.textContent = message;
  node.className = 'notice' + (['success', 'warning', 'error'].includes(tone) ? ' is-' + tone : '');
  node.hidden = !message;
}

function showDialog(node) {
  if (node.open) return;
  node.showModal();
}

function closeDetail() {
  if (state.writing) return;
  state.selected = '';
  state.detail = null;
  state.detailRevision += 1;
  byId('detailDialog').close();
}

function gate(error = null) {
  state.admin = null;
  state.csrf = '';
  state.selected = '';
  state.detail = null;
  state.pending = null;
  state.listRevision += 1;
  state.detailRevision += 1;
  byId('dashboard').hidden = true;
  byId('accessGate').hidden = false;
  byId('reloadAll').disabled = true;
  byId('gateActions').hidden = !error;
  byId('adminName').textContent = 'Pemilik sistem';
  byId('adminRole').textContent = error ? 'Akses belum disahkan' : 'Menyemak akses…';
  if (byId('confirmDialog').open) byId('confirmDialog').close();
  if (byId('detailDialog').open) byId('detailDialog').close();
  byId('gateTitle').textContent = error?.status === 401 ? 'Masuk dengan akaun pemilik'
    : error?.status === 403 ? 'Akses pemilik diperlukan'
    : error ? 'Panel belum dapat dimuatkan' : 'Menyemak akses pemilik';
  byId('gateMessage').textContent = error?.status === 401
    ? 'Gunakan akaun BeShare yang telah diberikan akses pentadbir sistem.'
    : error?.status === 403
      ? 'Akaun ini tidak mempunyai akses panel pemilik, atau sesi sudah berubah. Masuk semula dengan akaun yang dibenarkan.'
      : error ? error.message : 'Sila tunggu sebentar.';
}

function handleError(error, target = 'notice') {
  if (error.status === 401 || error.status === 403) {
    gate(error);
    return;
  }
  notice(error.message || 'Permintaan tidak dapat diselesaikan.', error.status === 0 ? 'warning' : 'error', target);
}

function setFilter(value) {
  if (!['all', 'attention', 'ready', 'suspended'].includes(value) || state.writing) return;
  state.filter = value;
  state.page = 1;
  for (const node of document.querySelectorAll('[data-filter]')) node.setAttribute('aria-pressed', String(node.dataset.filter === value));
  loadCustomers();
}

function renderSummary(summary) {
  for (const node of document.querySelectorAll('[data-metric]')) {
    node.textContent = Number.isFinite(Number(summary[node.dataset.metric])) ? formatCount(summary[node.dataset.metric]) : '—';
  }
  byId('metrics').setAttribute('aria-busy', 'false');
  byId('attentionCount').textContent = formatCount(summary.pending_connections);
}

function customerRow(customer) {
  const access = customer.access_status === 'suspended' ? badge('Digantung', 'red') : customer.access_status === 'active' ? badge('Aktif', 'green') : badge('Belum disahkan');
  const ai = customer.ai_enabled === true || customer.ai_enabled === 1 ? badge('Dihidupkan', 'violet') : badge('Dimatikan');
  const registered = customer.created_at ? 'Daftar ' + dateText(customer.created_at, false) : 'Tarikh belum direkodkan';
  return `<tr>
    <td><div class="customer-name"><span class="customer-avatar" aria-hidden="true">${h(initials(customer.name))}</span><span><strong>${h(customer.name)}</strong><small>${h(customer.owner_name || customer.owner_email || 'Pemilik belum direkodkan')}</small></span></div></td>
    <td data-label="WhatsApp"><span class="phone">${h(customer.phone || 'Tiada nombor')}</span>${readiness(customer.readiness)}</td>
    <td data-label="Akses BeShare">${access}</td>
    <td data-label="AI Auto Reply">${ai}</td>
    <td><span class="activity-date">${h(dateText(customer.last_activity))}</span><span class="activity-time">${h(registered)}</span></td>
    <td><button class="manage-button" type="button" data-open="${h(customer.public_id)}" aria-label="Urus ${h(customer.name)}"><span class="manage-label">Urus</span><span aria-hidden="true">↗</span></button></td>
  </tr>`;
}

async function loadCustomers() {
  if (!state.admin) return;
  const sequence = ++state.listRevision;
  byId('tableWrap').setAttribute('aria-busy', 'true');
  byId('listStatus').textContent = 'Memuatkan pelanggan…';
  byId('previousPage').disabled = true;
  byId('nextPage').disabled = true;
  try {
    const data = await api('pelanggan', null, { q: state.query, filter: state.filter, page: state.page });
    if (sequence !== state.listRevision || !state.admin) return;
    if (!Array.isArray(data.customers) || !data.summary || !data.pagination
      || data.customers.some(customer => !customer || typeof customer.public_id !== 'string' || typeof customer.name !== 'string')) {
      throw new ApiError('Senarai pelanggan tidak dapat dibaca. Muat semula untuk mendapatkan rekod terkini.');
    }
    renderSummary(data.summary);
    state.page = Math.max(1, count(data.pagination.page));
    state.pages = Math.max(1, count(data.pagination.pages));
    byId('customerCount').textContent = formatCount(data.pagination.total) + ' perniagaan';
    byId('customerRows').innerHTML = data.customers.map(customerRow).join('');
    byId('listStatus').innerHTML = data.customers.length ? '' : state.query || state.filter !== 'all'
      ? '<strong>Tiada pelanggan sepadan</strong>Cuba kata carian lain atau pilih penapis Semua.'
      : '<strong>Belum ada pelanggan</strong>Perniagaan yang mendaftar akan dipaparkan di sini.';
    byId('tableWrap').hidden = data.customers.length === 0;
    byId('pageInfo').textContent = 'Halaman ' + state.page + ' daripada ' + state.pages;
    byId('previousPage').disabled = state.page <= 1;
    byId('nextPage').disabled = state.page >= state.pages;
    byId('updatedAt').textContent = 'Dikemas kini ' + dateText(new Date().toISOString());
  } catch (error) {
    if (sequence !== state.listRevision) return;
    byId('customerRows').innerHTML = '';
    byId('tableWrap').hidden = true;
    byId('listStatus').textContent = 'Senarai belum tersedia. Tekan Muat semula untuk mencuba lagi.';
    handleError(error);
  } finally {
    if (sequence === state.listRevision) byId('tableWrap').setAttribute('aria-busy', 'false');
  }
}

const tokenLabels = { verified: 'Disahkan', stored: 'Menunggu pengesahan', expired: 'Tamat tempoh', revoked: 'Akses ditarik balik', error: 'Perlu perhatian', cleared: 'Sambungan ditutup' };
const webhookLabels = { subscribed: 'Dilanggan', not_subscribed: 'Belum dilanggan', subscribe_failed: 'Langganan belum selesai' };
const registrationLabels = { registered: 'Didaftarkan', not_registered: 'Belum didaftarkan', unknown: 'Belum dapat dipastikan', failed: 'Perlu perhatian' };
const messagingLabels = { unverified: 'Belum ada bukti mesej', send_verified: 'Penghantaran disahkan', receive_verified: 'Penerimaan disahkan', send_and_receive_verified: 'Hantar dan terima disahkan' };

function connectionCard(connection) {
  const mode = connection.mode === 'coexistence' ? 'WhatsApp Business App · Coexistence' : connection.mode === 'cloud_api' || connection.mode === 'cloud' ? 'WhatsApp Cloud API' : 'Jenis sambungan: ' + (connection.mode || 'belum dikenal pasti');
  const unknown = connection.operation_status === 'unknown';
  const explanation = connection.reason || (unknown
    ? 'Keputusan pengaktifan belum dapat dipastikan. Kemas kini status sebelum tindakan seterusnya.'
    : connection.readiness === 'ready' ? 'Nombor sedia digunakan. Aktiviti mesej dan tetapan AI dipaparkan secara berasingan.'
      : 'Semak keadaan sambungan untuk mengetahui tindakan seterusnya.');
  const actions = [];
  if (connection.can_refresh === true) actions.push(`<button type="button" class="button button-quiet" data-action="refresh" data-connection="${h(connection.id)}"><span aria-hidden="true">↻</span> Kemas kini status</button>`);
  if (connection.can_activate === true && !unknown) actions.push(`<button type="button" class="button button-primary" data-action="activate" data-connection="${h(connection.id)}">Lengkapkan pengaktifan WhatsApp</button>`);
  return `<article class="connection-card"><div class="connection-top"><div><h4 class="connection-phone">${h(connection.phone || 'Nombor belum dipaparkan')}</h4><div class="connection-mode">${h(mode)}</div></div>${readiness(connection.readiness)}</div>
    <dl class="connection-statuses"><div><dt>Akses Meta</dt><dd class="${connection.token_state === 'verified' ? 'is-good' : 'is-waiting'}">${h(tokenLabels[connection.token_state] || 'Belum dapat dipastikan')}</dd></div><div><dt>Webhook</dt><dd class="${connection.webhook_state === 'subscribed' ? 'is-good' : 'is-waiting'}">${h(webhookLabels[connection.webhook_state] || 'Belum dapat dipastikan')}</dd></div><div><dt>Pendaftaran nombor</dt><dd class="${connection.registration_state === 'registered' ? 'is-good' : 'is-waiting'}">${h(registrationLabels[connection.registration_state] || 'Belum dapat dipastikan')}</dd></div><div><dt>Aktiviti mesej</dt><dd>${h(messagingLabels[connection.messaging_state] || 'Belum ada bukti mesej')}</dd></div></dl>
    <p class="connection-explanation">${h(explanation)}</p>${actions.length ? '<div class="connection-actions">' + actions.join('') + '</div>' : ''}
    <p class="connection-updated">Pengesahan terakhir: ${h(dateText(connection.last_verified_at))}</p></article>`;
}

const auditLabels = {
  platform_akses_suspended: 'Akses BeShare digantung', platform_akses_active: 'Akses BeShare diaktifkan',
  platform_ai_dihidupkan: 'AI Auto Reply dihidupkan', platform_ai_dihentikan: 'AI Auto Reply dimatikan',
  admin_whatsapp_semakan: 'Semakan status WhatsApp dimulakan', admin_whatsapp_pendaftaran: 'Pendaftaran nombor WhatsApp dimulakan',
  admin_whatsapp_keputusan: 'Keputusan tindakan WhatsApp direkodkan',
  platform_access_suspended: 'Akses BeShare digantung', platform_access_active: 'Akses BeShare diaktifkan',
  platform_ai_enabled: 'AI Auto Reply dihidupkan', platform_ai_disabled: 'AI Auto Reply dimatikan',
  platform_whatsapp_refresh: 'Status WhatsApp dikemas kini', platform_whatsapp_activate: 'Pengaktifan WhatsApp dimulakan',
  platform_whatsapp_registered: 'Nombor WhatsApp didaftarkan', platform_whatsapp_unknown: 'Keputusan pengaktifan perlu disahkan',
  akaun_didaftarkan: 'Akaun didaftarkan', masuk: 'Log masuk', ai_tetapan_disimpan: 'Tetapan AI disimpan',
  ai_dihentikan: 'AI dihentikan'
};

function renderDetail(data) {
  const customer = data.customer;
  const connections = data.connections || [];
  const ai = data.ai || {};
  const knowledge = data.knowledge || {};
  const suspended = customer.access_status === 'suspended';
  const knownAccess = ['active', 'suspended'].includes(customer.access_status);
  const accessLabel = suspended ? badge('Digantung', 'red') : knownAccess ? badge('Aktif', 'green') : badge('Belum disahkan');
  const businessStatus = customer.business_status === 'suspended' ? 'Ruang perniagaan turut digantung oleh pemilik pelanggan.'
    : customer.business_status === 'provisioning' ? 'Ruang perniagaan sedang melalui proses pemilikan.' : '';
  const accessMessage = suspended ? 'Akses ruang perniagaan ini digantung oleh pentadbir sistem.'
    : knownAccess ? 'Akses platform dibenarkan untuk perniagaan ini.' : 'Status akses belum dapat dipastikan.';
  const enabled = ai.enabled === true;
  const canEnable = ai.available === true && ai.can_enable === true;
  const audience = ai.audience_mode === 'all' ? 'Semua pelanggan yang menghubungi nombor perniagaan'
    : ai.audience_mode === 'test' ? 'Nombor ujian yang disenaraikan' : '';
  const needsPreparation = !enabled && ai.available === true && !canEnable && knowledge.published !== true
    && ai.reason === 'Pemilik pelanggan perlu meluluskan fakta perniagaan dan tetapan AI dahulu.';
  const aiReason = ai.reason || (enabled ? 'AI dihidupkan mengikut tetapan dan persetujuan yang tersimpan.'
    : canEnable ? 'Tetapan pelanggan tersedia untuk pengaktifan AI.' : 'Tetapan AI pelanggan belum tersedia untuk pengaktifan.');
  const aiButton = enabled
    ? '<button type="button" class="button button-quiet" data-action="ai">Matikan AI Auto Reply</button>'
    : '<button id="enableCustomerAi" type="button" class="button button-quiet" aria-describedby="aiReadinessReason'
      + (needsPreparation ? ' aiPreparationHelp' : '') + '"' + (canEnable ? ' data-action="ai"' : ' disabled') + '>Hidupkan AI Auto Reply</button>';
  const owner = [customer.owner_name, customer.owner_email].filter(Boolean).join(' · ');
  byId('detailTitle').textContent = customer.name;
  byId('detailSubtitle').textContent = owner || 'Pemilik belum direkodkan';
  byId('detailContent').innerHTML = `<div class="detail-grid">
    <section class="detail-card"><h3><span aria-hidden="true">▤</span> Akses BeShare</h3>${accessLabel}<p>${h(accessMessage)}</p>${businessStatus ? '<p class="access-caveat">' + h(businessStatus) + '</p>' : ''}${knownAccess ? '<button type="button" class="button ' + (suspended ? 'button-quiet' : 'button-danger') + '" data-action="access">' + (suspended ? 'Aktifkan akses BeShare' : 'Gantung akses BeShare') + '</button>' : ''}</section>
    <section class="detail-card"><h3><span aria-hidden="true">✧</span> AI Auto Reply</h3>${enabled ? canEnable ? badge('Dihidupkan', 'violet') : badge('Dijeda', 'amber') : badge('Dimatikan')}<p id="aiReadinessReason">${h(aiReason)}</p>${needsPreparation ? '<p id="aiPreparationHelp" class="muted">Pemilik perniagaan pelanggan perlu membuka Pengetahuan dalam akaun mereka, melengkapkan maklumat, memilih “Semak &amp; terbitkan”, kemudian menyimpan tetapan AI dengan pengesahan pemilik. AI boleh kekal dimatikan semasa tetapan disimpan. Selepas itu, muat semula butiran ini untuk menghidupkan AI melalui panel pemilik.</p>' : ''}${audience ? '<p class="muted">Penerima: ' + h(audience) + '.</p>' : ''}${aiButton}<p><button type="button" class="button button-quiet" data-action="reload-detail">Muat semula butiran</button></p></section>
  </div>
  <h3 class="section-title">Sambungan WhatsApp <span>${formatCount(connections.length)} nombor</span></h3>
  ${connections.length ? connections.map(connectionCard).join('') : '<div class="detail-empty">Pelanggan belum menyambungkan nombor WhatsApp.</div>'}
  <h3 class="section-title">Pusat Pengetahuan</h3><div class="knowledge-row"><div><h3>Maklumat perniagaan</h3><p>${knowledge.published ? 'Diterbitkan: ' + h(dateText(knowledge.published_at)) : 'Maklumat perniagaan belum diterbitkan.'}</p></div>${knowledge.published ? badge('Diterbitkan', 'green') : badge('Belum diterbitkan')}</div>
  <h3 class="section-title">Aktiviti pentadbiran <span>Rekod terkini</span></h3>
  ${Array.isArray(data.audit) && data.audit.length ? '<ul class="audit-list">' + data.audit.map(item => `<li><span class="audit-dot" aria-hidden="true"></span><span><strong>${h(item.label || auditLabels[item.action] || 'Tindakan pentadbiran direkodkan')}</strong><small>${h(item.actor_name || 'Sistem')} · ${h(dateText(item.created_at))}</small></span></li>`).join('') + '</ul>' : '<div class="detail-empty">Belum ada tindakan pentadbiran direkodkan.</div>'}`;
}

async function loadDetail(publicId) {
  const sequence = ++state.detailRevision;
  byId('detailContent').setAttribute('aria-busy', 'true');
  try {
    const data = await api('butiran', null, { public_id: publicId });
    if (sequence !== state.detailRevision || state.selected !== publicId || !state.admin) return;
    if (!data.customer || data.customer.public_id !== publicId || typeof data.customer.name !== 'string' || !Array.isArray(data.connections)) {
      throw new ApiError('Butiran pelanggan tidak sepadan. Tutup dan buka semula pelanggan yang betul.');
    }
    state.detail = data;
    renderDetail(data);
  } catch (error) {
    if (sequence !== state.detailRevision || state.selected !== publicId) return;
    state.detail = null;
    byId('detailContent').innerHTML = '<div class="detail-empty">Butiran pelanggan belum dapat dimuatkan.</div>';
    handleError(error, 'detailNotice');
  } finally {
    if (sequence === state.detailRevision) byId('detailContent').setAttribute('aria-busy', 'false');
  }
}

async function openDetail(publicId) {
  if (!state.admin || state.writing || typeof publicId !== 'string' || !publicId) return;
  state.selected = publicId;
  state.detail = null;
  byId('detailTitle').textContent = 'Memuatkan pelanggan…';
  byId('detailSubtitle').textContent = '';
  byId('detailContent').innerHTML = '<div class="detail-empty">Mendapatkan status terkini…</div>';
  notice('', '', 'detailNotice');
  showDialog(byId('detailDialog'));
  await loadDetail(publicId);
}

function refreshDetail() {
  if (!state.admin || state.writing || state.pending || !state.detail
      || state.detail.customer.public_id !== state.selected) return;
  return openDetail(state.selected);
}

function requestAction(action, connectionId = null) {
  if (state.writing || !state.admin || !state.detail || state.detail.customer.public_id !== state.selected || state.pending) return;
  const customer = state.detail.customer;
  const ai = state.detail.ai || {};
  let title, message, note, label, body, phone = '';
  if (action === 'refresh' || action === 'activate') {
    const id = Number(connectionId);
    const connection = state.detail.connections.find(item => Number(item.id) === id);
    if (!connection || !Number.isSafeInteger(id) || id < 1) return;
    if (action === 'activate' && (connection.can_activate !== true || connection.operation_status === 'unknown')) return;
    if (action === 'refresh' && connection.can_refresh !== true) return;
    phone = connection.phone || '';
    body = { public_id: customer.public_id, connection_id: id, expected_phone: phone, confirmed: true };
    title = action === 'activate' ? 'Lengkapkan pengaktifan?' : 'Kemas kini status?';
    message = action === 'activate' ? 'Sistem akan meneruskan pengaktifan nombor WhatsApp yang telah pelanggan sambungkan.' : 'Sistem akan mendapatkan keadaan sambungan daripada Meta dan mengemas kini rekod pelanggan.';
    note = action === 'activate' ? 'Pengaktifan ini tidak menghidupkan AI Auto Reply. Keputusan akan dipaparkan selepas tindakan selesai.' : 'Tindakan ini tidak menghantar mesej atau mendaftarkan semula nombor.';
    label = action === 'activate' ? 'Lengkapkan pengaktifan' : 'Kemas kini status';
  } else if (action === 'access') {
    if (!['active', 'suspended'].includes(customer.access_status)) return;
    const suspended = customer.access_status === 'suspended';
    body = { public_id: customer.public_id, status: suspended ? 'active' : 'suspended', expected_status: customer.access_status, confirmed: true };
    title = suspended ? 'Aktifkan akses BeShare?' : 'Gantung akses BeShare?';
    message = suspended ? 'Pelanggan akan mendapat semula akses ruang perniagaan ini.' : 'Pelanggan akan kehilangan akses operasi ruang perniagaan ini sehingga diaktifkan semula.';
    note = 'Perubahan ini direkodkan atas akaun admin anda.';
    label = suspended ? 'Aktifkan akses' : 'Gantung akses';
  } else if (action === 'ai') {
    const enabled = ai.enabled === true;
    if (!enabled && !(ai.available === true && ai.can_enable === true)) return;
    if (!Number.isSafeInteger(ai.revision) || ai.revision < 0) return;
    body = { public_id: customer.public_id, enabled: !enabled, expected_revision: ai.revision, confirmed: true };
    title = enabled ? 'Matikan AI Auto Reply?' : 'Hidupkan AI Auto Reply?';
    message = enabled ? 'Balasan AI automatik untuk perniagaan ini akan dihentikan.' : ai.audience_mode === 'all'
      ? 'AI akan membalas mesej baharu daripada semua pelanggan yang menghubungi nombor perniagaan, mengikut pengetahuan dan persetujuan pemilik yang telah disimpan.'
      : 'AI akan membalas mesej baharu mengikut penerima, pengetahuan dan persetujuan pemilik yang telah disimpan.';
    note = enabled ? 'Sambungan nombor WhatsApp kekal tersimpan.' : 'Had harian dan pilihan penerima menggunakan tetapan yang telah diluluskan pemilik perniagaan.';
    label = enabled ? 'Matikan AI' : 'Hidupkan AI';
  } else return;
  state.pending = { action, body, publicId: customer.public_id };
  byId('confirmTitle').textContent = title;
  byId('confirmMessage').textContent = message;
  byId('confirmNote').textContent = note;
  byId('confirmTarget').innerHTML = '<dt>Perniagaan</dt><dd>' + h(customer.name) + '</dd>'
    + (phone ? '<dt>Nombor WhatsApp</dt><dd>' + h(phone) + '</dd>' : '');
  byId('executeConfirm').textContent = label;
  byId('executeConfirm').className = 'button ' + (action === 'access' && body.status === 'suspended' ? 'button-danger' : 'button-primary');
  byId('executeConfirm').disabled = false;
  byId('cancelConfirm').disabled = false;
  showDialog(byId('confirmDialog'));
}

function cancelConfirm() {
  if (state.writing) return;
  state.pending = null;
  byId('confirmDialog').close();
}

function setWriting(value) {
  state.writing = value;
  byId('executeConfirm').disabled = value;
  byId('cancelConfirm').disabled = value;
  byId('closeDetail').disabled = value;
  byId('reloadAll').disabled = value || !state.admin;
  for (const node of byId('detailContent').querySelectorAll('[data-action]')) node.disabled = value;
}

async function executeAction() {
  if (state.writing || !state.pending || !state.admin) return;
  const pending = state.pending;
  if (pending.publicId !== state.selected || pending.publicId !== state.detail?.customer.public_id) {
    cancelConfirm();
    notice('Pelanggan yang dipilih telah berubah. Buka semula tindakan pada pelanggan yang betul.', 'warning', 'detailNotice');
    return;
  }
  state.pending = null;
  setWriting(true);
  byId('executeConfirm').textContent = 'Sedang diproses…';
  notice('', '', 'detailNotice');
  let outcome = null;
  let failure = null;
  try {
    const data = await api(pending.action, pending.body);
    if (!data.result || typeof data.result.status !== 'string' || typeof data.result.message !== 'string' || !data.result.message.trim()) {
      throw new ApiError('Keputusan tindakan belum dapat dipastikan. Kemas kini status sebelum mencuba semula.');
    }
    outcome = data.result;
  } catch (error) { failure = error; }
  finally {
    byId('confirmDialog').close();
    setWriting(false);
  }
  if (failure) {
    handleError(failure, 'detailNotice');
    // Never repeat a mutation automatically, including after a lost response.
  } else {
    const tone = successStates.has(outcome.status) ? 'success' : attentionStates.has(outcome.status) ? 'warning' : 'warning';
    notice(outcome.message, tone, 'detailNotice');
  }
  if (state.admin) {
    await Promise.allSettled([
      loadCustomers(),
      state.selected === pending.publicId ? loadDetail(pending.publicId) : Promise.resolve()
    ]);
  }
}

async function start() {
  if (state.starting || state.writing) return;
  state.starting = true;
  gate();
  notice();
  try {
    const data = await api('sesi');
    if (!data.admin || typeof data.admin.name !== 'string' || typeof data.admin.email !== 'string' || !state.csrf) {
      throw new ApiError('Akses pemilik belum dapat disahkan.', 403);
    }
    state.admin = data.admin;
    byId('adminName').textContent = data.admin.name;
    byId('adminRole').textContent = 'Pentadbir sistem';
    byId('adminInitial').textContent = initials(data.admin.name);
    byId('accessGate').hidden = true;
    byId('dashboard').hidden = false;
    byId('reloadAll').disabled = false;
    await loadCustomers();
  } catch (error) { gate(error); }
  finally { state.starting = false; }
}

byId('reloadAll').addEventListener('click', () => { if (!state.writing) { notice(); loadCustomers(); } });
byId('retryAccess').addEventListener('click', start);
byId('attentionShortcut').addEventListener('click', () => { setFilter('attention'); if (state.admin) byId('customers').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
byId('searchInput').addEventListener('input', event => {
  clearTimeout(state.searchTimer);
  state.query = event.target.value.trim().slice(0, 160);
  state.page = 1;
  // Invalidate a previous search immediately, before the debounce runs.
  state.listRevision += 1;
  state.searchTimer = setTimeout(loadCustomers, 280);
});
document.querySelectorAll('[data-filter]').forEach(node => node.addEventListener('click', () => setFilter(node.dataset.filter)));
byId('previousPage').addEventListener('click', () => { if (state.page > 1 && !state.writing) { state.page -= 1; loadCustomers(); } });
byId('nextPage').addEventListener('click', () => { if (state.page < state.pages && !state.writing) { state.page += 1; loadCustomers(); } });
byId('customerRows').addEventListener('click', event => { const button = event.target.closest('[data-open]'); if (button) openDetail(button.dataset.open); });
byId('detailContent').addEventListener('click', event => {
  const button = event.target.closest('[data-action]');
  if (!button || button.disabled) return;
  if (button.dataset.action === 'reload-detail') refreshDetail();
  else requestAction(button.dataset.action, button.dataset.connection);
});
byId('closeDetail').addEventListener('click', closeDetail);
byId('detailDialog').addEventListener('cancel', event => { event.preventDefault(); closeDetail(); });
byId('cancelConfirm').addEventListener('click', cancelConfirm);
byId('confirmDialog').addEventListener('cancel', event => { event.preventDefault(); cancelConfirm(); });
byId('executeConfirm').addEventListener('click', executeAction);
const ownerBoot = start();
