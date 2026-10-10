const KEY = "beshare-os-demo";

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

function seed() {
  return {
    user: { name: "Demo Pemilik", email: "demo@beshare.test", role: "owner", verified: true },
    business: { name: "Kedai Demo Melur", industry: "Fesyen", language: "ms", hours: "10:00–22:00" },
    plan: "Standard",
    billStatus: "aktif",
    persona: "Mesra, ringkas, Bahasa Melayu.",
    version: 2,
    theme: "gelap",
    connection: { token: "Belum", webhook: "Belum", registration: "Belum", messaging: "Belum" },
    connectionNote: "",
    onboarding: { step: 6, done: true },
    inboxQuery: "",
    inboxFilter: "semua",
    inboxPane: "list",
    contactQuery: "",
    contactTag: "semua",
    activeContact: "k1",
    editingRule: "",
    notice: "",
    modal: "",
    knowledge: [
      { id: "n1", section: "Harga", title: "Tudung bawal", body: "Tudung bawal RM49.", enabled: true },
      { id: "n2", section: "Stok", title: "Stok warna", body: "Stok tidak direkodkan.", enabled: false }
    ],
    contacts: [
      { id: "k1", name: "Aina", phone: "+60 12-000 1001", tag: "Harga", assignee: "Demo Pemilik", note: "Tanya harga tudung.", priority: "Biasa", conversationId: "c1" },
      { id: "k2", name: "Farid", phone: "+60 12-000 1002", tag: "Stok", assignee: "Demo Staf", note: "Menunggu semakan stok.", priority: "Tinggi", conversationId: "c2" },
      { id: "k3", name: "Lina", phone: "+60 12-000 1003", tag: "Umum", assignee: "Demo Pemilik", note: "", priority: "Biasa", conversationId: "c3" },
      { id: "k4", name: "Hakim", phone: "+60 12-000 1004", tag: "Susulan", assignee: "Demo Staf", note: "Tetingkap perkhidmatan sudah tamat.", priority: "Biasa", conversationId: "c4" }
    ],
    conversations: [
      {
        id: "c1",
        name: "Aina",
        mode: "ai_active",
        unread: true,
        assignee: "Demo Pemilik",
        tag: "Harga",
        window: "open",
        note: "",
        messages: [
          { from: "customer", text: "Harga tudung bawal berapa?", wamid: "wamid.demo.1001", status: "delivered" },
          { from: "ai", text: "Tudung bawal dalam pengetahuan demo ialah RM49.", wamid: "wamid.demo.1002", status: "sent" }
        ]
      },
      {
        id: "c2",
        name: "Farid",
        mode: "human_takeover",
        unread: false,
        assignee: "Demo Staf",
        tag: "Stok",
        window: "open",
        note: "Jangan mereka angka stok.",
        messages: [
          { from: "customer", text: "Masih ada stok warna hitam?", wamid: "wamid.demo.2001", status: "delivered" },
          { from: "staff", text: "Saya semak dengan pemilik dulu.", wamid: "wamid.demo.2002", status: "sent" }
        ]
      },
      {
        id: "c3",
        name: "Lina",
        mode: "resolved",
        unread: false,
        assignee: "Demo Pemilik",
        tag: "Umum",
        window: "open",
        note: "",
        messages: [
          { from: "customer", text: "Terima kasih.", wamid: "wamid.demo.3001", status: "read" },
          { from: "ai", text: "Sama-sama. Perbualan demo ini ditutup.", wamid: "wamid.demo.3002", status: "sent" }
        ]
      },
      {
        id: "c4",
        name: "Hakim",
        mode: "human_takeover",
        unread: false,
        assignee: "Demo Staf",
        tag: "Susulan",
        window: "closed",
        note: "Perlu templat jika mahu menyambung.",
        messages: [
          { from: "customer", text: "Boleh hantar esok?", wamid: "wamid.demo.4001", status: "delivered" },
          { from: "staff", text: "Percubaan mesej biasa selepas tetingkap.", wamid: "wamid.demo.4002", status: "failed" }
        ]
      }
    ],
    active: "c1",
    rules: [
      { id: "r1", name: "Sambutan", keyword: "", action: "sambutan", enabled: true },
      { id: "r2", name: "Kata kunci harga", keyword: "harga", action: "balas_pengetahuan", enabled: true },
      { id: "r3", name: "Serahan stok", keyword: "stok", action: "serahan", enabled: true },
      { id: "r4", name: "Luar waktu", keyword: "", action: "luar_waktu", enabled: false }
    ],
    team: [
      { name: "Demo Pemilik", email: "demo@beshare.test", role: "owner" },
      { name: "Demo Staf", email: "staf@beshare.test", role: "staff" }
    ],
    invoices: [
      { id: "INV-DEMO-1", item: "Pemasangan Standard", amount: "RM1,200", status: "Demo" },
      { id: "INV-DEMO-2", item: "Langganan bulan ini", amount: "RM800", status: "Demo" }
    ],
    audit: [
      { at: "2026-10-10 07:00", actor: "Demo Pemilik", action: "Workspace demo dibuka." }
    ]
  };
}

