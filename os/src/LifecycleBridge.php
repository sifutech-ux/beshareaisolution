<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;
use Throwable;

/**
 * Internal bridge from the app callback to the OS lifecycle processor.
 * The original body is verified before any write. Ordinary messages stay
 * in the legacy handler. Nothing is forwarded over HTTP.
 */
final class LifecycleBridge
{
    /** @var list<string> */
    private const EVENTS = ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED'];

    /**
     * @return 'ignored'|'rejected'|'stored'|'failed'
     */
    public static function deliver(string $raw, string $header, ?PDO $pdo = null, ?TokenSeal $seal = null, ?string $secret = null): string
    {
        if (!self::containsLifecycle($raw)) {
            return 'ignored';
        }
        $ready = self::credentials($seal, $secret);
        if ($ready === null) {
            return 'failed';
        }
        if (!WhatsAppWebhook::signatureMatches($raw, $header, $ready['secret'])) {
            return 'rejected';
        }
        if (!$pdo instanceof PDO) {
            try {
                $pdo = Db::connect();
            } catch (Throwable) {
                self::remember($raw, $header, $ready['seal']);
                return 'failed';
            }
        }
        $materials = ['pdo' => $pdo, 'seal' => $ready['seal'], 'secret' => $ready['secret']];
        self::recoverWith($materials);
        return self::accept($raw, $header, $materials);
    }

    /** @return array{recovered:int,waiting:int} */
    public static function recover(?PDO $pdo = null, ?TokenSeal $seal = null, ?string $secret = null): array
    {
        $ready = self::credentials($seal, $secret);
        if ($ready === null) {
            return ['recovered' => 0, 'waiting' => 0];
        }
        if (!$pdo instanceof PDO) {
            try {
                $pdo = Db::connect();
            } catch (Throwable) {
                return ['recovered' => 0, 'waiting' => 0];
            }
        }
        return self::recoverWith(['pdo' => $pdo, 'seal' => $ready['seal'], 'secret' => $ready['secret']]);
    }

