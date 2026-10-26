import { readFileSync } from 'node:fs';
import { Script, createContext } from 'node:vm';
import { webcrypto } from 'node:crypto';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../../whatsapp/ruang.js', import.meta.url), 'utf8');
const script = new Script(source, { filename: 'whatsapp/ruang.js' });
const clone = value => JSON.parse(JSON.stringify(value));
let checks = 0;
function check(name, condition) { assert.ok(condition, name); checks += 1; }

function documentSeed() {
  return {
    facts: 'Fakta yang diluluskan sebelum pusat pengetahuan.',
    sources: [], assets: [],
    payment: { enabled: false, bank_name: '', account_name: '', account_number: '', instructions: '', qr_media_id: '' }
  };
}

function sessionSeed(business = 'synthetic-business') {
  return {
    ok: true, csrf: 'synthetic-csrf',
    user: { name: 'Synthetic owner', email: 'owner@example.invalid', role: 'owner' },
    business: { name: 'Synthetic business', public_id: business, status: 'active' },
    members: [], memberships: [], audit: [], contacts: [], conversations: [], inbox_ready: true, connection: {},
    ai: {
      available: true, can_manage: true, enabled: true, deployment_ready: true, knowledge: 'Pengetahuan aktif asal.',
      production_available: false, audience_mode: 'test', audience_approved: false, controls_configured: true,
      revision: 7, connection_id: 2, allowlist: ['60123456789'], daily_limit: 20, quota_used: 0,
      connections: [{ id: 2, display_phone: '+60110000000' }]
    },
    knowledge_centre: {
      available: true, can_manage: true, revision: 3, published_revision: 3, published_at: '2026-10-24 00:00:00',
      draft: documentSeed(), media: []
    }
  };
}

