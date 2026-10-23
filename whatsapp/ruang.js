const UI_KEY = "beshare-os-ui";
const AUTH_RETURN_KEY = "beshare-os-auth-return";
let authReturn = null;
let live = null;
let csrf = "";
let ready = false;
let paintedHash = "";

const NAV = [
  ["papan", "Papan", "workspace"],
  ["peti", "Peti masuk", "workspace"],
  ["kenalan", "Kenalan", "workspace"],
  ["pengetahuan", "Pengetahuan", "knowledge"],
  ["automasi", "Automasi", "knowledge"],
  ["analitik", "Analitik", "workspace"],
  ["pasukan", "Pasukan", "team"],
  ["sambungan", "Sambungan", "connection"],
  ["bil", "Bil", "billing"],
  ["tetapan", "Tetapan", "settings"],
  ["audit", "Audit", "audit"],
  ["pengendali", "Pengendali", "operator"]
];

function setAuthReturn(hash) {
  authReturn = hash === "sambungan" ? "sambungan" : "";
  try {
    if (authReturn) sessionStorage.setItem(AUTH_RETURN_KEY, authReturn);
    else sessionStorage.removeItem(AUTH_RETURN_KEY);
  } catch {
    /* Kekalkan destinasi dalam tab ini jika storan pelayar tidak tersedia. */
  }
}

function consumeAuthReturn(state) {
  if (authReturn === null) {
    try {
      authReturn = sessionStorage.getItem(AUTH_RETURN_KEY) === "sambungan" ? "sambungan" : "";
    } catch {
      authReturn = "";
    }
  }
  const target = authReturn;
  setAuthReturn("");
  return target === "sambungan" && state && role(state) === "owner" ? "sambungan" : "papan";
}

function blankUi() {
  return {
    theme: "gelap",
    templateDraft: { name: "", language: "ms", body: "", savedAt: "" },
    inboxQuery: "",
    inboxFilter: "semua",
    inboxPane: "list",
    contactQuery: "",
    contactTag: "semua",
    activeContact: "",
    editingRule: "",
    notice: "",
    modal: "",
    industry: "",
    language: "ms",
    hours: "",
    active: ""
  };
}

function loadUi() {
  const base = blankUi();
  try {
    const parsed = JSON.parse(sessionStorage.getItem(UI_KEY) || "null");
    if (!parsed || typeof parsed !== "object") return base;
    const draft = parsed.templateDraft && typeof parsed.templateDraft === "object" ? parsed.templateDraft : {};
    return {
      ...base,
      theme: parsed.theme === "cerah" ? "cerah" : "gelap",
      templateDraft: {
        name: typeof draft.name === "string" ? draft.name : "",
        language: draft.language === "en" ? "en" : "ms",
        body: typeof draft.body === "string" ? draft.body : "",
        savedAt: typeof draft.savedAt === "string" ? draft.savedAt : ""
      },
      inboxQuery: typeof parsed.inboxQuery === "string" ? parsed.inboxQuery : "",
      inboxFilter: typeof parsed.inboxFilter === "string" ? parsed.inboxFilter : "semua",
      inboxPane: typeof parsed.inboxPane === "string" ? parsed.inboxPane : "list",
      contactQuery: typeof parsed.contactQuery === "string" ? parsed.contactQuery : "",
      contactTag: typeof parsed.contactTag === "string" ? parsed.contactTag : "semua",
      activeContact: typeof parsed.activeContact === "string" ? parsed.activeContact : "",
      editingRule: typeof parsed.editingRule === "string" ? parsed.editingRule : "",
      notice: typeof parsed.notice === "string" ? parsed.notice : "",
      modal: typeof parsed.modal === "string" ? parsed.modal : "",
      industry: typeof parsed.industry === "string" ? parsed.industry : "",
      language: parsed.language === "en" ? "en" : "ms",
      hours: typeof parsed.hours === "string" ? parsed.hours : "",
      active: typeof parsed.active === "string" ? parsed.active : ""
    };
  } catch {
    return base;
  }
}

function load() {
  if (!live || !live.user || !live.business) return null;
  const ui = loadUi();
  return {
    ...ui,
    user: live.user,
    business: {
      name: live.business.name,
      public_id: live.business.public_id,
      status: live.business.status,
      industry: ui.industry,
      language: ui.language,
      hours: ui.hours
    },
    team: Array.isArray(live.members) ? live.members : [],
    memberships: Array.isArray(live.memberships) ? live.memberships : [],
    audit: Array.isArray(live.audit) ? live.audit : [],
    knowledge: [],
    contacts: Array.isArray(live.contacts) ? live.contacts : [],
    conversations: Array.isArray(live.conversations) ? live.conversations : [],
    connection: serverConnection(),
    ai: live.ai && typeof live.ai === "object" ? live.ai : { available: false, enabled: false },
    inboxReady: live.inbox_ready === true,
    rules: [],
    invoices: [],
    plan: "",
    billStatus: "",
    persona: "",
    onboarding: { step: 1, done: true }
  };
}

function save(state) {
  if (!state) return;
  const ui = loadUi();
  sessionStorage.setItem(UI_KEY, JSON.stringify({
    ...ui,
    theme: state.theme === "cerah" ? "cerah" : "gelap",
    templateDraft: state.templateDraft || ui.templateDraft,
    inboxQuery: state.inboxQuery || "",
    inboxFilter: state.inboxFilter || "semua",
    inboxPane: state.inboxPane || "list",
    contactQuery: state.contactQuery || "",
    contactTag: state.contactTag || "semua",
    activeContact: state.activeContact || "",
    editingRule: state.editingRule || "",
    notice: state.notice || "",
    modal: state.modal || "",
    industry: state.business && typeof state.business.industry === "string" ? state.business.industry : ui.industry,
    language: state.business && state.business.language === "en" ? "en" : "ms",
    hours: state.business && typeof state.business.hours === "string" ? state.business.hours : ui.hours,
    active: state.active || ""
  }));
}

function serverConnection() {
  const source = live && live.connection && typeof live.connection === "object" ? live.connection : {};
  return {
    token: typeof source.token === "string" && source.token ? source.token : "Belum",
    webhook: typeof source.webhook === "string" && source.webhook ? source.webhook : "Belum",
    registration: typeof source.registration === "string" && source.registration ? source.registration : "Belum",
    messaging: typeof source.messaging === "string" && source.messaging ? source.messaging : "Belum",
    note: typeof source.note === "string" ? source.note : "",
    phone: typeof source.phone === "string" ? source.phone : ""
  };
}

function applyLive(data) {
  if (data && typeof data.csrf === "string") csrf = data.csrf;
  if (!data || data.ok !== true || !data.user || !data.business) {
    live = null;
    return;
  }
  live = data;
}

async function callApi(action, body) {
  const headers = { Accept: "application/json" };
  const init = { method: body ? "POST" : "GET", credentials: "same-origin", headers, cache: "no-store" };
  if (body) {
    headers["Content-Type"] = "application/json";
    headers["X-CSRF-Token"] = csrf;
    init.body = JSON.stringify(body);
  }
  let data = { ok: false, error: "Pelayan tidak menjawab." };
  try {
    const response = await fetch("akaun/pintu.php?tindakan=" + encodeURIComponent(action), init);
    const parsed = await response.json();
    data = parsed && typeof parsed === "object" ? parsed : data;
    if (!response.ok && data.ok !== false) data.ok = false;
    if (!response.ok && !data.error) data.error = "Permintaan gagal.";
  } catch {
    data = { ok: false, error: "Pelayan tidak menjawab." };
  }
  if (typeof data.csrf === "string") csrf = data.csrf;
  return data;
}

function esc(value) {
  return String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;"
  }[char]));
}

function role(state) {
  const value = state && state.user ? state.user.role : "";
  return value === "owner" || value === "admin" || value === "staff" ? value : "staff";
}

function allow(state, key) {
  const denied = {
    staff: ["knowledge", "team", "connection", "billing", "settings", "audit", "operator"],
    admin: ["connection", "billing", "operator"],
    owner: []
  };
  return !(denied[role(state)] || []).includes(key);
}

function stamp() {
  return new Date().toISOString().slice(0, 16).replace("T", " ");
}

function log(state, action) {
  state.audit.unshift({ at: stamp(), actor: state.user.name, action });
}

function label(mode) {
  return {
    ai_active: "AI aktif",
    human_takeover: "Manusia mengambil alih",
    ai_paused: "AI dijeda",
    resolved: "Selesai"
  }[mode] || mode;
}

function actionLabel(action) {
  return {
    sambutan: "Sambutan",
    balas_pengetahuan: "Balas dari pengetahuan",
    serahan: "Serah kepada manusia",
    luar_waktu: "Balasan luar waktu",
    hantar_luar_tetingkap: "Hantar di luar tetingkap",
    picu_diri: "Picu peraturan yang sama"
  }[action] || action;
}

