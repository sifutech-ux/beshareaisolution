<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

ini_set('display_errors', '0');
mula_sesi('beshare_admin');
kepala_selamat();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    sahkan_csrf();
    $tindakan = (string) ($_POST['tindakan'] ?? '');
    if ($tindakan === 'masuk') {
        $kod = (string) ($_POST['peranan'] ?? '');
        $kata = (string) ($_POST['kata_laluan'] ?? '');
        if (cuba_masuk($kod, $kata)) {
            pergi('/admin/');
        }
        $kunci = $_SESSION['kunci_masa'] ?? 0;
        if (is_int($kunci) && $kunci > time()) {
            kilat('ralat', 'Terlalu banyak cubaan. Cuba lagi sebentar lagi.');
        } else {
            kilat('ralat', 'Kata laluan tidak sepadan.');
        }
        pergi('/admin/');
    }

    if (admin_semasa() === null) {
        pergi('/admin/');
    }

    try {
        if ($tindakan === 'pelanggan') {
            $hasil = cipta_pelanggan(
                (string) ($_POST['nama'] ?? ''),
                (string) ($_POST['nama_kedai'] ?? ''),
                (string) ($_POST['penerangan'] ?? ''),
                (string) ($_POST['produk'] ?? ''),
                (string) ($_POST['ucapan'] ?? '')
            );
            $_SESSION['kunci_sekali'] = $hasil;
            kilat('ok', 'Pelanggan dicipta. Salin kunci sekarang. Kunci ini tidak dipaparkan lagi.');
        } elseif ($tindakan === 'nombor') {
            ikat_nombor(
                (int) ($_POST['pelanggan_id'] ?? 0),
                (string) ($_POST['phone_number_id'] ?? ''),
                (string) ($_POST['paparan'] ?? ''),
                (string) ($_POST['token_meta'] ?? '')
            );
            kilat('ok', 'Nombor diikat.');
        }
    } catch (InvalidArgumentException $ralat) {
        kilat('ralat', $ralat->getMessage());
    } catch (RuntimeException) {
        kilat('ralat', 'Stor tidak dapat ditulis.');
    }
    pergi('/admin/');
}

$admin = admin_semasa();
$kilat = ambil_kilat();
$csrf = token_csrf();
$sekali = null;
if (is_array($_SESSION['kunci_sekali'] ?? null)) {
    $sekali = $_SESSION['kunci_sekali'];
    unset($_SESSION['kunci_sekali']);
}
$senarai = $admin ? senarai_awam() : [];
$tajukPeranan = $admin['label'] ?? '';
if ($admin && !empty($admin['nama'])) {
    $tajukPeranan .= ' · ' . $admin['nama'];
}
?>
<!doctype html>
<html lang="ms">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Dashboard admin — BeShare AI Solution</title>
  <link rel="icon" type="image/svg+xml" href="../favicon.svg">
  <link rel="stylesheet" href="../styles.css">
  <link rel="stylesheet" href="../whatsapp/landing.css">
  <link rel="stylesheet" href="admin.css">
</head>
<body>
  <div class="bg-glow" aria-hidden="true"></div>
<?php if ($admin === null): ?>
  <main class="login">
    <form class="panel form" method="post" action="/admin/">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="tindakan" value="masuk">
      <p class="pill">BeShare</p>
      <h1>Dashboard admin</h1>
      <p class="muted">Untuk Pemilik dan CEO.</p>
      <?php if ($kilat): ?>
        <p class="<?= $kilat['jenis'] === 'ok' ? 'ok' : 'ralat' ?>"><?= e((string) $kilat['teks']) ?></p>
      <?php endif; ?>
      <label for="peranan">Peranan
        <select id="peranan" name="peranan" required>
          <option value="" selected disabled>Pilih</option>
          <option value="pemilik">Pemilik</option>
          <option value="ceo">CEO</option>
        </select>
      </label>
      <label for="kata_laluan">Kata laluan
        <input id="kata_laluan" name="kata_laluan" type="password" autocomplete="current-password" required>
      </label>
      <button class="btn" type="submit">Masuk</button>
    </form>
  </main>
