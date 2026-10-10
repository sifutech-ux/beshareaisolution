<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/admin/lib.php';
require __DIR__ . '/lib.php';

ini_set('display_errors', '0');
mula_sesi('beshare_meja');
kepala_selamat();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    sahkan_csrf();
    $tindakan = (string) ($_POST['tindakan'] ?? '');
    try {
        if ($tindakan === 'bank') {
            $qr = simpan_qr_jika_ada();
            simpan_bank_meja(
                (string) ($_POST['bank_name'] ?? ''),
                (string) ($_POST['account_no'] ?? ''),
                (string) ($_POST['account_holder'] ?? ''),
                $qr
            );
            kilat('ok', 'Maklumat bank disimpan.');
        } elseif ($tindakan === 'latih') {
            latih_meja((string) ($_POST['website_url'] ?? ''));
            kilat('ok', 'AI dilatih dari pautan itu. Semak skrip di bawah, kemudian simpan jika anda ubah.');
        } elseif ($tindakan === 'token') {
            simpan_token_meja((string) ($_POST['token_meta'] ?? ''));
            kilat('ok', 'Token disimpan. Auto-reply untuk +60 11-5432 0018 hidup.');
        } elseif ($tindakan === 'skrip') {
            simpan_skrip_meja((string) ($_POST['prompt_text'] ?? ''));
            kilat('ok', 'Skrip disimpan.');
        }
    } catch (InvalidArgumentException $ralat) {
        kilat('ralat', $ralat->getMessage());
    } catch (RuntimeException) {
        kilat('ralat', 'Tidak berjaya. Cuba sekali lagi.');
    }
    pergi('/whatsapp/meja/');
}

$meja = baca_meja();
$storPerbualan = baca_perbualan();
$kilat = ambil_kilat();
$csrf = token_csrf();
$tokenAda = trim((string) ($meja['token_meta'] ?? '')) !== '';
$laman = trim((string) ($meja['website_url'] ?? ''));
$bankNama = trim((string) ($meja['bank_name'] ?? ''));
$bilChat = 0;
$bilSah = 0;
foreach ($storPerbualan['perbualan'] as $mesej) {
    if (!is_array($mesej) || $mesej === []) {
        continue;
    }
    $bilChat++;
    if (perbualan_sah($mesej)) {
        $bilSah++;
    }
}
$bilJeda = count(is_array($storPerbualan['jeda']) ? $storPerbualan['jeda'] : []);
$bilPesanan = count(is_array($storPerbualan['pesanan']) ? $storPerbualan['pesanan'] : []);

function simpan_qr_jika_ada(): string
{
    $fail = $_FILES['qr_file'] ?? null;
    if (!is_array($fail) || (int) ($fail['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int) ($fail['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Gambar QR tidak dapat dimuat naik.');
    }
    $saiz = (int) ($fail['size'] ?? 0);
    if ($saiz < 1 || $saiz > 2_000_000) {
        throw new InvalidArgumentException('Gambar QR mestilah di bawah 2MB.');
    }
    $sementara = (string) ($fail['tmp_name'] ?? '');
    $info = @getimagesize($sementara);
    $jenis = is_array($info) ? (int) ($info[2] ?? 0) : 0;
    $sambungan = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ][$jenis] ?? '';
    if ($sambungan === '') {
        throw new InvalidArgumentException('QR mestilah gambar JPG, PNG, atau WEBP.');
    }
    $dir = meja_laluan_muat();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Gambar tidak dapat disimpan.');
    }
    $nama = bin2hex(random_bytes(16)) . '.' . $sambungan;
    $ke = $dir . '/' . $nama;
    if (!move_uploaded_file($sementara, $ke)) {
        throw new RuntimeException('Gambar tidak dapat disimpan.');
    }
    @chmod($ke, 0644);
    return '/whatsapp/meja/muat/' . $nama;
}
?>
<!doctype html>
<html lang="ms">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Dashboard WhatsApp AI — +60 11-5432 0018</title>
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg">
  <link rel="stylesheet" href="../../styles.css">
  <link rel="stylesheet" href="../landing.css">
  <link rel="stylesheet" href="../../admin/admin.css">