function metrics(state) {
  const items = state.conversations;
  const incoming = items.length;
  const aiReplied = items.filter((item) => item.messages.some((message) => message.from === "ai" && ["sent", "delivered", "read"].includes(message.status))).length;
  const human = items.filter((item) => item.mode === "human_takeover").length;
  const unanswered = items.filter((item) => item.messages.filter((message) => message.from === "customer" || ["sent", "delivered", "read"].includes(message.status)).at(-1)?.from === "customer" && item.mode !== "resolved").length;
  const deliveries = items.flatMap((item) => item.messages);
  const sent = deliveries.filter((message) => message.status === "sent" || message.status === "delivered" || message.status === "read").length;
  const failed = deliveries.filter((message) => message.status === "failed").length;
  const handover = incoming ? Math.round((human / incoming) * 100) : 0;
  return { incoming, aiReplied, human, unanswered, sent, failed, handover };
}

function visibleConversations(state) {
  let list = state.conversations.slice();
  if (role(state) === "staff") list = list.filter((item) => !item.assignee || item.assignee === state.user.name);
  const query = (state.inboxQuery || "").trim().toLowerCase();
  if (query) list = list.filter((item) => (item.name + " " + item.tag).toLowerCase().includes(query));
  const filter = state.inboxFilter || "semua";
  if (filter === "belum") list = list.filter((item) => item.unread);
  if (filter === "saya") list = list.filter((item) => item.assignee === state.user.name);
  if (filter === "ai") list = list.filter((item) => item.mode === "ai_active" || item.mode === "ai_paused");
  if (filter === "manusia") list = list.filter((item) => item.mode === "human_takeover");
  if (filter === "selesai") list = list.filter((item) => item.mode === "resolved");
  return list;
}

function currentConversation(state) {
  return state.conversations.find((item) => item.id === state.active) || null;
}

function businessStatus(status) {
  return { provisioning: "Sedang disediakan", active: "Aktif", suspended: "Digantung" }[status] || status;
}

function roleLabel(value) {
  return { owner: "Pemilik", admin: "Admin", staff: "Staf" }[value] || value;
}

function applyTheme(state) {
  document.documentElement.dataset.theme = state && state.theme === "cerah" ? "cerah" : "gelap";
}

function route() {
  if (!ready) return;
  const bar = document.querySelector(".nav-links");
  const kept = bar ? bar.scrollLeft : 0;
  const state = load();
  applyTheme(state);
  const hash = location.hash.replace("#", "") || (state ? "papan" : "masuk");
  if (state && paintedHash && paintedHash !== hash && state.notice) {
    state.notice = "";
    save(state);
  }
  paintedHash = hash;
  const root = document.getElementById("app");
  const open = ["daftar", "masuk", "lupa", "sahkan"];
  if (!open.includes(hash) && !state) {
    setAuthReturn(hash);
    location.hash = "masuk";
    return;
  }
  if (hash === "daftar" || hash === "masuk" || hash === "lupa") root.innerHTML = authView(hash, state);
  else if (hash === "sahkan") root.innerHTML = `<main class="page"><section class="panel" style="max-width:560px;margin:2rem auto;"><h1>Pengesahan e-mel</h1><p class="muted">Pengesahan e-mel tidak digunakan. Tiada e-mel dihantar.</p><p><a href="#masuk">Masuk</a></p></section></main>`;
  else if (hash === "mula") root.innerHTML = shell(state, "papan", board(state));
  else if (!NAV.some(([id]) => id === hash)) root.innerHTML = shell(state, "papan", board(state));
  else {
    const item = NAV.find(([id]) => id === hash);
    root.innerHTML = allow(state, item[2])
      ? shell(state, hash, views[hash](state))
      : shell(state, hash, `<article class="panel"><h1>Skrin dikunci</h1><p class="muted">Peranan anda tidak membuka skrin ini.</p></article>`);
  }
  const place = () => {
    const next = document.querySelector(".nav-links");
    if (next) next.scrollLeft = kept;
  };
  place();
  requestAnimationFrame(place);
  if (hash === "sambungan") prepareSignup();
  if (state && (hash === "peti" || hash === "kenalan" || hash === "papan" || hash === "sambungan" || hash === "pengetahuan") && inboxLoad !== hash) {
    const requested = hash;
    inboxLoad = hash;
    const action = hash === "peti" || hash === "kenalan" ? "peti" : "sesi";
    callApi(action).then((data) => {
      if (data && data.user) applyLive(data);
      const current = location.hash.replace("#", "") || "papan";
      if (current === requested || (requested === "papan" && current === "mula")) route();
    });
  }
}

const views = {
  papan: board,
  peti: inbox,
  kenalan: contacts,
  pengetahuan: knowledge,
  automasi: automation,
  analitik: analytics,
  pasukan: team,
  sambungan: connection,
  bil: billing,
  tetapan: settings,
  audit: auditView,
  pengendali: operator
};

function authView(kind) {
  const title = { daftar: "Daftar", masuk: "Masuk", lupa: "Lupa kata laluan" }[kind];
  const extra = kind === "daftar"
    ? `<label class="field">Sahkan kata laluan<input name="password2" type="password" required minlength="10" autocomplete="new-password"></label>`
    : "";
  const nameField = kind === "daftar"
    ? `<label class="field">Nama<input name="name" required autocomplete="name"></label>
       <label class="field">Nama perniagaan<input name="business" required autocomplete="organization"></label>`
    : "";
  const fields = kind === "lupa"
    ? `<label class="field">E-mel<input name="email" type="email" required autocomplete="username"></label>`
    : `${nameField}
       <label class="field">E-mel<input name="email" type="email" required placeholder="nama@kedai.com" autocomplete="username"></label>
       <label class="field">Kata laluan<input name="password" type="password" required minlength="10" maxlength="72" autocomplete="${kind === "daftar" ? "new-password" : "current-password"}"></label>
       ${extra}`;
  return `<main class="page"><section class="panel" style="max-width:480px;margin:2rem auto;">
    <p class="demo-banner">Akaun disimpan di pelayan. Kata laluan tidak disimpan dalam pelayar.</p>
    <h1>${title}</h1>
    <form id="auth" data-kind="${kind}">
      ${fields}
      <p class="error" id="form-error" hidden></p>
      <button class="btn" type="submit">${title}</button>
    </form>
    <p class="muted"><a href="#daftar">Daftar</a> · <a href="#masuk">Masuk</a> · <a href="#lupa">Lupa kata laluan</a></p>
  </section></main>`;
}

function shell(state, hash, body) {
  const links = NAV.filter((item) => allow(state, item[2])).map(([id, name]) =>
    `<a class="${hash === id ? "is-on" : ""}" href="#${id}" ${hash === id ? 'aria-current="page"' : ""}>${esc(name)}</a>`
  ).join("");
  const modal = state.modal === "release" ? `<div class="modal"><div class="panel">
      <h2>Pulangkan kepada AI?</h2>
      <p class="muted">Balasan automatik akan hidup semula. Staf dan AI tidak membalas serentak.</p>
      <p class="actions"><button class="btn" type="button" data-act="release-yes">Ya, pulangkan</button>
      <button class="btn btn--ghost" type="button" data-act="modal-close">Batal</button></p>
    </div></div>` : "";
  return `<div class="shell">
    <aside class="nav">
      <div class="nav-brand">
        <strong>BeShare AI OS</strong>
        <p class="muted">${esc(state.business.name)}</p>
      </div>
      <div class="nav-links">${links}</div>
      <div class="nav-tools">
        ${(state.memberships || []).length > 1 ? `<label class="muted">Perniagaan
          <select data-act="pilih" aria-label="Perniagaan">
            ${state.memberships.map((item) => `<option value="${esc(item.public_id)}" ${item.public_id === state.business.public_id ? "selected" : ""}>${esc(item.name)}</option>`).join("")}
          </select>
        </label>` : ""}
        <button class="btn btn--ghost" type="button" data-act="theme">${state.theme === "cerah" ? "Mod gelap" : "Mod cerah"}</button>
        <button class="btn btn--ghost" type="button" data-act="logout">Keluar</button>
      </div>
    </aside>
    <section>
      <p class="demo-banner">${state.ai.available ? "Peti masuk dan tetapan AI disimpan pada pelayan. Ujian AI hanya membalas nombor yang ditetapkan dalam Pengetahuan. Peraturan automasi, analitik dan bil belum disambung." : state.inboxReady ? "Akaun, pasukan, dan keadaan sambungan datang dari pelayan. Peti masuk hanya memaparkan mesej webhook yang disahkan. Pengetahuan, automasi, analitik, dan bil belum disambung." : "Akaun dan pasukan datang dari pelayan. Sambungan Meta belum dikonfigurasi pada pelayan ini. Peti masuk, pengetahuan, automasi, analitik, dan bil belum disambung."}</p>
      ${state.notice ? `<p class="error">${esc(state.notice)}</p>` : ""}
      ${body}
    </section>
  </div>${modal}`;
}