async function fixture(initial = sessionSeed()) {
  let server = clone(initial);
  let currentHash = '#pengetahuan';
  const requests = [];
  const storage = new Map();
  const listeners = new Map();
  const nodes = new Map();
  const deferred = new Map();
  let mediaSequence = 1;
  class Node {
    constructor(attributes = {}) {
      this.attributes = attributes; this.innerHTML = ''; this.textContent = ''; this.hidden = false;
      this.disabled = false; this.className = ''; this.scrollLeft = 0; this.value = '';
      this.type = ''; this.checked = false; this.tagName = 'INPUT'; this.files = [];
    }
    getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    closest(selector) {
      if (selector === '[data-act]' && this.getAttribute('data-act')) return this;
      if (selector === "select[data-act='pilih']" && this.tagName === 'SELECT' && this.getAttribute('data-act') === 'pilih') return this;
      return null;
    }
    addEventListener() {}
    querySelector() { return new Node(); }
    scrollIntoView() {}
  }
  class Form extends Node {
    constructor(id, fields) { super({ id }); this.fields = fields; this.tagName = 'FORM'; }
  }
  class FormData {
    constructor(form) { this.fields = new Map(Object.entries(form?.fields || {})); }
    get(name) { return this.fields.get(name) ?? null; }
    append(name, value) { this.fields.set(name, value); }
  }
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, new Node());
    return nodes.get(id);
  };
  const location = { origin: 'https://fixture.invalid', pathname: '/whatsapp/ruang.html', search: '' };
  Object.defineProperty(location, 'hash', {
    get: () => currentHash,
    set: value => {
      currentHash = '#' + String(value).replace(/^#/, '');
      queueMicrotask(() => (listeners.get('hashchange') || []).forEach(fn => fn()));
    }
  });
  const window = {
    addEventListener(name, fn) {
      if (!listeners.has(name)) listeners.set(name, []);
      listeners.get(name).push(fn);
    },
    setTimeout: () => 1, clearTimeout() {}
  };
  const response = body => ({ ok: body.ok !== false, status: body.ok === false ? 400 : 200, json: async () => clone(body) });
  const context = createContext({
    window, location, crypto: webcrypto, TextEncoder, URL, URLSearchParams,
    FormData, HTMLFormElement: Form, navigator: { userAgent: 'Synthetic knowledge fixture' },
    history: { replaceState() {} }, requestAnimationFrame: fn => fn(),
    sessionStorage: {
      getItem: key => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, String(value)),
      removeItem: key => storage.delete(key)
    },
    document: {
      documentElement: { dataset: {} }, visibilityState: 'visible',
      getElementById: node, querySelector: selector => selector === '.nav-links' ? node('nav') : null,
      querySelectorAll: () => [], addEventListener() {}, head: { appendChild() {} }, createElement: () => new Node()
    },
    fetch: async (url, init = {}) => {
      if (url === 'akaun/knowledge-media.php') {
        assert.equal(init.method, 'POST');
        assert.equal(init.credentials, 'same-origin');
        assert.equal(init.headers['X-CSRF-Token'], 'synthetic-csrf');
        assert.equal(init.headers['Content-Type'], undefined, 'browser must set multipart boundary');
        assert.ok(init.body instanceof FormData, 'upload uses multipart form data');
        const file = init.body.get('file');
        assert.ok(file && file.size > 0);
        const expectedBusiness = init.body.get('expected_business_public_id');
        assert.equal(typeof expectedBusiness, 'string', 'upload carries the captured business');
        requests.push({ action: 'upload', file, expected_business_public_id: expectedBusiness });
        if (expectedBusiness !== server.business.public_id) return response({ ok: false, error: 'Perniagaan sesi telah berubah.' });
        const id = (mediaSequence++).toString(16).padStart(32, '0');
        const media = { id, mime: file.type, size: file.size, preview_url: '/whatsapp/akaun/knowledge-media.php?id=' + id };
        server.knowledge_centre.media.push(media);
        return response({ ok: true, csrf: 'synthetic-csrf', media });
      }
      assert.match(url, /^akaun\/pintu\.php\?tindakan=/, 'fixture forbids external network and Meta calls');
      const action = new URL(url, location.origin).searchParams.get('tindakan');
      const body = init.body ? JSON.parse(init.body) : null;
      requests.push({ action, body });
      if (['knowsave', 'knowpublish', 'knowimport'].includes(action)) {
        assert.equal(typeof body.expected_business_public_id, 'string', 'knowledge action carries the captured business');
        if (body.expected_business_public_id !== server.business.public_id) return response({ ok: false, error: 'Perniagaan sesi telah berubah.' });
      }
      if (deferred.has(action)) return deferred.get(action)(body);
      if (action === 'sesi' || action === 'peti') return response(server);
      if (action === 'knowimport') return response({
        ok: true, csrf: 'synthetic-csrf',
        source: { url: body.url, title: 'Tajuk sumber yang dibaca', text: 'Teks import untuk disemak pemilik.' }
      });
      if (action === 'knowsave') {
        if (body.expected_revision !== server.knowledge_centre.revision) return response({ ok: false, error: 'Draf telah berubah.' });
        server.knowledge_centre.draft = clone(body.document);
        server.knowledge_centre.revision += 1;
        return response(server);
      }
      if (action === 'knowpublish') {
        assert.equal(body.expected_revision, server.knowledge_centre.revision);
        assert.equal(body.approved, true);
        if (server.ai.revision === 0) {
          assert.equal(body.connection_id, 2, 'first publication requires the explicitly selected connection');
          server.ai.connection_id = body.connection_id;
          server.ai.enabled = false;
          server.ai.allowlist = [];
        }
        if (server.knowledge_centre.draft.payment.enabled) assert.equal(body.payment_confirmed, true);
        server.knowledge_centre.published_revision = server.knowledge_centre.revision;
        server.knowledge_centre.published_at = '2026-10-24 01:00:00';
        server.ai.knowledge = 'Versi diterbitkan daripada fixture.';
        server.ai.revision += 1;
        return response(server);
      }
      if (action === 'aiconfig') {
        assert.equal(body.knowledge, server.ai.knowledge, 'AI controls must send the existing active knowledge');
        assert.equal(body.approved, true, 'owner approval remains required');
        assert.ok(['all', 'test'].includes(body.audience_mode));
        server.ai.daily_limit = body.daily_limit;
        server.ai.allowlist = body.allowlist;
        server.ai.audience_mode = body.audience_mode;
        server.ai.controls_configured = true;
        server.ai.audience_approved = body.audience_mode === 'all';
        server.ai.enabled = body.enabled;
        server.ai.revision += 1;
        return response(server);
      }
      if (action === 'keluar') return response({ ok: true, csrf: 'synthetic-csrf' });
      assert.fail('Unexpected action: ' + action);
    }
  });
  script.runInContext(context);
  const settle = async () => { for (let i = 0; i < 4; i += 1) await new Promise(resolve => setImmediate(resolve)); };
  await settle();
  const readEditor = () => JSON.parse(new Script('JSON.stringify(knowledgeEditor)').runInContext(context));
  return {
    context, requests, storage, settle, node, html: () => node('app').innerHTML, editor: readEditor,
    server: () => clone(server),
    changeSessionInAnotherTab(payload) { server = clone(payload); },
    async click(action, value = '') {
      const button = new Node({ 'data-act': action, 'data-value': value }); button.tagName = 'BUTTON';
      await context.onClick({ target: button }); await settle();
    },
    startClick(action, value = '') {
      const button = new Node({ 'data-act': action, 'data-value': value }); button.tagName = 'BUTTON';
      return context.onClick({ target: button });
    },
    input(group, field, value, id = '') {
      const target = new Node({ 'data-kc-group': group, 'data-kc-id': id, 'data-kc-field': field });
      if (typeof value === 'boolean') { target.type = 'checkbox'; target.checked = value; }
      else target.value = value;
      context.onInput({ target });
    },
    control(field, value) {
      const target = new Node({ 'data-kc-control': field, ...(field === 'audience_mode' ? { 'data-ai-audience': '' } : {}) });
      if (typeof value === 'boolean') { target.type = 'checkbox'; target.checked = value; }
      else target.value = value;
      context.onChange({ target });
    },
    async submit(id, fields = {}) {
      await context.onSubmit({ target: new Form(id, fields), preventDefault() {} }); await settle();
    },
    async upload(group, id, type = 'image/png', size = 400) {
      const target = new Node({ 'data-kc-upload-group': group, 'data-kc-upload': id });
      target.type = 'file'; target.files = [{ name: 'synthetic-image', type, size }];
      await context.onChange({ target }); await settle();
    },
    async refresh(payload = server) {
      context.applyLive(clone(payload)); context.route(); await settle();
    },
    defer(action) {
      let release;
      deferred.set(action, () => new Promise(resolve => { release = body => resolve(response(body)); }));
      return async body => { deferred.delete(action); release(body); await settle(); };
    }
  };
}

