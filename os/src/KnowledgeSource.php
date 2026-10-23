<?php
declare(strict_types=1);

namespace BesharOs;

/** A single owner-requested public page becomes an editable draft, never active knowledge. */
final class KnowledgeSource
{
    private const BODY_BYTES = 524288;
    private const TEXT_BYTES = 8000;
    private const TITLE_BYTES = 160;
    private \Closure $dns;
    private \Closure $http;

    /**
     * Test-only injection: DNS receives host and returns all IP addresses; HTTP receives
     * (url, host, pinnedIp) and returns status, content_type and body. No network is
     * used by the synthetic tests. The default transport never follows redirects.
     */
    public function __construct(?callable $dns = null, ?callable $http = null)
    {
        $this->dns = \Closure::fromCallable($dns ?? [$this, 'resolve']);
        $this->http = \Closure::fromCallable($http ?? [$this, 'request']);
    }

    /** @return array{url:string,title:string,text:string} */
    public function import(string $url): array
    {
        [$url, $host] = $this->url($url);
        try {
            $addresses = ($this->dns)($host);
            if (!is_array($addresses) || !array_is_list($addresses)
                || $addresses === [] || count($addresses) > 32) {
                throw new AuthException('Alamat laman tidak dapat disahkan. Sila tampal kandungannya secara manual.');
            }
            foreach ($addresses as $address) {
                if (!is_string($address) || !$this->publicIp($address)) {
                    throw new AuthException('Pautan ini tidak menuju ke laman awam yang dibenarkan.');
                }
            }
            // Every DNS answer has been checked. Pin one answer for both connection
            // and TLS host verification; a second DNS lookup cannot redirect this fetch.
            $response = ($this->http)($url, $host, $addresses[0]);
            if (!is_array($response) || !is_int($response['status'] ?? null)
                || !is_string($response['body'] ?? null) || !is_string($response['content_type'] ?? null)
                || strlen($response['body']) > self::BODY_BYTES) {
                throw new AuthException('Kandungan laman terlalu besar atau tidak dapat dibaca. Sila tampal teks penting secara manual.');
            }
            if ($response['status'] >= 300 && $response['status'] < 400) {
                throw new AuthException('Pautan ini menghala ke alamat lain. Buka laman tersebut dan tampal alamat akhir, atau tampal kandungannya secara manual.');
            }
            if ($response['status'] !== 200) {
                throw new AuthException('Laman ini tidak boleh dibaca secara terbuka. Untuk Facebook atau iklan, sila tampal teks perniagaan secara manual.');
            }
            $type = strtolower(trim(explode(';', $response['content_type'], 2)[0]));
            if (!in_array($type, ['text/html', 'application/xhtml+xml', 'text/plain'], true)
                || preg_match('//u', $response['body']) !== 1 || str_contains($response['body'], "\0")) {
                throw new AuthException('Format laman tidak disokong. Sila tampal teks perniagaan secara manual.');
            }
            [$title, $text] = $type === 'text/plain'
                ? [$host, $this->cleanText($response['body'])]
                : $this->html($response['body'], $host);
            $loginText = preg_match('/\b(?:log\s?in to (?:facebook|continue)|sign\s?in to continue|log masuk (?:ke facebook|untuk meneruskan)|verify (?:that )?you are human|checking your browser)\b/iu', $text) === 1;
            $social = $host === 'facebook.com' || str_ends_with($host, '.facebook.com')
                || $host === 'fb.com' || str_ends_with($host, '.fb.com')
                || $host === 'instagram.com' || str_ends_with($host, '.instagram.com');
            if (strlen($text) < 40 || preg_match_all('/[\p{L}\p{N}]+/u', $text) < 5
                || ($loginText && ($social || strlen($text) < 600))) {
                throw new AuthException('Teks perniagaan tidak dapat dibaca daripada pautan ini. Laman mungkin memerlukan log masuk atau JavaScript. Sila tampal teksnya secara manual.');
            }
            return ['url' => $url, 'title' => $this->cut($title, self::TITLE_BYTES), 'text' => $this->cut($text, self::TEXT_BYTES)];
        } catch (AuthException $error) {
            throw $error;
        } catch (\Throwable) {
            // Never return raw DNS/cURL errors, response bodies or internal paths.
            throw new AuthException('Laman tidak dapat dibaca sekarang. Sila cuba semula atau tampal teks perniagaan secara manual.');
        }
    }