function board(state) {
  const count = metrics(state);
  const enabled = state.rules.filter((item) => item.enabled).length;
  return `<article class="panel">
    <h1>Papan ${esc(state.business.name)}</h1>
    <p class="muted">Status: ${esc(businessStatus(state.business.status))}. Peranan: ${esc(roleLabel(role(state)))}. Automasi hidup: ${enabled}. ${state.inboxReady ? "Peti masuk memaparkan mesej webhook yang disahkan." : "Peti masuk belum disambung."}</p>
    <div class="states">
      ${Object.entries({ Token: state.connection.token, Webhook: state.connection.webhook, Pendaftaran: state.connection.registration, Mesej: state.connection.messaging }).map(([name, value]) =>
        `<article class="state"><strong>${esc(name)}</strong><span>${esc(value)}</span></article>`).join("")}
    </div>
    <div class="os-kpis">
      <article class="state os-kpi"><strong>${count.incoming}</strong><span>${state.inboxReady ? "Perbualan daripada webhook yang disahkan." : "Perbualan masuk. Belum disambung ke pelayan."}</span></article>
      <article class="state os-kpi"><strong>${count.aiReplied}</strong><span>Perbualan yang ada balasan AI.</span></article>
      <article class="state os-kpi"><strong>${count.human}</strong><span>Sedang dipegang manusia.</span></article>
      <article class="state os-kpi"><strong>${count.unanswered}</strong><span>Mesej terakhir daripada pelanggan, belum selesai.</span></article>
    </div>
    <h2>Aktiviti terkini</h2>
    <ul>${state.audit.slice(0, 5).map((item) => `<li>${esc(item.at)} · ${esc(item.actor)} · ${esc(item.action)}</li>`).join("")}</ul>
    <p><a class="btn" href="#peti">Buka peti masuk</a></p>
  </article>`;
}

function inbox(state) {
  const list = visibleConversations(state);
  const current = list.find((item) => item.id === state.active) || null;
  const buttons = list.map((item) =>
    `<button class="thread-item ${item.id === state.active ? "is-on" : ""}" type="button" data-act="open" data-value="${esc(item.id)}"><span>${esc(item.name)}</span><small>${esc(label(item.mode))}${item.unread ? " · belum dibaca" : ""}</small></button>`
  ).join("") || `<p class="muted">${state.conversations.length ? "Tiada hasil." : (state.inboxReady ? "Belum ada mesej webhook yang disahkan." : "Peti masuk belum disambung ke pelayan.")}</p>`;
  const filters = [["semua", "Semua"], ["belum", "Belum dibaca"], ["saya", "Tugasan saya"], ["ai", "AI"], ["manusia", "Manusia"], ["selesai", "Selesai"]];
  return `<article class="panel inbox-screen" data-screen="${esc(state.inboxPane || "list")}">
    <h1>Peti masuk</h1>
    <p><button class="btn btn--ghost" type="button" data-act="refresh-inbox">Muat semula mesej</button></p>
    <div class="inbox-find">
    <label class="field">Cari<input name="q" value="${esc(state.inboxQuery || "")}" placeholder="Nama atau tag"></label>
    <div class="chips">${filters.map(([id, name]) => `<button class="btn btn--ghost ${state.inboxFilter === id ? "is-on" : ""}" type="button" data-act="filter" data-value="${id}">${name}</button>`).join("")}</div>
    </div>
    <div class="inbox" data-pane="${esc(state.inboxPane || "list")}">
      <div class="pane-list thread" id="thread">${buttons}</div>
      <div class="pane-talk">${talk(state, current)}</div>
      <aside class="pane-profile">${profile(state, current)}</aside>
    </div>
  </article>`;
}

function talk(state, current) {
  if (!current) return `<p class="muted">Pilih perbualan.</p><p><button class="btn btn--ghost" type="button" data-act="pane" data-value="list">Senarai</button></p>`;
  const who = { customer: "Pelanggan", ai: "AI", staff: "Staf" };
  const messages = current.messages.map((item) =>
    `<div class="msg ${item.from === "staff" ? "msg--staff" : ""}"><strong>${esc(who[item.from] || item.from)}</strong><p>${esc(item.text)}</p><small class="muted">${esc(item.wamid)} · ${esc(item.status || "")}</small></div>`
  ).join("");
  const back = `<div class="phone-bar"><button class="btn btn--ghost" type="button" data-act="pane" data-value="list">Senarai</button><strong>${esc(current.name)}</strong><button class="btn btn--ghost" type="button" data-act="pane" data-value="profile">Profil</button></div>`;
  if (current.mode === "resolved") {
    return `${back}<p>Mod: <strong>${esc(label(current.mode))}</strong></p><div class="msgs">${messages}</div>
      <p class="muted">Perbualan ditutup. Pembukaan semula perlu pilih mod.</p>
      <p class="actions"><button class="btn" type="button" data-act="reopen" data-value="ai_active">Buka semula sebagai AI</button>
      <button class="btn btn--ghost" type="button" data-act="reopen" data-value="human_takeover">Buka semula sebagai manusia</button></p>`;
  }
  const composer = current.window === "closed"
    ? `<p class="error">Tetingkap perkhidmatan pelanggan telah tamat. Mesej biasa tidak dihantar. Templat yang diluluskan diperlukan.</p>`
    : current.mode === "human_takeover"
      ? current.can_control ? `<p class="muted">AI dijeda untuk perbualan ini. Penghantaran manual oleh staf belum tersedia dalam peti masuk.</p>` : `<form id="reply"><label class="field">Balasan staf<input name="text" required></label><button class="btn" type="submit">Hantar</button></form>`
      : `<p class="muted">${current.mode === "ai_active" ? "AI aktif untuk mesej baharu yang memenuhi tetapan ujian. Ambil alih untuk menjeda balasan dalam perbualan ini." : "AI dijeda. Semak tetapan dalam Pengetahuan sebelum ujian seterusnya."}</p>`;
  const actions = current.mode === "human_takeover"
    ? `<button class="btn btn--ghost" type="button" data-act="release">Pulangkan kepada AI</button>${current.can_control ? "" : ' <button class="btn btn--ghost" type="button" data-act="resolve">Selesai</button>'}`
    : `<button class="btn" type="button" data-act="take">Ambil alih</button>`;
  return `${back}<p>Mod: <strong>${esc(label(current.mode))}</strong></p><div class="msgs">${messages}</div>${composer}<p class="actions">${actions}</p>`;
}

function profile(state, current) {
  if (!current) return `<div class="phone-bar"><button class="btn btn--ghost" type="button" data-act="pane" data-value="list">Senarai</button></div><p class="muted">Profil muncul selepas perbualan dipilih.</p>`;
  const contact = state.contacts.find((item) => item.conversationId === current.id);
  return `<div class="phone-bar"><button class="btn btn--ghost" type="button" data-act="pane" data-value="talk">Perbualan</button><strong>${esc(current.name)}</strong><button class="btn btn--ghost" type="button" data-act="pane" data-value="list">Senarai</button></div>
    <h2>${esc(current.name)}</h2>
    <p class="muted">${esc(contact ? contact.phone : "Tiada telefon.")}</p>
    <p>Tag: ${esc(current.tag)}<br>Ejen: ${esc(current.assignee)}<br>Tetingkap: ${current.window === "open" ? "dibuka" : "tamat"}</p>
    <p class="muted">${esc(current.note || "Tiada nota.")}</p>
    <p class="muted">Status penghantaran dipadankan dengan id mesej, bukan nombor telefon.</p>
    <button class="btn btn--ghost" type="button" data-act="assign-me">Tugaskan kepada saya</button>`;
}

function contacts(state) {
  const tags = ["semua", ...new Set(state.contacts.map((item) => item.tag))];
  let list = state.contacts.slice();
  if (role(state) === "staff") list = list.filter((item) => !item.assignee || item.assignee === state.user.name);
  const query = (state.contactQuery || "").trim().toLowerCase();
  if (query) list = list.filter((item) => (item.name + item.phone + item.tag).toLowerCase().includes(query));
  if (state.contactTag && state.contactTag !== "semua") list = list.filter((item) => item.tag === state.contactTag);
  const current = list.find((item) => item.id === state.activeContact) || list[0] || null;
  const rows = list.map((item) =>
    `<tr><td><button class="btn btn--ghost" type="button" data-act="contact" data-value="${esc(item.id)}">${esc(item.name)}</button></td><td>${esc(item.tag)}</td><td>${esc(item.assignee)}</td></tr>`
  ).join("") || `<tr><td colspan="3">${state.contacts.length ? "Tiada hasil." : (state.inboxReady ? "Belum ada kenalan daripada webhook." : "Kenalan belum disambung ke pelayan.")}</td></tr>`;
  const detail = current
    ? `<h2>${esc(current.name)}</h2><p>${esc(current.phone)}</p><p>Keutamaan: ${esc(current.priority)}</p><p class="muted">${esc(current.note || "Tiada nota.")}</p><p><a href="#peti">Buka perbualan berkaitan</a></p>`
    : `<p class="muted">Tiada profil.</p>`;
  return `<article class="panel">
    <h1>Kenalan</h1>
    <label class="field">Cari<input name="contact-q" value="${esc(state.contactQuery || "")}"></label>
    <div class="chips">${tags.map((tag) => `<button class="btn btn--ghost ${state.contactTag === tag ? "is-on" : ""}" type="button" data-act="tag" data-value="${esc(tag)}">${esc(tag)}</button>`).join("")}</div>
    <p class="actions"><button class="btn btn--ghost" type="button" disabled>Import belum dibuka</button> <button class="btn btn--ghost" type="button" disabled>Eksport belum dibuka</button></p>
    <table class="os-table"><thead><tr><th>Nama</th><th>Tag</th><th>Staf</th></tr></thead><tbody>${rows}</tbody></table>
    ${detail}
  </article>`;
}

