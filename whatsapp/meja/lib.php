<?php
declare(strict_types=1);

const MEJA_PHONE_ID = '1443799975474056';
const MEJA_PAPARAN = '+60 11-5432 0018';

function meja_laluan(): string
{
    $khas = getenv('BESHARE_STOR');
    $dir = (is_string($khas) && $khas !== '') ? $khas : dirname(__DIR__, 2) . '/admin/stor';
    return rtrim($dir, '/') . '/meja.json';
}

function meja_laluan_muat(): string
{
    $khas = getenv('BESHARE_MUAT');
    if (is_string($khas) && $khas !== '') {
        return rtrim($khas, '/');
    }
    return __DIR__ . '/muat';
}

function meja_kosong(): array
{
    return [
        'phone_number_id' => MEJA_PHONE_ID,
        'paparan' => MEJA_PAPARAN,
        'token_meta' => '',
        'bank_name' => '',
        'account_no' => '',
        'account_holder' => '',
        'qr_url' => '',
        'website_url' => '',
        'laman_teks' => '',
        'prompt' => '',
    ];
}

function baca_meja(): array
{
    $path = meja_laluan();
    if (!is_file($path)) {
        return meja_kosong();
    }
    $mentah = file_get_contents($path);
    $data = json_decode(is_string($mentah) ? $mentah : '', true);
    if (!is_array($data)) {
        return meja_kosong();
    }
    return array_merge(meja_kosong(), $data);
}

function dengan_meja(callable $ubah): array
{
    $path = meja_laluan();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Stor tidak boleh dibuka.');
    }
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Stor tidak boleh dibuka.');
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Stor sedang digunakan.');
        }
        $mentah = stream_get_contents($fh);
        $data = json_decode(is_string($mentah) ? $mentah : '', true);
        if (!is_array($data)) {
            $data = meja_kosong();
        } else {
            $data = array_merge(meja_kosong(), $data);
        }
        $data = $ubah($data);
        $data['phone_number_id'] = MEJA_PHONE_ID;
        $data['paparan'] = MEJA_PAPARAN;
        rewind($fh);
        ftruncate($fh, 0);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || fwrite($fh, $json) === false) {
            throw new RuntimeException('Stor tidak boleh ditulis.');
        }
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    @chmod($path, 0600);
    return $data;
}

function meja_potong(string $nilai, int $had): string
{
    $nilai = trim($nilai);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($nilai) > $had) {
            return mb_substr($nilai, 0, $had);
        }
        return $nilai;
    }
    if (strlen($nilai) <= $had) {
        return $nilai;
    }
    return substr($nilai, 0, $had);
}

function token_meta_sah(string $token): bool
{
    $token = trim($token);
    $panjang = strlen($token);
    if ($panjang < 40 || $panjang > 2000) {
        return false;
    }
    return (bool) preg_match('/\A[A-Za-z0-9_\-\.]+\z/', $token);
}

function hos_awam(string $url): bool
{
    $bahagian = parse_url($url);
    if (!is_array($bahagian)) {
        return false;
    }
    $skema = strtolower((string) ($bahagian['scheme'] ?? ''));
    if ($skema !== 'http' && $skema !== 'https') {
        return false;
    }
    if (isset($bahagian['user']) || isset($bahagian['pass'])) {
        return false;
    }
    $hos = strtolower((string) ($bahagian['host'] ?? ''));
    if ($hos === '' || $hos === 'localhost' || str_ends_with($hos, '.local') || str_ends_with($hos, '.internal')) {
        return false;
    }
    $port = (int) ($bahagian['port'] ?? ($skema === 'https' ? 443 : 80));
    if ($port !== 80 && $port !== 443) {
        return false;
    }
    if (filter_var($hos, FILTER_VALIDATE_IP)) {
        return ip_awam($hos);
    }
    $rekod = @dns_get_record($hos, DNS_A + DNS_AAAA);
    if (!is_array($rekod) || $rekod === []) {
        $ip = gethostbyname($hos);
        return $ip !== $hos && ip_awam($ip);
    }
    $ada = false;
    foreach ($rekod as $satu) {
        if (!is_array($satu)) {
            continue;
        }
        $ip = (string) ($satu['ip'] ?? $satu['ipv6'] ?? '');
        if ($ip === '') {
            continue;
        }
        if (!ip_awam($ip)) {
            return false;
        }
        $ada = true;
    }
    return $ada;
}

