<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/AuthException.php';
require_once __DIR__ . '/../src/KnowledgeSource.php';

use BesharOs\AuthException;
use BesharOs\KnowledgeSource;

// Synthetic DNS/HTTP only: these tests must never contact external pages.
function knowledgeSourceCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function knowledgeSourceDenied(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (AuthException $error) {
        knowledgeSourceCheck($error->status() === 400 && $error->getMessage() !== '', $label . ' has safe user error');
        return;
    }
    throw new RuntimeException($label . ' was accepted');
}

$validBody = '<html><head><title>Kedai Contoh</title></head><body><main><h1>Kedai Contoh</h1><p>Kami menyediakan produk contoh dan kombo untuk pelanggan. Waktu operasi setiap hari dari sembilan pagi hingga lima petang.</p></main></body></html>';
$dnsCalls = 0;
$httpCalls = 0;
$dns = static function (string $host) use (&$dnsCalls): array {
    $dnsCalls++;
    knowledgeSourceCheck($host === 'shop.example.com', 'DNS gets parsed host only');
    return ['93.184.216.34', '2606:4700:4700::1111'];
};
$http = static function (string $url, string $host, string $ip) use (&$httpCalls, $validBody): array {
    $httpCalls++;
    knowledgeSourceCheck($url === 'https://shop.example.com/products?ref=owner', 'Exact validated URL');
    knowledgeSourceCheck($host === 'shop.example.com' && $ip === '93.184.216.34', 'Fetch receives checked pinned IP');
    return ['status' => 200, 'content_type' => 'text/html; charset=utf-8', 'body' => $validBody];
};
$source = new KnowledgeSource($dns, $http);
foreach (['http://shop.example.com', 'https://name:secret@shop.example.com/', 'https://shop.example.com/#part',
    'https://shop.example.com:8443/', 'https://127.0.0.1/', 'https://127.1/', 'https://2130706433/',
    'https://0x7f000001/', 'https://[::1]/', 'https://metadata.google.internal/', 'https://home.local/',
    'https://shop.localhost/', 'https://shop.example.com\\@127.0.0.1/', "https://shop.example.com/\r\nHeader: injected",
    'https://%31%32%37.0.0.1/', 'file:///etc/passwd', 'https://shop.example.com./'] as $url) {
    knowledgeSourceDenied(static fn () => $source->import($url), 'Reject URL ' . $url);
}
knowledgeSourceCheck($dnsCalls === 0 && $httpCalls === 0, 'Invalid URLs trigger neither DNS nor HTTP');
$result = $source->import('https://SHOP.EXAMPLE.COM:443/products?ref=owner');
knowledgeSourceCheck($dnsCalls === 1 && $httpCalls === 1 && $result['title'] === 'Kedai Contoh'
    && str_contains($result['text'], 'Waktu operasi'), 'One public page produces draft');

$neverHttp = static function () use (&$httpCalls): array {
    $httpCalls++;
    throw new RuntimeException('Unsafe DNS must never reach HTTP');
};
foreach (['0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1', '192.0.0.1',
    '192.0.2.1', '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1',
    '255.255.255.255', '::1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1',
    '2001::1', '2002:7f00:1::', '3ffe::1', '3fff::1', 'not-an-ip'] as $address) {
    $unsafe = new KnowledgeSource(static fn (): array => ['93.184.216.34', $address], $neverHttp);
    knowledgeSourceDenied(static fn () => $unsafe->import('https://shop.example.com/'), 'Mixed DNS rejects ' . $address);
}
knowledgeSourceCheck($httpCalls === 1, 'All DNS answers checked before any network');
foreach ([[], ['address' => '93.184.216.34'], array_fill(0, 33, '93.184.216.34')] as $answers) {
    $unsafe = new KnowledgeSource(static fn (): array => $answers, $neverHttp);
    knowledgeSourceDenied(static fn () => $unsafe->import('https://shop.example.com/'), 'Reject invalid DNS result');
}