function knowledge(state) {
  if (state.ai.available) return aiSettings(state);
  const rows = state.knowledge.map((item) =>
    `<tr><td>${esc(item.section)}</td><td>${esc(item.title)}</td><td>${esc(item.body)}</td><td>${item.enabled ? "Hidup" : "Mati"}</td>
      <td><button class="btn btn--ghost" type="button" data-act="know-toggle" data-value="${esc(item.id)}">${item.enabled ? "Matikan" : "Hidupkan"}</button></td></tr>`
  ).join("");
  return `<article class="panel">
    <h1>Pengetahuan</h1>
    <p class="muted">Persona: ${esc(state.persona)}</p>
    <table class="os-table"><thead><tr><th>Seksyen</th><th>Tajuk</th><th>Fakta</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table>
    <form id="know">
      <label class="field">Seksyen<select name="section"><option>Harga</option><option>Stok</option><option>FAQ</option><option>Penghantaran</option></select></label>
      <label class="field">Tajuk<input name="title" required></label>
      <label class="field">Fakta<textarea name="body" required></textarea></label>
      <button class="btn" type="submit">Simpan</button>
    </form>
    <p class="muted">Pengetahuan belum disambung ke pelayan. Borang ini tidak menyimpan rekod.</p>
    <h2>Playground</h2>
    <form id="play">
      <label class="field">Mesej pelanggan<input name="text" required placeholder="Tanya harga atau stok"></label>
      <button class="btn" type="submit">Jana jawapan</button>
    </form>
    <p id="answer" class="muted"></p>
    <p id="source" class="muted"></p>
  </article>`;
}

function aiSettings(state) {
  const ai = state.ai;
  if (!ai.can_manage) return `<article class="panel"><h1>Pengetahuan AI</h1><p>Pemilik perniagaan mengurus fakta dan pengaktifan AI. Mod semasa: ${ai.enabled && ai.deployment_ready ? "ujian aktif" : "dimatikan"}.</p></article>`;
  const connections = Array.isArray(ai.connections) ? ai.connections : [];
  return `<article class="panel">
    <h1>Pengetahuan dan ujian AI</h1>
    <p>AI menjawab menggunakan fakta yang anda sahkan di sini. Soalan yang memerlukan tindakan atau maklumat tambahan diserahkan kepada manusia.</p>
    <p><strong>${ai.enabled && ai.deployment_ready ? "Ujian AI aktif" : "Ujian AI dimatikan"}</strong> · Penggunaan hari ini: ${esc(ai.quota_used || 0)} / ${esc(ai.daily_limit || 20)} cubaan jawapan.</p>
    ${!ai.deployment_ready ? '<p class="muted">Pengaktifan AI pada pelayan belum tersedia. Anda boleh menyimpan fakta dan nombor ujian dahulu.</p>' : ""}
    ${ai.enabled ? '<p><button class="btn btn--ghost" type="button" data-act="ai-stop">Hentikan AI</button></p>' : ""}
    ${connections.length ? `<form id="ai-settings">
      <p class="error" id="form-error" hidden></p>
      <input name="revision" type="hidden" value="${esc(ai.revision || 0)}">
      <label class="field">Nombor perniagaan<select name="connection" required>${connections.map((c) => `<option value="${esc(c.id)}" ${Number(c.id) === Number(ai.connection_id) ? "selected" : ""}>${esc(c.display_phone || "Nombor belum disahkan")}</option>`).join("")}</select></label>
      <label class="field">Fakta yang boleh digunakan AI<textarea name="knowledge" rows="10" required maxlength="8000" placeholder="Nyatakan perkhidmatan, harga yang disahkan, waktu operasi dan cara mendapatkan bantuan.">${esc(ai.knowledge || "")}</textarea></label>
      <label class="field">Nombor telefon untuk ujian<textarea name="allowlist" rows="3" required placeholder="Satu nombor pada setiap baris, bermula dengan kod negara.">${esc((ai.allowlist || []).join("\n"))}</textarea></label>
      <p class="muted">Gunakan nombor telefon lain yang anda kawal untuk menghantar mesej kepada nombor perniagaan. Maksimum 10 nombor ujian.</p>
      <label class="field">Had cubaan jawapan sehari<input name="daily_limit" type="number" min="1" max="1000" required value="${esc(ai.daily_limit || 20)}"></label>
      <label><input name="approved" type="checkbox" required> Saya sahkan fakta ini sesuai digunakan dalam balasan kepada nombor ujian.</label>
      <p><label><input name="enabled" type="checkbox" ${ai.enabled && ai.deployment_ready ? "checked" : ""} ${ai.deployment_ready ? "" : "disabled"}> Aktifkan balasan untuk mesej baharu daripada nombor ujian.</label></p>
      <button class="btn" type="submit">Simpan fakta dan tetapan</button>
    </form>` : '<p>Sambungkan nombor perniagaan pada pelayan dahulu. Tetapan AI boleh disimpan selepas sambungan itu tersedia.</p>'}
    <p class="muted">Selepas menyimpan, hantar mesej baharu daripada nombor ujian. Lihat jawapan dan status penghantaran dalam Peti masuk. Perbualan yang diambil alih boleh dipulangkan kepada AI dari situ.</p>
    <p><a class="btn btn--ghost" href="#peti">Buka peti masuk</a></p>
  </article>`;
}

function automation(state) {
  const keywords = state.rules.filter((item) => item.enabled && item.keyword).map((item) => item.keyword);
  const rows = state.rules.map((item) => {
    const clash = item.enabled && item.keyword && keywords.filter((keyword) => keyword === item.keyword).length > 1;
    return `<tr><td>${esc(item.name)}</td><td>${esc(item.keyword || "—")}</td><td>${esc(actionLabel(item.action))}</td><td>${item.enabled ? "Hidup" : "Mati"}${clash ? " · bertindih" : ""}</td>
      <td><button class="btn btn--ghost" type="button" data-act="rule-toggle" data-value="${esc(item.id)}">${item.enabled ? "Matikan" : "Hidupkan"}</button>
      <button class="btn btn--ghost" type="button" data-act="rule-edit" data-value="${esc(item.id)}">Sunting</button></td></tr>`;
  }).join("") || `<tr><td colspan="5">Tiada peraturan.</td></tr>`;
  const editing = state.rules.find((item) => item.id === state.editingRule);
  const form = editing ? `<form id="rule">
      <input type="hidden" name="ruleId" value="${esc(editing.id)}">
      <label class="field">Nama<input name="name" required value="${esc(editing.name)}"></label>
      <label class="field">Kata kunci<input name="keyword" value="${esc(editing.keyword)}"></label>
      <label class="field">Tindakan<select name="action">
        ${["sambutan", "balas_pengetahuan", "serahan", "luar_waktu", "hantar_luar_tetingkap", "picu_diri"].map((item) =>
          `<option value="${item}" ${editing.action === item ? "selected" : ""}>${esc(actionLabel(item))}</option>`).join("")}
      </select></label>
      ${state.notice ? `<p class="error">${esc(state.notice)}</p>` : ""}
      <button class="btn" type="submit">Simpan peraturan</button>
    </form>` : "";
  return `<article class="panel">
    <h1>Automasi</h1>
    <p class="muted">Senarai peraturan dengan pratonton. Pembina visual belum dibina.</p>
    <table class="os-table"><thead><tr><th>Nama</th><th>Kata kunci</th><th>Tindakan</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table>
    ${form}
    <p class="muted">Gelung, pendua kata kunci, dan mesej biasa di luar tetingkap disekat.</p>
  </article>`;
}

function analytics(state) {
  const count = metrics(state);
  const cards = [
    [String(count.incoming), "Perbualan masuk", "Belum disambung ke pelayan."],
    [String(count.aiReplied), "Dibalas AI", "Belum disambung ke pelayan."],
    [String(count.human), "Dipegang manusia", "Belum disambung ke pelayan."],
    [String(count.unanswered), "Belum dibalas", "Belum disambung ke pelayan."],
    [count.sent + " dihantar · " + count.failed + " gagal", "Hasil penghantaran", "Belum disambung ke pelayan."],
    [count.handover + "%", "Kadar serahan", "Belum disambung ke pelayan."]
  ];
  return `<article class="panel">
    <h1>Analitik</h1>
    <p class="muted">Analitik belum disambung ke pelayan. Kadar konversi tidak dipaparkan.</p>
    <div class="os-kpis">${cards.map(([value, name, note]) => `<article class="state os-kpi"><strong>${esc(value)}</strong><span>${esc(name)}. ${esc(note)}</span></article>`).join("")}</div>
  </article>`;
}