    /** @return array{string,string} */
    private function url(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1
            || preg_match('//u', $url) !== 1 || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null) || !$this->host($parts['host'])
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new AuthException('Gunakan pautan HTTPS laman awam tanpa kata laluan, port khas atau bahagian #.');
        }
        $host = strtolower($parts['host']);
        $url = 'https://' . $host . ($parts['path'] ?? '/')
            . (array_key_exists('query', $parts) ? '?' . $parts['query'] : '');
        return [$url, $host];
    }

    private function host(string $host): bool
    {
        $host = strtolower($host);
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})\z/', $host) !== 1) {
            return false;
        }
        foreach (['localhost', 'local', 'internal', 'lan', 'home', 'home.arpa', 'onion', 'invalid', 'test'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return false;
            }
        }
        return true;
    }

    private function publicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $range) {
                if ($this->inRange($ip, $range)) {
                    return false;
                }
            }
            return true;
        }
        // Permit global unicast only; reject translation/tunnel and special-use ranges.
        return $this->inRange($ip, '2000::/3') && !$this->inRange($ip, '2001::/23')
            && !$this->inRange($ip, '2001:db8::/32') && !$this->inRange($ip, '2002::/16')
            && !$this->inRange($ip, '3ffe::/16') && !$this->inRange($ip, '3fff::/20');
    }

    private function inRange(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $address = inet_pton($ip);
        $base = inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        return substr($address, 0, $whole) === substr($base, 0, $whole)
            && ($rest === 0 || (ord($address[$whole]) & (255 << (8 - $rest))) === (ord($base[$whole]) & (255 << (8 - $rest))));
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $seen = [];
        return $this->dnsRecords($host, $seen, 0);
    }

    /** @param array<string,bool> $seen @return list<string> */
    private function dnsRecords(string $host, array &$seen, int $depth): array
    {
        if ($depth > 3 || isset($seen[$host]) || !$this->host($host) || !function_exists('dns_get_record')) {
            throw new \RuntimeException('source_dns');
        }
        $seen[$host] = true;
        set_error_handler(static fn (): bool => true);
        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        } finally {
            restore_error_handler();
            error_clear_last();
        }
        if (!is_array($records) || count($records) > 32) {
            throw new \RuntimeException('source_dns');
        }
        $addresses = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'A' && is_string($record['ip'] ?? null)) {
                $addresses[] = $record['ip'];
            } elseif (($record['type'] ?? '') === 'AAAA' && is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            } elseif (($record['type'] ?? '') === 'CNAME' && is_string($record['target'] ?? null)) {
                $target = strtolower(rtrim($record['target'], '.'));
                $addresses = array_merge($addresses, $this->dnsRecords($target, $seen, $depth + 1));
            }
            if (count($addresses) > 32) {
                throw new \RuntimeException('source_dns');
            }
        }
        return array_values(array_unique($addresses));
    }

    /** @return array{status:int,content_type:string,body:string} */
    private function request(string $url, string $host, string $ip): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('source_transport');
        }
        $body = '';
        $headers = 0;
        $handle = null;
        set_error_handler(static fn (): bool => true);
        try {
            $handle = curl_init($url);
            $pin = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            if ($handle === false || !curl_setopt_array($handle, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml, text/plain;q=0.8'],
                CURLOPT_USERAGENT => 'BeShareKnowledge/1.0',
                CURLOPT_RESOLVE => [$host . ':443:' . $pin],
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    $headers += strlen($line);
                    return $headers <= 16384 ? strlen($line) : 0;
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::BODY_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ])) {
                throw new \RuntimeException('source_transport');
            }
            if (curl_exec($handle) === false || inet_pton((string) curl_getinfo($handle, CURLINFO_PRIMARY_IP)) !== inet_pton($ip)) {
                throw new \RuntimeException('source_transport');
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE), 'body' => $body];
        } finally {
            if ($handle !== null && $handle !== false) {
                curl_close($handle);
            }
            restore_error_handler();
            error_clear_last();
        }
    }

    /** @return array{string,string} */
    private function html(string $html, string $host): array
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new AuthException('Pembaca laman belum tersedia. Sila tampal teks perniagaan secara manual.');
        }
        $document = new \DOMDocument();
        $oldErrors = libxml_use_internal_errors(true);
        try {
            $ok = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($oldErrors);
        }
        if (!$ok) {
            throw new AuthException('Kandungan laman tidak dapat dibaca. Sila tampal teksnya secara manual.');
        }
        $xpath = new \DOMXPath($document);
        $title = $this->cleanText($xpath->evaluate('string(//title[1])'));
        if (preg_match('/\b(?:log\s?in|sign\s?in|log masuk|access denied|just a moment|attention required|security check|robot check|verify you are human)\b/iu', $title) === 1) {
            throw new AuthException('Laman memerlukan log masuk atau menyekat bacaan automatik. Sila tampal teks perniagaan secara manual.');
        }
        $remove = [];
        foreach ($xpath->query('//head|//script|//style|//noscript|//nav|//footer|//iframe|//object|//embed|//svg|//canvas|//template|//form|//input|//button|//select|//textarea|//*[@hidden]|//*[@aria-hidden]|//*[@style]|//*[@class]') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            $drop = in_array($tag, ['head', 'script', 'style', 'noscript', 'nav', 'footer', 'iframe', 'object', 'embed', 'svg', 'canvas', 'template', 'form', 'input', 'button', 'select', 'textarea'], true)
                || $node->hasAttribute('hidden') || strtolower(trim($node->getAttribute('aria-hidden'))) === 'true'
                || preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden|content-visibility\s*:\s*hidden)\b/i', $node->getAttribute('style')) === 1
                || preg_match('/(?:^|\s)(?:hidden|sr-only|visually-hidden)(?:\s|$)/i', $node->getAttribute('class')) === 1;
            if ($drop) {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
        $root = $xpath->query('//main[1]')->item(0) ?? $xpath->query('//*[@role="main"][1]')->item(0)
            ?? $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        $text = '';
        if ($root !== null) {
            $this->visibleText($root, $text, 0);
        }
        return [$title !== '' ? $title : $host, $this->cleanText($text)];
    }

    private function visibleText(\DOMNode $node, string &$text, int $depth): void
    {
        if (strlen($text) >= 64000 || $depth > 80) {
            return;
        }
        if ($node instanceof \DOMText) {
            $text .= $this->cut($node->nodeValue ?? '', 64000 - strlen($text));
            return;
        }
        $block = in_array(strtolower($node->nodeName), ['address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'main', 'ol', 'p', 'section', 'table', 'td', 'th', 'tr', 'ul'], true);
        if ($block) {
            $text .= "\n";
        }
        foreach ($node->childNodes as $child) {
            $this->visibleText($child, $text, $depth + 1);
        }
        if ($block && strlen($text) < 64000) {
            $text .= "\n";
        }
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/[\p{Z}\t]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */u', "\n", $text) ?? '';
        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? '');
    }

    private function cut(string $value, int $limit): string
    {
        $value = substr($value, 0, max(0, $limit));
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }
}