const f = await fixture();
check('renders all four knowledge-centre sections', ['Fakta & pautan sumber', 'Gambar produk & kombo', 'Maklumat bayaran', 'Kawalan AI Auto Reply'].every(text => f.html().includes(text)));
check('does not expose a second active-knowledge editor', !f.html().includes('name="knowledge"'));
check('existing approved facts seed the draft', f.editor().document.facts === documentSeed().facts);
f.input('document', 'facts', 'Fakta draf baharu.');
f.input('payment', 'account_number', '000123450067');
f.input('payment', 'account_name', 'Penerima <script>tidak boleh dilaksana</script>');
await f.refresh();
check('session refresh retains unsaved facts and exact leading-zero account number', f.editor().document.facts === 'Fakta draf baharu.' && f.editor().document.payment.account_number === '000123450067');
check('bank details are escaped in rendered preview', f.html().includes('Penerima &lt;script&gt;') && !f.html().includes('<script>tidak boleh'));
check('sensitive draft is absent from browser storage', ![...f.storage.values()].some(value => value.includes('000123450067') || value.includes('Fakta draf baharu')));

await f.click('kc-add-source');
let sourceId = f.editor().document.sources[0].id;
check('new source uses a stable opaque hex id', /^[a-f0-9]{32}$/.test(sourceId));
f.input('source', 'url', 'https://example.invalid/product', sourceId);
await f.click('kc-import', sourceId);
check('import fills only the source draft and preserves bank/facts edits', f.editor().document.sources[0].text === 'Teks import untuk disemak pemilik.' && f.editor().document.payment.account_number === '000123450067' && f.editor().document.facts === 'Fakta draf baharu.');
check('import does not enable or publish the source', f.editor().document.sources[0].enabled === false && !f.requests.some(request => ['knowsave', 'knowpublish'].includes(request.action)));

