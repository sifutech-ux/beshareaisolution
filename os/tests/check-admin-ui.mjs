import { readFileSync } from 'node:fs';
import { Script, createContext, runInContext } from 'node:vm';
import assert from 'node:assert/strict';

// Isolated browser-state fixtures: no network, production credentials or Meta calls.
const source = readFileSync(new URL('../../whatsapp/pemilik/pemilik.js', import.meta.url), 'utf8');
const html = readFileSync(new URL('../../whatsapp/pemilik/index.html', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../whatsapp/pemilik/pemilik.css', import.meta.url), 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
let checks = 0;
function check(name, value) { assert.ok(value, name); checks += 1; console.log('OK ' + name); }
function deferred() { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; }

const customer = id => ({
  public_id: id, name: id === 'customer-a' ? 'Perniagaan A <img src=x onerror=alert(1)>' : 'Perniagaan B',
  owner_name: 'Pemilik ujian', owner_email: 'owner@example.invalid', created_at: '2026-10-26T01:00:00Z',
  access_status: 'active', business_status: 'active', connections_count: 1, phone: '+601100000001',
  readiness: 'pending', ai_enabled: false, knowledge_published: true, last_activity: '2026-10-26T01:00:00Z'
});
const detail = id => ({
  ok: true, csrf: 'synthetic-csrf', customer: customer(id),
  connections: [{
    id: id === 'customer-a' ? 101 : 102, phone: '+601100000001', mode: 'cloud_api', token_state: 'verified',
    webhook_state: 'subscribed', registration_state: 'not_registered', messaging_state: 'unverified',
    readiness: 'pending', reason: 'Pendaftaran nombor belum selesai.', can_refresh: true, can_activate: true,
    operation_status: '', last_verified_at: '2026-10-26T01:00:00Z'
  }],
  ai: { available: true, enabled: false, can_enable: true, reason: 'Tetapan tersedia.', revision: 3 },
  knowledge: { published: true, published_at: '2026-10-26T01:00:00Z' }, audit: []
});

async function fixture({ denied = 0 } = {}) {
  const requests = [];
  const nodes = new Map();
  const delayed = new Map();
  const overrides = new Map();
  class Node {
    constructor(dataset = {}) { this.dataset = dataset; this.innerHTML = ''; this.textContent = ''; this.hidden = false; this.disabled = false; this.className = ''; this.attributes = new Map(); this.listeners = new Map(); this.open = false; }
    addEventListener(name, fn) { this.listeners.set(name, fn); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name); }
    showModal() { this.open = true; }
    close() { this.open = false; }
    querySelectorAll() { return []; }
    scrollIntoView() {}
  }
  const node = id => { if (!nodes.has(id)) nodes.set(id, new Node()); return nodes.get(id); };
  const metrics = ['customers', 'new_customers', 'ready_connections', 'pending_connections', 'ai_enabled', 'suspended_customers'].map(metric => new Node({ metric }));
  const filters = ['all', 'attention', 'ready', 'suspended'].map(filter => new Node({ filter }));
  const list = (names = ['customer-a']) => ({
    ok: true, csrf: 'synthetic-csrf', summary: { customers: 1, new_customers: 1, ready_connections: 0, pending_connections: 1, ai_enabled: 0, suspended_customers: 0 },
    customers: names.map(customer), pagination: { page: 1, pages: 1, page_size: 20, total: names.length }
  });
  const sandbox = createContext({
    URLSearchParams, Intl, Date, setTimeout, clearTimeout, console,
    document: {
      getElementById: node,
      querySelectorAll: selector => selector === '[data-metric]' ? metrics : selector === '[data-filter]' ? filters : []
    },
    fetch: async (url, options) => {
      assert.match(url, /^\/whatsapp\/pemilik\/pintu\.php\?/);
      assert.equal(options.credentials, 'same-origin');
      assert.equal(options.cache, 'no-store');
      const params = new URLSearchParams(url.split('?')[1]);
      const action = params.get('tindakan');
      const body = options.body ? JSON.parse(options.body) : null;
      requests.push({ action, body, params, options });
      if (denied) return { ok: false, status: denied, json: async () => ({ ok: false, error: 'Akses ditolak.' }) };
      const key = action + ':' + (params.get('q') || params.get('public_id') || '');
      if (delayed.has(key)) return delayed.get(key).promise;
      if (overrides.has(action)) return overrides.get(action)({ body, params });
      const response = action === 'sesi' ? { ok: true, csrf: 'synthetic-csrf', admin: { name: 'Pemilik ujian', email: 'admin@example.invalid', role: 'owner' } }
        : action === 'pelanggan' ? list()
        : action === 'butiran' ? detail(params.get('public_id'))
        : { ok: true, csrf: 'synthetic-csrf', result: { status: 'succeeded', message: 'Tindakan diselesaikan.' } };
      return { ok: true, status: 200, json: async () => clone(response) };
    }
  });
  new Script(source, { filename: 'pemilik.js' }).runInContext(sandbox);
  await runInContext('ownerBoot', sandbox);
  return {
    sandbox, nodes, node, requests, delayed, overrides, metrics, filters, list,
    call: (name, ...args) => sandbox[name](...args),
    evaluate: expression => runInContext(expression, sandbox),
    response: data => ({ ok: true, status: 200, json: async () => clone(data) })
  };
}

const f = await fixture();
check('Only a confirmed platform-admin session opens the dashboard', f.node('dashboard').hidden === false && f.requests[0].action === 'sesi');
check('Customer names are escaped before rendering', f.node('customerRows').innerHTML.includes('&lt;img') && !f.node('customerRows').innerHTML.includes('<img'));
check('Zero values display as zero rather than invented activity', f.metrics.find(n => n.dataset.metric === 'ai_enabled').textContent === '0');
check('Dates are explicitly rendered in Malaysia time', /09:00|9:00/.test(f.call('dateText', '2026-10-26T01:00:00Z')));
check('Unverified messaging is described as missing evidence, not a failed number', f.call('connectionCard', detail('customer-a').connections[0]).includes('Belum ada bukti mesej'));
check('Uncertain registration hides the activation button even if a stale permission says true', !f.call('connectionCard', { ...detail('customer-a').connections[0], operation_status: 'unknown' }).includes('data-action="activate"'));
check('A missing platform-access status is not reported as active', f.call('customerRow', { ...customer('customer-a'), access_status: undefined }).includes('Belum disahkan'));
await f.call('openDetail', 'customer-a');
f.call('requestAction', 'activate', 101);
check('Confirmation identifies the selected business and phone', f.node('confirmTarget').innerHTML.includes('+601100000001') && f.node('confirmTarget').innerHTML.includes('Perniagaan A'));
check('Opening confirmation does not perform the action', f.requests.filter(r => r.action === 'activate').length === 0);
const held = deferred();
f.overrides.set('activate', () => held.promise);
const first = f.call('executeAction');
const second = f.call('executeAction');
await second;
check('Repeated clicks while a write is pending send only one request', f.requests.filter(r => r.action === 'activate').length === 1);
const activation = f.requests.find(r => r.action === 'activate');
check('Activation uses CSRF, confirmation and explicit bound target', activation.options.headers['X-CSRF-Token'] === 'synthetic-csrf' && activation.body.public_id === 'customer-a' && activation.body.connection_id === 101 && activation.body.expected_phone === '+601100000001' && activation.body.confirmed === true);
held.resolve(f.response({ ok: true, result: { status: 'unknown', message: 'Keputusan perlu disahkan.' } }));
await first;
check('Unknown outcome is a warning, never a success', f.node('detailNotice').className.includes('is-warning') && !f.node('detailNotice').className.includes('is-success'));
check('Unknown mutation is not retried automatically', f.requests.filter(r => r.action === 'activate').length === 1);

f.overrides.set('activate', () => { throw new Error('Synthetic transport loss'); });
f.call('requestAction', 'activate', 101);
await f.call('executeAction');
check('Lost mutation response does not claim success', f.node('detailNotice').textContent.includes('Keputusan tindakan belum diterima') && f.node('detailNotice').className.includes('is-warning'));
check('Lost mutation response is not retried', f.requests.filter(r => r.action === 'activate').length === 2);

f.overrides.set('activate', () => f.response({ ok: true }));
f.call('requestAction', 'activate', 101);
await f.call('executeAction');
check('Malformed success payload is not rendered as success', f.node('detailNotice').textContent.includes('belum dapat dipastikan') && f.node('detailNotice').className.includes('is-warning'));

f.call('requestAction', 'access');
check('Access suspension is separate from WhatsApp activation', f.evaluate('state.pending.body.status') === 'suspended' && f.evaluate('state.pending.body.expected_status') === 'active' && !f.evaluate('"connection_id" in state.pending.body'));
f.call('cancelConfirm');
f.call('requestAction', 'ai');
check('AI activation uses the saved revision and separate operation', f.evaluate('state.pending.action') === 'ai' && f.evaluate('state.pending.body.expected_revision') === 3 && f.evaluate('state.pending.body.enabled') === true);
f.call('cancelConfirm');
f.evaluate('state.detail.ai.can_enable = false');
f.call('requestAction', 'ai');
check('Unavailable AI cannot be enabled through the UI', f.evaluate('state.pending') === null);
f.evaluate('state.detail.ai.can_enable = true');
f.call('requestAction', 'activate', 999);
check('A connection not belonging to the selected customer cannot be submitted', f.evaluate('state.pending') === null);

const oldList = deferred(), newList = deferred();
f.delayed.set('pelanggan:old', oldList);
f.delayed.set('pelanggan:new', newList);
f.evaluate("state.query = 'old'");
const oldRequest = f.call('loadCustomers');
f.evaluate("state.query = 'new'");
const newRequest = f.call('loadCustomers');
newList.resolve(f.response(f.list(['customer-b'])));
await newRequest;
oldList.resolve(f.response(f.list(['customer-a'])));
await oldRequest;
check('A late older search cannot overwrite newer customer results', f.node('customerRows').innerHTML.includes('Perniagaan B') && !f.node('customerRows').innerHTML.includes('Perniagaan A'));

const oldDetail = deferred(), newDetail = deferred();
f.delayed.set('butiran:customer-a', oldDetail);
f.delayed.set('butiran:customer-b', newDetail);
const requestA = f.call('openDetail', 'customer-a');
const requestB = f.call('openDetail', 'customer-b');
newDetail.resolve(f.response(detail('customer-b')));
await requestB;
oldDetail.resolve(f.response(detail('customer-a')));
await requestA;
check('A delayed detail response cannot switch the selected customer', f.node('detailTitle').textContent === 'Perniagaan B' && f.evaluate('state.detail.customer.public_id') === 'customer-b');
f.call('requestAction', 'activate', 102);
f.evaluate("state.selected = 'customer-a'");
await f.call('executeAction');
check('Changing customer after confirmation cancels the mutation', f.requests.filter(r => r.action === 'activate').length === 3);

const denied = await fixture({ denied: 403 });
check('Tenant-only or forbidden accounts do not load customer data', denied.node('dashboard').hidden === true && denied.requests.length === 1 && denied.node('gateTitle').textContent === 'Akses pemilik diperlukan');
const expired = await fixture({ denied: 401 });
check('Expired sessions display the account sign-in gate', expired.node('gateTitle').textContent === 'Masuk dengan akaun pemilik' && expired.requests.length === 1);
check('Auth-return link is a fixed local destination', html.includes('../ruang.html?tujuan=pemilik#masuk'));
check('Mobile layout, reduced motion and visible keyboard focus are supported', css.includes('@media(max-width:680px)') && css.includes('prefers-reduced-motion') && css.includes(':focus-visible'));
console.log(`Passed ${checks} focused admin UI checks.`);
