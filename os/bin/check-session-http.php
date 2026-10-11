<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$passwordFile = '/tmp/maria-lab.pw';
if (!is_file($passwordFile)) {
    fwrite(STDOUT, "FAIL lab password file\n");
    exit(1);
}
$password = trim((string) file_get_contents($passwordFile));
$dsn = 'mysql:host=127.0.0.1;port=3307;dbname=u879723783_beshare_os;charset=utf8mb4';
if (!str_contains($dsn, 'host=127.0.0.1')) {
    fwrite(STDOUT, "FAIL non-local database\n");
    exit(1);
}
$pdo = new PDO($dsn, 'root', $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== 'u879723783_beshare_os') {
    fwrite(STDOUT, "FAIL unexpected database\n");
    exit(1);
}

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    fwrite(STDOUT, ($ok ? 'OK ' : 'FAIL ') . $name . "\n");
    if (!$ok) {
        $failures++;
    }
};

$port = 8878;
$rateDir = '/tmp/beshare-os-rate-verify';
if (is_dir($rateDir)) {
    foreach (glob($rateDir . '/*.json') ?: [] as $old) {
        unlink($old);
    }
}
$log = '/tmp/beshare-session-http.log';
$command = 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root);
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['file', $log, 'w'],
    2 => ['file', $log, 'a'],
], $pipes, $root, [
    'PATH' => (string) getenv('PATH'),
    'BESHARE_OS_DSN' => $dsn,
    'BESHARE_OS_DB_USER' => 'root',
    'BESHARE_OS_DB_PASSWORD' => $password,
    'BESHARE_OS_RATE_DIR' => $rateDir,
]);
if (!is_resource($process)) {
    fwrite(STDOUT, "FAIL server process\n");
    exit(1);
}
$ready = false;
for ($i = 0; $i < 30; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/whatsapp/akaun/pintu.php?tindakan=sesi');
    if (is_string($probe) && str_contains($probe, '"csrf"')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
$check('local api server started', $ready);
if (!$ready) {
    proc_terminate($process);
    exit(1);
}

$base = 'http://127.0.0.1:' . $port . '/whatsapp/akaun/pintu.php?tindakan=';
$suffix = bin2hex(random_bytes(3));
$ownerEmail = 'sesi' . $suffix . '@kedai.test';
$staffEmail = 'staf' . $suffix . '@kedai.test';
$ownerPassword = 'kata-laluan-yang-panjang';

$call = static function (string $action, ?array $body, string $jar, array $headers = []) use ($base): array {
    $headerFile = tempnam(sys_get_temp_dir(), 'hdr');
    $args = [
        'curl', '-sS', '-D', $headerFile, '-b', $jar, '-c', $jar,
        '-H', 'Accept: application/json',
    ];
    foreach ($headers as $header) {
        $args[] = '-H';
        $args[] = $header;
    }
    if ($body !== null) {
        $args[] = '-H';
        $args[] = 'Content-Type: application/json';
        $args[] = '--data';
        $args[] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $args[] = $base . $action;
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $curl = proc_open($args, $spec, $pipes);
    $raw = is_resource($curl) ? stream_get_contents($pipes[1]) : '';
    if (is_resource($curl)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($curl);
    }
    $headersText = is_string($headerFile) && is_file($headerFile) ? (string) file_get_contents($headerFile) : '';
    if (is_string($headerFile) && is_file($headerFile)) {
        unlink($headerFile);
    }
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $headersText, $match) === 1) {
        $status = (int) $match[1];
    }
    $decoded = json_decode($raw, true);
    return [$status, is_array($decoded) ? $decoded : [], $headersText];
};

$cookieValue = static function (string $jar): string {
    $text = is_file($jar) ? (string) file_get_contents($jar) : '';
    if (preg_match('/beshare_os\s+(\S+)/', $text, $match) === 1) {
        return $match[1];
    }
    return '';
};

$before = tempnam(sys_get_temp_dir(), 'jar');
[$status, $session] = $call('sesi', null, (string) $before);
$beforeId = $cookieValue((string) $before);
$check('first visit sets a session cookie', $status === 200 && $session['user'] === null && $beforeId !== '');

$after = tempnam(sys_get_temp_dir(), 'jar');
copy((string) $before, (string) $after);
[$status, $created, $createdHeaders] = $call('daftar', [
    'name' => 'Pemilik Sesi',
    'email' => $ownerEmail,
    'password' => $ownerPassword,
    'business' => 'Kedai Sesi',
    'business_id' => 999,
], (string) $after, ['X-CSRF-Token: ' . (string) $session['csrf']]);
$afterId = $cookieValue((string) $after);
$check('register returns the owner', $status === 200 && ($created['user']['email'] ?? '') === $ownerEmail && ($created['user']['role'] ?? '') === 'owner');
$check('register changes the session id', $afterId !== '' && $afterId !== $beforeId);

[$staleStatus, $staleBody] = $call('sesi', null, (string) $before);
$check('pre-login session is not authenticated', $staleStatus === 200 && array_key_exists('user', $staleBody) && $staleBody['user'] === null);

$staffJar = tempnam(sys_get_temp_dir(), 'jar');
[$staffAnonStatus, $staffAnon] = $call('sesi', null, (string) $staffJar);
[$staffAddStatus] = $call('ahli', [
    'name' => 'Staf Sesi',
    'email' => $staffEmail,
    'password' => 'staf-laluan-panjang',
    'role' => 'staff',
    'business_id' => 1,
], (string) $after, ['X-CSRF-Token: ' . (string) $created['csrf']]);
$check('owner invite uses the new session', $staffAddStatus === 200);

$loginJar = tempnam(sys_get_temp_dir(), 'jar');
copy((string) $staffJar, (string) $loginJar);
$loginBefore = $cookieValue((string) $loginJar);
[$loginStatus, $loginBody] = $call('masuk', [
    'email' => strtoupper($staffEmail),
    'password' => 'staf-laluan-panjang',
], (string) $loginJar, [
    'X-CSRF-Token: ' . (string) $staffAnon['csrf'],
    'X-Forwarded-For: 203.0.113.50',
]);
$loginAfter = $cookieValue((string) $loginJar);
$check('login regenerates the session id', $loginStatus === 200 && ($loginBody['user']['role'] ?? '') === 'staff' && $loginAfter !== '' && $loginAfter !== $loginBefore);
[$oldLoginStatus, $oldLoginBody] = $call('sesi', null, (string) $staffJar);
$check('previous login cookie is not authenticated', $oldLoginStatus === 200 && array_key_exists('user', $oldLoginBody) && $oldLoginBody['user'] === null);

$logoutJar = tempnam(sys_get_temp_dir(), 'jar');
copy((string) $loginJar, (string) $logoutJar);
[$logoutStatus, $logoutBody] = $call('keluar', [], (string) $logoutJar, ['X-CSRF-Token: ' . (string) $loginBody['csrf']]);
$check('logout response has no user', $logoutStatus === 200 && array_key_exists('user', $logoutBody) && $logoutBody['user'] === null);
[$replayStatus, $replayBody] = $call('sesi', null, (string) $loginJar);
[$afterLogoutStatus, $afterLogoutBody] = $call('sesi', null, (string) $logoutJar);
$check('logout invalidates the authenticated cookie', $replayStatus === 200 && array_key_exists('user', $replayBody) && $replayBody['user'] === null && array_key_exists('user', $afterLogoutBody) && $afterLogoutBody['user'] === null);

$rateSession = tempnam(sys_get_temp_dir(), 'jar');
[$rateAnonStatus, $rateAnon] = $call('sesi', null, (string) $rateSession);
$variants = [
    strtoupper($ownerEmail),
    ' ' . $ownerEmail . ' ',
    ucfirst($ownerEmail),
    strtoupper($ownerEmail),
    '  ' . strtoupper($ownerEmail),
];
$wrong = [];
foreach ($variants as $index => $variant) {
    $headers = ['X-CSRF-Token: ' . (string) $rateAnon['csrf']];
    if ($index === 2) {
        $headers[] = 'X-Forwarded-For: 198.51.100.20';
    }
    $wrong[] = $call('masuk', ['email' => $variant, 'password' => 'salah-salah-salah'], (string) $rateSession, $headers)[0];
    $rateAnon['csrf'] = $wrong === [] ? $rateAnon['csrf'] : $rateAnon['csrf'];
}
[$lockedStatus] = $call('masuk', [
    'email' => ' ' . strtoupper($ownerEmail) . ' ',
    'password' => $ownerPassword,
], (string) $rateSession, [
    'X-CSRF-Token: ' . (string) $rateAnon['csrf'],
    'X-Forwarded-For: 198.51.100.77',
]);
$check('forwarded address does not reset the login bucket', $wrong === [401, 401, 401, 401, 401] && $lockedStatus === 429);

$files = glob($rateDir . '/*.json') ?: [];
$private = $files !== [];
foreach ($files as $file) {
    $text = (string) file_get_contents($file);
    if ((fileperms($file) & 0777) !== 0600 || str_contains($text, $ownerPassword) || str_contains($text, '@') || !is_array(json_decode($text, true))) {
        $private = false;
    }
}
$dirPrivate = is_dir($rateDir) && (fileperms($rateDir) & 0777) === 0700;
$check('rate storage stays outside the response and is private', $private && $dirPrivate);

proc_terminate($process);
proc_close($process);
foreach ([$before, $after, $staffJar, $loginJar, $logoutJar, $rateSession] as $jar) {
    if (is_string($jar) && is_file($jar)) {
        unlink($jar);
    }
}

exit($failures === 0 ? 0 : 1);