await f.click('kc-add-asset');
const assetId = f.editor().document.assets[0].id;
f.input('asset', 'title', 'Kombo Starter', assetId);
f.input('asset', 'caption', 'Kapsyen yang disemak.', assetId);
f.input('asset', 'when_to_send', 'Apabila pelanggan meminta gambar Kombo Starter.', assetId);
const uploadCount = f.requests.filter(request => request.action === 'upload').length;
await f.upload('asset', assetId, 'image/svg+xml', 100);
await f.upload('asset', assetId, 'image/jpeg', 2 * 1024 * 1024 + 1);
check('wrong media type and oversized file never reach upload API', f.requests.filter(request => request.action === 'upload').length === uploadCount);
await f.upload('asset', assetId);
check('valid upload attaches server media without enabling it', /^[a-f0-9]{32}$/.test(f.editor().document.assets[0].media_id) && f.editor().document.assets[0].enabled === false);
check('upload keeps other unsaved fields', f.editor().document.assets[0].caption === 'Kapsyen yang disemak.' && f.editor().document.payment.account_number === '000123450067');
await f.upload('payment', '');
check('QR uses uploaded server media only', /^[a-f0-9]{32}$/.test(f.editor().document.payment.qr_media_id));

await f.submit('ai-settings', { connection: '2', revision: '7', knowledge: 'MUST NOT REPLACE ACTIVE FACTS', allowlist: '60123456789', daily_limit: '30', approved: 'on', enabled: 'on' });
check('pilot config does not overwrite centre draft', f.editor().document.facts === 'Fakta draf baharu.' && f.editor().document.assets[0].caption === 'Kapsyen yang disemak.' && f.editor().document.payment.account_number === '000123450067');
check('pilot config sends active facts rather than an alternate textarea', f.requests.find(request => request.action === 'aiconfig').body.knowledge === 'Pengetahuan aktif asal.');
await f.submit('knowledge-publish', { approved: 'on', payment_confirmed: 'on' });
check('unsaved draft cannot be published', !f.requests.some(request => request.action === 'knowpublish'));
f.input('source', 'enabled', true, sourceId);
f.input('asset', 'enabled', true, assetId);
f.input('payment', 'enabled', true);
f.input('payment', 'bank_name', 'Bank Fixture');
await f.submit('knowledge-draft');
check('save submits the exact document and expected revision without publishing', f.requests.find(request => request.action === 'knowsave').body.expected_revision === 3 && !f.editor().dirty && f.editor().revision === 4 && f.server().knowledge_centre.published_revision === 3);
await f.click('kc-review');
check('review shows exact bank details and recipient confirmation', f.html().includes('000123450067') && f.html().includes('name="payment_confirmed"') && f.html().includes('Sahkan & terbitkan pengetahuan'));
await f.submit('knowledge-publish', { approved: 'on' });
check('enabled payment requires separate confirmation before any publish request', !f.requests.some(request => request.action === 'knowpublish'));
await f.submit('knowledge-publish', { approved: 'on', payment_confirmed: 'on' });
check('explicitly confirmed publication uses the saved revision', f.requests.find(request => request.action === 'knowpublish').body.expected_revision === 4 && f.editor().publishedRevision === 4);
check('publication and preview never write bank details to browser storage', ![...f.storage.values()].some(value => value.includes('000123450067') || value.includes('Bank Fixture')));

f.input('document', 'facts', '😀'.repeat(2001));
const savedCount = f.requests.filter(request => request.action === 'knowsave').length;
await f.submit('knowledge-draft');
check('UTF-8 byte limit is checked without losing the draft', f.requests.filter(request => request.action === 'knowsave').length === savedCount && f.editor().document.facts.length === 4002 && f.editor().notice.includes('terlalu panjang'));
f.input('document', 'facts', 'Perubahan tempatan yang belum disimpan.');
const newer = f.server();
newer.knowledge_centre.revision += 1;
newer.knowledge_centre.draft.facts = 'Versi pelayan yang lebih baharu.';
await f.refresh(newer);
check('newer server revision signals a conflict and preserves local work', f.editor().conflict && f.editor().document.facts === 'Perubahan tempatan yang belum disimpan.' && f.html().includes('Buang perubahan & muat semula draf'));

