<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/admin/lib.php';
require dirname(__DIR__) . '/lib.php';

require dirname(__DIR__) . '/guard.php';
meja_require_access([]);

$senarai = array_reverse(baca_perbualan()['pesanan']);
?>
<!doctype html>
<html lang="ms">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Senarai Pesanan — WhatsApp AI</title>
  <link rel="icon" type="image/svg+xml" href="../../../assets/brand/aurora-v2/beshare-ai-os-favicon.svg">
  <link rel="apple-touch-icon" href="../../../assets/brand/aurora-v2/beshare-ai-os-apple-touch-icon.png">
  <link rel="stylesheet" href="../../../styles.css">
  <link rel="stylesheet" href="../../landing.css">
  <link rel="stylesheet" href="../../../admin/admin.css">
  <link rel="stylesheet" href="../../../assets/brand.css?v=20261127-aurora-v2">
  <style>
    .balik { color: var(--muted); font-weight: 700; text-decoration: none; }
    .kad-pesanan { margin-bottom: 1rem; }
    .kad-pesanan p { white-space: pre-wrap; }
  </style>
</head>
<body>
  <div class="bg-glow" aria-hidden="true"></div>
  <main class="page">
    <a class="bs-brand" href="/whatsapp/meja/" aria-label="BeShare AI OS — Dashboard WhatsApp" style="--bs-brand-width:220px; margin-bottom:1.5rem;"><img class="bs-brand__logo bs-brand__logo--dark" src="../../../assets/brand/aurora-v2/beshare-ai-os-logo-dark.svg" alt="" aria-hidden="true"><img class="bs-brand__logo bs-brand__logo--light" src="../../../assets/brand/aurora-v2/beshare-ai-os-logo-light.svg" alt="" aria-hidden="true"></a>
    <p><a class="balik" href="/whatsapp/meja/">Kembali ke dashboard</a></p>
    <div class="kepala-admin">
      <h1>Senarai Pesanan Masuk</h1>
      <p class="muted">Pesanan yang disebut dalam chat disimpan di sini.</p>
    </div>
    <p><a class="btn" href="/whatsapp/meja/pesanan/">Refresh</a></p>
    <?php if ($senarai === []): ?>
      <section class="panel">
        <h2>Tiada Pesanan</h2>
        <p class="muted">Belum ada pesanan yang disimpan.</p>
      </section>
    <?php endif; ?>
    <?php foreach ($senarai as $pesanan): ?>
      <?php if (!is_array($pesanan)) { continue; } ?>
      <section class="panel kad-pesanan">
        <p class="muted"><?= e((string) ($pesanan['time'] ?? '')) ?></p>
        <h2>+<?= e((string) ($pesanan['phone'] ?? '')) ?></h2>
        <p><?= e((string) ($pesanan['details'] ?? '')) ?></p>
      </section>
    <?php endforeach; ?>
  </main>
</body>
</html>
