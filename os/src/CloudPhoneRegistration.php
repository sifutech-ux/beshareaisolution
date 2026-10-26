<?php
declare(strict_types=1);

namespace BesharOs;

/** Explicit new-number adapter. It never subscribes a WABA or changes a callback. */
final class CloudPhoneRegistration
{
    public function __construct(private string $version, private MetaTransport $transport)
    {
        if (preg_match('/\Av\d+\.\d+\z/', $version) !== 1) {
            throw new AuthException('Versi Meta tidak sah.', 503);
        }
    }

    /** Read one exact phone; no paging URL supplied by Meta is followed. */
    public function inspectPhone(string $token, string $phoneId): array
    {
        $this->validate($token, $phoneId);
        $url = $this->url($phoneId) . '?' . http_build_query([
            'fields' => 'id,display_phone_number,status,code_verification_status,is_on_biz_app,platform_type',
        ]);
        try {
            $response = $this->transport->call('GET', $url, ['authorization' => 'Bearer ' . $token], '');
        } catch (\Throwable) {
            throw new MetaCallException('transport', 502);
        }
        $status = (int) ($response['status'] ?? 0);
        $json = $response['json'] ?? null;
        if ($status < 200 || $status >= 300 || !is_array($json) || isset($json['error'])) {
            throw new MetaCallException($this->errorCode(is_array($json) ? $json : []), $status ?: 502);
        }
        return $json;
    }

    /**
     * Exactly one POST. An ambiguous response is never retried by this adapter.
     * @return array{status:string,error_code:?string}
     */
    public function register(string $token, string $phoneId, string $pin): array
    {
        $this->validate($token, $phoneId);
        if (preg_match('/\A\d{6}\z/', $pin) !== 1) {
            throw new AuthException('PIN pengaktifan tidak sah.', 400);
        }
        try {
            $response = $this->transport->call('POST', $this->url($phoneId) . '/register', [
                'authorization' => 'Bearer ' . $token,
                'content-type' => 'application/json',
            ], (string) json_encode(['messaging_product' => 'whatsapp', 'pin' => $pin], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return ['status' => 'unknown', 'error_code' => 'registration_transport'];
        }
        $status = (int) ($response['status'] ?? 0);
        $json = $response['json'] ?? null;
        if ($status >= 200 && $status < 300 && is_array($json)
            && ($json['success'] ?? null) === true && !isset($json['error'])) {
            return ['status' => 'registered', 'error_code' => null];
        }
        if ($status >= 400 && $status < 500 && is_array($json)
            && is_array($json['error'] ?? null) && $this->errorCode($json) !== 'http') {
            return ['status' => 'rejected', 'error_code' => 'meta_' . $this->errorCode($json)];
        }
        return ['status' => 'unknown', 'error_code' => 'registration_response_unknown'];
    }

    private function validate(string $token, string $phoneId): void
    {
        if ($token === '' || strlen($token) > 1800 || preg_match('/[\r\n]/', $token) === 1
            || preg_match('/\A[1-9]\d{4,31}\z/', $phoneId) !== 1) {
            throw new AuthException('Kelayakan sambungan tidak sah.', 409);
        }
    }

    private function url(string $phoneId): string
    {
        return 'https://graph.facebook.com/' . $this->version . '/' . $phoneId;
    }

    private function errorCode(array $json): string
    {
        $code = $json['error']['code'] ?? null;
        return (is_int($code) || is_string($code)) && preg_match('/\A\d{1,6}\z/', (string) $code) === 1
            ? (string) $code : 'http';
    }
}