const mediaUrl = '/whatsapp/akaun/knowledge-media.php?id=' + 'a'.repeat(32);
const thread = { id: 'ai-1', name: 'Synthetic', mode: 'ai_active', window: 'open', can_control: true, messages: [{ from: 'ai', text: 'Kapsyen', wamid: 'long'.repeat(120), status: 'sent', media_url: mediaUrl }] };
const rendered = f.context.talk(f.context.load(), thread);
check('outbound image renders using the authenticated same-origin media endpoint', rendered.includes('class="msg-image"') && rendered.includes('https://fixture.invalid' + mediaUrl));
thread.messages[0].media_url = 'https://outside.invalid/pixel?id=' + 'a'.repeat(32);
check('arbitrary external image URLs are not rendered', !f.context.talk(f.context.load(), thread).includes('class="msg-image"'));

const renderDelivery = (status, extra = {}) => f.context.talk(f.context.load(), {
  ...thread,
  messages: [{ from: 'ai', text: 'Balasan yang dijana.', wamid: 'wamid.internal-id-not-for-display', status,
    at: '2026-10-26 06:12:00', ...extra }]
});
const acceptedReply = renderDelivery('sent', { error_code: 131026 });
check('API acceptance is visibly awaiting delivery, with no stale failure code or internal message id', acceptedReply.includes('Menunggu pengesahan sampai') && acceptedReply.includes('Penghantaran kepada pelanggan belum disahkan') && !acceptedReply.includes('msg-status--success') && !acceptedReply.includes('Kod Meta:') && !acceptedReply.includes('wamid.internal'));
check('AI timestamp is identified as generation time and retains an unambiguous UTC datetime', acceptedReply.includes('Dijana:') && acceptedReply.includes('datetime="2026-10-26T06:12:00.000Z"') && acceptedReply.includes('MYT'));
const failedReply = renderDelivery('failed', { error_code: 131026, error_subcode: 7, failure_reason: 'provider-text-must-not-be-shown' });
check('failed outbound reply stays visible with a distinct failure state and safe numeric code', failedReply.includes('Balasan yang dijana.') && failedReply.includes('msg--failed') && failedReply.includes('Gagal dihantar') && failedReply.includes('tidak berjaya dihantar kepada pelanggan') && failedReply.includes('Kod Meta: 131026') && failedReply.includes('Subkod: 7') && !failedReply.includes('provider-text-must-not-be-shown'));
check('older failed replies without diagnostics remain clear and malformed diagnostic fields are ignored', [renderDelivery('failed'), renderDelivery('failed', { error_code: '<img src=x>', error_subcode: 'not-a-number' })].every(html => html.includes('Gagal dihantar') && !html.includes('Kod Meta:') && !html.includes('<img')));
check('confirmed delivery and read receipts are distinct from acceptance', renderDelivery('delivered').includes('Sudah sampai') && !renderDelivery('delivered').includes('Menunggu pengesahan sampai') && renderDelivery('read').includes('Sudah dibaca'));
check('unsent, uncertain and unrecognized states never appear as confirmed delivery', ['generated', 'sending', 'unknown', 'handoff', 'future-status'].every(status => !renderDelivery(status).includes('msg-status--success')));