</head>
<body>
  <div class="bg-glow" aria-hidden="true"></div>
  <main class="page">
    <p class="pill">WhatsApp AI</p>
    <div class="kepala-admin">
      <h1>Dashboard +60 11-5432 0018</h1>
      <p class="muted">Isi alamat bank, pautan latih AI, dan token Meta. Auto-reply guna nombor ini sahaja.</p>
    </div>
    <?php if ($kilat): ?>
      <p class="<?= $kilat['jenis'] === 'ok' ? 'ok' : 'ralat' ?>"><?= e((string) $kilat['teks']) ?></p>
    <?php endif; ?>

    <section class="panel" style="margin-bottom:1.25rem;">
      <h2>Status auto-reply</h2>
      <?php if ($tokenAda): ?>
        <p class="good">Hidup untuk +60 11-5432 0018</p>
      <?php else: ?>
        <p class="ralat">Belum hidup. Simpan token Meta di bawah.</p>
      <?php endif; ?>
      <p class="muted">Phone number ID 1443799975474056. Webhook sudah pada beshareaisolution.com/webhook/.</p>
      <p class="muted">Laman latih: <?= $laman !== '' ? e($laman) : 'Belum' ?>. Bank: <?= $bankNama !== '' ? e($bankNama) : 'Belum' ?>.</p>
      <p class="muted">Chat yang masuk disimpan dalam Peti Masuk. Tekan Refresh Inbox selepas anda hantar mesej baharu.</p>
    </section>

    <section class="panel" style="margin-bottom:1.25rem;">
      <h2>Peti Masuk Perbualan</h2>
      <p class="muted"><?= (int) $bilChat ?> prospek sedang bersembang. <?= (int) $bilJeda ?> nombor diambil alih.</p>
      <a class="btn" href="/whatsapp/meja/perbualan/">Buka Inbox Semua Prospek</a>
    </section>

    <section class="panel" style="margin-bottom:1.25rem;">
      <h2>Peti Masuk Chat Pembeli</h2>
      <p class="muted"><?= (int) $bilSah ?> chat yang sudah beri pesanan.</p>
      <a class="btn" href="/whatsapp/meja/perbualan/?tapis=sah">Buka Inbox Pembeli Sah</a>
    </section>

    <section class="panel" style="margin-bottom:1.25rem;">
      <h2>Pengurusan Pesanan</h2>
      <p class="muted"><?= (int) $bilPesanan ?> pesanan disimpan.</p>
      <a class="btn" href="/whatsapp/meja/pesanan/">Lihat Semua Pesanan</a>
    </section>

    <section class="panel" style="margin-bottom:1.25rem;">
      <h2>Maklumat bank</h2>
      <form class="form" method="post" action="/whatsapp/meja/" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="tindakan" value="bank">
        <div class="pair">
          <label>Nama bank
            <input name="bank_name" required value="<?= e((string) $meja['bank_name']) ?>">
          </label>
          <label>Nombor akaun
            <input name="account_no" required value="<?= e((string) $meja['account_no']) ?>">
          </label>
        </div>
        <label>Nama pemegang akaun
          <input name="account_holder" required value="<?= e((string) $meja['account_holder']) ?>">
        </label>
        <label>Gambar QR (DuitNow / QR bank)
          <input type="file" name="qr_file" accept="image/jpeg,image/png,image/webp">
        </label>
        <?php if (!empty($meja['qr_url'])): ?>
          <p class="good">QR aktif: <a href="<?= e((string) $meja['qr_url']) ?>">Buka gambar QR</a></p>
        <?php endif; ?>
        <button class="btn" type="submit">Simpan Maklumat Bank &amp; QR</button>
      </form>
    </section>

    <section class="panel" style="margin-bottom:1.25rem;">
      <h2>Auto-Train AI</h2>
      <form class="form" method="post" action="/whatsapp/meja/">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="tindakan" value="latih">
        <label>Pautan laman / landing page
          <input type="url" name="website_url" required placeholder="https://" value="<?= e($laman) ?>">
        </label>
        <button class="btn" type="submit">Auto-Train AI</button>
      </form>
    </section>

    <section class="panel" style="margin-bottom:1.25rem;">
      <h2>Token Meta untuk auto-reply</h2>
      <p class="muted">Dalam app Meta BeShare Ai: menu kiri Step 1. Try it out, kemudian Generate access token. Senarai nombor mesti ada +60 11-5432 0018. Tampal di kotak ini.</p>
      <form class="form" method="post" action="/whatsapp/meja/">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="tindakan" value="token">
        <label>Token
          <input name="token_meta" type="password" autocomplete="off" required>
        </label>
        <button class="btn" type="submit">Simpan token</button>
      </form>
    </section>

    <section class="panel">
      <h2>Skrip AI</h2>
      <form class="form" method="post" action="/whatsapp/meja/">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="tindakan" value="skrip">
        <label>Arahan dan maklumat jualan
          <textarea name="prompt_text" rows="12"><?= e((string) $meja['prompt']) ?></textarea>
        </label>
        <button class="btn" type="submit">Simpan Skrip Sekarang</button>
      </form>
    </section>
  </main>
</body>
</html>
