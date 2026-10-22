<?php
declare(strict_types=1);

ini_set('display_errors', '0');

const WEBHOOK_TOKEN_HASH = '384957d2a80da96f47c954979d2f01b93a1f5c3586a501cc254d897ce78b4560';
const NOMBOR_011 = '1443799975474056';
const NOMBOR_DHERBS = '1207159062490441';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $mode = pertanyaan('hub.mode');
    $token = pertanyaan('hub.verify_token');
    $cabaran = pertanyaan('hub.challenge');
    $hash = hash('sha256', $token);
    if ($mode === 'subscribe' && $token !== '' && hash_equals(WEBHOOK_TOKEN_HASH, $hash)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $cabaran;
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Tidak sah.';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
// Authenticate the exact bytes before loading legacy handlers or decoding JSON.
require_once dirname(__DIR__) . '/os/src/bootstrap.php';
try {
    $private = \BesharOs\PrivateConfig::load();
} catch (Throwable) {
    $private = null;
}
$secret = $private['app_secret'] ?? '';
if (!is_string($secret) || $secret === '') {
    http_response_code(503);
    exit('{"ok":false}');
}
$mentah = file_get_contents('php://input', false, null, 0, 1048577);
if (!is_string($mentah) || strlen($mentah) > 1048576) {
    http_response_code(413);
    exit('{"ok":false}');
}
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!is_string($signature) || !\BesharOs\WhatsAppWebhook::signatureMatches($mentah, $signature, $secret)) {
    http_response_code(403);
    exit('{"ok":false}');
}
require_once dirname(__DIR__) . '/whatsapp/meja/lib.php';
$data = json_decode($mentah, true, 512, JSON_BIGINT_AS_STRING);
if (!is_array($data) || ($data['object'] ?? '') !== 'whatsapp_business_account' || !is_array($data['entry'] ?? null)) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

// Missing binding must be retryable, never silently acknowledge and drop intake.
foreach ($data['entry'] as $entry) {
    foreach (is_array($entry) && is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
        if (is_array($change) && ($change['field'] ?? '') === 'messages'
            && (preg_match('/\A[1-9][0-9]*\z/', $private['legacy_business_id'] ?? '') !== 1
                || preg_match('/\A[0-9]{1,32}\z/', $private['legacy_waba_id'] ?? '') !== 1)) {
            http_response_code(503);
            exit('{"ok":false}');
        }
    }
}

foreach ($data['entry'] ?? [] as $entri) {
    if (!is_array($entri) || !is_string($private['legacy_waba_id'] ?? null)
        || ($private['legacy_waba_id'] ?? '') === ''
        || (string) ($entri['id'] ?? '') !== $private['legacy_waba_id']
        || preg_match('/\A[1-9][0-9]*\z/', $private['legacy_business_id'] ?? '') !== 1) {
        continue;
    }
    foreach ($entri['changes'] ?? [] as $perubahan) {
        if (!is_array($perubahan) || ($perubahan['field'] ?? '') !== 'messages') {
            continue;
        }
        $nilai = $perubahan['value'] ?? null;
        if (!is_array($nilai)) {
            continue;
        }
        $meta = $nilai['metadata'] ?? [];
        $nombor = is_array($meta) ? (string) ($meta['phone_number_id'] ?? '') : '';
        if ($nombor === '' || $nombor === NOMBOR_DHERBS || $nombor !== NOMBOR_011) {
            continue;
        }
        // P0: intake only. No token reads, AI generation or outbound replies.
        foreach ($nilai['messages'] ?? [] as $mesej) {
            if (!is_array($mesej)) {
                continue;
            }
            $id = (string) ($mesej['id'] ?? '');
            if ($id !== '' && sudah_dilihat($id)) {
                continue;
            }
            $dari = preg_replace('/\D+/', '', (string) ($mesej['from'] ?? '')) ?? '';
            if ($dari === '') {
                continue;
            }
            $jenis = (string) ($mesej['type'] ?? '');
            if ($jenis === 'text') {
                $badan = trim((string) ($mesej['text']['body'] ?? ''));
                if ($badan === '') {
                    continue;
                }
                rekod_perbualan($dari, 'user', $badan);
                if (nampak_pesanan($badan)) {
                    rekod_pesanan($dari, $badan);
                }
            } else {
                rekod_perbualan($dari, 'user', 'Mesej bukan teks.');
            }
            if ($id !== '') {
                tandakan_dilihat($id);
            }
        }
    }
}

$keputusan = 'ignored';
if (ada_peristiwa_hayat(is_string($mentah) ? $mentah : '')) {
    try {
        require_once dirname(__DIR__) . '/os/src/bootstrap.php';
        $tandatangan = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $keputusan = \BesharOs\LifecycleBridge::deliver(is_string($mentah) ? $mentah : '', is_string($tandatangan) ? $tandatangan : '');
    } catch (Throwable) {
        $keputusan = 'failed';
    }
}
if ($keputusan === 'failed') {
    http_response_code(500);
    echo '{"ok":false}';
    exit;
}

echo '{"ok":true}';

function ada_peristiwa_hayat(string $mentah): bool
{
    $data = json_decode($mentah, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($data)) {
        return false;
    }
    foreach ($data['entry'] ?? [] as $entri) {
        if (!is_array($entri)) {
            continue;
        }
        foreach ($entri['changes'] ?? [] as $perubahan) {
            if (!is_array($perubahan) || ($perubahan['field'] ?? '') !== 'account_update' || !is_array($perubahan['value'] ?? null)) {
                continue;
            }
            $acara = (string) ($perubahan['value']['event'] ?? '');
            if ($acara === 'PARTNER_REMOVED' || $acara === 'ACCOUNT_OFFBOARDED') {
                return true;
            }
        }
    }
    return false;
}

function pertanyaan(string $nama): string
{
    $kunci = str_replace('.', '_', $nama);
    $nilai = $_GET[$kunci] ?? $_GET[$nama] ?? '';
    return is_string($nilai) ? $nilai : '';
}

function laluan_dilihat(): string
{
    $khas = getenv('BESHARE_STOR');
    if (is_string($khas) && $khas !== '') {
        return rtrim($khas, '/') . '/webhook-dilihat.txt';
    }
    return dirname(__DIR__) . '/admin/stor/webhook-dilihat.txt';
}

function sudah_dilihat(string $id): bool
{
    $path = laluan_dilihat();
    if (!is_file($path)) {
        return false;
    }
    $baris = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($baris) && in_array($id, $baris, true);
}

function tandakan_dilihat(string $id): void
{
    $path = laluan_dilihat();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return;
    }
    $baris = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    if (!is_array($baris)) {
        $baris = [];
    }
    $baris[] = $id;
    $baris = array_slice($baris, -200);
    file_put_contents($path, implode("\n", $baris) . "\n", LOCK_EX);
    @chmod($path, 0600);
}

