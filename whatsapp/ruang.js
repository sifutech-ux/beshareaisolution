const KEY = "beshare-os-demo";

const seed = () => ({
  user: { name: "Demo Pemilik", email: "demo@beshare.test" },
  business: { name: "Kedai Demo Melur", industry: "Fesyen" },
  plan: "Standard",
  persona: "Mesra, ringkas, Bahasa Melayu.",
  conversations: [
    {
      id: "c1",
      name: "Aina",
      mode: "ai_active",
      messages: [
        { from: "customer", text: "Harga tudung bawal berapa?", wamid: "wamid.demo.1001" },
        { from: "ai", text: "Tudung bawal dalam pengetahuan demo ialah RM49.", wamid: "wamid.demo.1002" }
      ]
    },
    {
      id: "c2",
      name: "Farid",
      mode: "human_takeover",
      messages: [
        { from: "customer", text: "Masih ada stok warna hitam?", wamid: "wamid.demo.2001" },
        { from: "staff", text: "Saya semak dengan pemilik dulu.", wamid: "wamid.demo.2002" }
      ]
    }
  ],
  active: "c1"
});

function load() {
  const raw = sessionStorage.getItem(KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function save(state) {
  sessionStorage.setItem(KEY, JSON.stringify(state));
}

function esc(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;"
  }[char]));
}

function ensureDemo() {
  if (new URLSearchParams(location.search).get("demo") === "1" && !load()) {
    save(seed());
  }
}

function route() {
  const hash = location.hash.replace("#", "") || (load() ? "papan" : "masuk");
  if (["papan", "peti", "pengetahuan"].includes(hash) && !load()) {
    location.hash = "masuk";
    return;
  }
  const root = document.getElementById("app");
  const state = load();
  if (hash === "daftar") root.innerHTML = formView("daftar");
  else if (hash === "masuk") root.innerHTML = formView("masuk");
  else root.innerHTML = shell(state, hash);
  bind(hash);
}

function formView(kind) {
  const title = kind === "daftar" ? "Daftar demo" : "Masuk demo";
  return `<main class="page"><section class="panel" style="max-width:480px;margin:2rem auto;">
    <p class="demo-banner">Akaun ini disimpan dalam pelayar sahaja. Tiada pelayan dan tiada WhatsApp sebenar.</p>
    <h1>${title}</h1>
    <form id="auth">
      <label class="field">Nama<input name="name" required value="Demo Pemilik"></label>
      <label class="field">E-mel<input name="email" type="email" required placeholder="nama@kedai.com"></label>
      <label class="field">Kata laluan<input name="password" type="password" required minlength="8"></label>
      ${kind === "daftar" ? '<label class="field"><span><input name="terms" type="checkbox" required> Saya terima bahawa ini demo, bukan pendaftaran sebenar.</span></label>' : ""}
      <p class="error" id="form-error" hidden></p>
      <button class="btn" type="submit">${title}</button>
    </form>
  </section></main>`;
}

function shell(state, hash) {
  const body = hash === "peti" ? inbox(state) : hash === "pengetahuan" ? knowledge(state) : board(state);
  return `<div class="shell">
    <aside class="panel nav">
      <strong>BeShare AI OS</strong>
      <p class="muted">${esc(state.business.name)}</p>
      <a class="${hash === "papan" ? "is-on" : ""}" href="#papan">Papan</a>
      <a class="${hash === "peti" ? "is-on" : ""}" href="#peti">Peti masuk</a>
      <a class="${hash === "pengetahuan" ? "is-on" : ""}" href="#pengetahuan">Pengetahuan</a>
      <a href="index.html">Kembali ke halaman</a>
    </aside>
    <section>${body}</section>
  </div>`;
}

function board(state) {
  return `<article class="panel">
    <p class="demo-banner">Angka di bawah ialah contoh demo, bukan prestasi kedai sebenar.</p>
    <h1>Papan ${esc(state.business.name)}</h1>
    <p class="muted">Pakej demo: ${esc(state.plan)}. Empat keadaan sambungan masih belum bermula.</p>
    <div class="states">
      <article class="state"><strong>Token</strong><span>Belum</span></article>
      <article class="state"><strong>Webhook</strong><span>Belum</span></article>
      <article class="state"><strong>Pendaftaran</strong><span>Belum</span></article>
      <article class="state"><strong>Mesej</strong><span>Belum</span></article>
    </div>
    <p><a class="btn" href="#peti">Buka peti masuk</a></p>
  </article>`;
}