function ip_awam(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function teks_dari_html(string $html): string
{
    $html = preg_replace('#<(script|style|noscript|svg|iframe|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $teks = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $teks = str_replace(["\r\n", "\r"], "\n", $teks);
    $teks = preg_replace("/[ \t\f\v]+/", ' ', $teks) ?? $teks;
    $baris = [];
    foreach (preg_split("/\n/", $teks) ?: [] as $satu) {
        $satu = trim((string) $satu);
        if ($satu !== '') {
            $baris[] = $satu;
        }
    }
    $bersih = implode("\n", $baris);
    if (strlen($bersih) < 40) {
        throw new InvalidArgumentException('Laman itu terlalu pendek untuk dilatih.');
    }
    return meja_potong($bersih, 7000);
}

function ambil_laman(string $url): string
{
    $url = trim($url);
    if (!hos_awam($url)) {
        throw new InvalidArgumentException('Pautan itu tidak boleh digunakan. Guna pautan https yang terbuka.');
    }
    $html = http_ambil($url);
    if (!hos_awam($url)) {
        throw new InvalidArgumentException('Pautan itu tidak boleh digunakan.');
    }
    return teks_dari_html($html);
}

function http_ambil(string $url): string
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Laman tidak dapat dibuka.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'BeShareWhatsAppAI/1.0',
        ]);
        $badan = curl_exec($curl);
        $akhir = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        $kod = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($badan) || $badan === '' || $kod < 200 || $kod >= 300 || !hos_awam($akhir)) {
            throw new RuntimeException('Laman tidak dapat dibaca.');
        }
        return substr($badan, 0, 400000);
    }
    return http_ambil_tanpa_curl($url, 0);
}

function http_ambil_tanpa_curl(string $url, int $lompat): string
{
    if (!hos_awam($url)) {
        throw new InvalidArgumentException('Pautan itu tidak boleh digunakan.');
    }
    $konteks = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'follow_location' => 0,
            'ignore_errors' => true,
            'header' => "User-Agent: BeShareWhatsAppAI/1.0\r\n",
        ],
    ]);
    $badan = @file_get_contents($url, false, $konteks, 0, 400000);
    $status = 0;
    $lokasi = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $baris) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $baris, $padan)) {
                $status = (int) $padan[1];
            }
            if (stripos((string) $baris, 'Location:') === 0) {
                $lokasi = trim(substr((string) $baris, 9));
            }
        }
    }
    if ($status >= 300 && $status < 400 && $lokasi !== '' && $lompat < 3) {
        if (str_starts_with($lokasi, '/')) {
            $bahagian = parse_url($url);
            $lokasi = ($bahagian['scheme'] ?? 'https') . '://' . ($bahagian['host'] ?? '') . $lokasi;
        }
        return http_ambil_tanpa_curl($lokasi, $lompat + 1);
    }
    if (!is_string($badan) || $badan === '' || $status < 200 || $status >= 300) {
        throw new RuntimeException('Laman tidak dapat dibaca.');
    }
    return $badan;
}

function latih_meja(string $url): array
{
    $teks = ambil_laman($url);
    $skrip = "Pembantu jualan WhatsApp untuk " . MEJA_PAPARAN . ".\n"
        . "Balas pendek dalam Bahasa Melayu, hanya kepada orang yang menulis dahulu.\n\n"
        . "Maklumat laman " . $url . ":\n" . $teks;
    return dengan_meja(function (array $data) use ($url, $teks, $skrip): array {
        $data['website_url'] = meja_potong($url, 500);
        $data['laman_teks'] = $teks;
        $data['prompt'] = meja_potong($skrip, 8000);
        return $data;
    });
}

function simpan_bank_meja(string $nama, string $akaun, string $pemegang, string $qr): array
{
    $nama = meja_potong($nama, 80);
    $akaun = meja_potong($akaun, 40);
    $pemegang = meja_potong($pemegang, 80);
    if ($nama === '' || $akaun === '' || $pemegang === '') {
        throw new InvalidArgumentException('Isi nama bank, nombor akaun, dan nama pemegang.');
    }
    return dengan_meja(function (array $data) use ($nama, $akaun, $pemegang, $qr): array {
        $data['bank_name'] = $nama;
        $data['account_no'] = $akaun;
        $data['account_holder'] = $pemegang;
        if ($qr !== '') {
            $data['qr_url'] = $qr;
        }
        return $data;
    });
}

function simpan_token_meja(string $token): array
{
    $token = trim($token);
    if (!token_meta_sah($token)) {
        throw new InvalidArgumentException('Token Meta tidak lengkap. Jana semula dalam app Meta, kemudian tampal di sini.');
    }
    return dengan_meja(function (array $data) use ($token): array {
        $data['token_meta'] = $token;
        return $data;
    });
}