await f.click('logout');
check('logout clears in-memory payment and source drafts', f.editor() === null);
const g = await fixture();
await g.click('kc-add-source');
sourceId = g.editor().document.sources[0].id;
g.input('source', 'url', 'https://example.invalid/old-tenant', sourceId);
const finishImport = g.defer('knowimport');
const pending = g.startClick('kc-import', sourceId);
await g.settle();
await g.refresh(sessionSeed('different-business'));
await finishImport({ ok: true, csrf: 'synthetic-csrf', source: { url: 'https://example.invalid/old-tenant', title: 'Old tenant data', text: 'Private old tenant draft.' } });
await pending;
check('late import response cannot restore another tenant draft', g.editor().scope.startsWith('different-business:') && g.editor().document.sources.length === 0 && !g.html().includes('Private old tenant'));
const viewer = sessionSeed('different-business');
viewer.user.role = 'admin';
viewer.knowledge_centre.can_manage = false;
viewer.knowledge_centre.draft = undefined;
await g.refresh(viewer);
check('loss of owner capability clears private editor memory', g.editor() === null && !g.html().includes('id="knowledge-draft"'));

const first = sessionSeed('new-owner');
first.ai.revision = 0;
first.ai.knowledge = '';
first.ai.connection_id = 0;
first.ai.enabled = false;
first.ai.allowlist = [];
first.knowledge_centre.published_revision = 0;
first.knowledge_centre.published_at = null;
const h = await fixture(first);
await h.click('kc-review');
await h.submit('knowledge-publish', { approved: 'on' });
check('first publication is blocked until a connection is explicitly selected', !h.requests.some(request => request.action === 'knowpublish') && h.editor().notice.includes('Pilih nombor perniagaan'));
h.control('publication_connection', '2');
await h.submit('knowledge-publish', { approved: 'on' });
check('first publication includes selected connection and leaves AI off', h.requests.find(request => request.action === 'knowpublish').body.connection_id === 2 && h.server().ai.enabled === false && h.server().ai.allowlist.length === 0);

const unopened = clone(first);
unopened.ai.available = false;
unopened.ai.can_manage = false;
const independent = await fixture(unopened);
await independent.click('kc-review');
check('first publication exposes its own number selector without AI control permission', independent.html().includes('data-kc-control="publication_connection"') && !independent.html().includes('id="ai-settings"'));
await independent.submit('knowledge-publish', { connection: '2', approved: 'on' });
check('nonpilot owner can publish knowledge through own-tenant connection without enabling AI', independent.requests.find(request => request.action === 'knowpublish').body.connection_id === 2 && independent.server().ai.enabled === false);

const freshProduction = clone(first);
freshProduction.ai.production_available = true;
freshProduction.ai.controls_configured = false;
const production = await fixture(freshProduction);
check('unconfigured production account defaults to all customers without prechecking consent or activation', production.html().includes('<option value="all" selected') && /id="ai-approved"[^>]*required/.test(production.html()) && !/id="ai-approved"[^>]*checked/.test(production.html()) && !/name="enabled"[^>]*checked/.test(production.html()));
check('all-customer mode hides test list and does not require a test number', production.html().includes('id="ai-test-recipients" hidden') && !/id="ai-allowlist"[^>]*required/.test(production.html()));
await production.click('kc-review');
await production.submit('knowledge-publish', { connection: '2', approved: 'on' });
check('publication-only settings keep production default all while AI remains off', production.html().includes('<option value="all" selected') && production.server().ai.enabled === false && production.server().ai.controls_configured === false);
production.input('document', 'facts', 'Draf perniagaan belum diterbitkan.');
await production.submit('ai-settings', { connection: '2', revision: '1', audience_mode: 'all', allowlist: '', daily_limit: '25', enabled: 'on' });
check('all-customer save is blocked without explicit owner consent', !production.requests.some(request => request.action === 'aiconfig') && production.editor().notice.includes('Sahkan pengetahuan'));
await production.submit('ai-settings', { connection: '2', revision: '1', audience_mode: 'all', allowlist: '', daily_limit: '25', approved: 'on', enabled: 'on' });
const allSave = production.requests.find(request => request.action === 'aiconfig').body;
check('all-customer save carries explicit audience approval and accepts an empty test list', allSave.audience_mode === 'all' && allSave.approved === true && allSave.allowlist.length === 0 && allSave.enabled === true);
check('all-customer settings preserve unsaved knowledge and use only published facts', production.editor().document.facts === 'Draf perniagaan belum diterbitkan.' && allSave.knowledge === 'Versi diterbitkan daripada fixture.');
check('production inbox banner does not claim knowledge is disconnected or replies are test-only', !production.html().includes('Pengetahuan, automasi') && !production.html().includes('Ujian AI hanya membalas'));