function inbox(state) {
  const current = state.conversations.find((item) => item.id === state.active) || state.conversations[0];
  const list = state.conversations.map((item) =>
    `<button class="btn btn--ghost" type="button" data-open="${esc(item.id)}">${esc(item.name)} · ${esc(label(item.mode))}</button>`
  ).join("");
  const messages = current.messages.map((item) =>
    `<div class="msg ${item.from === "staff" ? "msg--staff" : ""}"><strong>${esc(item.from)}</strong><p>${esc(item.text)}</p><small class="muted">${esc(item.wamid)}</small></div>`
  ).join("");
  const reply = current.mode === "human_takeover"
    ? `<form id="reply"><label class="field">Balasan staf<input name="text" required></label><button class="btn" type="submit">Hantar</button></form>`
    : `<p class="muted">AI sedang aktif. Ambil alih dahulu supaya tidak ada dua balasan serentak.</p>`;
  return `<article class="panel">
    <h1>Peti masuk</h1>
    <div class="inbox">
      <div class="thread">${list}</div>
      <div>
        <p>Mod: <strong>${esc(label(current.mode))}</strong></p>
        <div class="msgs">${messages}</div>
        ${reply}
        <p class="actions">
          <button class="btn" type="button" id="take">Ambil alih</button>
          <button class="btn btn--ghost" type="button" id="release">Pulangkan kepada AI</button>
        </p>
      </div>
      <aside>
        <h2>${esc(current.name)}</h2>
        <p class="muted">Status penghantaran dipadankan dengan id mesej, bukan nombor telefon.</p>
      </aside>
    </div>
  </article>`;
}

function knowledge(state) {
  return `<article class="panel">
    <h1>Ujian pengetahuan</h1>
    <p class="muted">Persona demo: ${esc(state.persona)} Harga yang ada: tudung bawal RM49. Stok tidak direkodkan.</p>
    <form id="play">
      <label class="field">Mesej pelanggan<input name="text" required placeholder="Tanya harga atau stok"></label>
      <button class="btn" type="submit">Jana jawapan demo</button>
    </form>
    <p id="answer" class="muted"></p>
  </article>`;
}

function label(mode) {
  return {
    ai_active: "AI aktif",
    human_takeover: "Manusia mengambil alih",
    ai_paused: "AI dijeda",
    resolved: "Selesai"
  }[mode] || mode;
}

function bind(hash) {
  const form = document.getElementById("auth");
  if (form) {
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      const data = new FormData(form);
      const email = String(data.get("email") || "");
      const password = String(data.get("password") || "");
      const error = document.getElementById("form-error");
      if (!email.includes("@") || password.length < 8) {
        error.hidden = false;
        error.textContent = "Gunakan e-mel yang sah dan kata laluan sekurang-kurangnya 8 aksara.";
        return;
      }
      const next = seed();
      next.user = { name: String(data.get("name") || "Demo"), email };
      save(next);
      location.hash = "papan";
    });
  }
  document.querySelectorAll("[data-open]").forEach((button) => {
    button.addEventListener("click", () => {
      const state = load();
      state.active = button.getAttribute("data-open");
      save(state);
      route();
    });
  });
  const take = document.getElementById("take");
  const release = document.getElementById("release");
  if (take) {
    take.addEventListener("click", () => setMode("human_takeover"));
    release.addEventListener("click", () => setMode("ai_active"));
  }
  const reply = document.getElementById("reply");
  if (reply) {
    reply.addEventListener("submit", (event) => {
      event.preventDefault();
      const state = load();
      const current = state.conversations.find((item) => item.id === state.active);
      const text = String(new FormData(reply).get("text") || "").trim();
      if (!text || current.mode !== "human_takeover") return;
      current.messages.push({ from: "staff", text, wamid: "wamid.demo." + Date.now() });
      save(state);
      route();
    });
  }
  const play = document.getElementById("play");
  if (play) {
    play.addEventListener("submit", (event) => {
      event.preventDefault();
      const text = String(new FormData(play).get("text") || "").toLowerCase();
      const answer = document.getElementById("answer");
      if (text.includes("harga") || text.includes("bawal")) {
        answer.textContent = "Jawapan demo: tudung bawal RM49, daripada pengetahuan yang disediakan.";
      } else if (text.includes("stok")) {
        answer.textContent = "Maklumat tidak cukup. AI demo tidak mereka stok dan akan menyerahkan kepada manusia.";
      } else {
        answer.textContent = "Jawapan demo umum. Tiada fakta tambahan dalam pengetahuan.";
      }
    });
  }
}

function setMode(mode) {
  const state = load();
  const current = state.conversations.find((item) => item.id === state.active);
  current.mode = mode;
  save(state);
  route();
}

ensureDemo();
window.addEventListener("hashchange", route);
route();
