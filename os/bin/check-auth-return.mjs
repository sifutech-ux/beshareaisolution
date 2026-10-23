import { readFileSync } from 'node:fs';
import { Script, createContext } from 'node:vm';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../../whatsapp/ruang.js', import.meta.url), 'utf8');
const script = new Script(source, { filename: 'whatsapp/ruang.js' });
const returnKey = 'beshare-os-auth-return';
let checks = 0;

function check(name, condition) {
  assert.ok(condition, name);
  console.log('OK ' + name);
  checks += 1;
}

function fixture({ hash = '#masuk', role = 'owner', authenticated = false, storage = new Map(), deniedStorage = false } = {}) {
  const calls = [];
  const metaLaunches = [];
  const handlers = new Map();
  const nodes = new Map();
  const nav = { scrollLeft: 0 };
  let current = authenticated;
  let authAccepted = true;
  let currentHash = hash;
  const session = () => current ? {
    ok: true,
    csrf: 'synthetic-csrf',
    user: { name: 'Synthetic owner', email: 'owner@example.invalid', role },
    business: { name: 'Synthetic business', public_id: 'synthetic-business', status: 'active' },
    members: [], memberships: [], audit: [], contacts: [], conversations: [], connection: {}
  } : { ok: true, csrf: 'synthetic-csrf' };
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', hidden: true, addEventListener() {} });
    return nodes.get(id);
  };
  class FakeForm {
    constructor(kind) { this.kind = kind; }
    getAttribute(name) { return name === 'id' ? 'auth' : name === 'data-kind' ? this.kind : null; }
  }
  class FakeFormData {
    get(name) {
      return ({
        email: 'owner@example.invalid',
        password: 'synthetic-password-123',
        password2: 'synthetic-password-123',
        name: 'Synthetic owner',
        business: 'Synthetic business'
      })[name] || '';
    }
  }
  const location = { origin: 'https://fixture.invalid', pathname: '/whatsapp/ruang.html', search: '' };
  Object.defineProperty(location, 'hash', {
    get: () => currentHash,
    set: value => {
      const next = value ? '#' + String(value).replace(/^#/, '') : '';
      if (next === currentHash) return;
      currentHash = next;
      queueMicrotask(() => (handlers.get('hashchange') || []).forEach(fn => fn()));
    }
  });
  const sessionStorage = {
    getItem(key) {
      if (deniedStorage) throw new Error('Storage denied');
      return storage.get(key) ?? null;
    },
    setItem(key, value) {
      if (deniedStorage) throw new Error('Storage denied');
      storage.set(key, String(value));
    },
    removeItem(key) {
      if (deniedStorage) throw new Error('Storage denied');
      storage.delete(key);
    }
  };
  const window = {
    addEventListener(name, fn) {
      if (!handlers.has(name)) handlers.set(name, []);
      handlers.get(name).push(fn);
    },
    setTimeout: () => 1,
    clearTimeout() {},
    FB: { init() {}, login: () => metaLaunches.push('login') }
  };
  const context = createContext({
    window, location, sessionStorage, HTMLFormElement: FakeForm, FormData: FakeFormData,
    URL, URLSearchParams, navigator: { userAgent: 'Synthetic route fixture' },
    history: { replaceState() {} },
    requestAnimationFrame: fn => fn(),
    document: {
      documentElement: { dataset: {} }, visibilityState: 'visible',
      getElementById: id => node(id), querySelector: selector => selector === '.nav-links' ? nav : null,
      addEventListener() {}, head: { appendChild() {} }, createElement: () => ({})
    },
    fetch: async (url, init = {}) => {
      if (url === 'sambungan/awam.php') {
        calls.push('awam');
        return { ok: true, json: async () => ({ ready: true, appId: '123456', configId: '654321', version: 'v26.0' }) };
      }
      assert.match(url, /^akaun\/pintu\.php\?tindakan=/, 'fixture denies nonlocal URLs');
      const action = new URL(url, location.origin).searchParams.get('tindakan');
      calls.push(action);
      assert.ok(['sesi', 'masuk', 'daftar', 'keluar'].includes(action), 'fixture denies Meta exchange or other actions');
      if (action === 'masuk' || action === 'daftar') {
        if (!authAccepted) return { ok: false, json: async () => ({ ok: false, error: 'Synthetic login rejection' }) };
        assert.equal(init.method, 'POST');
        current = true;
      }
      if (action === 'keluar') current = false;
      return { ok: true, json: async () => session() };
    }
  });
  script.runInContext(context);
  async function settle() {
    for (let i = 0; i < 3; i += 1) await new Promise(resolve => setImmediate(resolve));
  }
  return {
    storage, calls, metaLaunches, settle,
    hash: () => currentHash,
    html: () => node('app').innerHTML,
    rejectAuth: () => { authAccepted = false; },
    acceptAuth: () => { authAccepted = true; },
    async submit(kind = 'masuk') {
      context.onSubmit({ target: new FakeForm(kind), preventDefault() {} });
      await settle();
    },
    async logout() {
      const button = { tagName: 'BUTTON', getAttribute: name => name === 'data-act' ? 'logout' : '' };
      context.onClick({ type: 'click', target: { closest: selector => selector === '[data-act]' ? button : null } });
      await settle();
    }
  };
}

