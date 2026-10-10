<?php
declare(strict_types=1);

const HASH_PEMILIK = '$2y$10$aWfJjOH0kBUvwTiBFD6e1.28vFvFcNgIEUr68CvXKA42rrGM.XEai';
const HASH_CEO = '$2y$10$1InhZkZeUTLubwsmUQJ9IO9B1Q0AYMIJau6A8/vGf9q2mBl6LyUKi';
const HASH_KOSONG = '$2y$10$JspQbz5mtPnKZwoUg/2UD.uEAv5/R/KqvMDi9BAZbAn1PGBib8eYC';

const PERANAN = [
    'pemilik' => ['label' => 'Pemilik', 'hash' => HASH_PEMILIK],
    'ceo' => ['label' => 'CEO', 'nama' => "Dato' Anwar", 'hash' => HASH_CEO],
];

function e(string $nilai): string
{
    return htmlspecialchars($nilai, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function laluan_stor(): string
{
    $khas = getenv('BESHARE_STOR');
    if (is_string($khas) && $khas !== '') {
        return $khas;
    }
    return __DIR__ . '/stor';
}

function mula_sesi(string $nama): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name($nama);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function kepala_selamat(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function token_csrf(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function sahkan_csrf(): void
{
    $diberi = $_POST['csrf'] ?? '';
    $sah = $_SESSION['csrf'] ?? '';
    if (!is_string($diberi) || !is_string($sah) || $sah === '' || !hash_equals($sah, $diberi)) {
        http_response_code(400);
        exit('Permintaan tidak sah.');
    }
}

function kilat(string $jenis, string $teks): void
{
    $_SESSION['kilat'] = ['jenis' => $jenis, 'teks' => $teks];
}

function ambil_kilat(): ?array
{
    $kilat = $_SESSION['kilat'] ?? null;
    unset($_SESSION['kilat']);
    if (!is_array($kilat) || !isset($kilat['teks'])) {
        return null;
    }
    return $kilat;
}

function pergi(string $ke): void
{
    header('Location: ' . $ke, true, 303);
    exit;
}

function admin_semasa(): ?array
{
    $kod = $_SESSION['peranan'] ?? '';
    if (!is_string($kod) || !isset(PERANAN[$kod])) {
        return null;
    }
    $peranan = PERANAN[$kod];
    $peranan['kod'] = $kod;
    return $peranan;
}

function cuba_masuk(string $kod, string $kata): bool
{
    $kunci = $_SESSION['kunci_masa'] ?? 0;
    if (is_int($kunci) && $kunci > time()) {
        return false;
    }
    $hash = PERANAN[$kod]['hash'] ?? HASH_KOSONG;
    $sah = password_verify($kata, $hash) && isset(PERANAN[$kod]);
    if ($sah) {
        session_regenerate_id(true);
        $_SESSION['peranan'] = $kod;
        $_SESSION['cubaan'] = 0;
        unset($_SESSION['kunci_masa'], $_SESSION['csrf']);
        return true;
    }
    $cubaan = (int) ($_SESSION['cubaan'] ?? 0) + 1;
    $_SESSION['cubaan'] = $cubaan;
    if ($cubaan >= 8) {
        $_SESSION['kunci_masa'] = time() + 600;
        $_SESSION['cubaan'] = 0;
    }
    return false;
}

function data_kosong(): array
{
    return ['pelanggan' => [], 'seterusnya' => 1];
}

function baca_data(): array
{
    $path = laluan_stor() . '/data.json';
    if (!is_file($path)) {
        return data_kosong();
    }
    $mentah = file_get_contents($path);
    $data = json_decode(is_string($mentah) ? $mentah : '', true);
    if (!is_array($data) || !isset($data['pelanggan']) || !is_array($data['pelanggan'])) {
        return data_kosong();
    }
    return $data;
}

function dengan_data(callable $ubah): array
{
    $dir = laluan_stor();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Stor tidak boleh dibuka.');
    }
    $path = $dir . '/data.json';
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
        if (!is_array($data) || !isset($data['pelanggan']) || !is_array($data['pelanggan'])) {
            $data = data_kosong();
        }
        $data = $ubah($data);
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

function potong(string $nilai, int $had): string
{
    $nilai = trim($nilai);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($nilai) > $had) {
            return mb_substr($nilai, 0, $had);
        }
        return $nilai;
    }
    if (preg_match('/^.{0,' . $had . '}/us', $nilai, $padan)) {
        return $padan[0];
    }
    return $nilai;
}

function cipta_kunci(): string
{
    return 'bsh_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function awalan_kunci(string $kunci): string
{
    return substr($kunci, 0, 12);
}

function cipta_pelanggan(string $nama, string $kedai, string $penerangan, string $produk, string $ucapan): array
{
    $nama = potong($nama, 120);
    $kedai = potong($kedai, 120);
    if ($nama === '' || $kedai === '') {
        throw new InvalidArgumentException('Isi nama pelanggan dan nama kedai.');
    }
    $kunci = cipta_kunci();
    $id = 0;
    dengan_data(function (array $data) use ($nama, $kedai, $penerangan, $produk, $ucapan, $kunci, &$id): array {
        $id = (int) ($data['seterusnya'] ?? 1);
        $data['seterusnya'] = $id + 1;
        $data['pelanggan'][] = [
            'id' => $id,
            'nama' => $nama,
            'nama_kedai' => $kedai,
            'penerangan' => potong($penerangan, 2000),
            'produk' => potong($produk, 4000),
            'ucapan' => potong($ucapan, 300),
            'kunci_cincang' => hash('sha256', $kunci),
            'awalan' => awalan_kunci($kunci),
            'dicipta' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('j/n/Y, H:i'),
            'phone_number_id' => '',
            'paparan' => '',
            'token_meta' => '',
        ];
        return $data;
    });
    return ['id' => $id, 'kunci' => $kunci, 'nama' => $nama, 'nama_kedai' => $kedai];
}

function ikat_nombor(int $id, string $phoneId, string $paparan, string $token): void
{
    $phoneId = potong($phoneId, 32);
    $paparan = potong($paparan, 30);
    $token = trim($token);
    if ($id < 1 || $phoneId === '' || $token === '' || mb_strlen($token) > 800) {
        throw new InvalidArgumentException('Isi ID pelanggan, Phone number ID, dan token.');
    }
    if (!preg_match('/^\d{6,24}$/', $phoneId)) {
        throw new InvalidArgumentException('Phone number ID tidak sah.');
    }
    $jumpa = false;
    dengan_data(function (array $data) use ($id, $phoneId, $paparan, $token, &$jumpa): array {
        foreach ($data['pelanggan'] as $i => $pelanggan) {
            if ((int) ($pelanggan['id'] ?? 0) === $id) {
                $data['pelanggan'][$i]['phone_number_id'] = $phoneId;
                $data['pelanggan'][$i]['paparan'] = $paparan;
                $data['pelanggan'][$i]['token_meta'] = $token;
                $jumpa = true;
                break;
            }
        }
        return $data;
    });
    if (!$jumpa) {
        throw new InvalidArgumentException('Pelanggan tidak dijumpai.');
    }
}

function senarai_awam(): array
{
    $senarai = [];
    foreach (baca_data()['pelanggan'] as $pelanggan) {
        if (!is_array($pelanggan)) {
            continue;
        }
        $senarai[] = [
            'id' => (int) ($pelanggan['id'] ?? 0),
            'nama' => (string) ($pelanggan['nama'] ?? ''),
            'nama_kedai' => (string) ($pelanggan['nama_kedai'] ?? ''),
            'awalan' => (string) ($pelanggan['awalan'] ?? ''),
            'dicipta' => (string) ($pelanggan['dicipta'] ?? ''),
            'paparan' => (string) ($pelanggan['paparan'] ?? ''),
            'diikat' => (string) ($pelanggan['phone_number_id'] ?? '') !== '',
        ];
    }
    usort($senarai, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
    return $senarai;
}

function cari_pelanggan_id(int $id): ?array
{
    foreach (baca_data()['pelanggan'] as $pelanggan) {
        if (is_array($pelanggan) && (int) ($pelanggan['id'] ?? 0) === $id) {
            return $pelanggan;
        }
    }
    return null;
}

function cari_dengan_kunci(string $kunci): ?array
{
    $kunci = trim($kunci);
    if ($kunci === '' || strlen($kunci) > 200 || !str_starts_with($kunci, 'bsh_')) {
        return null;
    }
    $cincang = hash('sha256', $kunci);
    foreach (baca_data()['pelanggan'] as $pelanggan) {
        $simpan = (string) ($pelanggan['kunci_cincang'] ?? '');
        if (strlen($simpan) === 64 && hash_equals($simpan, $cincang)) {
            return $pelanggan;
        }
    }
    return null;
}

function medan_awam(array $pelanggan): array
{
    return [
        'id' => (int) ($pelanggan['id'] ?? 0),
        'nama' => (string) ($pelanggan['nama'] ?? ''),
        'nama_kedai' => (string) ($pelanggan['nama_kedai'] ?? ''),
        'penerangan' => (string) ($pelanggan['penerangan'] ?? ''),
        'produk' => (string) ($pelanggan['produk'] ?? ''),
        'ucapan' => (string) ($pelanggan['ucapan'] ?? ''),
        'diikat' => (string) ($pelanggan['phone_number_id'] ?? '') !== '',
        'paparan' => (string) ($pelanggan['paparan'] ?? ''),
    ];
}