function load() {
  const raw = sessionStorage.getItem(KEY);
  if (!raw) return null;
  try {
    return normalize(JSON.parse(raw));
  } catch {
    return null;
  }
}

function save(state) {
  sessionStorage.setItem(KEY, JSON.stringify(state));
}

function normalize(state) {
  const base = seed();
  if (!state || typeof state !== "object") return base;
  const next = { ...base, ...state };
  next.user = { ...base.user, ...(state.user || {}) };
  next.business = { ...base.business, ...(state.business || {}) };
  next.connection = { ...base.connection, ...(state.connection || {}) };
  next.onboarding = { ...base.onboarding, ...(state.onboarding || {}) };
  next.knowledge = Array.isArray(state.knowledge) ? state.knowledge : base.knowledge;
  next.contacts = Array.isArray(state.contacts) ? state.contacts : base.contacts;
  next.rules = Array.isArray(state.rules) ? state.rules : base.rules;
  next.team = Array.isArray(state.team) ? state.team : base.team;
  next.invoices = Array.isArray(state.invoices) ? state.invoices : base.invoices;
  next.audit = Array.isArray(state.audit) ? state.audit : base.audit;
  const conversations = Array.isArray(state.conversations) ? state.conversations : base.conversations;
  next.conversations = conversations.map((item) => ({
    unread: false,
    assignee: "Demo Pemilik",
    tag: "Umum",
    window: "open",
    note: "",
    ...item,
    messages: (item.messages || []).map((message) => ({ status: "sent", ...message }))
  }));
  return next;
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
  return state.user.role || "owner";
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
  const aiReplied = items.filter((item) => item.messages.some((message) => message.from === "ai")).length;
  const human = items.filter((item) => item.mode === "human_takeover").length;
  const unanswered = items.filter((item) => item.messages.at(-1)?.from === "customer" && item.mode !== "resolved").length;
  const deliveries = items.flatMap((item) => item.messages);
  const sent = deliveries.filter((message) => message.status === "sent" || message.status === "delivered" || message.status === "read").length;
  const failed = deliveries.filter((message) => message.status === "failed").length;
  const handover = incoming ? Math.round((human / incoming) * 100) : 0;
  return { incoming, aiReplied, human, unanswered, sent, failed, handover };
}