function team(state) {
  const owner = role(state) === "owner";
  const rows = state.team.map((item) =>
    `<tr><td>${esc(item.name)}</td><td>${esc(item.email)}</td><td>${esc(roleLabel(item.role))}</td><td>${owner && item.role !== "owner" ? `<button class="btn btn--ghost" type="button" data-act="remove-member" data-value="${esc(item.email)}">Buang akses</button>` : ""}</td></tr>`
  ).join("") || `<tr><td colspan="4">Tiada ahli.</td></tr>`;
  const form = owner ? `<form id="invite">
      <p class="error" id="form-error" hidden></p>
      <label class="field">Nama<input name="name" required></label>
      <label class="field">E-mel<input name="email" type="email" required></label>
      <label class="field">Kata laluan awal<input name="password" type="password" required minlength="10" maxlength="72" autocomplete="new-password"></label>
      <label class="field">Peranan<select name="role"><option value="staff">Staf</option><option value="admin">Admin</option></select></label>
      <button class="btn" type="submit">Tambah ahli</button>
    </form>
    <p class="muted">Tiada e-mel dihantar. Jika e-mel itu sudah ada, kata laluan sedia ada dikekalkan.</p>` : `<p class="muted">Hanya pemilik boleh menambah atau membuang ahli.</p>`;
  return `<article class="panel">
    <h1>Pasukan</h1>
    <table class="os-table"><thead><tr><th>Nama</th><th>E-mel</th><th>Peranan</th><th></th></tr></thead><tbody>${rows}</tbody></table>
    ${form}
  </article>`;
}

function connection(state) {
  const draft = state.templateDraft || { name: "", language: "ms", body: "", savedAt: "" };
  const saved = draft.savedAt
    ? `Draf disimpan dalam pelayar pada ${esc(draft.savedAt)}. Tidak dihantar ke Meta.`
    : "Draf belum disimpan.";
  return `<article class="panel record-screen">
    <h1>Sambungan WhatsApp</h1>
    <p>Kebenaran <strong>whatsapp_business_management</strong> digunakan selepas perniagaan selesai Embedded Signup. Aplikasi kemudian boleh mengurus akaun WhatsApp Business itu: nombor telefon, templat mesej, kod QR, dan langganan webhook. Ini penggunaan Cloud API rasmi, bukan status rakan kongsi Meta.</p>
    <h2>Langkah pada skrin</h2>
    <ol class="record-steps">
      <li>Log masuk ke workspace.</li>
      <li>Buka halaman ini dan baca empat keadaan di bawah.</li>
      <li>Untuk nombor sedia ada dalam WhatsApp Business App, pilih Sambung WhatsApp Business App. Buka tetingkap Meta ialah aliran biasa.</li>
      <li>Semak hasil sambungan selepas aliran Meta. Token tersimpan sahaja belum mengesahkan nombor boleh digunakan.</li>
    </ol>
    <h2>Empat keadaan</h2>
    <p class="muted">Keadaan ini datang dari pelayan. Token yang tersimpan belum bermaksud nombor sudah boleh menghantar mesej. Pendaftaran nombor tidak dijalankan dari skrin ini.</p>
    <div class="states">
      <article class="state"><strong>Token</strong><span>${esc(state.connection.token)}</span></article>
      <article class="state"><strong>Webhook</strong><span>${esc(state.connection.webhook)}</span></article>
      <article class="state"><strong>Pendaftaran</strong><span>${esc(state.connection.registration)}</span></article>
      <article class="state"><strong>Mesej</strong><span>${esc(state.connection.messaging)}</span></article>
    </div>
    ${state.connection.phone ? `<p>Nombor paparan: ${esc(state.connection.phone)}</p>` : ""}
    <p>Ralat terakhir: ${esc(state.connection.note || "Tiada.")}</p>
    ${state.ai.available ? '<p><a class="btn" href="#pengetahuan">Tetapkan pengetahuan dan ujian AI</a></p>' : ""}
    <div class="record-actions">
      <button class="btn" type="button" data-act="connect">Buka tetingkap Meta</button>
      ${role(state) === "owner" ? `<button class="btn btn--ghost" type="button" data-act="connect-app">Sambung WhatsApp Business App</button>` : ""}
      <a class="btn btn--ghost" href="panduan-rakaman.html">Skrip rakaman</a>
    </div>
    <p class="muted" id="signup-hint">Butang ini membuka tetingkap Facebook untuk kebenaran whatsapp_business_management dan whatsapp_business_messaging. Kod ditukar pada pelayan. Token tidak disimpan dalam pelayar.</p>
    ${role(state) === "owner" ? `<p class="muted" id="signup-app-hint">Sambung WhatsApp Business App membuka aliran rasmi untuk nombor yang sudah ada pada WhatsApp Business App. Ia tidak mendaftarkan nombor baharu dan tidak melanggan webhook.</p>` : ""}
    <h2>Draf templat</h2>
    <p class="muted">Borang ini menyimpan nama, bahasa, dan isi dalam pelayar sahaja. Ia tidak dihantar ke Meta dan tidak mencipta templat sebenar.</p>
    <form id="template-draft">
      <p class="error" id="form-error" hidden></p>
      <label class="field">Nama templat<input name="name" required maxlength="512" autocomplete="off" value="${esc(draft.name)}" placeholder="sambutan_kedai"></label>
      <label class="field">Bahasa
        <select name="language">
          <option value="ms" ${draft.language === "ms" ? "selected" : ""}>Bahasa Melayu</option>
          <option value="en" ${draft.language === "en" ? "selected" : ""}>English</option>
        </select>
      </label>
      <label class="field">Isi templat<textarea name="body" required maxlength="1024" rows="4">${esc(draft.body)}</textarea></label>
      <button class="btn" type="submit">Simpan draf dalam pelayar</button>
    </form>
    <p class="muted">${saved}</p>
    <h2>Laluan webhook baharu</h2>
    <p>URL calon: <code>https://beshareaisolution.com/whatsapp/sambungan/webhook.php</code></p>
    <p class="muted">Laluan ini berasingan daripada callback yang sedang hidup. Jangan tukar callback aplikasi Meta yang sedang berkhidmat. URL ini belum didaftarkan.</p>
  </article>`;
}

function billing() {
  return `<article class="panel">
    <h1>Bil</h1>
    <p>Standard: pemasangan RM1,200 dan RM800 sebulan. Ultimate: pemasangan RM1,800 dan RM1,000 sebulan. Fungsi sama sehingga had diluluskan.</p>
    <p class="muted">Bil kedai ini belum disambung ke pelayan. Tiada invois. Kos mesej Meta dan caj payment gateway tidak termasuk.</p>
  </article>`;
}

function settings(state) {
  const owner = role(state) === "owner";
  const toggle = owner && state.business.status === "suspended"
    ? `<button class="btn" type="button" data-act="resume">Aktifkan semula</button>`
    : owner && state.business.status === "active"
      ? `<button class="btn btn--ghost" type="button" data-act="suspend">Gantung perniagaan</button>`
      : "";
  return `<article class="panel">
    <h1>Tetapan</h1>
    <p class="muted">Status pelayan: ${esc(businessStatus(state.business.status))}.</p>
    <form id="settings">
      <p class="error" id="form-error" hidden></p>
      <label class="field">Nama perniagaan<input name="name" required value="${esc(state.business.name)}"></label>
      <label class="field">Industri<input name="industry" value="${esc(state.business.industry)}"></label>
      <label class="field">Bahasa<select name="language"><option value="ms" ${state.business.language === "ms" ? "selected" : ""}>Bahasa Melayu</option><option value="en" ${state.business.language === "en" ? "selected" : ""}>English kemudian</option></select></label>
      <label class="field">Waktu<input name="hours" value="${esc(state.business.hours)}"></label>
      <button class="btn" type="submit">Simpan nama</button>
    </form>
    <p class="muted">Pelayan menyimpan nama perniagaan. Industri, bahasa, dan waktu kekal dalam pelayar ini.</p>
    <p class="actions">${toggle}</p>
    <h2>Pemindahan pemilikan</h2>
    <p class="muted">Pemindahan pemilikan belum dibuka.</p>
  </article>`;
}

function auditView(state) {
  const rows = state.audit.map((item) => `<tr><td>${esc(item.at)}</td><td>${esc(item.actor)}</td><td>${esc(item.action)}</td></tr>`).join("") || `<tr><td colspan="3">Tiada rekod.</td></tr>`;
  return `<article class="panel"><h1>Audit</h1><table class="os-table"><thead><tr><th>Masa</th><th>Pelaku</th><th>Tindakan</th></tr></thead><tbody>${rows}</tbody></table></article>`;
}

function operator() {
  return `<article class="panel">
    <h1>Pengendali</h1>
    <p class="muted">Senarai platform belum disambung. Tiada tenant dipaparkan.</p>
  </article>`;
}

