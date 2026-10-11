<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$port = 8911;
$configPath = sys_get_temp_dir() . '/beshare-webhook-http-' . bin2hex(random_bytes(4)) . '.php';
$verify = 'ujian-verify-' . bin2hex(random_bytes(4));
$secret = 'ujian-secret-' . bin2hex(random_bytes(4));
$key = base64_encode(random_bytes(32));
file_put_contents($configPath, "<?php\nreturn ['verify_token' => " . var_export($verify, true) . ", 'app_secret' => " . var_export($secret, true) . ", 'token_key' => " . var_export($key, true) . ", 'token_key_version' => '1'];\n");
chmod($configPath, 0600);

$env = getenv();
if (!is_array($env)) {
    $env = [];
}
unset($env['BESHARE_OS_DSN'], $env['BESHARE_OS_DB_USER'], $env['BESHARE_OS_DB_PASSWORD'], $env['BESHARE_OS_DB_CONFIG']);
$env['BESHARE_OS_WEBHOOK_CONFIG'] = $configPath;
$pipes = [];
$process = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
    $pipes,
    $root,
    $env
);
if (!is_resource($process)) {
    fwrite(STDOUT, "FAIL webhook server did not start\n");
    unlink($configPath);
    exit(1);
}
$status = proc_get_status($process);
$pid = (int) ($status['pid'] ?? 0);

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    fwrite(STDOUT, ($ok ? 'OK ' : 'FAIL ') . $name . "\n");
    if (!$ok) {
        $failures++;
    }
};

$fetch = static function (string $method, string $url, string $body = '', array $headers = []) use ($verify, $secret): array {
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $payload = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    $text = is_string($payload) ? $payload : '';
    return [
        'status' => (int) ($match[1] ?? 0),
        'body' => $text,
        'leaks' => str_contains($text, $verify) || str_contains($text, $secret),
    ];
};

$base = 'http://127.0.0.1:' . $port . '/whatsapp/sambungan/webhook.php';
$ready = false;
for ($attempt = 0; $attempt < 20; $attempt++) {
    $probe = $fetch('GET', $base);
    if ($probe['status'] !== 0) {
        $ready = true;
        break;
    }
    usleep(100000);
}
$check('local webhook server answers', $ready && $probe['status'] === 403 && $probe['body'] === 'Forbidden' && $probe['leaks'] === false);

$wrong = $fetch('GET', $base . '?hub_mode=subscribe&hub_verify_token=salah&hub_challenge=12345');
$check('wrong verify token does not echo the challenge', $wrong['status'] === 403 && $wrong['body'] === 'Forbidden' && $wrong['leaks'] === false);

$markup = $fetch('GET', $base . '?hub_mode=subscribe&hub_verify_token=' . rawurlencode($verify) . '&hub_challenge=' . rawurlencode('<script>'));
$check('non numeric challenge is not reflected', $markup['status'] === 403 && !str_contains($markup['body'], '<script>') && $markup['leaks'] === false);

$challenge = $fetch('GET', $base . '?hub_mode=subscribe&hub_verify_token=' . rawurlencode($verify) . '&hub_challenge=48291');
$check('subscription challenge echoes only the numeric value', $challenge['status'] === 200 && $challenge['body'] === '48291' && $challenge['leaks'] === false);

$raw = '{"object":"whatsapp_business_account","entry":[]}';
$bad = $fetch('POST', $base, $raw, ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . str_repeat('ab', 32)]);
$check('invalid signature is forbidden and hides the payload', $bad['status'] === 403 && $bad['body'] === 'Forbidden' && !str_contains($bad['body'], 'whatsapp_business_account') && $bad['leaks'] === false);

$signed = $fetch('POST', $base, $raw, ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $raw, $secret)]);
$check('valid signature without a database does not expose the payload', $signed['status'] === 503 && $signed['body'] === 'Unavailable' && !str_contains($signed['body'], 'whatsapp_business_account') && $signed['leaks'] === false);

$other = $fetch('PUT', $base);
$check('unsupported method is rejected', $other['status'] === 405 && $other['body'] === 'Method not allowed' && $other['leaks'] === false);

if ($pid > 0 && function_exists('posix_kill')) {
    posix_kill($pid, 15);
}
proc_terminate($process);
if (isset($pipes[0]) && is_resource($pipes[0])) {
    fclose($pipes[0]);
}
proc_close($process);
unlink($configPath);

exit($failures === 0 ? 0 : 1);