function visibleConversations(state) {
  let list = state.conversations.slice();
  if (role(state) === "staff") list = list.filter((item) => item.assignee === state.user.name);
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

function ensureDemo() {
  if (new URLSearchParams(location.search).get("demo") !== "1") return;
  let version = 0;
  try {
    version = JSON.parse(sessionStorage.getItem(KEY) || "{}").version || 0;
  } catch {
    version = 0;
  }
  if (version !== 2) save(seed());
}

function applyTheme(state) {
  document.documentElement.dataset.theme = state && state.theme === "cerah" ? "cerah" : "gelap";
}

function route() {
  const state = load();
  applyTheme(state);
  const hash = location.hash.replace("#", "") || (state ? "papan" : "masuk");
  if (state && hash !== "automasi" && state.notice) {
    state.notice = "";
    save(state);
  }
  const root = document.getElementById("app");
  const open = ["daftar", "masuk", "lupa", "sahkan"];
  if (!open.includes(hash) && !state) {
    location.hash = "masuk";
    return;
  }
  if (hash === "daftar" || hash === "masuk" || hash === "lupa") root.innerHTML = authView(hash, state);
  else if (hash === "sahkan") root.innerHTML = verifyView(state);
  else if (hash === "mula") root.innerHTML = shell(state, "mula", onboarding(state));
  else if (!NAV.some(([id]) => id === hash)) root.innerHTML = shell(state, "papan", board(state));
  else {
    const item = NAV.find(([id]) => id === hash);
    root.innerHTML = allow(state, item[2])
      ? shell(state, hash, views[hash](state))
      : shell(state, hash, `<article class="panel"><h1>Skrin dikunci</h1><p class="muted">Peranan demo ini tidak membuka skrin tersebut.</p></article>`);
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

function authView(kind, state) {
  const title = { daftar: "Daftar demo", masuk: "Masuk demo", lupa: "Lupa kata laluan" }[kind];
  const extra = kind === "daftar"
    ? `<label class="field">Sahkan kata laluan<input name="password2" type="password" required minlength="8"></label>
       <label class="field"><span><input name="terms" type="checkbox" required> Saya terima bahawa ini demo, bukan pendaftaran sebenar.</span></label>`
    : "";
  const nameField = kind === "daftar"
    ? `<label class="field">Nama<input name="name" value="Demo Pemilik" required></label>`
    : "";
  const fields = kind === "lupa"
    ? `<label class="field">E-mel<input name="email" type="email" required></label>`
    : `${nameField}
       <label class="field">E-mel<input name="email" type="email" required placeholder="nama@kedai.com"></label>
       <label class="field">Kata laluan<input name="password" type="password" required minlength="8"></label>
       ${extra}`;
  return `<main class="page"><section class="panel" style="max-width:480px;margin:2rem auto;">
    <p class="demo-banner">Akaun ini disimpan dalam pelayar sahaja. Tiada pelayan, tiada e-mel, dan tiada WhatsApp sebenar.</p>
    <h1>${title}</h1>
    <form id="auth" data-kind="${kind}">
      ${kind === "masuk" ? "" : ""}
      ${fields}
      <p class="error" id="form-error" hidden></p>
      <button class="btn" type="submit">${title}</button>
    </form>
    <p class="muted"><a href="#daftar">Daftar</a> · <a href="#masuk">Masuk</a> · <a href="#lupa">Lupa kata laluan</a></p>
    ${state && state.user && !state.user.verified ? `<p><button class="btn btn--ghost" type="button" data-act="resend">Hantar semula semakan demo</button></p>` : ""}
  </section></main>`;
}

function verifyView(state) {
  if (!state) return authView("masuk", null);
  return `<main class="page"><section class="panel" style="max-width:560px;margin:2rem auto;">
    <h1>Semak peti e-mel</h1>
    <p class="muted">Tiada e-mel dihantar. Untuk demo, sahkan terus pada skrin ini. Akaun yang belum disahkan tidak masuk ke workspace.</p>
    <button class="btn" type="button" data-act="verify">Saya sudah semak (demo)</button>
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
    <aside class="panel nav">
      <strong>BeShare AI OS</strong>
      <p class="muted nav-meta">${esc(state.business.name)}</p>
      ${links}
      <div class="nav-tools">
        <label class="muted">Peranan demo
          <select data-act="role" aria-label="Peranan demo">
            ${["owner", "admin", "staff"].map((item) => `<option value="${item}" ${role(state) === item ? "selected" : ""}>${item === "owner" ? "Pemilik" : item === "admin" ? "Admin" : "Staf"}</option>`).join("")}
          </select>
        </label>
        <button class="btn btn--ghost" type="button" data-act="theme">${state.theme === "cerah" ? "Mod gelap" : "Mod cerah"}</button>
      </div>
    </aside>
    <section>
      <p class="demo-banner">Data demo dalam pelayar. Nombor lama tidak dipindahkan. Meta belum dipanggil. Angka dikira daripada rekod demo ini, bukan prestasi kedai sebenar.</p>
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
    <p class="muted">Pakej ${esc(state.plan)}. Langganan demo: ${esc(billLabel(state.billStatus))}. Automasi hidup: ${enabled}.</p>
    <div class="states">
      ${Object.entries({ Token: state.connection.token, Webhook: state.connection.webhook, Pendaftaran: state.connection.registration, Mesej: state.connection.messaging }).map(([name, value]) =>
        `<article class="state"><strong>${esc(name)}</strong><span>${esc(value)}</span></article>`).join("")}
    </div>
    <div class="os-kpis">
      <article class="state os-kpi"><strong>${count.incoming}</strong><span>Perbualan masuk pada data demo.</span></article>
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
    `<button class="btn btn--ghost ${item.id === state.active ? "is-on" : ""}" type="button" data-act="open" data-value="${esc(item.id)}">${esc(item.name)} · ${esc(label(item.mode))}${item.unread ? " · belum dibaca" : ""}</button>`
  ).join("") || `<p class="muted">Tiada hasil.</p>`;
  const filters = [["semua", "Semua"], ["belum", "Belum dibaca"], ["saya", "Tugasan saya"], ["ai", "AI"], ["manusia", "Manusia"], ["selesai", "Selesai"]];
  return `<article class="panel">
    <h1>Peti masuk</h1>
    <label class="field">Cari<input name="q" value="${esc(state.inboxQuery || "")}" placeholder="Nama atau tag"></label>
    <div class="chips">${filters.map(([id, name]) => `<button class="btn btn--ghost ${state.inboxFilter === id ? "is-on" : ""}" type="button" data-act="filter" data-value="${id}">${name}</button>`).join("")}</div>
    <div class="inbox" data-pane="${esc(state.inboxPane || "list")}">
      <div class="pane-list thread" id="thread">${buttons}</div>
      <div class="pane-talk">${talk(state, current)}</div>
      <aside class="pane-profile">${profile(state, current)}</aside>
    </div>
  </article>`;
}

function talk(state, current) {
  if (!current) return `<p class="muted">Pilih perbualan.</p><p><button class="btn btn--ghost" type="button" data-act="pane" data-value="list">Senarai</button></p>`;
  const messages = current.messages.map((item) =>
    `<div class="msg ${item.from === "staff" ? "msg--staff" : ""}"><strong>${esc(item.from)}</strong><p>${esc(item.text)}</p><small class="muted">${esc(item.wamid)} · ${esc(item.status || "")}</small></div>`
  ).join("");
  const back = `<p class="actions"><button class="btn btn--ghost" type="button" data-act="pane" data-value="list">Senarai</button> <button class="btn btn--ghost" type="button" data-act="pane" data-value="profile">Profil</button></p>`;
  if (current.mode === "resolved") {
    return `${back}<p>Mod: <strong>${esc(label(current.mode))}</strong></p><div class="msgs">${messages}</div>
      <p class="muted">Perbualan ditutup. Pembukaan semula perlu pilih mod.</p>
      <p class="actions"><button class="btn" type="button" data-act="reopen" data-value="ai_active">Buka semula sebagai AI</button>
      <button class="btn btn--ghost" type="button" data-act="reopen" data-value="human_takeover">Buka semula sebagai manusia</button></p>`;
  }
  const composer = current.window === "closed"
    ? `<p class="error">Tetingkap perkhidmatan pelanggan telah tamat. Mesej biasa tidak dihantar. Templat yang diluluskan diperlukan.</p>`
    : current.mode === "human_takeover"
      ? `<form id="reply"><label class="field">Balasan staf<input name="text" required></label><button class="btn" type="submit">Hantar</button></form>`
      : `<p class="muted">AI sedang aktif. Ambil alih dahulu supaya tidak ada dua balasan serentak.</p>`;
  const actions = current.mode === "human_takeover"
    ? `<button class="btn btn--ghost" type="button" data-act="release">Pulangkan kepada AI</button> <button class="btn btn--ghost" type="button" data-act="resolve">Selesai</button>`
    : `<button class="btn" type="button" data-act="take">Ambil alih</button>`;
  return `${back}<p>Mod: <strong>${esc(label(current.mode))}</strong></p><div class="msgs">${messages}</div>${composer}<p class="actions">${actions}</p>`;
}

function profile(state, current) {
  if (!current) return `<p class="muted">Profil muncul selepas perbualan dipilih.</p>`;
  const contact = state.contacts.find((item) => item.conversationId === current.id);
  return `<h2>${esc(current.name)}</h2>
    <p class="muted">${esc(contact ? contact.phone : "Tiada telefon demo")}</p>
    <p>Tag: ${esc(current.tag)}<br>Ejen: ${esc(current.assignee)}<br>Tetingkap: ${current.window === "open" ? "dibuka" : "tamat"}</p>
    <p class="muted">${esc(current.note || "Tiada nota.")}</p>
    <p class="muted">Status penghantaran dipadankan dengan id mesej, bukan nombor telefon.</p>
    <button class="btn btn--ghost" type="button" data-act="assign-me">Tugaskan kepada saya</button>`;
}

function contacts(state) {
  const tags = ["semua", ...new Set(state.contacts.map((item) => item.tag))];
  let list = state.contacts.slice();
  if (role(state) === "staff") list = list.filter((item) => item.assignee === state.user.name);
  const query = (state.contactQuery || "").trim().toLowerCase();
  if (query) list = list.filter((item) => (item.name + item.phone + item.tag).toLowerCase().includes(query));
  if (state.contactTag && state.contactTag !== "semua") list = list.filter((item) => item.tag === state.contactTag);
  const current = list.find((item) => item.id === state.activeContact) || list[0] || null;
  const rows = list.map((item) =>
    `<tr><td><button class="btn btn--ghost" type="button" data-act="contact" data-value="${esc(item.id)}">${esc(item.name)}</button></td><td>${esc(item.tag)}</td><td>${esc(item.assignee)}</td></tr>`
  ).join("") || `<tr><td colspan="3">Tiada hasil.</td></tr>`;
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
      <button class="btn" type="submit">Simpan draf demo</button>
    </form>
    <h2>Playground</h2>
    <form id="play">
      <label class="field">Mesej pelanggan<input name="text" required placeholder="Tanya harga atau stok"></label>
      <button class="btn" type="submit">Jana jawapan demo</button>
    </form>
    <p id="answer" class="muted"></p>
    <p id="source" class="muted"></p>
  </article>`;
}

function answerFor(state, text) {
  const enabled = state.knowledge.filter((item) => item.enabled);
  const lower = text.toLowerCase();
  if (lower.includes("harga") || lower.includes("bawal")) {
    const source = enabled.find((item) => item.section === "Harga" || item.body.toLowerCase().includes("rm"));
    if (!source) return { text: "Maklumat tidak cukup. AI demo tidak mereka harga.", source: "Tiada sumber hidup.", handover: true };
    return { text: "Jawapan demo: " + source.body, source: source.section + " · " + source.title, handover: false };
  }
  if (lower.includes("stok")) {
    const source = enabled.find((item) => item.section === "Stok");
    if (!source) return { text: "Maklumat tidak cukup. AI demo tidak mereka stok dan akan menyerahkan kepada manusia.", source: "Tiada sumber stok yang hidup.", handover: true };
    return { text: "Jawapan demo: " + source.body, source: source.section + " · " + source.title, handover: false };
  }
  return { text: "Jawapan demo umum. Tiada fakta tambahan dalam pengetahuan.", source: "Tiada padanan.", handover: false };
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
    [String(count.incoming), "Perbualan masuk", "Bilangan perbualan dalam data demo."],
    [String(count.aiReplied), "Dibalas AI", "Perbualan yang mengandungi sekurang-kurangnya satu mesej AI."],
    [String(count.human), "Dipegang manusia", "Mod semasa ialah manusia mengambil alih."],
    [String(count.unanswered), "Belum dibalas", "Mesej terakhir daripada pelanggan dan perbualan belum selesai."],
    [count.sent + " dihantar · " + count.failed + " gagal", "Hasil penghantaran", "Dikira daripada status pada id mesej demo."],
    [count.handover + "%", "Kadar serahan", "Perbualan mod manusia dibahagi semua perbualan demo."]
  ];
  return `<article class="panel">
    <h1>Analitik</h1>
    <p class="muted">Masa tindak balas purata tidak dikira kerana data demo belum ada cap masa mula dan balasan pertama. Kadar konversi tidak dipaparkan.</p>
    <div class="os-kpis">${cards.map(([value, name, note]) => `<article class="state os-kpi"><strong>${esc(value)}</strong><span>${esc(name)}. ${esc(note)}</span></article>`).join("")}</div>
  </article>`;
}

function team(state) {
  const rows = state.team.map((item) =>
    `<tr><td>${esc(item.name)}</td><td>${esc(item.email)}</td><td>${esc(item.role)}</td><td>${item.role === "owner" ? "" : `<button class="btn btn--ghost" type="button" data-act="remove-member" data-value="${esc(item.email)}">Buang akses</button>`}</td></tr>`
  ).join("");
  return `<article class="panel">
    <h1>Pasukan</h1>
    <p class="muted">Jemputan demo menambah senarai dalam pelayar. Tiada e-mel dihantar.</p>
    <table class="os-table"><thead><tr><th>Nama</th><th>E-mel</th><th>Peranan</th><th></th></tr></thead><tbody>${rows}</tbody></table>
    <form id="invite">
      <label class="field">Nama<input name="name" required></label>
      <label class="field">E-mel<input name="email" type="email" required></label>
      <label class="field">Peranan<select name="role"><option value="staff">Staf</option><option value="admin">Admin</option></select></label>
      <button class="btn" type="submit">Jemput (demo)</button>
    </form>
  </article>`;
}

function connection(state) {
  return `<article class="panel">
    <h1>Sambungan WhatsApp</h1>
    <p class="muted">Empat keadaan berasingan. Token yang tersimpan belum bermaksud nombor sudah berfungsi.</p>
    <div class="states">
      <article class="state"><strong>Token</strong><span>${esc(state.connection.token)}</span></article>
      <article class="state"><strong>Webhook</strong><span>${esc(state.connection.webhook)}</span></article>
      <article class="state"><strong>Pendaftaran</strong><span>${esc(state.connection.registration)}</span></article>
      <article class="state"><strong>Mesej</strong><span>${esc(state.connection.messaging)}</span></article>
    </div>
    <p>Ralat terakhir: ${esc(state.connectionNote || "Tiada.")}</p>
    <button class="btn" type="button" data-act="connect">Mula Embedded Signup</button>
    <p class="muted">Butang ini tidak memanggil Meta. Kebenaran whatsapp_business_management belum diminta. Video semakan Meta ialah langkah seterusnya, selepas modul ini.</p>
  </article>`;
}

function billLabel(status) {
  return { aktif: "Aktif (demo)", ihsan: "Tempoh ihsan (paparan)", gantung: "Digantung (paparan)" }[status] || status;
}

function billing(state) {
  const monthly = state.plan === "Ultimate" ? "RM1,000" : "RM800";
  const setup = state.plan === "Ultimate" ? "RM1,800" : "RM1,200";
  const rows = state.invoices.map((item) => `<tr><td>${esc(item.id)}</td><td>${esc(item.item)}</td><td>${esc(item.amount)}</td><td>${esc(item.status)}</td></tr>`).join("");
  return `<article class="panel">
    <h1>Bil</h1>
    <p>Pakej ${esc(state.plan)}. Pemasangan ${setup}. Bulanan ${monthly}. Status: ${esc(billLabel(state.billStatus))}.</p>
    <p class="muted">Kos mesej Meta tidak termasuk. Caj payment gateway tidak termasuk. Tiada bayaran sebenar diambil.</p>
    <div class="chips">
      <button class="btn btn--ghost" type="button" data-act="bill" data-value="aktif">Paparan aktif</button>
      <button class="btn btn--ghost" type="button" data-act="bill" data-value="ihsan">Paparan ihsan</button>
      <button class="btn btn--ghost" type="button" data-act="bill" data-value="gantung">Paparan gantung</button>
    </div>
    <table class="os-table"><thead><tr><th>Invois</th><th>Item</th><th>Amaun</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table>
  </article>`;
}

function settings(state) {
  return `<article class="panel">
    <h1>Tetapan</h1>
    <form id="settings">
      <label class="field">Nama perniagaan<input name="name" required value="${esc(state.business.name)}"></label>
      <label class="field">Industri<input name="industry" required value="${esc(state.business.industry)}"></label>
      <label class="field">Bahasa<select name="language"><option value="ms" ${state.business.language === "ms" ? "selected" : ""}>Bahasa Melayu</option><option value="en" ${state.business.language === "en" ? "selected" : ""}>English kemudian</option></select></label>
      <label class="field">Waktu<input name="hours" value="${esc(state.business.hours)}"></label>
      <button class="btn" type="submit">Simpan draf demo</button>
    </form>
    <h2>Pemindahan pemilikan</h2>
    <p class="muted">Pemindahan pemilikan belum dibuka.</p>
  </article>`;
}

function auditView(state) {
  const rows = state.audit.map((item) => `<tr><td>${esc(item.at)}</td><td>${esc(item.actor)}</td><td>${esc(item.action)}</td></tr>`).join("");
  return `<article class="panel"><h1>Audit</h1><table class="os-table"><thead><tr><th>Masa</th><th>Pelaku</th><th>Tindakan</th></tr></thead><tbody>${rows}</tbody></table></article>`;
}

function operator() {
  return `<article class="panel">
    <h1>Pengendali</h1>
    <p class="demo-banner">Skrin ini tidak memaparkan transkrip perbualan.</p>
    <table class="os-table">
      <thead><tr><th>Tenant</th><th>Onboarding</th><th>Langganan</th><th>Sambungan</th></tr></thead>
      <tbody>
        <tr><td>Kedai Demo Melur</td><td>Selesai pada demo</td><td>Demo</td><td>Token, webhook, pendaftaran, dan mesej masih belum</td></tr>
        <tr><td>Kedai Demo Sungai</td><td>Tersangkut di langkah WhatsApp</td><td>Belum</td><td>Belum bermula</td></tr>
      </tbody>
    </table>
    <p class="muted">Amaran: satu tenant tersekat. Log audit platform kosong. Tiada tindakan untuk membaca data tenant.</p>
  </article>`;
}

function onboarding(state) {
  const step = state.onboarding.step || 1;
  const pills = ["Akaun", "Perniagaan", "Pakej", "WhatsApp", "AI", "Ujian"].map((name, index) => {
    const number = index + 1;
    const locked = number > step;
    return `<button type="button" class="${number === step ? "is-on" : ""}" data-act="step-go" data-value="${number}" ${locked ? "disabled" : ""}>${number}. ${name}</button>`;
  }).join("");
  const bodies = {
    1: `<p class="muted">Akaun demo ${esc(state.user.email)} sudah ada pada pelayar ini.</p>`,
    2: `<form id="step-form"><label class="field">Nama perniagaan<input name="name" required value="${esc(state.business.name)}"></label><label class="field">Industri<input name="industry" required value="${esc(state.business.industry)}"></label></form>`,
    3: `<form id="step-form"><label class="field"><span><input type="radio" name="plan" value="Standard" ${state.plan !== "Ultimate" ? "checked" : ""}> Standard · RM1,200 sekali, kemudian RM800 sebulan</span></label><label class="field"><span><input type="radio" name="plan" value="Ultimate" ${state.plan === "Ultimate" ? "checked" : ""}> Ultimate · RM1,800 sekali, kemudian RM1,000 sebulan</span></label><p class="muted">Fungsi sama sehingga had diluluskan. Kos Meta dan gateway tidak termasuk.</p></form>`,
    4: `<div class="states"><article class="state"><strong>Token</strong><span>Belum</span></article><article class="state"><strong>Webhook</strong><span>Belum</span></article><article class="state"><strong>Pendaftaran</strong><span>Belum</span></article><article class="state"><strong>Mesej</strong><span>Belum</span></article></div><p class="muted">Embedded Signup tidak dipanggil pada langkah ini.</p>`,
    5: `<form id="step-form"><label class="field">Persona<textarea name="persona" required>${esc(state.persona)}</textarea></label><p class="muted">Sekurang-kurangnya satu fakta harga sudah ada dalam pengetahuan demo.</p></form>`,
    6: `<ul><li>Ujian keluar: belum</li><li>Ujian masuk: belum</li><li>Tingkah laku AI: belum</li></ul><p class="muted">Skrin ini tidak menandakan ujian sebagai lulus.</p>`
  };
  return `<article class="panel"><h1>Mula</h1><div class="steps">${pills}</div>${bodies[step] || ""}
    <p class="error" id="step-error" hidden></p>
    <p class="actions">${step > 1 ? `<button class="btn btn--ghost" type="button" data-act="step-back">Kembali</button>` : ""}
    <button class="btn" type="button" data-act="step-next">${step === 6 ? "Buka workspace" : "Teruskan"}</button></p></article>`;
}

function showError(message) {
  const error = document.getElementById("form-error") || document.getElementById("step-error");
  if (!error) return;
  error.hidden = !message;
  error.textContent = message || "";
}

function readStep(state) {
  const form = document.getElementById("step-form");
  if (!form) return "";
  const data = new FormData(form);
  if (state.onboarding.step === 2) {
    state.business.name = String(data.get("name") || "").trim();
    state.business.industry = String(data.get("industry") || "").trim();
    if (!state.business.name || !state.business.industry) return "Nama dan industri diperlukan.";
  }
  if (state.onboarding.step === 3) state.plan = String(data.get("plan") || "Standard");
  if (state.onboarding.step === 5) {
    state.persona = String(data.get("persona") || "").trim();
    if (state.persona.length < 8) return "Tulis persona sekurang-kurangnya 8 aksara.";
  }
  return "";
}

function setMode(mode) {
  const state = load();
  const current = currentConversation(state);
  if (!current) return;
  current.mode = mode;
  log(state, "Mod perbualan " + current.name + " menjadi " + label(mode) + ".");
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
  if (act === "verify" && state) {
    state.user.verified = true;
    log(state, "E-mel demo disahkan pada skrin.");
    save(state);
    location.hash = "mula";
    return;
  }
  if (act === "resend") {
    location.hash = "sahkan";
    return;
  }
  if (!state) return;
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
  if (act === "assign-me") {
    const current = currentConversation(state);
    if (current) {
      current.assignee = state.user.name;
      log(state, "Perbualan " + current.name + " ditugaskan kepada " + state.user.name + ".");
      save(state);
      route();
    }
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
  if (act === "know-toggle") {
    const item = state.knowledge.find((entry) => entry.id === value);
    if (item) item.enabled = !item.enabled;
    log(state, "Entri pengetahuan demo dikemas kini.");
    save(state);
    route();
    return;
  }
  if (act === "rule-toggle") {
    const item = state.rules.find((entry) => entry.id === value);
    if (item) item.enabled = !item.enabled;
    log(state, "Peraturan automasi demo dikemas kini.");
    save(state);
    route();
    return;
  }
  if (act === "rule-edit") {
    state.editingRule = value;
    state.notice = "";
    save(state);
    route();
    return;
  }
  if (act === "remove-member") {
    state.team = state.team.filter((item) => item.email !== value || item.role === "owner");
    log(state, "Akses demo dibuang untuk " + value + ".");
    save(state);
    route();
    return;
  }
  if (act === "connect") {
    state.connectionNote = "Embedded Signup tidak dipanggil. Empat keadaan kekal Belum.";
    log(state, "Percubaan sambung disekat: Meta tidak dipanggil.");
    save(state);
    route();
    return;
  }
  if (act === "bill") {
    state.billStatus = value;
    log(state, "Paparan status bil ditukar kepada " + billLabel(value) + ".");
    save(state);
    route();
    return;
  }
  if (act === "step-go") {
    const next = Number(value);
    if (next <= state.onboarding.step) {
      state.onboarding.step = next;
      save(state);
      route();
    }
    return;
  }
  if (act === "step-back") {
    state.onboarding.step = Math.max(1, state.onboarding.step - 1);
    save(state);
    route();
    return;
  }
  if (act === "step-next") {
    const error = readStep(state);
    if (error) {
      save(state);
      showError(error);
      return;
    }
    if (state.onboarding.step >= 6) {
      state.onboarding.done = true;
      log(state, "Onboarding demo dibuka ke workspace. Ujian Meta kekal belum.");
      save(state);
      location.hash = "papan";
      return;
    }
    state.onboarding.step += 1;
    save(state);
    route();
  }
}

function onChange(event) {
  const select = event.target.closest("select[data-act='role']");
  if (!select) return;
  const state = load();
  if (!state) return;
  const value = select.value;
  state.user.role = value;
  if (value === "staff") state.user.name = "Demo Staf";
  else if (value === "admin") state.user.name = "Demo Admin";
  else state.user.name = "Demo Pemilik";
  log(state, "Peranan demo ditukar kepada " + value + ".");
  save(state);
  route();
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
      showError("");
      form.insertAdjacentHTML("beforeend", `<p class="muted">Jika e-mel itu wujud, arahan set semula dipaparkan di skrin ini. Tiada e-mel dihantar.</p>`);
      return;
    }
    if (!email.includes("@") || password.length < 8) {
      showError("Gunakan e-mel yang sah dan kata laluan sekurang-kurangnya 8 aksara.");
      return;
    }
    if (kind === "daftar") {
      if (!data.get("terms")) {
        showError("Terma demo perlu diterima.");
        return;
      }
      if (String(data.get("password2") || "") !== password) {
        showError("Kata laluan tidak sepadan.");
        return;
      }
      const next = seed();
      next.user = { name: String(data.get("name") || "Demo"), email, role: "owner", verified: false };
      next.onboarding = { step: 1, done: false };
      save(next);
      location.hash = "sahkan";
      return;
    }
    const state = load();
    if (!state || state.user.email !== email) {
      showError("Akaun demo ini belum didaftar dalam pelayar. Daftar dahulu.");
      return;
    }
    if (!state.user.verified) {
      showError("E-mel demo belum disahkan.");
      location.hash = "sahkan";
      return;
    }
    location.hash = "papan";
    return;
  }
  const state = load();
  if (!state) return;
  if (formId === "reply") {
    const current = currentConversation(state);
    const text = String(data.get("text") || "").trim();
    if (!text || !current || current.mode !== "human_takeover" || current.window !== "open") return;
    current.messages.push({ from: "staff", text, wamid: "wamid.demo." + Date.now(), status: "sent" });
    log(state, "Balasan staf demo pada " + current.name + ".");
    save(state);
    route();
    return;
  }
  if (formId === "play") {
    const result = answerFor(state, String(data.get("text") || ""));
    document.getElementById("answer").textContent = result.text;
    document.getElementById("source").textContent = "Sumber: " + result.source + (result.handover ? " Serahan manusia dicadangkan." : "");
    return;
  }
  if (formId === "know") {
    state.knowledge.push({
      id: "n" + Date.now(),
      section: String(data.get("section") || "FAQ"),
      title: String(data.get("title") || "").trim(),
      body: String(data.get("body") || "").trim(),
      enabled: true
    });
    log(state, "Draf pengetahuan demo disimpan dalam pelayar.");
    save(state);
    route();
    return;
  }
  if (formId === "rule") {
    const item = state.rules.find((entry) => entry.id === String(data.get("ruleId")));
    const action = String(data.get("action") || "");
    if (action === "hantar_luar_tetingkap" || action === "picu_diri") {
      state.notice = action === "picu_diri"
        ? "Gelung ditolak. Peraturan tidak boleh mencetus dirinya."
        : "Mesej biasa di luar tetingkap perkhidmatan tidak dibenarkan. Gunakan templat yang diluluskan.";
      save(state);
      route();
      return;
    }
    if (item) {
      item.name = String(data.get("name") || "").trim();
      item.keyword = String(data.get("keyword") || "").trim().toLowerCase();
      item.action = action;
    }
    state.notice = "";
    log(state, "Peraturan demo disimpan.");
    save(state);
    route();
    return;
  }
  if (formId === "invite") {
    const email = String(data.get("email") || "").trim();
    if (!email.includes("@")) return;
    state.team.push({ name: String(data.get("name") || "").trim(), email, role: String(data.get("role") || "staff") });
    log(state, "Jemputan demo tidak menghantar e-mel kepada " + email + ".");
    save(state);
    route();
    return;
  }
  if (formId === "settings") {
    state.business.name = String(data.get("name") || "").trim();
    state.business.industry = String(data.get("industry") || "").trim();
    state.business.language = String(data.get("language") || "ms");
    state.business.hours = String(data.get("hours") || "");
    log(state, "Tetapan perniagaan demo disimpan dalam pelayar.");
    save(state);
    route();
  }
}

function onInput(event) {
  const state = load();
  if (!state) return;
  if (event.target.name === "q") {
    state.inboxQuery = event.target.value;
    save(state);
    const list = visibleConversations(state).map((item) =>
      `<button class="btn btn--ghost ${item.id === state.active ? "is-on" : ""}" type="button" data-act="open" data-value="${esc(item.id)}">${esc(item.name)} · ${esc(label(item.mode))}${item.unread ? " · belum dibaca" : ""}</button>`
    ).join("") || `<p class="muted">Tiada hasil.</p>`;
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

ensureDemo();
const app = document.getElementById("app");
app.addEventListener("click", onClick);
app.addEventListener("change", onChange);
app.addEventListener("submit", onSubmit);
app.addEventListener("input", onInput);
window.addEventListener("hashchange", route);
route();