    public static function containsLifecycle(string $raw): bool
    {
        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload)) {
            return false;
        }
        foreach ($payload['entry'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($entry['changes'] ?? [] as $change) {
                if (self::lifecycleChange($change)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array{pdo:PDO,seal:TokenSeal,secret:string} $materials */
    private static function accept(string $raw, string $header, array $materials): string
    {
        if (!WhatsAppWebhook::signatureMatches($raw, $header, $materials['secret'])) {
            return 'rejected';
        }
        $slice = self::lifecyclePayload($raw);
        if ($slice === null) {
            return 'rejected';
        }
        $sliceHeader = 'sha256=' . hash_hmac('sha256', $slice, $materials['secret']);
        try {
            (new WhatsAppWebhook($materials['pdo'], $materials['seal']))->ingestVerifiedLifecycle($slice, $materials['secret'], $sliceHeader);
            self::forget($raw);
            return 'stored';
        } catch (AuthException $error) {
            if ($error->status() < 500) {
                return 'rejected';
            }
        } catch (Throwable) {
        }
        self::remember($raw, $header, $materials['seal']);
        return 'failed';
    }

    /**
     * @param array{pdo:PDO,seal:TokenSeal,secret:string} $materials
     * @return array{recovered:int,waiting:int}
     */
    private static function recoverWith(array $materials): array
    {
        $directory = self::spoolDirectory(false);
        if ($directory === null) {
            return ['recovered' => 0, 'waiting' => 0];
        }
        $recovered = 0;
        $waiting = 0;
        $paths = glob($directory . '/*.bin');
        if (!is_array($paths)) {
            return ['recovered' => 0, 'waiting' => 0];
        }
        sort($paths);
        foreach ($paths as $path) {
            $opened = self::openSpool($path, $materials['seal']);
            if ($opened === null) {
                $waiting++;
                continue;
            }
            if (!WhatsAppWebhook::signatureMatches($opened['raw'], $opened['header'], $materials['secret'])) {
                $waiting++;
                continue;
            }
            $result = self::accept($opened['raw'], $opened['header'], $materials);
            if ($result === 'stored' || $result === 'rejected') {
                self::forget($opened['raw']);
                if ($result === 'stored') {
                    $recovered++;
                }
                continue;
            }
            $waiting++;
        }
        return ['recovered' => $recovered, 'waiting' => $waiting];
    }

    /** @return array{seal:TokenSeal,secret:string}|null */
    private static function credentials(?TokenSeal $seal, ?string $secret): ?array
    {
        if ($secret === null || $seal === null) {
            $config = PrivateConfig::load();
            if ($secret === null) {
                $secret = is_array($config) ? (string) ($config['app_secret'] ?? '') : '';
            }
            if ($seal === null) {
                $seal = TokenSeal::tryFrom(is_array($config) ? $config : null);
            }
        }
        if ($secret === '' || !$seal instanceof TokenSeal) {
            return null;
        }
        return ['seal' => $seal, 'secret' => $secret];
    }

    private static function lifecyclePayload(string $raw): ?string
    {
        $payload = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account' || !is_array($payload['entry'] ?? null)) {
            return null;
        }
        $entries = [];
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || !is_array($entry['changes'] ?? null)) {
                continue;
            }
            $changes = [];
            foreach ($entry['changes'] as $change) {
                if (self::lifecycleChange($change)) {
                    $changes[] = $change;
                }
            }
            if ($changes === []) {
                continue;
            }
            $entries[] = [
                'id' => (string) ($entry['id'] ?? ''),
                'changes' => $changes,
            ];
        }
        if ($entries === []) {
            return null;
        }
        $json = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => $entries,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }

    private static function lifecycleChange(mixed $change): bool
    {
        if (!is_array($change) || (string) ($change['field'] ?? '') !== 'account_update' || !is_array($change['value'] ?? null)) {
            return false;
        }
        return in_array((string) ($change['value']['event'] ?? ''), self::EVENTS, true);
    }

    private static function remember(string $raw, string $header, TokenSeal $seal): bool
    {
        if (strlen($raw) > 1000000 || strlen($header) > 80) {
            return false;
        }
        $directory = self::spoolDirectory(true);
        if ($directory === null) {
            return false;
        }
        $json = json_encode(['raw' => $raw, 'header' => $header], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }
        try {
            $sealed = $seal->seal($json, 1048576);
        } catch (Throwable) {
            return false;
        }
        $body = json_encode([
            'payload_ciphertext' => bin2hex($sealed['ciphertext']),
            'payload_nonce' => bin2hex($sealed['nonce']),
            'payload_tag' => bin2hex($sealed['tag']),
            'payload_key_version' => $sealed['version'],
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return false;
        }
        $path = $directory . '/' . hash('sha256', $raw) . '.bin';
        $temporary = $directory . '/.' . hash('sha256', $raw) . '.tmp';
        if (file_put_contents($temporary, $body, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0600);
        return rename($temporary, $path);
    }

    /** @return array{raw:string,header:string}|null */
    private static function openSpool(string $path, TokenSeal $seal): ?array
    {
        $raw = file_get_contents($path);
        $stored = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($stored)) {
            return null;
        }
        $cipher = hex2bin((string) ($stored['payload_ciphertext'] ?? ''));
        $nonce = hex2bin((string) ($stored['payload_nonce'] ?? ''));
        $tag = hex2bin((string) ($stored['payload_tag'] ?? ''));
        if (!is_string($cipher) || !is_string($nonce) || !is_string($tag)) {
            return null;
        }
        try {
            $plain = $seal->open($cipher, $nonce, $tag, (int) ($stored['payload_key_version'] ?? 0));
        } catch (Throwable) {
            return null;
        }
        $envelope = json_decode($plain, true);
        if (!is_array($envelope) || !is_string($envelope['raw'] ?? null) || !is_string($envelope['header'] ?? null)) {
            return null;
        }
        return ['raw' => $envelope['raw'], 'header' => $envelope['header']];
    }

    private static function forget(string $raw): void
    {
        $directory = self::spoolDirectory(false);
        if ($directory === null) {
            return;
        }
        $path = $directory . '/' . hash('sha256', $raw) . '.bin';
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function spoolDirectory(bool $create): ?string
    {
        $configured = getenv('BESHARE_OS_LIFECYCLE_SPOOL');
        $path = is_string($configured) && $configured !== '' ? $configured : '';
        if ($path === '') {
            $config = getenv('BESHARE_OS_WEBHOOK_CONFIG');
            if (!is_string($config) || $config === '') {
                $config = dirname(__DIR__, 3) . '/private/beshare-os-webhook.php';
            }
            $path = dirname($config) . '/lifecycle-spool';
        }
        if (preg_match('#(?:^|/)public_html(?:/|$)#', $path) === 1 || str_contains($path, "\0")) {
            return null;
        }
        $repo = realpath(dirname(__DIR__, 2));
        $parent = is_dir($path) ? realpath($path) : realpath(dirname($path));
        if ($repo === false || $parent === false) {
            return null;
        }
        $prefix = rtrim($repo, '/') . '/';
        if ($parent === $repo || str_starts_with($parent, $prefix)) {
            return null;
        }
        if (!is_dir($path)) {
            if (!$create || (!mkdir($path, 0700, true) && !is_dir($path))) {
                return null;
            }
        }
        return rtrim($path, '/');
    }
}