function showError(message) {
  const error = document.getElementById("form-error") || document.getElementById("step-error");
  if (!error) return;
  error.hidden = !message;
  error.textContent = message || "";
}

function setMode(next) {
  const state = load();
  if (!state) return;
  const current = currentConversation(state);
  if (current && current.can_control && ["human_takeover", "ai_active"].includes(next)) {
    callApi("aimode", { conversation_id: current.id, mode: next === "ai_active" ? "ai" : "human" }).then((result) => {
      state.modal = "";
      state.notice = result.ok ? (next === "ai_active" ? "AI disambung untuk mesej baharu." : "AI dijeda untuk perbualan ini.") : result.error || "Mod tidak dapat ditukar.";
      save(state);
      if (result.ok) applyLive(result);
      route();
    });
    return;
  }
  state.notice = "Kawalan ini belum tersedia untuk perbualan yang dipilih.";
  state.modal = "";
  save(state);
  route();
}

function onClick(event) {
  const select = event.target.closest("select[data-act]");
  if (select && event.type === "change") return onChange(event);
  const button = event.target.closest("[data-act]");
  if (!button || button.tagName === "SELECT") return;
  const act = button.getAttribute("data-act");
  const value = button.getAttribute("data-value") || "";
  const state = load();
  if (act === "verify" || act === "resend") {
    location.hash = "masuk";
    return;
  }
  if (act === "logout") {
    callApi("keluar", {}).then((data) => {
      if (!data.ok) {
        if (state) {
          state.notice = data.error || "Keluar gagal.";
          save(state);
          route();
        }
        return;
      }
      applyLive(data);
      signupState = "";
      signupQueue = null;
      clearSignupRecord();
      setAuthReturn("");
      inboxLoad = "";
      location.hash = "masuk";
      route();
    });
    return;
  }
  if (!state) return;
  if (act === "ai-stop" || act === "refresh-inbox") {
    button.disabled = true;
    callApi(act === "ai-stop" ? "aistop" : "peti", act === "ai-stop" ? {} : undefined).then((result) => {
      state.notice = result.ok ? (act === "ai-stop" ? "AI dihentikan." : "") : result.error || "Permintaan gagal.";
      save(state);
      if (result.ok) applyLive(result);
      route();
    });
    return;
  }
  if (act === "suspend" || act === "resume") {
    callApi("status", {
      kind: "suspend_or_resume",
      next_status: act === "suspend" ? "suspended" : "active"
    }).then((data) => {
      if (!data.ok) {
        state.notice = data.error || "Status tidak dapat ditukar.";
        save(state);
        route();
        return;
      }
      applyLive(data);
      route();
    });
    return;
  }
  if (act === "theme") {
    state.theme = state.theme === "cerah" ? "gelap" : "cerah";
    save(state);
    route();
    return;
  }
  if (act === "open") {
    state.active = value;
    const current = currentConversation(state);
    if (current) current.unread = false;
    state.inboxPane = "talk";
    save(state);
    route();
    return;
  }
  if (act === "filter") {
    state.inboxFilter = value;
    save(state);
    route();
    return;
  }
  if (act === "pane") {
    state.inboxPane = value;
    save(state);
    route();
    return;
  }
  if (act === "take") return setMode("human_takeover");
  if (act === "release") {
    state.modal = "release";
    save(state);
    route();
    return;
  }
  if (act === "release-yes") return setMode("ai_active");
  if (act === "modal-close") {
    state.modal = "";
    save(state);
    route();
    return;
  }
  if (act === "resolve") return setMode("resolved");
  if (act === "reopen") return setMode(value);
  if (act === "assign-me" || act === "know-toggle" || act === "rule-toggle" || act === "rule-edit") {
    state.notice = "Bahagian ini belum disambung ke pelayan.";
    save(state);
    route();
    return;
  }
  if (act === "tag") {
    state.contactTag = value;
    save(state);
    route();
    return;
  }
  if (act === "contact") {
    state.activeContact = value;
    const contact = state.contacts.find((item) => item.id === value);
    if (contact) state.active = contact.conversationId;
    save(state);
    route();
    return;
  }
  if (act === "remove-member") {
    callApi("buang", { email: value }).then((data) => {
      if (!data.ok) {
        state.notice = data.error || "Ahli tidak dapat dibuang.";
        save(state);
        route();
        return;
      }
      applyLive(data);
      route();
    });
    return;
  }
  if (act === "connect") {
    openEmbeddedSignup();
    return;
  }
  if (act === "connect-app") {
    openCoexistenceSignup();
    return;
  }
  if (act === "bill" || act === "step-go" || act === "step-back" || act === "step-next") {
    state.notice = "Bahagian ini belum disambung ke pelayan.";
    save(state);
    route();
  }
}

function onChange(event) {
  const select = event.target.closest("select[data-act='pilih']");
  if (!select) return;
  callApi("pilih", { public_id: select.value }).then((data) => {
    if (!data.ok) return;
    applyLive(data);
    route();
  });
}

function onSubmit(event) {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  event.preventDefault();
  const data = new FormData(form);
  const formId = form.getAttribute("id");
  if (formId === "auth") {
    const kind = form.getAttribute("data-kind");
    const email = String(data.get("email") || "").trim();
    const password = String(data.get("password") || "");
    if (kind === "lupa") {
      showError("Set semula kata laluan belum tersedia. Tiada e-mel dihantar.");
      return;
    }
    if (!email.includes("@") || password.length < 10 || password.length > 72) {
      showError("Gunakan e-mel yang sah dan kata laluan 10 hingga 72 aksara.");
      return;
    }
    if (kind === "daftar") {
      if (String(data.get("password2") || "") !== password) {
        showError("Kata laluan tidak sepadan.");
        return;
      }
      callApi("daftar", {
        name: String(data.get("name") || ""),
        business: String(data.get("business") || ""),
        email,
        password
      }).then((result) => {
        if (!result.ok) {
          showError(result.error || "Pendaftaran gagal.");
          return;
        }
        applyLive(result);
        location.hash = consumeAuthReturn(load());
        route();
      });
      return;
    }
    callApi("masuk", { email, password }).then((result) => {
      if (!result.ok) {
        showError(result.error || "Masuk gagal.");
        return;
      }
      applyLive(result);
      location.hash = consumeAuthReturn(load());
      route();
    });
    return;
  }
  const state = load();
  if (!state) return;
  if (formId === "ai-settings") {
    const submit = form.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;
    callApi("aiconfig", {
      connection_id: Number(data.get("connection")), expected_revision: Number(data.get("revision")),
      knowledge: String(data.get("knowledge") || ""),
      allowlist: String(data.get("allowlist") || "").split(/[\n,]+/).map((s) => s.trim()).filter(Boolean),
      daily_limit: Number(data.get("daily_limit")), approved: data.get("approved") === "on", enabled: data.get("enabled") === "on"
    }).then((result) => {
      if (!result.ok) {
        if (submit) submit.disabled = false;
        showError(result.error || "Tetapan AI tidak dapat disimpan.");
        return;
      }
      state.notice = "Fakta dan tetapan AI disimpan pada pelayan.";
      save(state);
      applyLive(result);
      route();
    });
    return;
  }
  if (formId === "reply" || formId === "know") {
    showError("Belum disambung ke pelayan.");
    return;
  }
  if (formId === "play") {
    const answer = document.getElementById("answer");
    const source = document.getElementById("source");
    if (answer) answer.textContent = "Belum disambung. Jawapan tidak dijana.";
    if (source) source.textContent = "";
    return;
  }
  if (formId === "rule") {
    state.notice = "Automasi belum disambung ke pelayan.";
    save(state);
    route();
    return;
  }
  if (formId === "invite") {
    const email = String(data.get("email") || "").trim();
    const password = String(data.get("password") || "");
    if (!email.includes("@") || password.length < 10) {
      showError("Gunakan e-mel yang sah dan kata laluan 10 hingga 72 aksara.");
      return;
    }
    callApi("ahli", {
      name: String(data.get("name") || "").trim(),
      email,
      password,
      role: String(data.get("role") || "staff")
    }).then((result) => {
      if (!result.ok) {
        showError(result.error || "Ahli tidak dapat ditambah.");
        return;
      }
      applyLive(result);
      route();
    });
    return;
  }
  if (formId === "template-draft") {
    const name = String(data.get("name") || "").trim();
    const language = String(data.get("language") || "");
    const body = String(data.get("body") || "").trim();
    if (!/^[a-z0-9_]{1,512}$/.test(name)) {
      showError("Nama templat guna huruf kecil, nombor, dan garis bawah sahaja.");
      return;
    }
    if (language !== "ms" && language !== "en") {
      showError("Pilih Bahasa Melayu atau English.");
      return;
    }
    if (!body || body.length > 1024) {
      showError("Isi templat diperlukan, paling banyak 1024 aksara.");
      return;
    }
    state.templateDraft = { name, language, body, savedAt: stamp() };
    log(state, "Draf templat disimpan dalam pelayar. Meta tidak dipanggil.");
    save(state);
    route();
    return;
  }
  if (formId === "settings") {
    state.business.industry = String(data.get("industry") || "").trim();
    state.business.language = String(data.get("language") || "ms");
    state.business.hours = String(data.get("hours") || "");
    save(state);
    callApi("tetapan", { name: String(data.get("name") || "").trim() }).then((result) => {
      if (!result.ok) {
        showError(result.error || "Nama tidak dapat disimpan.");
        return;
      }
      applyLive(result);
      route();
    });
  }
}

