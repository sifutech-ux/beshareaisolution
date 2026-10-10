<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/admin/lib.php';
require dirname(__DIR__) . '/lib.php';

ini_set('display_errors', '0');
mula_sesi('beshare_meja');
kepala_selamat();

$tapis = (($_GET['tapis'] ?? '') === 'sah') ? 'sah' : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    sahkan_csrf();
    $telefon = telefon_bersih((string) ($_POST['telefon'] ?? ''));
    $tindakan = (string) ($_POST['tindakan'] ?? '');
    $tapis = (($_POST['tapis'] ?? '') === 'sah') ? 'sah' : '';
    try {
        if ($telefon === '') {
            throw new InvalidArgumentException('Nombor tidak sah.');
        }
        if ($tindakan === 'jeda') {
            set_jeda($telefon, true);
            kilat('ok', 'AI dihentikan untuk nombor ini. Anda boleh balas sendiri.');
        } elseif ($tindakan === 'sambung') {
            set_jeda($telefon, false);
            $token = token_meja();
            $nota = 'Bot AI kembali aktif! Ada apa-apa yang boleh saya bantu?';
            if ($token !== '' && hantar_whatsapp(MEJA_PHONE_ID, $token, $telefon, $nota)) {
                rekod_perbualan($telefon, 'assistant', $nota, 'ai');
            }
            kilat('ok', 'AI diaktifkan semula.');
        } elseif ($tindakan === 'balas') {
            $mesej = meja_potong(trim((string) ($_POST['mesej'] ?? '')), 1000);
            if ($mesej === '') {
                throw new InvalidArgumentException('Mesej kosong.');
            }
            $token = token_meja();
            if ($token === '' || !hantar_whatsapp(MEJA_PHONE_ID, $token, $telefon, $mesej)) {
                throw new RuntimeException('Mesej tidak dihantar ke WhatsApp.');
            }
            rekod_perbualan($telefon, 'assistant', $mesej, 'admin');
            set_jeda($telefon, true);
            kilat('ok', 'Mesej admin dihantar dan AI dihentikan untuk nombor ini.');
        }
    } catch (InvalidArgumentException $ralat) {
        kilat('ralat', $ralat->getMessage());
    } catch (RuntimeException $ralat) {
        kilat('ralat', $ralat->getMessage());
    }
    $ke = '/whatsapp/meja/perbualan/';
    if ($tapis === 'sah') {
        $ke .= '?tapis=sah';
    }
    pergi($ke);
}

$stor = baca_perbualan();
$kilat = ambil_kilat();
$csrf = token_csrf();
$senarai = is_array($stor['perbualan']) ? $stor['perbualan'] : [];
$senarai = array_reverse($senarai, true);
if ($tapis === 'sah') {
    $senarai = array_filter($senarai, static function ($mesej): bool {
        return is_array($mesej) && perbualan_sah($mesej);
    });
}
$jeda = is_array($stor['jeda']) ? $stor['jeda'] : [];
?>
<!doctype html>
<html lang="ms">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Peti Masuk Perbualan — WhatsApp AI</title>
  <link rel="icon" type="image/svg+xml" href="../../../favicon.svg">
  <link rel="stylesheet" href="../../../styles.css">
  <link rel="stylesheet" href="../../landing.css">
  <link rel="stylesheet" href="../../../admin/admin.css">
  <style>
    .balik { color: var(--muted); font-weight: 700; text-decoration: none; }
    .kad-chat { margin-bottom: 1.25rem; }
    .kepala-chat, .kaki-chat { display: flex; gap: 0.6rem; align-items: center; justify-content: space-between; }
    .badan-chat { display: grid; gap: 0.55rem; margin: 0.9rem 0; max-height: 420px; overflow: auto; }
    .gelembung { max-width: 85%; padding: 0.65rem 0.8rem; border-radius: 14px; border: 1px solid var(--card-border); line-height: 1.45; white-space: pre-wrap; }
    .gelembung--masuk { background: rgba(255,255,255,0.05); }
    .gelembung--keluar { margin-left: auto; background: color-mix(in srgb, var(--whatsapp) 18%, transparent); }
    .siapa { display: block; font-size: 0.72rem; font-weight: 700; margin-bottom: 0.2rem; }
    .kaki-chat form { display: flex; gap: 0.5rem; width: 100%; }
    .kaki-chat input[type="text"] { flex: 1; }
    .butang-kecil { font: inherit; font-size: 0.82rem; font-weight: 700; border-radius: 999px; padding: 0.45rem 0.7rem; border: 1px solid var(--card-border); background: transparent; color: var(--text); }
  </style>