<?php else: ?>
  <div class="topbar">
    <a class="brand" href="../">
      <span class="brand__mark" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="34" height="34">
          <rect width="32" height="32" rx="8" fill="#131734"></rect>
          <path d="M8 9h16l-8 15z" fill="#7c9cff"></path>
        </svg>
      </span>
      <span class="brand__name">BeShare<span class="brand__accent"> AI Solution</span></span>
    </a>
    <div class="topbar__links">
      <span class="peranan-kini"><?= e($tajukPeranan) ?></span>
      <form method="post" action="/admin/keluar.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--ghost" type="submit">Keluar</button>
      </form>
    </div>
  </div>

  <main class="page">
    <header class="kepala-admin">
      <h1>Dashboard admin</h1>
      <p class="muted">Meja pengendali ada di sini. Cipta pelanggan, salin kunci sekali, kemudian berikan kunci itu kepada kedai.</p>
    </header>

    <?php if ($kilat): ?>
      <p class="<?= $kilat['jenis'] === 'ok' ? 'ok' : 'ralat' ?>"><?= e((string) $kilat['teks']) ?></p>
    <?php endif; ?>

    <?php if (is_array($sekali) && isset($sekali['kunci'])): ?>
      <section class="panel kunci-sekali" id="kunci-baru">
        <h2>Kunci API pelanggan #<?= e((string) $sekali['id']) ?></h2>
        <p class="muted"><?= e((string) $sekali['nama']) ?> · <?= e((string) $sekali['nama_kedai']) ?>. Salin sekarang. Selepas ini hanya awalan kunci yang tinggal.</p>
        <textarea id="kunci-sekali" readonly rows="2"><?= e((string) $sekali['kunci']) ?></textarea>
        <button class="btn" type="button" id="salin">Salin</button>
      </section>
    <?php endif; ?>

    <section class="panel">
      <h2>Webhook nombor 011</h2>
      <p class="muted">Tampal URL ini pada Callback URL Meta. Token semakan tidak dipaparkan di laman. Nombor DHerbs tidak dibalas.</p>
      <label for="callback-url">Callback URL
        <input id="callback-url" readonly value="https://beshareaisolution.com/webhook/">
      </label>
      <p class="muted">Phone number ID 011: 1443799975474056</p>
    </section>

    <div class="meja-dua">
      <section class="panel">
        <h2>Pelanggan baharu</h2>
        <p class="muted">Kunci API dipaparkan sekali selepas pelanggan dicipta.</p>
        <form class="form" method="post" action="/admin/">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="tindakan" value="pelanggan">
          <label for="nama">Nama pelanggan
            <input id="nama" name="nama" required maxlength="120">
          </label>
          <label for="nama_kedai">Nama kedai
            <input id="nama_kedai" name="nama_kedai" required maxlength="120">
          </label>
          <label for="penerangan">Penerangan
            <textarea id="penerangan" name="penerangan" maxlength="2000"></textarea>
          </label>
          <label for="produk">Produk dan harga
            <textarea id="produk" name="produk" maxlength="4000" placeholder="Contoh: Minyak 30ml — RM 39"></textarea>
          </label>
          <label for="ucapan">Ucapan
            <input id="ucapan" name="ucapan" maxlength="300" placeholder="Hai, terima kasih kerana menulis.">
          </label>
          <button class="btn" type="submit">Cipta pelanggan</button>
        </form>
      </section>

      <section class="panel">
        <h2>Ikat nombor WhatsApp</h2>
        <p class="muted">Token Meta disimpan untuk BeShare hantar mesej. Pelanggan tidak nampak token ini.</p>
        <?php if ($senarai === []): ?>
          <p class="muted">Cipta pelanggan dahulu.</p>
        <?php else: ?>
          <form class="form" method="post" action="/admin/">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="tindakan" value="nombor">
            <label for="pelanggan_id">ID pelanggan
              <select id="pelanggan_id" name="pelanggan_id" required>
                <option value="" selected disabled>Pilih</option>
                <?php foreach ($senarai as $pelanggan): ?>
                  <option value="<?= e((string) $pelanggan['id']) ?>">#<?= e((string) $pelanggan['id']) ?> · <?= e($pelanggan['nama']) ?> · <?= e($pelanggan['nama_kedai']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label for="phone_number_id">Phone number ID
              <input id="phone_number_id" name="phone_number_id" inputmode="numeric" required>
            </label>
            <label for="paparan">Nombor paparan
              <input id="paparan" name="paparan" placeholder="+60 …" maxlength="30">
            </label>
            <label for="token_meta">Token Meta
              <input id="token_meta" name="token_meta" type="password" autocomplete="new-password" required>
            </label>
            <button class="btn" type="submit">Ikat nombor</button>
          </form>
        <?php endif; ?>
      </section>
    </div>

    <section>
      <div class="section-title kepala-admin">
        <h2>Pelanggan</h2>
        <p>Kunci penuh tidak disimpan untuk dipaparkan semula. Yang kelihatan hanya awalannya.</p>
      </div>
      <?php if ($senarai === []): ?>
        <p class="muted">Belum ada pelanggan.</p>
      <?php else: ?>
        <div class="senarai">
          <?php foreach ($senarai as $pelanggan): ?>
            <article class="panel baris-pelanggan">
              <strong>#<?= e((string) $pelanggan['id']) ?> · <?= e($pelanggan['nama']) ?></strong>
              <span><?= e($pelanggan['nama_kedai']) ?></span>
              <span class="muted">Kunci <?= e($pelanggan['awalan']) ?>… · <?= e($pelanggan['dicipta']) ?></span>
              <span class="muted"><?= $pelanggan['diikat'] ? 'Nombor diikat' . ($pelanggan['paparan'] !== '' ? ' · ' . e($pelanggan['paparan']) : '') : 'Nombor belum diikat' ?></span>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <script>
    document.getElementById("salin")?.addEventListener("click", async () => {
      const medan = document.getElementById("kunci-sekali");
      if (!medan) return;
      medan.focus();
      medan.select();
      try {
        await navigator.clipboard.writeText(medan.value);
      } catch (e) {
        document.execCommand("copy");
      }
    });
  </script>
<?php endif; ?>
</body>
</html>