function onInput(event) {
  const state = load();
  if (!state) return;
  if (event.target.name === "q") {
    state.inboxQuery = event.target.value;
    save(state);
    const list = visibleConversations(state).map((item) =>
      `<button class="thread-item ${item.id === state.active ? "is-on" : ""}" type="button" data-act="open" data-value="${esc(item.id)}"><span>${esc(item.name)}</span><small>${esc(label(item.mode))}${item.unread ? " · belum dibaca" : ""}</small></button>`
    ).join("") || `<p class="muted">${state.conversations.length ? "Tiada hasil." : (state.inboxReady ? "Belum ada mesej webhook yang disahkan." : "Peti masuk belum disambung ke pelayan.")}</p>`;
    const thread = document.getElementById("thread");
    if (thread) thread.innerHTML = list;
    return;
  }
  if (event.target.name === "contact-q") {
    state.contactQuery = event.target.value;
    save(state);
    route();
    const field = document.querySelector("[name='contact-q']");
    if (field) {
      field.focus();
      field.setSelectionRange(field.value.length, field.value.length);
    }
  }
}

let signupReady = { appId: "", configId: "", version: "v26.0" };
let signupState = "";
let signupQueue = null;
let inboxLoad = "";

function facebookHost(origin) {
  try {
    const host = new URL(origin).hostname;
    return host === "facebook.com" || host.endsWith(".facebook.com");
  } catch {
    return false;
  }
}

function signupCode(value) {
  return typeof value === "string" && /^[A-Za-z0-9_\-\.~]{8,1024}$/.test(value) ? value : "";
}

function signupQueryResult(search) {
  const raw = typeof search === "string" ? search : "";
  const params = new URLSearchParams(raw.startsWith("?") ? raw.slice(1) : raw);
  const reason = params.get("error_reason") || params.get("error") || "";
  return {
    code: signupCode(params.get("code") || ""),
    error: /^[A-Za-z0-9_]{1,40}$/.test(reason) ? reason : ""
  };
}

function signupPayload(origin, data) {
  if (!facebookHost(origin)) return null;
  let parsed = data;
  for (let attempt = 0; attempt < 2 && typeof parsed === "string"; attempt += 1) {
    try {
      parsed = JSON.parse(parsed);
    } catch {
      return null;
    }
  }
  if (!parsed || parsed.type !== "WA_EMBEDDED_SIGNUP") return null;
  return parsed;
}

function signupResultNote(data) {
  const connection = data && data.connection && typeof data.connection === "object" ? data.connection : null;
  if (data && data.ok === true && connection && connection.linked === true) return "Sambungan WhatsApp disimpan.";
  if (connection && typeof connection.note === "string" && connection.note) return connection.note;
  if (data && data.ok === true) return "Hasil Meta belum lengkap. Sambungan tidak disimpan.";
  return (data && data.error) || "Hasil Embedded Signup tidak diterima. Sambungan tidak disimpan.";
}

function signupClientHint(agent) {
  const ua = typeof agent === "string" ? agent : "";
  if (/FBAN|FBAV|Instagram|Line\//.test(ua)) {
    return " Buka halaman ini dalam Safari. Pelayar dalam aplikasi sering menggugurkan hasil Meta.";
  }
  return "";
}

function showSignupNote(note) {
  const state = load();
  if (!state) return;
  state.notice = note;
  save(state);
  inboxLoad = "";
  if ((location.hash.replace("#", "") || "") === "sambungan") route();
}

function prepareSignup() {
  fetch("sambungan/awam.php", { cache: "no-store" })
    .then((res) => res.json())
    .then((data) => {
      if (!data || data.ready !== true || !/^\d{5,20}$/.test(data.appId || "") || !/^\d{5,20}$/.test(data.configId || "")) {
        signupReady = { appId: "", configId: "", version: "v26.0" };
        return;
      }
      signupReady = { appId: data.appId, configId: data.configId, version: data.version || "v26.0" };
      const hint = document.getElementById("signup-hint");
      if (hint) hint.textContent = "App ID dan Configuration ID sedia. Tetingkap Meta dibuka dari butang. Rahsia aplikasi tidak berada dalam pelayar.";
      loadFacebookSdk(signupReady);
    })
    .catch(() => {
      signupReady = { appId: "", configId: "", version: "v26.0" };
    });
}

function loadFacebookSdk(cfg) {
  if (signupSdkReady && facebookSdkReady()) return;
  window.fbAsyncInit = () => markFacebookReady(cfg);
  if (facebookSdkReady()) {
    markFacebookReady(cfg);
    return;
  }
  if (!document.getElementById("facebook-jssdk")) {
    const script = document.createElement("script");
    script.id = "facebook-jssdk";
    script.async = true;
    script.crossOrigin = "anonymous";
    script.src = "https://connect.facebook.net/en_US/sdk.js";
    document.head.appendChild(script);
  }
}

let signupSdkReady = false;

function facebookSdkReady() {
  return !!(window.FB && typeof window.FB.login === "function" && !window.FB.__buffer);
}

function markFacebookReady(cfg) {
  if (!facebookSdkReady() || signupSdkReady) return;
  window.FB.init({
    appId: cfg.appId,
    autoLogAppEvents: true,
    xfbml: false,
    version: cfg.version
  });
  signupSdkReady = true;
}

function signupPageRedirect() {
  return location.origin + location.pathname;
}

function signupLoginOptions(configId, redirectUri) {
  return {
    config_id: configId,
    response_type: "code",
    override_default_response_type: true,
    fallback_redirect_uri: redirectUri,
    extras: {
      setup: {},
      sessionInfoVersion: "3"
    }
  };
}

function coexistenceLoginOptions(configId, redirectUri) {
  const options = signupLoginOptions(configId, redirectUri);
  return {
    config_id: options.config_id,
    response_type: options.response_type,
    override_default_response_type: options.override_default_response_type,
    fallback_redirect_uri: options.fallback_redirect_uri,
    extras: {
      setup: {},
      featureType: "whatsapp_business_app_onboarding",
      sessionInfoVersion: "3"
    }
  };
}

function metaStatus(response) {
  const status = response && typeof response.status === "string" ? response.status : "";
  return /^[a-z_]{1,40}$/.test(status) ? status : "";
}

function metaText(value) {
  if (typeof value === "number" && Number.isFinite(value)) return String(Math.trunc(value));
  return typeof value === "string" ? value : "";
}

function signupDiagnostic(info) {
  const source = info && typeof info === "object" ? info : {};
  const rawCode = metaText(source.error_code) || metaText(source.error_id);
  const session = metaText(source.session_id);
  return {
    error_code: /^[A-Za-z0-9_-]{1,64}$/.test(rawCode) ? rawCode : "",
    session_id: /^[A-Za-z0-9_-]{1,64}$/.test(session) ? session : ""
  };
}

function signupEventFields(data) {
  const info = data && data.data && typeof data.data === "object" ? data.data : {};
  const diagnostic = signupDiagnostic(info);
  return {
    event: data && typeof data.event === "string" ? data.event : "",
    waba_id: metaText(info.waba_id),
    phone_number_id: metaText(info.phone_number_id),
    current_step: typeof info.current_step === "string" ? info.current_step : "",
    error_code: diagnostic.error_code,
    session_id: diagnostic.session_id
  };
}

function openEmbeddedSignup() {
  beginSignup(signupLoginOptions(signupReady.configId, signupPageRedirect()));
}

function openCoexistenceSignup() {
  beginSignup(coexistenceLoginOptions(signupReady.configId, signupPageRedirect()));
}

const SIGNUP_RECORD = "beshare-os-signup";
let signupReturnTimer = 0;
let signupLaunchTimer = 0;

function readSignupRecord() {
  try {
    const parsed = JSON.parse(sessionStorage.getItem(SIGNUP_RECORD) || "");
    if (!parsed || typeof parsed !== "object") return null;
    if (parsed.at && Date.now() - Number(parsed.at) > 15 * 60 * 1000) return null;
    return parsed;
  } catch {
    return null;
  }
}

function writeSignupRecord(record) {
  try {
    sessionStorage.setItem(SIGNUP_RECORD, JSON.stringify(record));
  } catch {
    /* Mod peribadi pelayar tidak menyimpan rekod sementara. */
  }
}

function clearSignupRecord() {
  try {
    sessionStorage.removeItem(SIGNUP_RECORD);
  } catch {
    /* Abaikan. */
  }
}

function rememberSignupCode(code) {
  const safe = signupCode(code);
  if (!safe) return;
  const record = readSignupRecord() || { state: "", events: [], at: Date.now(), codeSent: false, finish: false, reported: false };
  record.code = safe;
  writeSignupRecord(record);
  flushSignup();
}

