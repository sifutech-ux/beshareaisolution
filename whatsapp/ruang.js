const UI_KEY = "beshare-os-ui";
const AUTH_RETURN_KEY = "beshare-os-auth-return";
let authReturn = null;
let live = null;
let csrf = "";
let ready = false;
let paintedHash = "";
// Business drafts, including payment details, stay in memory until explicitly saved.
let knowledgeEditor = null;

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
    platformAdmin: live.platform_admin === true,
    business: {
      name: live.business.name,
      public_id: live.business.public_id,
      status: live.business.status,
      accessStatus: live.business.access_status || "active",
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
    knowledgeCentre: live.knowledge_centre && typeof live.knowledge_centre === "object" ? live.knowledge_centre : { available: false, can_manage: false },
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
    messaging: source.linked === true && source.messaging === "Belum" ? "Belum ada bukti mesej" : (typeof source.messaging === "string" && source.messaging ? source.messaging : "Belum"),
    note: typeof source.note === "string" ? source.note : "",
    phone: typeof source.phone === "string" ? source.phone : ""
  };
}

function applyLive(data) {
  if (data && typeof data.csrf === "string") csrf = data.csrf;
  if (!data || data.ok !== true || !data.user || !data.business) {
    live = null;
    knowledgeEditor = null;
    return;
  }
  if (knowledgeEditor && (knowledgeEditor.scope !== knowledgeScope(data)
      || !data.knowledge_centre || data.knowledge_centre.can_manage !== true)) knowledgeEditor = null;
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
  else if (state && state.business.accessStatus === "suspended") root.innerHTML = shell(state, "papan", '<article class="panel"><h1>Akses perniagaan digantung</h1><p>Hubungi pemilik sistem BeShare untuk bantuan. Anda masih boleh memilih ruang perniagaan lain atau keluar daripada akaun.</p></article>');
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
  if (hash === "sambungan" && state && state.business.accessStatus !== "suspended") prepareSignup();
  if (state && state.business.accessStatus !== "suspended" && (hash === "peti" || hash === "kenalan" || hash === "papan" || hash === "sambungan" || hash === "pengetahuan") && inboxLoad !== hash) {
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
        ${state.platformAdmin ? '<a class="btn btn--ghost" href="/whatsapp/pemilik/">Panel Pemilik</a>' : ""}
        ${(state.memberships || []).length > 1 ? `<label class="muted">Perniagaan
          <select data-act="pilih" aria-label="Perniagaan">
            ${state.memberships.map((item) => `<option value="${esc(item.public_id)}" ${item.public_id === state.business.public_id ? "selected" : ""}>${esc(item.name)}</option>`).join("")}
          </select>
        </label>` : ""}
        <button class="btn btn--ghost" type="button" data-act="theme">${state.theme === "cerah" ? "Mod gelap" : "Mod cerah"}</button>
        <button class="btn btn--ghost" type="button" data-act="logout">Keluar</button>
      </div>
    </aside>
    <section${hash === "peti" || hash === "pengetahuan" ? ' class="workspace-bounded"' : ""}>
      <p class="demo-banner">${state.ai.available ? "Urus mesej, pengetahuan dan balasan AI untuk perniagaan ini. Pilih penerima dan had balasan dalam Pengetahuan; ambil alih perbualan melalui Peti masuk." : state.knowledgeCentre.available ? "Sediakan dan terbitkan maklumat perniagaan dalam Pengetahuan. Status nombor WhatsApp boleh disemak dalam Sambungan." : state.inboxReady ? "Peti masuk memaparkan mesej WhatsApp yang diterima melalui sambungan perniagaan ini." : "Lengkapkan sambungan WhatsApp untuk mula menerima mesej pelanggan."}</p>
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
      <article class="state os-kpi"><strong>${count.aiReplied}</strong><span>Perbualan dengan balasan AI diterima Meta. Semak status sampai dalam Peti masuk.</span></article>
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

function messageDelivery(status) {
  const states = {
    generated: { label: "Balasan disediakan", tone: "pending", help: "Belum dihantar ke WhatsApp." },
    sending: { label: "Sedang menghantar", tone: "pending", help: "Keputusan penghantaran belum diterima." },
    sent: { label: "Menunggu pengesahan sampai", tone: "pending", help: "Meta telah menerima mesej. Penghantaran kepada pelanggan belum disahkan." },
    delivered: { label: "Sudah sampai", tone: "success", help: "Penerimaan mesej disahkan oleh WhatsApp." },
    read: { label: "Sudah dibaca", tone: "success", help: "" },
    failed: { label: "Gagal dihantar", tone: "failed", help: "Balasan ini tidak berjaya dihantar kepada pelanggan." },
    unknown: { label: "Status belum pasti", tone: "pending", help: "Belum ada pengesahan sama ada mesej ini berjaya dihantar." },
    handoff: { label: "Diserahkan kepada manusia", tone: "neutral", help: "Balasan ini tidak dihantar. Perbualan diserahkan kepada manusia." }
  };
  return Object.hasOwn(states, status) ? states[status] : states.unknown;
}

function messageTime(item) {
  const raw = typeof item.at === "string" ? item.at.trim() : "";
  const value = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw) ? raw.replace(" ", "T") + "Z" : raw;
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/.test(value)) return "";
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return "";
  const label = date.toLocaleString("ms-MY", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Kuala_Lumpur" });
  return `<small class="msg-time">${item.from === "ai" ? "Dijana" : "Masa mesej"}: <time datetime="${esc(date.toISOString())}">${esc(label)} MYT</time></small>`;
}

function messageBubble(item) {
  const who = { customer: "Pelanggan", ai: "AI", staff: "Staf" };
  const delivery = item.from === "ai" || item.from === "staff" ? messageDelivery(item.status) : null;
  const failed = delivery?.tone === "failed";
  const code = failed && Number.isSafeInteger(item.error_code) && item.error_code > 0 ? item.error_code : null;
  const subcode = code !== null && Number.isSafeInteger(item.error_subcode) && item.error_subcode >= 0 ? item.error_subcode : null;
  const media = knowledgeMediaUrl(item.media_url);
  return `<div class="msg${item.from === "staff" ? " msg--staff" : ""}${failed ? " msg--failed" : ""}">
    <div class="msg-heading"><strong>${esc(who[item.from] || item.from)}</strong>${delivery ? `<span class="msg-status msg-status--${delivery.tone}">${esc(delivery.label)}</span>` : ""}</div>
    ${media ? `<a class="msg-image" href="${esc(media)}" target="_blank" rel="noopener"><img src="${esc(media)}" alt="Gambar dalam balasan" loading="lazy"></a>` : ""}
    <p>${esc(item.text)}</p>
    ${delivery?.help ? `<p class="msg-delivery-help">${esc(delivery.help)}</p>` : ""}
    ${code !== null ? `<small class="msg-error-code">Kod Meta: ${code}${subcode !== null ? " · Subkod: " + subcode : ""}</small>` : ""}
    ${messageTime(item)}
  </div>`;
}

function talk(state, current) {
  if (!current) return `<p class="muted">Pilih perbualan.</p><p><button class="btn btn--ghost" type="button" data-act="pane" data-value="list">Senarai</button></p>`;
  const messages = current.messages.map(messageBubble).join("");
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
      : `<p class="muted">${current.mode === "ai_active" ? "AI aktif untuk mesej baharu mengikut tetapan perniagaan. Ambil alih untuk menjeda balasan dalam perbualan ini." : "AI dijeda. Semak tetapan balasan dalam Pengetahuan."}</p>`;
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
    <button class="btn btn--ghost" type="button" disabled aria-describedby="assignment-unavailable">Tugaskan kepada saya</button>
    <p id="assignment-unavailable" class="muted">Penugasan ejen belum tersedia.</p>`;
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
  if (state.knowledgeCentre.available) return knowledgeCentre(state);
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

function knowledgeScope(state) {
  return state && state.business && state.user ? state.business.public_id + ":" + state.user.email : "";
}

function knowledgeDocument(value, fallback = "") {
  const doc = value && typeof value === "object" ? value : { facts: fallback };
  const text = (value) => typeof value === "string" ? value : "";
  const payment = doc.payment && typeof doc.payment === "object" ? doc.payment : {};
  return {
    facts: text(doc.facts),
    sources: (Array.isArray(doc.sources) ? doc.sources : []).map((item) => ({
      id: text(item.id), kind: ["website", "landing", "facebook", "ads"].includes(item.kind) ? item.kind : "website",
      url: text(item.url), title: text(item.title), text: text(item.text), enabled: item.enabled === true
    })),
    assets: (Array.isArray(doc.assets) ? doc.assets : []).map((item) => ({
      id: text(item.id), category: ["product", "combo", "reference"].includes(item.category) ? item.category : "product",
      title: text(item.title), facts: text(item.facts), caption: text(item.caption),
      when_to_send: text(item.when_to_send), media_id: text(item.media_id), enabled: item.enabled === true
    })),
    payment: {
      enabled: payment.enabled === true, bank_name: text(payment.bank_name), account_name: text(payment.account_name),
      account_number: text(payment.account_number), instructions: text(payment.instructions), qr_media_id: text(payment.qr_media_id)
    }
  };
}

function knowledgeMediaUrl(value) {
  if (typeof value !== "string" || value === "") return "";
  try {
    const base = location.origin + location.pathname;
    const expected = new URL("akaun/knowledge-media.php", base);
    const url = new URL(value, base);
    if (url.origin !== expected.origin || url.pathname !== expected.pathname || url.username || url.password
        || !/^[a-f0-9]{32}$/.test(url.searchParams.get("id") || "")) return "";
    return url.href;
  } catch {
    return "";
  }
}

function knowledgeEditorFor(state) {
  const centre = state && state.knowledgeCentre;
  if (!centre || centre.available !== true || centre.can_manage !== true) return null;
  const scope = knowledgeScope(state);
  const revision = Number.isInteger(centre.revision) ? centre.revision : 0;
  if (!knowledgeEditor || knowledgeEditor.scope !== scope) {
    const doc = knowledgeDocument(centre.draft, state.ai.knowledge || "");
    knowledgeEditor = {
      scope, businessPublicId: state.business.public_id, document: doc, saved: JSON.stringify(doc), revision, dirty: false, busy: "", conflict: false,
      media: new Map(), open: new Set(), review: false, notice: "", noticeKind: "info", controls: null,
      publicationConnection: state.ai.connection_id || 0,
      publishedRevision: centre.published_revision || 0, publishedAt: centre.published_at || ""
    };
  }
  const editor = knowledgeEditor;
  if (revision > editor.revision) {
    if (!editor.dirty && !editor.busy) {
      editor.document = knowledgeDocument(centre.draft);
      editor.saved = JSON.stringify(editor.document);
      editor.revision = revision;
      editor.review = false;
      editor.conflict = false;
    } else {
      editor.conflict = true;
    }
  }
  if ((centre.published_revision || 0) >= editor.publishedRevision) {
    editor.publishedRevision = centre.published_revision || 0;
    editor.publishedAt = centre.published_at || "";
  }
  for (const media of Array.isArray(centre.media) ? centre.media : []) {
    if (media && /^[a-f0-9]{32}$/.test(media.id || "") && knowledgeMediaUrl(media.preview_url)) editor.media.set(media.id, media);
  }
  return editor;
}

function knowledgeId() {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function knowledgeCompiledBytes(doc) {
  // Match the approved server compiler; bank coordinates are not part of model facts.
  const trim = (value) => value.replace(/^[ \t\n\r\0\x0b]+|[ \t\n\r\0\x0b]+$/g, "");
  const parts = [];
  if (trim(doc.facts)) parts.push(trim(doc.facts));
  for (const source of doc.sources) {
    if (source.enabled) parts.push("Sumber disahkan: " + trim(source.title) + "\nPautan: " + trim(source.url) + "\n" + trim(source.text));
  }
  for (const asset of doc.assets) {
    if (asset.enabled) parts.push("Gambar diluluskan: " + trim(asset.title) + (trim(asset.facts) ? "\n" + trim(asset.facts) : "") + "\nBila sesuai dihantar: " + trim(asset.when_to_send));
  }
  if (doc.payment.enabled) parts.push("Maklumat pembayaran yang diluluskan tersedia. Gunakan butiran pembayaran berstruktur apabila pelanggan meminta cara membayar.");
  return new TextEncoder().encode(trim(parts.join("\n\n"))).length;
}

function knowledgeAttrs(group, id, field) {
  return `data-kc-group="${group}" data-kc-id="${esc(id)}" data-kc-field="${field}"`;
}

function knowledgeMediaPreview(editor, id, title, qr = false) {
  const media = editor.media.get(id);
  const url = media && knowledgeMediaUrl(media.preview_url);
  if (!url) return `<div class="kc-image-empty">${qr ? "Belum ada gambar QR dipilih." : "Belum ada gambar dipilih."}</div>`;
  return `<figure class="kc-image-wrap ${qr ? "kc-qr" : ""}"><a href="${esc(url)}" target="_blank" rel="noopener"><img class="kc-image" src="${esc(url)}" alt="${esc(title || (qr ? "Kod QR bayaran" : "Gambar produk"))}" loading="lazy" decoding="async"></a><figcaption>${esc(media.mime === "image/png" ? "PNG" : "JPEG")} · ${Math.ceil(Number(media.size || 0) / 1024)} KB</figcaption></figure>`;
}

function knowledgeMediaPicker(editor, group, id, field, current, title) {
  const qr = group === "payment";
  return `<div class="kc-media-picker">
    <div data-kc-preview="${esc(id || "payment")}">${knowledgeMediaPreview(editor, current, title, qr)}</div>
    <div>
      <label class="field">${qr ? "Pilih gambar QR yang telah dimuat naik" : "Pilih gambar yang telah dimuat naik"}
        <select ${knowledgeAttrs(group, id, field)}><option value="">Tiada gambar</option>${Array.from(editor.media.values()).map((media, index) => `<option value="${esc(media.id)}" ${media.id === current ? "selected" : ""}>Gambar ${index + 1} · ${media.mime === "image/png" ? "PNG" : "JPEG"} · ${Math.ceil(Number(media.size || 0) / 1024)} KB</option>`).join("")}</select>
      </label>
      <label class="field kc-upload">${qr ? "Muat naik gambar QR" : "Muat naik gambar baharu"}
        <input type="file" accept="image/jpeg,image/png" data-kc-upload="${esc(id)}" data-kc-upload-group="${group}">
      </label>
      <p class="muted kc-hint">JPG atau PNG, maksimum 2 MB. Gambar hanya boleh digunakan AI selepas anda menerbitkan draf.</p>
    </div>
  </div>`;
}

function knowledgeSourceCard(editor, item, index) {
  const attr = (field) => knowledgeAttrs("source", item.id, field);
  const kinds = [["website", "Laman web"], ["landing", "Landing page"], ["facebook", "Facebook Page"], ["ads", "Iklan"]];
  return `<details class="kc-card" data-kc-record="${esc(item.id)}" ${editor.open.has(item.id) ? "open" : ""}>
    <summary><span><strong>${esc(item.title || `Sumber ${index + 1}`)}</strong><small>${esc(kinds.find(([key]) => key === item.kind)?.[1] || "Pautan")} · ${item.enabled ? "Disertakan dalam draf" : "Tidak disertakan"}</small></span></summary>
    <div class="kc-card-body">
      <div class="kc-grid">
        <label class="field">Jenis sumber<select ${attr("kind")}>${kinds.map(([key, label]) => `<option value="${key}" ${item.kind === key ? "selected" : ""}>${label}</option>`).join("")}</select></label>
        <label class="field">Tajuk sumber<input ${attr("title")} value="${esc(item.title)}" maxlength="160" placeholder="Contoh: Halaman produk utama"></label>
      </div>
      <label class="field">Pautan sumber<input ${attr("url")} type="url" inputmode="url" value="${esc(item.url)}" maxlength="2048" placeholder="https://…"></label>
      <p><button class="btn btn--ghost" type="button" data-act="kc-import" data-value="${esc(item.id)}">Baca pautan</button></p>
      <p class="muted kc-hint">Semak kandungan yang dibaca sebelum digunakan. Jika Facebook Page atau iklan tidak dapat dibaca, tampal teksnya di bawah.</p>
      <label class="field">Teks sumber yang telah disemak<textarea ${attr("text")} rows="5" maxlength="8000" placeholder="Tampal teks daripada laman, halaman produk atau iklan anda.">${esc(item.text)}</textarea></label>
      <div class="kc-card-actions">
        <label class="kc-check"><input ${attr("enabled")} type="checkbox" ${item.enabled ? "checked" : ""}> <span>Sertakan sumber ini apabila diterbitkan</span></label>
        <button class="btn btn--ghost kc-remove" type="button" data-act="kc-remove-source" data-value="${esc(item.id)}">Buang sumber</button>
      </div>
    </div>
  </details>`;
}

function knowledgeAssetCard(editor, item, index) {
  const attr = (field) => knowledgeAttrs("asset", item.id, field);
  const categories = [["product", "Produk"], ["combo", "Kombo"], ["reference", "Maklumat penting"]];
  return `<details class="kc-card" data-kc-record="${esc(item.id)}" ${editor.open.has(item.id) ? "open" : ""}>
    <summary><span><strong>${esc(item.title || `Gambar ${index + 1}`)}</strong><small>${esc(categories.find(([key]) => key === item.category)?.[1] || "Produk")} · ${item.enabled ? "Boleh dipilih selepas diterbitkan" : "Belum dibenarkan untuk AI"}</small></span></summary>
    <div class="kc-card-body">
      ${knowledgeMediaPicker(editor, "asset", item.id, "media_id", item.media_id, item.title)}
      <div class="kc-grid">
        <label class="field">Kategori<select ${attr("category")}>${categories.map(([key, label]) => `<option value="${key}" ${item.category === key ? "selected" : ""}>${label}</option>`).join("")}</select></label>
        <label class="field">Tajuk gambar<input ${attr("title")} value="${esc(item.title)}" maxlength="160" placeholder="Contoh: Kombo Starter"></label>
      </div>
      <label class="field">Fakta produk atau kombo<textarea ${attr("facts")} rows="3" maxlength="2000" placeholder="Kandungan, harga yang disahkan, pilihan dan maklumat penting.">${esc(item.facts)}</textarea></label>
      <label class="field">Kapsyen kepada pelanggan<textarea ${attr("caption")} rows="2" maxlength="1000" placeholder="Teks ringkas yang boleh dihantar bersama gambar.">${esc(item.caption)}</textarea></label>
      <label class="field">Bila AI boleh menghantar gambar ini<textarea ${attr("when_to_send")} rows="2" maxlength="400" placeholder="Contoh: Apabila pelanggan meminta gambar atau kandungan Kombo Starter.">${esc(item.when_to_send)}</textarea></label>
      <div class="kc-card-actions">
        <label class="kc-check"><input ${attr("enabled")} type="checkbox" ${item.enabled ? "checked" : ""}> <span>Benarkan AI memilih gambar ini selepas diterbitkan</span></label>
        <button class="btn btn--ghost kc-remove" type="button" data-act="kc-remove-asset" data-value="${esc(item.id)}">Buang rekod gambar</button>
      </div>
    </div>
  </details>`;
}

function knowledgePaymentPreview(editor) {
  const payment = editor.document.payment;
  return `<div class="kc-payment-preview">
    <dl><div><dt>Bank</dt><dd>${esc(payment.bank_name || "—")}</dd></div>
    <div><dt>Nama pemegang akaun</dt><dd>${esc(payment.account_name || "—")}</dd></div>
    <div><dt>Nombor akaun</dt><dd>${esc(payment.account_number || "—")}</dd></div></dl>
    ${payment.instructions ? `<p class="kc-preserve">${esc(payment.instructions)}</p>` : ""}
    ${knowledgeMediaPreview(editor, payment.qr_media_id, "Kod QR bayaran", true)}
  </div>`;
}

function knowledgeReview(editor, state) {
  const doc = editor.document;
  const sources = doc.sources.filter((item) => item.enabled);
  const assets = doc.assets.filter((item) => item.enabled);
  const connections = Array.isArray(state.ai.connections) ? state.ai.connections : [];
  const firstPublication = !state.ai.revision;
  return `<section class="kc-review kc-section" id="kc-review" aria-labelledby="kc-review-title">
    <h2 id="kc-review-title">Semak sebelum diterbitkan</h2>
    <p>Anda akan menggantikan pengetahuan aktif dengan draf versi ${editor.revision}. Hanya maklumat dan gambar yang disertakan di bawah boleh digunakan AI.</p>
    ${firstPublication ? '<p class="kc-notice">Pilih nombor WhatsApp yang akan menggunakan pengetahuan ini. Selepas penerbitan, lengkapkan kawalan AI untuk menghidupkan balasan.</p>' : ""}
    <h3>Fakta perniagaan</h3><div class="kc-preview-text">${esc(doc.facts || "Tiada fakta utama.")}</div>
    ${sources.length ? `<h3>Sumber yang disertakan</h3>${sources.map((item) => `<details class="kc-card"><summary>${esc(item.title)}</summary><div class="kc-card-body"><p class="muted kc-preserve">${esc(item.url)}</p><div class="kc-preview-text">${esc(item.text)}</div></div></details>`).join("")}` : '<p class="muted">Tiada pautan sumber disertakan.</p>'}
    ${assets.length ? `<h3>Gambar yang dibenarkan</h3><div class="kc-review-media">${assets.map((item) => `<article class="kc-mini-card">${knowledgeMediaPreview(editor, item.media_id, item.title)}<h4>${esc(item.title)}</h4><p class="kc-preserve">${esc(item.facts)}</p><p class="kc-preserve"><strong>Kapsyen:</strong> ${esc(item.caption || "Tiada kapsyen")}</p><p class="kc-preserve"><strong>Bila dihantar:</strong> ${esc(item.when_to_send)}</p></article>`).join("")}</div>` : '<p class="muted">Tiada gambar produk dibenarkan untuk AI.</p>'}
    <h3>Maklumat bayaran</h3>
    ${doc.payment.enabled ? knowledgePaymentPreview(editor) : '<p class="muted">Maklumat bayaran tidak disertakan.</p>'}
    <p class="muted kc-hint">Had gabungan fakta untuk penerbitan ialah 8,000 bait. Jika terlalu panjang, ringkaskan sumber atau nyahpilih maklumat yang tidak diperlukan.</p>
    <form id="knowledge-publish">
      <fieldset class="kc-fields" ${editor.busy ? "disabled" : ""}>
        ${firstPublication ? connections.length ? `<label class="field">Nombor WhatsApp untuk pengetahuan ini<select name="connection" data-kc-control="publication_connection" required><option value="" ${!editor.publicationConnection ? "selected" : ""}>Pilih nombor perniagaan</option>${connections.map((connection) => `<option value="${esc(connection.id)}" ${Number(connection.id) === Number(editor.publicationConnection) ? "selected" : ""}>${esc(connection.display_phone || "Nombor belum disahkan")}</option>`).join("")}</select></label>` : '<p class="kc-notice">Sambungkan nombor WhatsApp dalam halaman Sambungan sebelum penerbitan pertama. Draf ini kekal disimpan.</p>' : ""}
        <label class="kc-check"><input type="checkbox" name="approved" required> <span>Saya telah menyemak dan mengesahkan fakta, sumber dan gambar yang disertakan.</span></label>
        ${doc.payment.enabled ? '<label class="kc-check"><input type="checkbox" name="payment_confirmed" required> <span>Saya telah menyemak bank, nama pemegang akaun, nombor akaun, arahan dan kod QR (jika ada) yang dipaparkan.</span></label>' : ""}
        <p class="kc-actions"><button class="btn" type="submit" ${firstPublication && !connections.length ? "disabled" : ""}>Sahkan & terbitkan pengetahuan</button><button class="btn btn--ghost" type="button" data-act="kc-close-review">Kembali menyunting</button></p>
      </fieldset>
    </form>
    <p class="muted kc-hint">Penerbitan mengemas kini pengetahuan. Pilihan penerima, had harian dan pengaktifan balasan diurus dalam Kawalan AI.</p>
  </section>`;
}

function knowledgeCentre(state) {
  const editor = knowledgeEditorFor(state);
  if (!editor) return '<article class="panel"><h1>Pusat Pengetahuan Perniagaan</h1><p class="muted">Pemilik perniagaan mengurus fakta, gambar dan maklumat bayaran yang boleh digunakan AI.</p></article>';
  const doc = editor.document;
  const payment = doc.payment;
  const attr = (field) => knowledgeAttrs("payment", "", field);
  const note = editor.busy || editor.notice;
  const compiledBytes = knowledgeCompiledBytes(doc);
  return `<article class="panel knowledge-centre" aria-busy="${editor.busy ? "true" : "false"}">
    <header class="kc-heading"><p class="kc-eyebrow">Pengetahuan perniagaan anda</p><h1>Pusat Pengetahuan Perniagaan</h1>
      <p class="muted">Sediakan fakta, gambar dan maklumat bayaran untuk membantu AI menjawab pelanggan. Simpan draf, semak kandungannya, kemudian terbitkan apabila semuanya betul.</p>
      <div class="kc-status-row"><span class="kc-status" id="kc-save-state">${editor.dirty ? "Perubahan belum disimpan" : editor.revision ? `Draf disimpan · Versi ${editor.revision}` : "Draf baharu"}</span><span class="muted">${editor.publishedRevision ? `Versi aktif ${esc(editor.publishedRevision)}${editor.publishedAt ? " · " + esc(editor.publishedAt) : ""}` : "Belum diterbitkan"}</span></div>
    </header>
    <nav class="kc-nav" aria-label="Bahagian pusat pengetahuan">${[["kc-facts", "01", "Fakta & pautan"], ["kc-assets", "02", "Gambar produk"], ["kc-payment", "03", "Bayaran"], ["kc-test", "04", "Kawalan AI"]].map(([id, number, label]) => `<button type="button" data-act="kc-jump" data-value="${id}"><span>${number}</span>${label}</button>`).join("")}</nav>
    <p id="kc-feedback" class="kc-notice ${!editor.busy && editor.noticeKind === "error" ? "kc-notice--error" : ""}" role="${editor.noticeKind === "error" ? "alert" : "status"}" ${note ? "" : "hidden"}>${esc(note)}</p>
    ${editor.conflict ? '<div class="kc-notice kc-notice--error" role="alert"><p>Draf pelayan telah berubah dalam sesi lain. Perubahan anda masih berada dalam borang ini. Salin perubahan yang diperlukan sebelum memuat semula draf.</p><button class="btn btn--ghost" type="button" data-act="kc-reload">Buang perubahan & muat semula draf</button></div>' : ""}
    <form id="knowledge-draft" autocomplete="off">
      <fieldset class="kc-fields" ${editor.busy ? "disabled" : ""}>
        <section class="kc-section" id="kc-facts"><h2><span class="kc-number">01</span> Fakta & pautan sumber</h2>
          <p class="muted">Mulakan dengan maklumat yang paling kerap ditanya: produk, harga, penghantaran, waktu operasi dan bantuan pelanggan.</p>
          <label class="field">Fakta perniagaan<textarea ${knowledgeAttrs("document", "", "facts")} rows="8" maxlength="8000" placeholder="Tulis fakta yang telah disahkan untuk perniagaan anda.">${esc(doc.facts)}</textarea></label>
          <div class="kc-section-heading"><h3>Pautan sumber <span class="muted">${doc.sources.length} / 8</span></h3><button class="btn btn--ghost" type="button" data-act="kc-add-source" ${doc.sources.length >= 8 ? "disabled" : ""}>Tambah pautan</button></div>
          <p class="muted kc-hint">Laman web, landing page, iklan atau Facebook Page. Kandungan yang dibaca menjadi draf untuk anda semak.</p>
          ${doc.sources.map((item, index) => knowledgeSourceCard(editor, item, index)).join("") || '<div class="kc-empty">Belum ada pautan sumber. Tambah pautan atau terus tulis fakta perniagaan di atas.</div>'}
        </section>
        <section class="kc-section" id="kc-assets"><div class="kc-section-heading"><h2><span class="kc-number">02</span> Gambar produk & kombo</h2><button class="btn btn--ghost" type="button" data-act="kc-add-asset" ${doc.assets.length >= 20 ? "disabled" : ""}>Tambah gambar</button></div>
          <p class="muted">Sediakan gambar produk, kombo atau maklumat penting bersama kapsyen dan keadaan yang sesuai untuk menghantarnya. ${doc.assets.length} / 20 rekod.</p>
          ${doc.assets.map((item, index) => knowledgeAssetCard(editor, item, index)).join("") || '<div class="kc-empty">Belum ada gambar produk. Setiap gambar boleh diberikan tajuk, fakta, kapsyen dan syarat penggunaannya.</div>'}
        </section>
        <section class="kc-section" id="kc-payment"><h2><span class="kc-number">03</span> Maklumat bayaran</h2>
          <p class="muted">Semak butiran penerima dan gambar QR sebelum membenarkan AI memberikannya kepada pelanggan. Maklumat ini tidak mengesahkan bahawa bayaran telah diterima.</p>
          <label class="kc-check"><input ${attr("enabled")} type="checkbox" ${payment.enabled ? "checked" : ""}> <span>Benarkan AI berkongsi maklumat bayaran selepas diterbitkan</span></label>
          <div class="kc-grid kc-payment-fields">
            <label class="field">Nama bank<input ${attr("bank_name")} value="${esc(payment.bank_name)}" maxlength="100" autocomplete="off" placeholder="Contoh: Nama bank perniagaan"></label>
            <label class="field">Nama pemegang akaun<input ${attr("account_name")} value="${esc(payment.account_name)}" maxlength="160" autocomplete="off"></label>
          </div>
          <label class="field">Nombor akaun<input ${attr("account_number")} type="text" inputmode="numeric" value="${esc(payment.account_number)}" maxlength="64" autocomplete="off"></label>
          <label class="field">Arahan bayaran / rujukan<textarea ${attr("instructions")} rows="3" maxlength="400" placeholder="Contoh: Gunakan nombor pesanan sebagai rujukan.">${esc(payment.instructions)}</textarea></label>
          ${knowledgeMediaPicker(editor, "payment", "", "qr_media_id", payment.qr_media_id, "Kod QR bayaran")}
          <details class="kc-card" open><summary>Pratonton maklumat bayaran</summary><div class="kc-card-body" id="kc-payment-preview">${knowledgePaymentPreview(editor)}</div></details>
        </section>
        <div class="kc-capacity"><p id="kc-capacity-label" class="${compiledBytes > 8000 ? "kc-limit-error" : "muted"}">Ruang fakta: ${compiledBytes.toLocaleString("en-US")} / 8,000 bait.</p><progress id="kc-capacity-meter" max="8000" value="${Math.min(compiledBytes, 8000)}" aria-labelledby="kc-capacity-label"></progress><p class="muted kc-hint">Gabungan fakta utama, teks sumber dan keterangan gambar mesti muat sebelum diterbitkan. ${compiledBytes > 8000 ? "Draf masih boleh disimpan. Ringkaskan teks atau nyahpilih sumber yang tidak diperlukan." : "Pautan dan tajuk sumber turut menggunakan ruang ini."}</p></div>
        <div class="kc-save-bar"><div><strong>Draf dahulu, terbitkan selepas semakan.</strong><p class="muted kc-hint">Perubahan belum disimpan akan hilang jika halaman ditutup. Draf yang disimpan belum digunakan AI.</p></div>
          <div class="kc-actions"><button class="btn" type="submit" data-kc-save ${editor.conflict || (!editor.dirty && editor.revision > 0) ? "disabled" : ""}>Simpan draf</button><button class="btn btn--ghost" type="button" data-act="kc-review" data-kc-review ${editor.dirty || editor.conflict || editor.revision < 1 ? "disabled" : ""}>Semak & terbitkan</button></div>
        </div>
      </fieldset>
    </form>
    ${editor.review && !editor.dirty ? knowledgeReview(editor, state) : ""}
    ${aiSettings(state, true)}
  </article>`;
}

function aiControlDefaults(ai) {
  const configured = ai.controls_configured === true || (ai.controls_configured !== false && Number(ai.revision) > 0);
  return {
    connection: ai.connection_id || 0, revision: ai.revision || 0, allowlist: (ai.allowlist || []).join("\n"),
    audience_mode: ai.production_available === true && !configured ? "all" : ai.audience_mode === "all" ? "all" : "test",
    daily_limit: ai.daily_limit || 20, enabled: ai.enabled && ai.deployment_ready, approved: false
  };
}

function aiAudienceCopy(mode) {
  return mode === "all" ? {
    consent: "Saya sahkan pengetahuan aktif dan membenarkan AI membalas mesej baharu daripada semua pelanggan yang menghubungi nombor perniagaan ini, mengikut had harian yang ditetapkan.",
    enable: "Aktifkan AI Auto Reply untuk mesej baharu daripada semua pelanggan.",
    followup: "Selepas mengaktifkan AI, hantar mesej baharu kepada nombor perniagaan daripada nombor WhatsApp lain. Semak mesej masuk, balasan AI dan status penghantaran dalam Peti masuk."
  } : {
    consent: "Saya sahkan pengetahuan aktif dan membenarkan AI membalas mesej baharu daripada nombor ujian yang disenaraikan, mengikut had harian yang ditetapkan.",
    enable: "Aktifkan balasan untuk mesej baharu daripada nombor ujian.",
    followup: "Selepas mengaktifkan AI, hantar mesej baharu daripada nombor ujian yang disenaraikan. Semak mesej masuk, balasan AI dan status penghantaran dalam Peti masuk."
  };
}

function updateAiAudienceControls(target) {
  if (!target || !["all", "test"].includes(target.value)) return;
  const all = target.value === "all";
  const copy = aiAudienceCopy(target.value);
  const recipients = document.getElementById("ai-test-recipients");
  const allowlist = document.getElementById("ai-allowlist");
  const approved = document.getElementById("ai-approved");
  if (recipients) recipients.hidden = all;
  if (allowlist) allowlist.required = !all;
  if (approved) approved.checked = false;
  for (const [id, text] of [["ai-consent-copy", copy.consent], ["ai-enable-copy", copy.enable], ["ai-followup-copy", copy.followup]]) {
    const node = document.getElementById(id);
    if (node) node.textContent = text;
  }
}

function aiSettings(state, embedded = false) {
  const ai = state.ai;
  const centre = state.knowledgeCentre.available === true;
  const editor = centre ? knowledgeEditorFor(state) : null;
  const controls = editor && editor.controls ? editor.controls : aiControlDefaults(ai);
  const all = controls.audience_mode === "all";
  const copy = aiAudienceCopy(controls.audience_mode);
  const active = ai.enabled && ai.deployment_ready && ai.controls_configured !== false
    && (ai.audience_mode !== "all" || ai.audience_approved === true);
  const status = active ? "aktif" : ai.enabled ? "dijeda" : "dimatikan";
  const tag = embedded ? "section" : "article";
  const heading = embedded ? "h2" : "h1";
  if (!ai.can_manage) return `<${tag} class="${embedded ? "kc-section" : "panel"}" ${embedded ? 'id="kc-test"' : ""}><${heading}>Kawalan AI Auto Reply</${heading}><p>Pemilik perniagaan mengurus fakta dan pengaktifan AI. Status semasa: ${status}.</p>${role(state) === "owner" && !ai.available ? '<p class="muted">Kawalan AI belum tersedia. Anda masih boleh menyediakan dan menerbitkan pengetahuan perniagaan.</p>' : ""}</${tag}>`;
  const connections = Array.isArray(ai.connections) ? ai.connections : [];
  return `<${tag} class="${embedded ? "kc-section" : "panel"}" ${embedded ? 'id="kc-test"' : ""}>
    <${heading}>${embedded ? '<span class="kc-number">04</span> Kawalan AI Auto Reply' : "Pengetahuan dan AI Auto Reply"}</${heading}>
    <p>AI menjawab menggunakan pengetahuan yang anda sahkan. Soalan yang memerlukan tindakan atau maklumat tambahan diserahkan kepada manusia.</p>
    <p><strong>AI Auto Reply ${status}</strong> · Penggunaan hari ini: ${esc(ai.quota_used || 0)} / ${esc(ai.daily_limit || 20)} cubaan jawapan.</p>
    ${ai.enabled && !active && ai.deployment_ready ? '<p class="kc-notice">Pemilik semasa perlu menyemak pengetahuan dan meluluskan pilihan penerima sebelum balasan diteruskan.</p>' : ""}
    ${ai.controls_configured === true ? `<p class="muted">Penerima tersimpan: ${ai.audience_mode === "all" ? "semua pelanggan yang menghubungi nombor perniagaan" : "nombor ujian yang disenaraikan"}.</p>` : ""}
    ${!ai.deployment_ready ? '<p class="muted">Pengaktifan AI pada pelayan belum tersedia. Anda boleh menyediakan pengetahuan dan tetapan balasan dahulu.</p>' : ""}
    ${ai.enabled ? '<p><button class="btn btn--ghost" type="button" data-act="ai-stop">Hentikan AI</button></p>' : ""}
    ${connections.length ? `<form id="ai-settings">
      <p class="error" id="form-error" hidden></p>
      <fieldset class="kc-fields" ${editor && editor.busy ? "disabled" : ""}>
      <input name="revision" type="hidden" value="${esc(controls.revision)}">
      <label class="field">Nombor perniagaan<select name="connection" ${centre ? 'data-kc-control="connection"' : ""} required>${!controls.connection ? '<option value="" selected>Pilih nombor perniagaan</option>' : ""}${connections.map((c) => `<option value="${esc(c.id)}" ${Number(c.id) === Number(controls.connection) ? "selected" : ""}>${esc(c.display_phone || "Nombor belum disahkan")}</option>`).join("")}</select></label>
      ${centre ? `<p class="muted">Tetapan ini menggunakan versi pengetahuan yang telah diterbitkan. Draf yang belum diterbitkan tidak mengubah balasan AI.</p>${!ai.knowledge ? '<p class="kc-notice">Terbitkan pengetahuan perniagaan sebelum mengaktifkan AI Auto Reply.</p>' : ""}` : `<label class="field">Fakta yang boleh digunakan AI<textarea name="knowledge" rows="10" required maxlength="8000" placeholder="Nyatakan perkhidmatan, harga yang disahkan, waktu operasi dan cara mendapatkan bantuan.">${esc(ai.knowledge || "")}</textarea></label>`}
      <label class="field">Siapa yang boleh menerima balasan AI?<select name="audience_mode" data-ai-audience ${centre ? 'data-kc-control="audience_mode"' : ""}>${ai.production_available === true || all ? `<option value="all" ${all ? "selected" : ""} ${ai.production_available === true ? "" : "disabled"}>Semua pelanggan yang menghubungi nombor perniagaan</option>` : ""}<option value="test" ${all ? "" : "selected"}>Nombor ujian yang disenaraikan sahaja</option></select></label>
      <div id="ai-test-recipients" ${all ? "hidden" : ""}>
        <label class="field">Nombor telefon untuk ujian<textarea id="ai-allowlist" name="allowlist" ${centre ? 'data-kc-control="allowlist"' : ""} rows="3" ${all ? "" : "required"} placeholder="Satu nombor pada setiap baris, bermula dengan kod negara.">${esc(controls.allowlist)}</textarea></label>
        <p class="muted">Gunakan nombor telefon lain yang anda kawal untuk menghantar mesej kepada nombor perniagaan. Maksimum 10 nombor ujian.</p>
      </div>
      <label class="field">Had cubaan jawapan sehari<input name="daily_limit" ${centre ? 'data-kc-control="daily_limit"' : ""} type="number" min="1" max="1000" required value="${esc(controls.daily_limit)}"></label>
      <label class="kc-check"><input id="ai-approved" name="approved" ${centre ? 'data-kc-control="approved"' : ""} type="checkbox" ${controls.approved ? "checked" : ""} required> <span id="ai-consent-copy">${copy.consent}</span></label>
      <p><label class="kc-check"><input name="enabled" ${centre ? 'data-kc-control="enabled"' : ""} type="checkbox" ${controls.enabled ? "checked" : ""} ${ai.deployment_ready ? "" : "disabled"}> <span id="ai-enable-copy">${copy.enable}</span></label></p>
      <button class="btn" type="submit">${centre ? "Simpan tetapan AI" : "Simpan fakta dan tetapan"}</button>
      </fieldset>
    </form>` : '<p>Sambungkan nombor perniagaan dahulu. Tetapan AI boleh disimpan selepas sambungan itu tersedia.</p>'}
    <p id="ai-followup-copy" class="muted">${copy.followup}</p>
    <p class="muted">Anda boleh menghentikan AI di sini atau mengambil alih perbualan tertentu melalui Peti masuk.</p>
    <p><a class="btn btn--ghost" href="#peti">Buka peti masuk</a></p>
  </${tag}>`;
}

function knowledgeNotice(editor, message, kind = "info") {
  editor.notice = message;
  editor.noticeKind = kind;
}

function knowledgeCurrent(editor) {
  return knowledgeEditor === editor && live && knowledgeScope(live) === editor.scope
    && live.knowledge_centre && live.knowledge_centre.can_manage === true;
}

function knowledgeChrome(editor) {
  const status = document.getElementById("kc-save-state");
  if (status) status.textContent = editor.dirty ? "Perubahan belum disimpan" : editor.revision ? `Draf disimpan · Versi ${editor.revision}` : "Draf baharu";
  const feedback = document.getElementById("kc-feedback");
  if (feedback) {
    feedback.textContent = editor.busy || editor.notice;
    feedback.hidden = !feedback.textContent;
    feedback.className = "kc-notice" + (!editor.busy && editor.noticeKind === "error" ? " kc-notice--error" : "");
    feedback.setAttribute("role", editor.noticeKind === "error" ? "alert" : "status");
  }
  for (const button of document.querySelectorAll("[data-kc-save]")) button.disabled = Boolean(editor.busy || editor.conflict || (!editor.dirty && editor.revision > 0));
  for (const button of document.querySelectorAll("[data-kc-review]")) button.disabled = Boolean(editor.busy || editor.dirty || editor.conflict || editor.revision < 1);
  const review = document.getElementById("kc-review");
  if (review && !editor.review) review.hidden = true;
  const bytes = knowledgeCompiledBytes(editor.document);
  const capacity = document.getElementById("kc-capacity-label");
  if (capacity) {
    capacity.textContent = `Ruang fakta: ${bytes.toLocaleString("en-US")} / 8,000 bait.`;
    capacity.className = bytes > 8000 ? "kc-limit-error" : "muted";
  }
  const meter = document.getElementById("kc-capacity-meter");
  if (meter) meter.value = Math.min(bytes, 8000);
}

function knowledgeChanged(editor) {
  editor.dirty = JSON.stringify(editor.document) !== editor.saved;
  editor.review = false;
  knowledgeNotice(editor, "");
  knowledgeChrome(editor);
}

function knowledgeInput(target) {
  if (!target || typeof target.getAttribute !== "function") return false;
  const field = target.getAttribute("data-kc-field");
  const control = target.getAttribute("data-kc-control");
  if (!field && !control) return false;
  const state = load();
  const editor = knowledgeEditorFor(state);
  if (!editor || editor.busy) return true;
  const value = target.type === "checkbox" ? target.checked : target.value;
  if (control) {
    if (control === "publication_connection") { editor.publicationConnection = value; return true; }
    if (!["connection", "allowlist", "audience_mode", "daily_limit", "enabled", "approved"].includes(control)) return true;
    if (!editor.controls) editor.controls = aiControlDefaults(state.ai);
    if (control === "audience_mode") {
      if (!["all", "test"].includes(value) || (value === "all" && state.ai.production_available !== true)) return true;
      if (editor.controls.audience_mode !== value) editor.controls.approved = false;
    }
    editor.controls[control] = value;
    return true;
  }
  const group = target.getAttribute("data-kc-group");
  const id = target.getAttribute("data-kc-id");
  const fields = {
    document: ["facts"], source: ["kind", "url", "title", "text", "enabled"],
    asset: ["category", "title", "facts", "caption", "when_to_send", "media_id", "enabled"],
    payment: ["enabled", "bank_name", "account_name", "account_number", "instructions", "qr_media_id"]
  };
  if (!fields[group] || !fields[group].includes(field)) return true;
  const record = group === "document" ? editor.document : group === "payment" ? editor.document.payment
    : (group === "source" ? editor.document.sources : editor.document.assets).find((item) => item.id === id);
  if (!record || record[field] === value) return true;
  record[field] = value;
  knowledgeChanged(editor);
  if (group === "payment") {
    const preview = document.getElementById("kc-payment-preview");
    if (preview) preview.innerHTML = knowledgePaymentPreview(editor);
  }
  if (field === "media_id" || field === "qr_media_id") {
    const preview = document.querySelector(`[data-kc-preview="${group === "payment" ? "payment" : id}"]`);
    if (preview) preview.innerHTML = knowledgeMediaPreview(editor, value, record.title || "Kod QR bayaran", group === "payment");
  }
  return true;
}

function knowledgeValidation(doc, publishing = false) {
  const bytes = (value) => new TextEncoder().encode(value).length;
  if (doc.sources.length > 8 || doc.assets.length > 20) return "Maksimum 8 pautan sumber dan 20 rekod gambar.";
  const bounded = (text, limit, label) => bytes(text) > limit ? `${label} terlalu panjang. Ringkaskan kepada paling banyak ${limit.toLocaleString("en-US")} bait.` : "";
  let error = bounded(doc.facts, 8000, "Fakta perniagaan");
  if (error) return error;
  for (const source of doc.sources) {
    for (const [key, limit, label] of [["url", 2048, "Pautan sumber"], ["title", 160, "Tajuk sumber"], ["text", 8000, "Teks sumber"]]) {
      error = bounded(source[key], limit, label);
      if (error) return error;
    }
    if (source.url) {
      try {
        const url = new URL(source.url);
        if (url.protocol !== "https:" || url.username || url.password) return "Gunakan pautan HTTPS awam tanpa maklumat log masuk.";
      } catch {
        return "Pautan sumber tidak sah. Gunakan alamat penuh bermula dengan https://.";
      }
    }
    if (publishing && source.enabled && (!source.url.trim() || !source.title.trim() || !source.text.trim())) return "Lengkapkan pautan, tajuk dan teks bagi setiap sumber yang disertakan.";
  }
  for (const asset of doc.assets) {
    for (const [key, limit, label] of [["title", 160, "Tajuk gambar"], ["facts", 2000, "Fakta produk"], ["caption", 1000, "Kapsyen"], ["when_to_send", 400, "Syarat penghantaran gambar"]]) {
      error = bounded(asset[key], limit, label);
      if (error) return error;
    }
    if (publishing && asset.enabled && (!asset.title.trim() || !asset.media_id || !asset.when_to_send.trim())) return "Lengkapkan tajuk, gambar dan syarat penghantaran bagi setiap gambar yang dibenarkan.";
  }
  for (const [key, limit, label] of [["bank_name", 100, "Nama bank"], ["account_name", 160, "Nama pemegang akaun"], ["account_number", 64, "Nombor akaun"], ["instructions", 400, "Arahan bayaran"]]) {
    error = bounded(doc.payment[key], limit, label);
    if (error) return error;
  }
  if (publishing && doc.payment.enabled && (!doc.payment.bank_name.trim() || !doc.payment.account_name.trim() || !doc.payment.account_number.trim())) return "Lengkapkan bank, nama pemegang akaun dan nombor akaun sebelum menerbitkan maklumat bayaran.";
  if (publishing) {
    const compiledBytes = knowledgeCompiledBytes(doc);
    if (compiledBytes === 0) return "Masukkan fakta atau sertakan sumber yang telah disemak sebelum menerbitkan.";
    if (compiledBytes > 8000) return `Gabungan fakta yang disertakan menggunakan ${compiledBytes.toLocaleString("en-US")} / 8,000 bait. Ringkaskan teks sumber, fakta produk atau nyahpilih sumber yang tidak diperlukan. Draf anda dikekalkan.`;
  }
  if (bytes(JSON.stringify(doc)) > 60000) return "Draf terlalu besar. Ringkaskan kandungan atau buang sumber yang tidak diperlukan sebelum menyimpan.";
  return "";
}

function knowledgeAccept(editor, result, submitted, publication = false) {
  const centre = result && result.knowledge_centre;
  if (!knowledgeCurrent(editor) || knowledgeScope(result) !== editor.scope || !centre || centre.can_manage !== true
      || !Number.isInteger(centre.revision) || !centre.draft) return false;
  const unchanged = JSON.stringify(editor.document) === submitted;
  applyLive(result);
  const doc = knowledgeDocument(centre.draft);
  editor.saved = JSON.stringify(doc);
  if (unchanged) editor.document = doc;
  editor.revision = centre.revision;
  editor.dirty = JSON.stringify(editor.document) !== editor.saved;
  editor.conflict = false;
  editor.review = false;
  editor.publishedRevision = centre.published_revision || 0;
  editor.publishedAt = centre.published_at || "";
  if (publication && result.ai) {
    editor.publicationConnection = result.ai.connection_id || editor.publicationConnection;
    if (editor.controls) {
      editor.controls.revision = result.ai.revision || 0;
      if (!editor.controls.connection) editor.controls.connection = result.ai.connection_id || 0;
    }
  }
  knowledgeEditorFor(load());
  return true;
}

async function saveKnowledgeDraft() {
  const editor = knowledgeEditorFor(load());
  if (!editor || editor.busy) return;
  const error = editor.conflict ? "Draf pelayan telah berubah. Muat semula draf sebelum menyimpan." : knowledgeValidation(editor.document);
  if (error) { knowledgeNotice(editor, error, "error"); route(); return; }
  const submitted = JSON.stringify(editor.document);
  editor.busy = "Menyimpan draf…";
  route();
  const result = await callApi("knowsave", { expected_business_public_id: editor.businessPublicId, expected_revision: editor.revision, document: JSON.parse(submitted) });
  if (!knowledgeCurrent(editor)) return;
  editor.busy = "";
  if (result.ok && knowledgeAccept(editor, result, submitted)) knowledgeNotice(editor, editor.dirty ? "Draf disimpan. Masih ada perubahan baharu yang belum disimpan." : "Draf disimpan. Semak dan terbitkan apabila anda bersedia.");
  else knowledgeNotice(editor, result.error || "Draf tidak dapat disimpan. Perubahan anda masih berada dalam borang.", "error");
  route();
}

async function publishKnowledge(data) {
  const state = load();
  const editor = knowledgeEditorFor(state);
  if (!editor || editor.busy) return;
  const error = editor.dirty || editor.conflict || editor.revision < 1 ? "Simpan draf terkini sebelum menerbitkan." : knowledgeValidation(editor.document, true);
  if (error || !editor.review || data.get("approved") !== "on" || (editor.document.payment.enabled && data.get("payment_confirmed") !== "on")) {
    knowledgeNotice(editor, error || "Semak pratonton dan tandakan pengesahan sebelum menerbitkan.", "error");
    route();
    return;
  }
  const payload = { expected_business_public_id: editor.businessPublicId, expected_revision: editor.revision, approved: true, payment_confirmed: data.get("payment_confirmed") === "on" };
  if (!state.ai.revision) {
    const connectionId = Number(data.get("connection") ?? editor.publicationConnection);
    if (!Number.isSafeInteger(connectionId) || connectionId < 1 || !(state.ai.connections || []).some((item) => Number(item.id) === connectionId)) {
      knowledgeNotice(editor, "Pilih nombor perniagaan dalam semakan penerbitan ini dahulu. Jika tiada pilihan, sambungkan nombor WhatsApp perniagaan.", "error");
      route();
      return;
    }
    payload.connection_id = connectionId;
  }
  const submitted = JSON.stringify(editor.document);
  editor.busy = "Menerbitkan pengetahuan yang disahkan…";
  route();
  const result = await callApi("knowpublish", payload);
  if (!knowledgeCurrent(editor)) return;
  editor.busy = "";
  if (result.ok && knowledgeAccept(editor, result, submitted, true)) {
    knowledgeNotice(editor, "Pengetahuan diterbitkan. Lengkapkan Kawalan AI untuk membenarkan balasan menggunakan versi yang disahkan.");
  } else {
    knowledgeNotice(editor, result.error || "Status penerbitan belum dapat disahkan. Muat semula untuk menyemak versi aktif.", "error");
    editor.review = true;
  }
  route();
}

async function importKnowledgeSource(editor, id) {
  const source = editor.document.sources.find((item) => item.id === id);
  if (!source || editor.busy) return;
  let valid = false;
  try { const url = new URL(source.url); valid = url.protocol === "https:" && !url.username && !url.password; } catch {}
  if (!valid) { knowledgeNotice(editor, "Masukkan pautan HTTPS awam sebelum membaca sumber.", "error"); route(); return; }
  editor.busy = "Membaca pautan sebagai draf…";
  route();
  const result = await callApi("knowimport", { expected_business_public_id: editor.businessPublicId, url: source.url });
  if (!knowledgeCurrent(editor)) return;
  editor.busy = "";
  const imported = result.source || result.imported;
  const target = editor.document.sources.find((item) => item.id === id);
  if (result.ok && target && imported && typeof imported.url === "string" && typeof imported.title === "string" && typeof imported.text === "string") {
    target.url = imported.url;
    target.title = imported.title;
    target.text = imported.text;
    editor.open.add(id);
    knowledgeChanged(editor);
    knowledgeNotice(editor, "Pautan dibaca sebagai draf. Semak teks, pilih untuk disertakan, kemudian simpan draf. Pengetahuan aktif belum berubah.");
  } else {
    knowledgeNotice(editor, (result.error || "Pautan tidak dapat dibaca.") + " Anda boleh tampal teks sumber secara manual.", "error");
  }
  route();
}

async function uploadKnowledgeMedia(target) {
  const editor = knowledgeEditorFor(load());
  if (!editor || editor.busy) return;
  const file = target.files && target.files[0];
  if (!file) return;
  if (!["image/jpeg", "image/png"].includes(file.type) || file.size < 1 || file.size > 2 * 1024 * 1024) {
    target.value = "";
    knowledgeNotice(editor, "Pilih gambar JPG atau PNG yang tidak melebihi 2 MB.", "error");
    knowledgeChrome(editor);
    return;
  }
  const group = target.getAttribute("data-kc-upload-group");
  const id = target.getAttribute("data-kc-upload");
  if (group !== "payment" && !editor.document.assets.some((item) => item.id === id)) return;
  const body = new FormData();
  body.append("expected_business_public_id", editor.businessPublicId);
  body.append("file", file);
  editor.busy = "Memuat naik gambar…";
  route();
  let result = { ok: false, error: "Gambar tidak dapat dimuat naik. Cuba semula." };
  try {
    const response = await fetch("akaun/knowledge-media.php", {
      method: "POST", credentials: "same-origin", cache: "no-store",
      headers: { Accept: "application/json", "X-CSRF-Token": csrf }, body
    });
    const parsed = await response.json();
    if (parsed && typeof parsed === "object") result = parsed;
    if (!response.ok) result.ok = false;
  } catch {}
  if (!knowledgeCurrent(editor)) return;
  if (typeof result.csrf === "string") csrf = result.csrf;
  editor.busy = "";
  const media = result.media;
  if (result.ok && media && /^[a-f0-9]{32}$/.test(media.id || "") && ["image/jpeg", "image/png"].includes(media.mime) && knowledgeMediaUrl(media.preview_url)) {
    editor.media.set(media.id, media);
    if (group === "payment") editor.document.payment.qr_media_id = media.id;
    else {
      const asset = editor.document.assets.find((item) => item.id === id);
      if (asset) asset.media_id = media.id;
    }
    knowledgeChanged(editor);
    knowledgeNotice(editor, "Gambar dimuat naik ke draf. Semak dan simpan draf sebelum menerbitkannya.");
  } else {
    knowledgeNotice(editor, result.error || "Pelayan tidak memulangkan gambar yang sah. Cuba semula.", "error");
  }
  route();
}

async function reloadKnowledgeDraft(editor) {
  editor.busy = "Memuat semula draf pelayan…";
  route();
  const result = await callApi("sesi");
  if (!knowledgeCurrent(editor)) return;
  editor.busy = "";
  if (result.ok && knowledgeScope(result) === editor.scope && result.knowledge_centre && result.knowledge_centre.can_manage) {
    applyLive(result);
    knowledgeEditor = null;
    const refreshed = knowledgeEditorFor(load());
    if (refreshed) knowledgeNotice(refreshed, "Draf terkini dimuat semula daripada pelayan.");
  } else knowledgeNotice(editor, result.error || "Draf tidak dapat dimuat semula. Perubahan anda dikekalkan.", "error");
  route();
}

function knowledgeClick(action, value) {
  const editor = knowledgeEditorFor(load());
  if (!editor) return;
  if (action === "kc-jump") {
    if (["kc-facts", "kc-assets", "kc-payment", "kc-test"].includes(value)) document.getElementById(value)?.scrollIntoView({ behavior: "smooth", block: "start" });
    return;
  }
  if (editor.busy) return;
  if (action === "kc-import") return importKnowledgeSource(editor, value);
  if (action === "kc-reload") return reloadKnowledgeDraft(editor);
  if (action === "kc-review") {
    const error = editor.dirty || editor.conflict || editor.revision < 1 ? "Simpan draf terkini sebelum membuat semakan penerbitan." : knowledgeValidation(editor.document, true);
    if (error) knowledgeNotice(editor, error, "error");
    else { editor.review = true; knowledgeNotice(editor, "Semak maklumat di bawah sebelum mengesahkan penerbitan."); }
    route();
    if (!error) document.getElementById("kc-review")?.scrollIntoView({ behavior: "smooth", block: "start" });
    return;
  }
  if (action === "kc-close-review") { editor.review = false; route(); return; }
  if (action === "kc-add-source" && editor.document.sources.length < 8) {
    const id = knowledgeId();
    editor.document.sources.push({ id, kind: "website", url: "", title: "", text: "", enabled: false });
    editor.open.add(id);
  } else if (action === "kc-add-asset" && editor.document.assets.length < 20) {
    const id = knowledgeId();
    editor.document.assets.push({ id, category: "product", title: "", facts: "", caption: "", when_to_send: "", media_id: "", enabled: false });
    editor.open.add(id);
  } else if (action === "kc-remove-source") {
    editor.document.sources = editor.document.sources.filter((item) => item.id !== value);
  } else if (action === "kc-remove-asset") {
    editor.document.assets = editor.document.assets.filter((item) => item.id !== value);
  } else return;
  knowledgeChanged(editor);
  route();
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
    [String(count.aiReplied), "Balasan AI diterima Meta", "Semak status sampai dalam Peti masuk."],
    [String(count.human), "Dipegang manusia", "Belum disambung ke pelayan."],
    [String(count.unanswered), "Belum dibalas", "Belum disambung ke pelayan."],
    [count.sent + " diterima Meta · " + count.failed + " gagal", "Hasil penghantaran", "Diterima Meta belum semestinya sampai kepada pelanggan."],
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
    ${state.ai.available || state.knowledgeCentre.available ? '<p><a class="btn" href="#pengetahuan">Tetapkan pengetahuan dan AI Auto Reply</a></p>' : ""}
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
  if (act.startsWith("kc-")) return knowledgeClick(act, value);
  if (act === "ai-stop" || act === "refresh-inbox") {
    button.disabled = true;
    callApi(act === "ai-stop" ? "aistop" : "peti", act === "ai-stop" ? {} : undefined).then((result) => {
      state.notice = result.ok ? (act === "ai-stop" ? "AI dihentikan." : "") : result.error || "Permintaan gagal.";
      save(state);
      if (result.ok) {
        if (act === "ai-stop" && knowledgeEditor) knowledgeEditor.controls = null;
        applyLive(result);
      }
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
  if (event.target && typeof event.target.getAttribute === "function" && event.target.getAttribute("data-kc-upload-group")) return uploadKnowledgeMedia(event.target);
  const knowledgeHandled = knowledgeInput(event.target);
  if (event.target && typeof event.target.getAttribute === "function" && event.target.getAttribute("data-ai-audience") !== null) {
    updateAiAudienceControls(event.target);
    return;
  }
  if (knowledgeHandled) return;
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
      if (openOwnerPanel()) return;
      location.hash = consumeAuthReturn(load());
      route();
    });
    return;
  }
  const state = load();
  if (!state) return;
  if (formId === "knowledge-draft") return saveKnowledgeDraft();
  if (formId === "knowledge-publish") return publishKnowledge(data);
  if (formId === "ai-settings") {
    const editor = state.knowledgeCentre.available ? knowledgeEditorFor(state) : null;
    if (editor && editor.busy) return;
    const audienceMode = String(data.get("audience_mode") || state.ai.audience_mode || "test");
    const approvalError = !["all", "test"].includes(audienceMode) || (audienceMode === "all" && state.ai.production_available !== true)
      ? "Pilihan penerima AI belum tersedia. Muat semula tetapan sebelum meneruskan."
      : data.get("approved") !== "on" ? "Sahkan pengetahuan dan pilihan penerima sebelum menyimpan tetapan AI." : "";
    if (approvalError) {
      if (editor) { knowledgeNotice(editor, approvalError, "error"); route(); }
      else showError(approvalError);
      return;
    }
    const submit = form.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;
    if (editor) {
      editor.busy = "Menyimpan tetapan AI…";
      route();
    }
    callApi("aiconfig", {
      connection_id: Number(data.get("connection")), expected_revision: Number(data.get("revision")),
      knowledge: state.knowledgeCentre.available ? String(state.ai.knowledge || "") : String(data.get("knowledge") || ""),
      audience_mode: audienceMode,
      allowlist: String(data.get("allowlist") || "").split(/[\n,]+/).map((s) => s.trim()).filter(Boolean),
      daily_limit: Number(data.get("daily_limit")), approved: data.get("approved") === "on", enabled: data.get("enabled") === "on"
    }).then((result) => {
      if (editor && !knowledgeCurrent(editor)) return;
      if (editor) editor.busy = "";
      if (!result.ok) {
        if (editor) {
          knowledgeNotice(editor, result.error || "Tetapan AI tidak dapat disimpan.", "error");
          route();
          return;
        }
        if (submit) submit.disabled = false;
        showError(result.error || "Tetapan AI tidak dapat disimpan.");
        return;
      }
      if (editor) {
        editor.controls = null;
        knowledgeNotice(editor, "Tetapan AI disimpan. Perubahan draf pengetahuan anda dikekalkan.");
      } else {
        state.notice = "Fakta dan tetapan AI disimpan pada pelayan.";
        save(state);
      }
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

// Only one fixed same-origin destination; the server independently authorizes every admin request.
function openOwnerPanel() {
  if (live && live.platform_admin === true && new URLSearchParams(location.search || "").get("tujuan") === "pemilik") {
    location.href = "/whatsapp/pemilik/";
    return true;
  }
  return false;
}

function onInput(event) {
  if (knowledgeInput(event.target)) return;
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
  try {
    const storedUi = JSON.parse(sessionStorage.getItem(UI_KEY) || "null");
    if (storedUi && typeof storedUi === "object" && !Array.isArray(storedUi)) {
      sessionStorage.setItem(UI_KEY, JSON.stringify({ ...storedUi, notice: "", modal: "" }));
    }
  } catch {
    /* Teruskan jika storan pelayar tidak tersedia. */
  }
  const returned = consumeRedirectResult();
  if (returned.code) rememberSignupCode(returned.code);
  const root = document.getElementById("app");
  if (root) root.innerHTML = `<main class="page"><p class="muted">Memuatkan sesi…</p></main>`;
  try {
    applyLive(await callApi("sesi"));
    if (openOwnerPanel()) return;
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
app.addEventListener("toggle", (event) => {
  const id = event.target && typeof event.target.getAttribute === "function" ? event.target.getAttribute("data-kc-record") : "";
  if (!id || !knowledgeEditor) return;
  if (event.target.open) knowledgeEditor.open.add(id);
  else knowledgeEditor.open.delete(id);
}, true);
window.addEventListener("beforeunload", (event) => {
  if (!knowledgeEditor || !knowledgeEditor.dirty) return;
  event.preventDefault();
  event.returnValue = "";
});
window.addEventListener("hashchange", route);
