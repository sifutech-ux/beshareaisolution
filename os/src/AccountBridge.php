<?php
declare(strict_types=1);
namespace BesharOs;

/** One-use proof of the exact operator request, redeemed over the canonical HTTPS origin. */
final class AccountBridge
{
    public static function call(array $command): array
    {
        $raw = json_encode($command, JSON_THROW_ON_ERROR);
        $ticket = bin2hex(random_bytes(32));
        $file = self::path($ticket);
        $handle = fopen($file, 'xb');
        if (!$handle) { throw new AuthException('Pengesahan pelayan belum tersedia.', 503); }
        try {
            if (fwrite($handle, hash('sha256', $raw)) !== 64) { throw new AuthException('Pengesahan gagal.',503); }
            fclose($handle);
            $ctx = stream_context_create(['http'=>['method'=>'POST','content'=>$raw,'timeout'=>12,
                'ignore_errors'=>true,'follow_location'=>0,
                'header'=>"Content-Type: application/json\r\nAuthorization: Ticket ".$ticket."\r\nConnection: close"]]);
            $response = @file_get_contents('https://akaun.beshareaisolution.com/api/akaun/operasi', false, $ctx, 0, 150000);
            $result = is_string($response) ? json_decode($response, true) : null;
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                throw new AuthException(is_string($result['error']??null) ? $result['error'] : 'Pelayan akaun belum tersedia.', 409);
            }
            return $result;
        } finally { if (is_resource($handle)) { fclose($handle); } if (is_file($file)) { @unlink($file); } }
    }

    public static function verify(string $ticket, string $hash): bool
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $ticket) || !preg_match('/\A[a-f0-9]{64}\z/', $hash)) { return false; }
        $file = self::path($ticket);
        // Atomic rename makes each proof redeemable by one request only.
        $used = $file.'.used';
        if (!@rename($file, $used)) { return false; }
        try { return filemtime($used) >= time()-30 && hash_equals((string)file_get_contents($used), $hash); }
        finally { @unlink($used); }
    }

    private static function path(string $ticket): string
    {
        return RateLimit::resolveDirectory().'/access-'.hash('sha256',$ticket);
    }
}