function rememberSignupState(state) {
  if (!/^[0-9a-f]{64}$/.test(state || "")) return;
  const record = readSignupRecord() || { code: "", events: [], at: Date.now(), codeSent: false, finish: false, reported: false };
  record.state = state;
  writeSignupRecord(record);
  signupState = state;
  flushSignup();
}

function rememberSignupEvent(fields) {
  const record = readSignupRecord() || { state: signupState || "", code: "", events: [], at: Date.now(), codeSent: false, finish: false, reported: false };
  if (!Array.isArray(record.events)) record.events = [];
  record.events.push(fields);
  if (fields.event === "FINISH" || fields.event === "FINISH_ONLY_WABA" || fields.event === "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING") {
    record.finish = true;
  }
  writeSignupRecord(record);
  if (signupQueue) signupQueue.finish = record.finish === true;
  flushSignup();
}

function consumeRedirectResult() {
  const found = signupQueryResult(location.search);
  if (!found.code && !found.error) return found;
  const params = new URLSearchParams(location.search);
  ["code", "error", "error_reason", "error_description"].forEach((key) => params.delete(key));
  const query = params.toString();
  history.replaceState({}, "", location.pathname + (query ? "?" + query : "") + location.hash);
  return found;
}

function signupFailure(message) {
  showSignupNote(message + signupClientHint(navigator.userAgent));
}

function flushSignup() {
  const record = readSignupRecord();
  if (!record || !/^[0-9a-f]{64}$/.test(record.state || "")) return;
  signupState = record.state;
  if (record.code && !record.codeSent) {
    record.codeSent = true;
    writeSignupRecord(record);
    exchangeSignupCode(record.code);
  }
  const events = Array.isArray(record.events) ? record.events : [];
  if (!events.length) return;
  record.events = [];
  writeSignupRecord(record);
  events.forEach((fields) => sendSignupEvent(fields));
}

function reportSignupGap() {
  const found = consumeRedirectResult();
  if (found.code) {
    rememberSignupCode(found.code);
    flushSignup();
    return;
  }
  const record = readSignupRecord();
  if (!record || record.code || record.codeSent || record.reported) return;
  if (signupQueue && signupQueue.code) return;
  record.reported = true;
  writeSignupRecord(record);
  signupFailure(record.finish
    ? "Peristiwa FINISH diterima, tetapi kod kebenaran tidak sampai. Sambungan tidak disimpan."
    : "Pelayar tidak memulangkan kod kebenaran atau peristiwa FINISH. Sambungan tidak disimpan.");
}

function armSignupReturn() {
  window.clearTimeout(signupReturnTimer);
  signupReturnTimer = window.setTimeout(reportSignupGap, 2000);
}

function beginSignup(options) {
  const state = load();
  if (!state) return;
  if (role(state) !== "owner") {
    showSignupNote("Hanya pemilik boleh menyambung WhatsApp.");
    return;
  }
  if (!signupReady.appId || !signupReady.configId || !signupSdkReady || !facebookSdkReady()) {
    showSignupNote(signupReady.appId
      ? "SDK Meta masih dimuat. Tekan butang sekali lagi."
      : "Tetingkap Meta tidak dibuka. App ID dan Configuration ID belum dipasang pada pelayan.");
    return;
  }
  window.clearTimeout(signupLaunchTimer);
  const pending = { code: "", finish: false };
  signupQueue = pending;
  writeSignupRecord({ state: "", code: "", events: [], at: Date.now(), codeSent: false, finish: false, reported: false, hidden: false });
  const hint = document.getElementById("signup-hint");
  if (hint) hint.textContent = "Meminta tetingkap Meta. Jika tiada tetingkap muncul, benarkan pop-up atau buka workspace dalam pelayar biasa.";
  signupLaunchTimer = window.setTimeout(() => {
    if (signupQueue !== pending || pending.code || pending.reported) return;
    const record = readSignupRecord();
    if (record && (record.codeSent || record.finish || record.hidden)) return;
    showSignupNote("Belum menerima respons Meta. Jika tetingkap tidak muncul, benarkan pop-up atau buka workspace dalam pelayar biasa, kemudian cuba lagi. Sambungan belum disahkan.");
  }, 15000);
  try {
    window.FB.login((response) => {
      if (signupQueue !== pending) return;
      window.clearTimeout(signupLaunchTimer);
      const code = signupCode(response && response.authResponse ? response.authResponse.code : "");
      pending.status = metaStatus(response);
      if (code) {
        pending.code = code;
        rememberSignupCode(code);
        flushSignup();
        return;
      }
      if (document.visibilityState === "hidden") return;
      pending.reported = true;
      const record = readSignupRecord();
      if (record) {
        record.reported = true;
        writeSignupRecord(record);
      }
      signupFailure(pending.status
        ? "Tetingkap Meta ditutup sebelum kod kebenaran diterima. Keadaan: " + pending.status + ". Sambungan tidak disimpan."
        : "Tetingkap Meta ditutup sebelum kod kebenaran diterima. Sambungan tidak disimpan.");
    }, options);
  } catch {
    window.clearTimeout(signupLaunchTimer);
    signupQueue = null;
    signupState = "";
    clearSignupRecord();
    signupFailure("Tetingkap Meta tidak dapat dimulakan. Benarkan pop-up atau cuba pelayar biasa. Sambungan tidak disimpan.");
    return;
  }
  if (pending.reported && !pending.code) return;
  const started = callApi("sambung", {});
  started.then((data) => {
    if (signupQueue !== pending) return;
    if (!data || data.ok !== true || !/^[0-9a-f]{64}$/.test(data.state || "")) {
      window.clearTimeout(signupLaunchTimer);
      signupQueue = null;
      signupState = "";
      clearSignupRecord();
      showSignupNote((data && data.error) || "Sesi sambungan tidak dapat dibuka. Sambungan tidak disimpan.");
      return;
    }
    rememberSignupState(data.state);
    flushSignup();
  });
}

function exchangeSignupCode(code) {
  const state = signupState;
  if (!state) {
    showSignupNote("Sesi sambungan tidak sah. Buka tetingkap Meta sekali lagi. Sambungan tidak disimpan.");
    return;
  }
  callApi("tukar", { state, code }).then((data) => {
    if (data && data.user) applyLive(data);
    inboxLoad = "";
    const linked = data && data.connection && data.connection.linked === true;
    if (linked) clearSignupRecord();
    else flushSignup();
    showSignupNote(signupResultNote(data));
  });
}

window.addEventListener("message", (event) => {
  const parsed = signupPayload(event.origin, event.data);
  if (!parsed) return;
  rememberSignupEvent(signupEventFields(parsed));
  flushSignup();
});

window.addEventListener("blur", () => {
  const record = readSignupRecord();
  if (!record || record.codeSent) return;
  record.hidden = true;
  writeSignupRecord(record);
});

document.addEventListener("visibilitychange", () => {
  const record = readSignupRecord();
  if (!record) return;
  if (document.visibilityState === "hidden") {
    record.hidden = true;
    writeSignupRecord(record);
    return;
  }
  if (record.hidden) armSignupReturn();
});

window.addEventListener("focus", () => {
  const record = readSignupRecord();
  if (record && record.hidden) armSignupReturn();
});

function sendSignupEvent(fields) {
  callApi("peristiwa", Object.assign({ state: signupState }, fields)).then((result) => {
    if (result && result.user) applyLive(result);
    inboxLoad = "";
    const linked = result && result.connection && result.connection.linked === true;
    const note = result && result.connection && typeof result.connection.note === "string" ? result.connection.note : "";
    if (linked) {
      clearSignupRecord();
      showSignupNote("Sambungan WhatsApp disimpan.");
      return;
    }
    if (result && result.ok === true && !note) return;
    showSignupNote(signupResultNote(result));
  });
}

async function boot() {
  const returned = consumeRedirectResult();
  if (returned.code) rememberSignupCode(returned.code);
  const root = document.getElementById("app");
  if (root) root.innerHTML = `<main class="page"><p class="muted">Memuatkan sesi…</p></main>`;
  try {
    applyLive(await callApi("sesi"));
  } catch {
    live = null;
  }
  const stored = readSignupRecord();
  if (stored && /^[0-9a-f]{64}$/.test(stored.state || "")) signupState = stored.state;
  ready = true;
  route();
  if (returned.error && !returned.code) {
    signupFailure("Meta tidak memulangkan kebenaran. Sambungan tidak disimpan.");
  }
  flushSignup();
  if (returned.code) {
    const after = readSignupRecord();
    if (!after || !after.codeSent) {
      signupFailure("Kod kebenaran sampai tanpa sesi sambungan yang sah. Sambungan tidak disimpan.");
    }
  }
}

boot();
const app = document.getElementById("app");
app.addEventListener("click", onClick);
app.addEventListener("change", onChange);
app.addEventListener("submit", onSubmit);
app.addEventListener("input", onInput);
window.addEventListener("hashchange", route);