const savedTest = sessionSeed('saved-test-business');
savedTest.ai.production_available = true;
const preserved = await fixture(savedTest);
check('opening production controls preserves a saved test-only audience', preserved.html().includes('<option value="test" selected') && /id="ai-allowlist"[^>]*required/.test(preserved.html()));
preserved.control('approved', true);
preserved.node('ai-approved').checked = true;
preserved.control('audience_mode', 'all');
check('expanding audience clears previous consent and updates requirement and copy immediately', preserved.editor().controls.approved === false && preserved.node('ai-approved').checked === false && preserved.node('ai-test-recipients').hidden === true && preserved.node('ai-allowlist').required === false && preserved.node('ai-consent-copy').textContent.includes('semua pelanggan'));
check('changing recipient mode retains existing test numbers for later reuse', preserved.editor().controls.allowlist === '60123456789');
await preserved.refresh();
check('session refresh preserves unsaved audience choice without granting consent', preserved.html().includes('<option value="all" selected') && !/id="ai-approved"[^>]*checked/.test(preserved.html()));
preserved.control('audience_mode', 'test');
check('returning to test mode restores number requirement and test consent copy', preserved.node('ai-test-recipients').hidden === false && preserved.node('ai-allowlist').required === true && preserved.node('ai-consent-copy').textContent.includes('nombor ujian'));

const unavailable = await fixture();
await unavailable.submit('ai-settings', { connection: '2', revision: '7', audience_mode: 'all', allowlist: '', daily_limit: '20', approved: 'on', enabled: 'on' });
check('an unavailable all-customer mode cannot be submitted through the UI', !unavailable.requests.some(request => request.action === 'aiconfig'));
const ownershipChanged = sessionSeed('new-business-owner');
ownershipChanged.ai.production_available = true;
ownershipChanged.ai.audience_mode = 'all';
ownershipChanged.ai.audience_approved = false;
ownershipChanged.ai.controls_configured = false;
const reapproval = await fixture(ownershipChanged);
check('enabled all-customer settings without current-owner consent are displayed as paused', reapproval.html().includes('AI Auto Reply dijeda') && !reapproval.html().includes('AI Auto Reply aktif') && reapproval.html().includes('Pemilik semasa perlu'));

const stale = await fixture();
stale.input('document', 'facts', 'Private draft from the original business.');
stale.changeSessionInAnotherTab(sessionSeed('other-tab-business'));
await stale.submit('knowledge-draft');
check('stale tab submits its captured business identity and cannot write the new session tenant', stale.requests.find(request => request.action === 'knowsave').body.expected_business_public_id === 'synthetic-business' && stale.server().knowledge_centre.draft.facts === documentSeed().facts && stale.editor().document.facts === 'Private draft from the original business.');
await stale.upload('payment', '');
check('stale-tab upload also carries original scope and creates no media in the new tenant', stale.requests.find(request => request.action === 'upload').expected_business_public_id === 'synthetic-business' && stale.server().knowledge_centre.media.length === 0);

const large = sessionSeed();
large.knowledge_centre.draft.sources = [{
  id: 'b'.repeat(32), kind: 'website', url: 'https://example.invalid/source',
  title: 'Source with a complete 8 KB import', text: 'a'.repeat(8000), enabled: true
}];
const full = await fixture(large);
await full.click('kc-review');
check('combined publication size includes enabled source titles and URLs', full.editor().notice.includes('Gabungan fakta') && full.html().includes('Ruang fakta:') && !full.editor().review);
check('over-limit publication does not truncate sources or send a publish request', full.editor().document.sources[0].text.length === 8000 && !full.requests.some(request => request.action === 'knowpublish'));
full.input('source', 'enabled', false, 'b'.repeat(32));
await full.submit('knowledge-draft');
await full.click('kc-review');
check('disabling an unneeded source frees publication space while preserving its draft', full.editor().review && full.editor().document.sources[0].text.length === 8000);

console.log(`PASS — knowledge-centre UI: ${checks} focused checks; synthetic APIs only.`);