</head>
<body>
  <div class="bg-glow" aria-hidden="true"></div>
  <main class="page">
    <p><a class="balik" href="/whatsapp/meja/">Kembali ke dashboard</a></p>
    <div class="kepala-admin">
      <h1><?= $tapis === 'sah' ? 'Peti Masuk Chat Pembeli' : 'Peti Masuk Perbualan' ?></h1>
      <p class="muted">Chat yang masuk pada +60 11-5432 0018 disimpan di sini.</p>
    </div>
    <p><a class="btn" href="/whatsapp/meja/perbualan/<?= $tapis === 'sah' ? '?tapis=sah' : '' ?>">Refresh Inbox</a></p>
    <?php if ($kilat): ?>
      <p class="<?= $kilat['jenis'] === 'ok' ? 'ok' : 'ralat' ?>"><?= e((string) $kilat['teks']) ?></p>
    <?php endif; ?>

    <?php if ($senarai === []): ?>
      <section class="panel">
        <h2>Peti Masuk Kosong</h2>
        <p class="muted">Belum ada perbualan disimpan. Hantar mesej baharu ke +60 11-5432 0018, kemudian tekan Refresh Inbox.</p>
      </section>
    <?php endif; ?>

    <?php foreach ($senarai as $telefon => $mesej): ?>
      <?php if (!is_array($mesej)) { continue; } ?>
      <?php $dijeda = in_array((string) $telefon, $jeda, true); ?>
      <section class="panel kad-chat">
        <div class="kepala-chat">
          <div>
            <h2>+<?= e((string) $telefon) ?></h2>
            <?php if ($dijeda): ?>
              <p class="ralat">Admin mengambil alih. AI dihentikan.</p>
            <?php else: ?>
              <p class="good">AI aktif</p>
            <?php endif; ?>
          </div>
          <form method="post" action="/whatsapp/meja/perbualan/">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="telefon" value="<?= e((string) $telefon) ?>">
            <input type="hidden" name="tapis" value="<?= e($tapis) ?>">
            <?php if ($dijeda): ?>
              <input type="hidden" name="tindakan" value="sambung">
              <button class="butang-kecil" type="submit">Aktifkan AI Semula</button>
            <?php else: ?>
              <input type="hidden" name="tindakan" value="jeda">
              <button class="butang-kecil" type="submit">Ambil alih</button>
            <?php endif; ?>
          </form>
        </div>
        <div class="badan-chat">
          <?php foreach ($mesej as $satu): ?>
            <?php if (!is_array($satu)) { continue; } ?>
            <?php $keluar = ($satu['role'] ?? '') === 'assistant'; ?>
            <div class="gelembung <?= $keluar ? 'gelembung--keluar' : 'gelembung--masuk' ?>">
              <span class="siapa"><?= $keluar ? (($satu['by'] ?? '') === 'admin' ? 'Admin' : 'Bot AI') : 'Prospek' ?> · <?= e((string) ($satu['masa'] ?? '')) ?></span>
              <?= e((string) ($satu['content'] ?? '')) ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="kaki-chat">
          <form class="form" method="post" action="/whatsapp/meja/perbualan/">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="tindakan" value="balas">
            <input type="hidden" name="telefon" value="<?= e((string) $telefon) ?>">
            <input type="hidden" name="tapis" value="<?= e($tapis) ?>">
            <input type="text" name="mesej" required placeholder="Taip mesej admin di sini">
            <button class="btn" type="submit">Hantar</button>
          </form>
        </div>
      </section>
    <?php endforeach; ?>
  </main>
</body>
</html>