{
  const f = fixture({ hash: '#sambungan' });
  await f.settle();
  check('anonymous connection link opens login and retains the destination in this tab',
    f.hash() === '#masuk' && f.storage.get(returnKey) === 'sambungan');
  await f.submit();
  check('owner login returns to the real connection screen',
    f.hash() === '#sambungan' && f.html().includes('<h1>Sambungan WhatsApp</h1>'));
  check('successful login consumes the saved destination', !f.storage.has(returnKey));
  check('returning to connection requires a fresh click before Meta starts',
    f.metaLaunches.length === 0 && f.calls.every(action => !['sambung', 'tukar', 'peristiwa'].includes(action)));
}

{
  const first = fixture({ hash: '#sambungan' });
  await first.settle();
  const refreshed = fixture({ hash: first.hash(), storage: first.storage });
  await refreshed.settle();
  await refreshed.submit();
  check('refreshing the login page preserves the intended connection destination',
    refreshed.hash() === '#sambungan' && !refreshed.storage.has(returnKey));
}

{
  const f = fixture({ hash: '#sambungan' });
  await f.settle();
  await f.submit('daftar');
  check('successful owner registration also returns to connection', f.hash() === '#sambungan');
  check('registration consumes the destination without launching Meta',
    !f.storage.has(returnKey) && f.metaLaunches.length === 0);
}

{
  const f = fixture({ hash: '#sambungan' });
  await f.settle();
  f.rejectAuth();
  await f.submit();
  check('failed login retains the destination for retry',
    f.hash() === '#masuk' && f.storage.get(returnKey) === 'sambungan');
  f.acceptAuth();
  await f.submit();
  check('a successful retry restores the destination', f.hash() === '#sambungan');
}

for (const role of ['staff', 'admin', 'unexpected-role']) {
  const f = fixture({ hash: '#sambungan', role });
  await f.settle();
  await f.submit();
  check(role + ' cannot use saved connection navigation to bypass the owner gate',
    f.hash() === '#papan' && !f.storage.has(returnKey) && f.metaLaunches.length === 0);
}

for (const target of ['https://outside.invalid/', '//outside.invalid/', 'javascript:alert(1)', 'pengendali', 'SAMBUNGAN', '#sambungan']) {
  const f = fixture({ storage: new Map([[returnKey, target]]) });
  await f.settle();
  await f.submit();
  check('invalid saved target is discarded: ' + target,
    f.hash() === '#papan' && !f.storage.has(returnKey) && f.metaLaunches.length === 0);
}

{
  const f = fixture({ hash: '#peti', storage: new Map([[returnKey, 'sambungan']]) });
  await f.settle();
  await f.submit();
  check('another protected link clears an older connection destination', f.hash() === '#papan');
}

{
  const f = fixture({ hash: '#papan', authenticated: true, storage: new Map([[returnKey, 'sambungan']]) });
  await f.settle();
  await f.logout();
  check('logout clears pending return state and opens login',
    f.hash() === '#masuk' && !f.storage.has(returnKey));
  await f.submit();
  check('login after logout follows the ordinary dashboard route', f.hash() === '#papan');
}

{
  const f = fixture({ hash: '#sambungan', deniedStorage: true });
  await f.settle();
  await f.submit();
  check('denied browser storage still permits an owner return during this page visit', f.hash() === '#sambungan');
  await f.logout();
  await f.submit();
  check('logout clears the memory fallback when browser storage is denied', f.hash() === '#papan');
}

{
  const f = fixture({ hash: '#masuk', deniedStorage: true });
  await f.settle();
  await f.submit();
  check('fresh page without usable persisted state safely falls back to dashboard', f.hash() === '#papan');
}

{
  const waitingTab = fixture({ hash: '#sambungan' });
  const otherTab = fixture();
  await waitingTab.settle();
  await otherTab.settle();
  await otherTab.submit();
  check('a separate tab without the pending destination retains ordinary login behavior',
    otherTab.hash() === '#papan' && waitingTab.storage.get(returnKey) === 'sambungan');
}

console.log('PASS ' + checks + ' auth-return checks; real workspace script in a synthetic VM, no network');