function simpan_skrip_meja(string $skrip): array
{
    $skrip = meja_potong($skrip, 8000);
    if ($skrip === '') {
        throw new InvalidArgumentException('Skrip kosong.');
    }
    return dengan_meja(function (array $data) use ($skrip): array {
        $data['prompt'] = $skrip;
        return $data;
    });
}

function token_meja(): string
{
    return trim((string) (baca_meja()['token_meta'] ?? ''));
}

function ayat_bank(array $meja): string
{
    $nama = trim((string) ($meja['bank_name'] ?? ''));
    $akaun = trim((string) ($meja['account_no'] ?? ''));
    $pemegang = trim((string) ($meja['account_holder'] ?? ''));
    if ($nama === '' || $akaun === '' || $pemegang === '') {
        return '';
    }
    $ayat = "Bayaran:\nBank: {$nama}\nNo. akaun: {$akaun}\nNama: {$pemegang}";
    $qr = trim((string) ($meja['qr_url'] ?? ''));
    if ($qr !== '') {
        $ayat .= "\nQR: {$qr}";
    }
    return $ayat;
}

function sumber_jawapan(array $meja): string
{
    $kumpulan = [
        (string) ($meja['prompt'] ?? ''),
        (string) ($meja['laman_teks'] ?? ''),
    ];
    $berguna = [];
    foreach ($kumpulan as $teks) {
        foreach (preg_split("/\n/", $teks) ?: [] as $baris) {
            $baris = trim((string) $baris);
            if ($baris === '' || strlen($baris) < 12) {
                continue;
            }
            if (preg_match('/^(anda|tugas|syarat|jangan|dilarang|peranan|pembantu jualan|balas pendek)\b/i', $baris)) {
                continue;
            }
            $berguna[] = $baris;
        }
    }
    return implode("\n", $berguna);
}

function bina_balasan(string $soalan, array $meja): string
{
    $soalan = trim($soalan);
    $bank = ayat_bank($meja);
    $nakBank = $bank !== '' && preg_match('/bank|bayar|transfer|akaun|qr|duitnow|resit/i', $soalan) === 1;
    $sumber = sumber_jawapan($meja);
    if ($sumber === '') {
        $asas = 'Hai, terima kasih kerana menulis kepada BeShare AI Solution. Saya pembantu WhatsApp rasmi. Apa soalan jualan yang boleh saya bantu?';
        return $nakBank ? $asas . "\n\n" . $bank : $asas;
    }
    $petikan = petik_berkaitan($sumber, $soalan);
    $balas = 'Hai. ' . $petikan;
    if ($nakBank) {
        $balas .= "\n\n" . $bank;
    }
    return meja_potong($balas, 900);
}

function petik_berkaitan(string $teks, string $soalan): string
{
    $ayat = preg_split('/(?<=[\.\!\?\n])\s+/u', $teks) ?: [];
    $kata = preg_split('/\s+/u', mb_strtolower($soalan)) ?: [];
    $kata = array_values(array_filter($kata, static function (string $k): bool {
        return mb_strlen($k) >= 4;
    }));
    $pilih = [];
    foreach ($ayat as $baris) {
        $baris = trim((string) $baris);
        if (mb_strlen($baris) < 20) {
            continue;
        }
        $rendah = mb_strtolower($baris);
        $skor = 0;
        foreach ($kata as $k) {
            if (mb_strpos($rendah, $k) !== false) {
                $skor++;
            }
        }
        if ($skor > 0 && preg_match('/\bRM\s?\d/i', $baris) && preg_match('/harga|pakej|beli|berapa|price/i', $soalan)) {
            $skor += 3;
        }
        if ($skor > 0) {
            $pilih[] = [$skor, $baris];
        }
    }
    usort($pilih, static function (array $a, array $b): int {
        return $b[0] <=> $a[0];
    });
    $ambil = [];
    foreach (array_slice($pilih, 0, 3) as $item) {
        $ambil[] = $item[1];
    }
    if ($ambil === []) {
        $ambil[] = meja_potong(trim($teks), 420);
    }
    return meja_potong(implode("\n", $ambil), 700);
}

function hantar_whatsapp(string $phoneId, string $token, string $kepada, string $teks): void
{
    $badan = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $kepada,
        'type' => 'text',
        'text' => ['body' => $teks],
    ], JSON_UNESCAPED_UNICODE);
    if ($badan === false) {
        return;
    }
    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($phoneId) . '/messages';
    $header = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            return;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $badan,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($curl);
        curl_close($curl);
        return;
    }
    $konteks = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $header) . "\r\n",
            'content' => $badan,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $konteks);
}