$publicDns = static fn (): array => ['93.184.216.34'];
foreach ([
    ['status' => 302, 'content_type' => 'text/html', 'body' => $validBody, 'location' => 'http://127.0.0.1/'],
    ['status' => 403, 'content_type' => 'text/html', 'body' => $validBody],
    ['status' => 200, 'content_type' => 'application/json', 'body' => '{"private":"content"}'],
    ['status' => 200, 'content_type' => 'text/html', 'body' => str_repeat('a', 524289)],
    ['status' => 200, 'content_type' => 'text/html', 'body' => "invalid\xffUTF8"],
    ['status' => 200, 'content_type' => 'text/html', 'body' => "null\0body"],
    ['status' => 200, 'content_type' => 'text/html', 'body' => '<main>Please enable JavaScript</main>'],
    ['status' => 200, 'content_type' => 'text/html', 'body' => '<title>Log in to Facebook</title>' . $validBody],
    ['status' => 200, 'content_type' => 'text/html', 'body' => '<title>Just a moment...</title>' . $validBody],
    ['status' => 200, 'content_type' => 'text/html', 'body' => '<main>Log in to Facebook to continue. Please enter your password to view this business page.</main>'],
] as $response) {
    $calls = 0;
    $blocked = new KnowledgeSource($publicDns, static function () use ($response, &$calls): array {
        $calls++;
        return $response;
    });
    knowledgeSourceDenied(static fn () => $blocked->import('https://shop.example.com/'), 'Reject unreadable response');
    knowledgeSourceCheck($calls === 1, 'Never follows redirects or retries response');
}
$broken = new KnowledgeSource($publicDns, static fn () => throw new RuntimeException('secret-token-internal-path'));
try {
    $broken->import('https://shop.example.com/');
    throw new RuntimeException('Transport failure accepted');
} catch (AuthException $error) {
    knowledgeSourceCheck(!str_contains($error->getMessage(), 'secret'), 'Transport exception details remain private');
}

$html = '<!doctype html><html><head><title>Kedai &amp; Kombo</title><script>SECRET_SCRIPT</script></head><body>'
    . '<nav>SECRET_NAV</nav><main><h1>Maklumat perniagaan</h1><p>Kami menjual produk asli dan kombo pilihan untuk pelanggan setiap hari.</p>'
    . '<p>Penghantaran <strong>tersedia</strong> ke seluruh Malaysia.</p><div hidden>SECRET_HIDDEN</div><div aria-hidden="true">SECRET_ARIA</div>'
    . '<div style="color:red; display: none !important">SECRET_STYLE</div><div class="card visually-hidden">SECRET_CLASS</div>'
    . '<form>SECRET_FORM<input type="password"></form><style>SECRET_CSS</style><script>SECRET_BODY</script>'
    . '<img src="http://127.0.0.1/private"><iframe src="http://169.254.169.254/">SECRET_FRAME</iframe></main><footer>SECRET_FOOTER</footer></body></html>';
$extractCalls = 0;
$extractor = new KnowledgeSource($publicDns, static function () use ($html, &$extractCalls): array {
    $extractCalls++;
    return ['status' => 200, 'content_type' => 'text/html', 'body' => $html];
});
$clean = $extractor->import('https://shop.example.com/');
knowledgeSourceCheck($extractCalls === 1 && !str_contains($clean['text'], 'SECRET') && !str_contains($clean['text'], '<')
    && $clean['title'] === 'Kedai & Kombo' && str_contains($clean['text'], "hari.\n\nPenghantaran tersedia ke seluruh Malaysia."), 'Visible plain text only, no subresource fetch');

$long = '<html><head><title>' . str_repeat('é', 200) . '</title></head><body><main>'
    . str_repeat('Produk asli 😊 untuk pelanggan. ', 500) . '</main></body></html>';
$bounded = new KnowledgeSource($publicDns, static fn (): array => ['status' => 200, 'content_type' => 'text/html', 'body' => $long]);
$limited = $bounded->import('https://shop.example.com/');
knowledgeSourceCheck(strlen($limited['title']) <= 160 && strlen($limited['text']) <= 8000
    && strlen($limited['text']) > 7900 && preg_match('//u', $limited['title']) === 1 && preg_match('//u', $limited['text']) === 1,
    'Title and imported draft have UTF-8-safe byte bounds');
$plain = new KnowledgeSource(static fn (): array => ['2606:4700:4700::1111'],
    static fn (): array => ['status' => 200, 'content_type' => 'text/plain', 'body' => 'Kami menyediakan produk contoh untuk pelanggan. Semua harga perlu dirujuk kepada pasukan jualan.']);
knowledgeSourceCheck($plain->import('https://shop.example.com/')['title'] === 'shop.example.com', 'Public IPv6 and plain text supported');

echo "Knowledge source checks passed.\n";
