<?php
declare(strict_types=1);

require __DIR__ . '/../admin/lib.php';

ini_set('display_errors', '0');
mula_sesi('beshare_papan');
kepala_selamat();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    sahkan_csrf();
    $kunci = (string) ($_POST['kunci_api'] ?? '');
    $pelanggan = cari_dengan_kunci($kunci);
    if ($pelanggan === null) {
        kilat('ralat', 'Kunci tidak sepadan.');
        pergi('/papan/');
    }
    session_regenerate_id(true);
    $_SESSION['pelanggan_id'] = (int) $pelanggan['id'];
    unset($_SESSION['csrf']);
    pergi('/papan/');
}

$kilat = ambil_kilat();
$csrf = token_csrf();
$kedai = null;
$id = $_SESSION['pelanggan_id'] ?? 0;
if (is_int($id) || (is_string($id) && ctype_digit($id))) {
    $rekod = cari_pelanggan_id((int) $id);
    if ($rekod !== null) {
        $kedai = medan_awam($rekod);
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Papan lead — Whatsapp BeShare API</title>
  <link rel="icon" type="image/svg+xml" href="../favicon.svg">
  <link rel="stylesheet" href="../styles.css">
  <link rel="stylesheet" href="../whatsapp/landing.css">
  <link rel="stylesheet" href="../whatsapp-api/api.css">
  <link rel="stylesheet" href="../admin/admin.css">
</head>
<body>
  <div class="bg-glow" aria-hidden="true"></div>
<?php if ($kedai === null): ?>
  <main class="login">
    <form class="panel form" method="post" action="/papan/">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <p class="pill">Satu kunci</p>
      <h1>Papan lead</h1>
      <p class="muted">Tampal kunci API BeShare yang diberikan kepada kedai.</p>
      <?php if ($kilat): ?>
        <p class="ralat"><?= e((string) $kilat['teks']) ?></p>
      <?php endif; ?>
      <label for="kunci_api">Kunci API
        <textarea id="kunci_api" name="kunci_api" rows="2" required autocomplete="off"></textarea>
      </label>
      <button class="btn" type="submit">Masuk</button>
    </form>
  </main>
<?php else: ?>
  <div class="topbar">
    <a class="brand" href="../whatsapp-api/">
      <span class="brand__mark" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="34" height="34">
          <rect width="32" height="32" rx="8" fill="#131734"></rect>
          <path d="M8 9h16l-8 15z" fill="#25d366"></path>
        </svg>
      </span>
      <span class="brand__name"><?= e($kedai['nama_kedai']) ?></span>
    </a>
    <div class="topbar__links">
      <form method="post" action="/papan/keluar.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--ghost" type="submit">Keluar</button>
      </form>
    </div>
  </div>
  <main class="page">
    <header class="kepala-admin">
      <h1>Papan lead</h1>
      <p class="muted"><?= e($kedai['nama']) ?>. Tiga lajur: Baru, Sedang ikut, Sudah tutup.</p>
    </header>
    <section class="panel papan-kedai">
      <h2><?= e($kedai['nama_kedai']) ?></h2>
      <?php if ($kedai['produk'] !== ''): ?>
        <p class="muted"><?= e($kedai['produk']) ?></p>
      <?php endif; ?>
      <p class="muted"><?= $kedai['diikat'] ? 'Nombor diikat' . ($kedai['paparan'] !== '' ? ' · ' . e($kedai['paparan']) : '') : 'Nombor WhatsApp belum diikat.' ?></p>
    </section>
    <div class="lajur-api">
      <section class="panel" id="lajur-baru">
        <h3>Baru <span class="kira">0</span></h3>
        <p class="muted">Belum ada lead.</p>
      </section>
      <section class="panel" id="lajur-ikut">
        <h3>Sedang ikut <span class="kira">0</span></h3>
        <p class="muted">Belum ada lead.</p>
      </section>
      <section class="panel" id="lajur-tutup">
        <h3>Sudah tutup <span class="kira">0</span></h3>
        <p class="muted">Belum ada lead.</p>
      </section>
    </div>
  </main>
<?php endif; ?>
</body>
</html>
