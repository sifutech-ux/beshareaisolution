<?php
declare(strict_types=1);

const WEBHOOK_TOKEN_HASH = '384957d2a80da96f47c954979d2f01b93a1f5c3586a501cc254d897ce78b4560';
const NOMBOR_011 = '1443799975474056';
const NOMBOR_DHERBS = '1207159062490441';
const BALASAN_011 = 'Hai, terima kasih kerana menulis kepada BeShare AI Solution. Saya pembantu WhatsApp rasmi. Apa soalan jualan yang boleh saya bantu?';

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
$mentah = file_get_contents('php://input');
$data = json_decode(is_string($mentah) ? $mentah : '', true);
if (!is_array($data)) {
    echo '{"ok":true}';
    exit;
}

foreach ($data['entry'] ?? [] as $entri) {
    if (!is_array($entri)) {
        continue;
    }
    foreach ($entri['changes'] ?? [] as $perubahan) {
        if (!is_array($perubahan)) {
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
        $token = token_nombor($nombor);
        if ($token === '') {
            continue;
        }
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
            $teks = $jenis === 'text'
                ? BALASAN_011
                : 'Sila taip mesej teks. Saya pembantu WhatsApp BeShare AI Solution.';
            hantar_teks($nombor, $token, $dari, $teks);
            if ($id !== '') {
                tandakan_dilihat($id);
            }
        }
    }
}

echo '{"ok":true}';

function pertanyaan(string $nama): string
{
    $kunci = str_replace('.', '_', $nama);
    $nilai = $_GET[$kunci] ?? $_GET[$nama] ?? '';
    return is_string($nilai) ? $nilai : '';
}

function token_nombor(string $phoneId): string
{
    $path = dirname(__DIR__) . '/admin/stor/data.json';
    if (!is_file($path)) {
        return '';
    }
    $mentah = file_get_contents($path);
    $data = json_decode(is_string($mentah) ? $mentah : '', true);
    if (!is_array($data)) {
        return '';
    }
    foreach ($data['pelanggan'] ?? [] as $pelanggan) {
        if (!is_array($pelanggan)) {
            continue;
        }
        if ((string) ($pelanggan['phone_number_id'] ?? '') !== $phoneId) {
            continue;
        }
        return trim((string) ($pelanggan['token_meta'] ?? ''));
    }
    return '';
}

function laluan_dilihat(): string
{
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

function hantar_teks(string $phoneId, string $token, string $kepada, string $teks): void
{
    if (!function_exists('curl_init')) {
        return;
    }
    $badan = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $kepada,
        'type' => 'text',
        'text' => ['body' => $teks],
    ], JSON_UNESCAPED_UNICODE);
    if ($badan === false) {
        return;
    }
    $curl = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($phoneId) . '/messages');
    if ($curl === false) {
        return;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $badan,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($curl);
    curl_close($curl);
}
