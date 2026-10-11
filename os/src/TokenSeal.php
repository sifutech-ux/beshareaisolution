<?php
declare(strict_types=1);

namespace BesharOs;

final class TokenSeal
{
    public function __construct(private string $key, private int $version)
    {
        if (strlen($this->key) !== 32 || $this->version < 1 || $this->version > 9999) {
            throw new AuthException('Kunci token tidak sah.', 500);
        }
    }

    public static function tryFrom(?array $config): ?self
    {
        $encoded = getenv('BESHARE_OS_TOKEN_KEY');
        if (!is_string($encoded) || $encoded === '') {
            $encoded = is_array($config) ? (string) ($config['token_key'] ?? '') : '';
        }
        $version = getenv('BESHARE_OS_TOKEN_KEY_VERSION');
        if (!is_string($version) || $version === '') {
            $version = is_array($config) ? (string) ($config['token_key_version'] ?? '1') : '1';
        }
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== 32 || preg_match('/\A[1-9]\d{0,3}\z/', $version) !== 1) {
            return null;
        }
        return new self($key, (int) $version);
    }

    /** @return array{ciphertext:string,nonce:string,tag:string,version:int} */
    public function seal(string $plain, int $maxBytes): array
    {
        if ($plain === '' || strlen($plain) > $maxBytes) {
            throw new AuthException('Rekod tidak dapat disimpan.', 500);
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new AuthException('Rekod tidak dapat disimpan.', 500);
        }
        return [
            'ciphertext' => $cipher,
            'nonce' => $nonce,
            'tag' => $tag,
            'version' => $this->version,
        ];
    }

    public function open(string $cipher, string $nonce, string $tag, int $version): string
    {
        if ($version !== $this->version || strlen($nonce) !== 12 || strlen($tag) !== 16 || $cipher === '') {
            throw new AuthException('Rekod tidak dapat dibaca.', 500);
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($plain) || $plain === '') {
            throw new AuthException('Rekod tidak dapat dibaca.', 500);
        }
        return $plain;
    }
}
