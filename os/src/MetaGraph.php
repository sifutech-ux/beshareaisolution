<?php
declare(strict_types=1);

namespace BesharOs;

interface MetaTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,json:array<string,mixed>}
     */
    public function call(string $method, string $url, array $headers, string $body): array;
}

final class MetaCallException extends \RuntimeException
{
    public function __construct(public readonly string $metaCode, public readonly int $httpStatus)
    {
        parent::__construct('meta');
    }
}

final class StreamMetaTransport implements MetaTransport
{
    public function call(string $method, string $url, array $headers, string $body): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.facebook.com') {
            throw new MetaCallException('host', 502);
        }
        $debugInput = $headers['x-debug-input'] ?? '';
        unset($headers['x-debug-input']);
        if ($debugInput !== '') {
            $authorization = $headers['authorization'] ?? '';
            unset($headers['authorization']);
            $appToken = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query([
                'input_token' => $debugInput,
                'access_token' => $appToken,
            ]);
        }
        $lines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $fetched = file_get_contents($url, false, $context, 0, 1048577);
        } finally {
            restore_error_handler();
            error_clear_last();
        }
        $raw = is_string($fetched) ? $fetched : null;
        if ($raw === null || strlen($raw) > 1048576) {
            throw new MetaCallException('transport', 502);
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#\AHTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        $json = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        return ['status' => $status, 'json' => is_array($json) ? $json : []];
    }
}

final class MetaGraph
{
    public function __construct(
        private string $appId,
        private string $appSecret,
        private string $version,
        private ?MetaTransport $transport = null,
        private mixed $ready = null
    ) {
        if (preg_match('/\A\d{5,20}\z/', $this->appId) !== 1 || $this->appSecret === '' || preg_match('/\Av\d+\.\d+\z/', $this->version) !== 1) {
            throw new AuthException('Konfigurasi Meta tidak sah.', 503);
        }
    }

    public function exchangeCode(string $code): string
    {
        $json = $this->request('GET', 'oauth/access_token', [], '', [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'code' => $code,
        ]);
        $token = $json['access_token'] ?? '';
        if (!is_string($token) || $token === '' || strlen($token) > 1800) {
            throw new MetaCallException('token', 502);
        }
        return $token;
    }

    /** @return array<string,mixed> */
    public function debugToken(string $token): array
    {
        return $this->request('GET', 'debug_token', [
            'authorization' => 'Bearer ' . $this->appId . '|' . $this->appSecret,
            'x-debug-input' => $token,
        ], '');
    }

    /** @return array<string,mixed> */
    public function waba(string $token, string $wabaId): array
    {
        $this->assertId($wabaId);
        return $this->request('GET', $wabaId, $this->bearer($token), '', ['fields' => 'id,name']);
    }

    /** Read-only owner import edges. Never follow a response-provided URL. */
    public function ownerWabas(string $token, string $portfolioId): array
    {
        $this->assertId($portfolioId);
        return $this->ownerPages($token, $portfolioId . '/owned_whatsapp_business_accounts', ['fields' => 'id,name']);
    }

    public function ownerPhones(string $token, string $wabaId): array
    {
        $this->assertId($wabaId);
        return $this->ownerPages($token, $wabaId . '/phone_numbers', [
            'fields' => 'id,display_phone_number,verified_name,code_verification_status,status',
        ]);
    }

    public function ownerSubscribedApps(string $token, string $wabaId): array
    {
        $this->assertId($wabaId);
        return $this->ownerPages($token, $wabaId . '/subscribed_apps', []);
    }

    /** @param array<string,string> $query @return array{data:list<array<string,mixed>>} */
    private function ownerPages(string $token, string $path, array $query): array
    {
        $this->requireTransport();
        if ($token === '' || strlen($token) > 1800 || preg_match('/[\r\n]/', $token) === 1) {
            throw new MetaCallException('token', 400);
        }
        $rows = [];
        $seen = [];
        $query['limit'] = '100';
        for ($page = 0; $page < 20; $page++) {
            $json = $this->request('GET', $path, $this->bearer($token), '', $query);
            if (!isset($json['data']) || !is_array($json['data']) || !array_is_list($json['data'])) {
                throw new MetaCallException('owner_edge_shape', 502);
            }
            foreach ($json['data'] as $row) {
                if (!is_array($row) || count($rows) >= 2000) {
                    throw new MetaCallException('owner_edge_limit', 502);
                }
                $rows[] = $row;
            }
            $paging = $json['paging'] ?? [];
            if (!is_array($paging)) {
                throw new MetaCallException('owner_edge_paging', 502);
            }
            if (!isset($paging['next']) || $paging['next'] === '') {
                return ['data' => $rows];
            }
            $after = $paging['cursors']['after'] ?? null;
            if (!is_string($paging['next']) || !is_string($after) || preg_match('/\A[A-Za-z0-9_+\/=\-]{1,2048}\z/', $after) !== 1 || isset($seen[$after])) {
                throw new MetaCallException('owner_edge_cursor', 502);
            }
            $seen[$after] = true;
            $query['after'] = $after;
        }
        throw new MetaCallException('owner_edge_limit', 502);
    }

    /** @return array<string,mixed> */
    public function phones(string $token, string $wabaId): array
    {
        $this->assertId($wabaId);
        return $this->request('GET', $wabaId . '/phone_numbers', $this->bearer($token), '', [
            'fields' => 'id,display_phone_number,verified_name,code_verification_status,status',
        ]);
    }

    /** @return array<string,mixed> */
    public function coexistencePhones(string $token, string $wabaId): array
    {
        $this->assertId($wabaId);
        return $this->request('GET', $wabaId . '/phone_numbers', $this->bearer($token), '', [
            'fields' => 'id,display_phone_number,status,is_on_biz_app,platform_type',
        ]);
    }

    /**
     * Read apps subscribed to a WABA. This does not subscribe or remove anything.
     *
     * @return array<string,mixed>
     */
    public function subscribedApps(string $token, string $wabaId): array
    {
        $this->requireTransport();
        $this->assertId($wabaId);
        return $this->request('GET', $wabaId . '/subscribed_apps', $this->bearer($token), '');
    }

    /**
     * Read the phone, WABA, and application callback URLs.
     *
     * @return array<string,mixed>
     */
    public function webhookConfiguration(string $token, string $phoneId): array
    {
        $this->requireTransport();
        $this->assertId($phoneId);
        return $this->request('GET', $phoneId, $this->bearer($token), '', ['fields' => 'webhook_configuration']);
    }

    /**
     * Set only this phone's alternate callback. The URL is chosen by the caller
     * from the fixed OS endpoint; this method does not subscribe a WABA.
     *
     * @return array<string,mixed>
     */
    public function setPhoneOverride(string $token, string $phoneId, string $callbackUrl, string $verifyToken): array
    {
        $this->requireTransport();
        $this->assertId($phoneId);
        if (preg_match('#\Ahttps://beshareaisolution\.com/whatsapp/sambungan/webhook\.php\z#', $callbackUrl) !== 1) {
            throw new MetaCallException('callback', 400);
        }
        if ($verifyToken === '' || strlen($verifyToken) > 200 || preg_match('/[\r\n]/', $verifyToken) === 1) {
            throw new MetaCallException('verify', 400);
        }
        $body = json_encode([
            'webhook_configuration' => [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken,
            ],
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new MetaCallException('callback', 400);
        }
        return $this->request('POST', $phoneId, $this->bearer($token) + ['content-type' => 'application/json'], $body);
    }

    /**
     * Remove only this phone's alternate callback. An empty URI is the documented
     * phone rollback. It is not an empty WABA subscribed_apps request.
     *
     * @return array<string,mixed>
     */
    public function clearPhoneOverride(string $token, string $phoneId): array
    {
        $this->requireTransport();
        $this->assertId($phoneId);
        $body = json_encode([
            'webhook_configuration' => [
                'override_callback_uri' => '',
            ],
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new MetaCallException('callback', 400);
        }
        return $this->request('POST', $phoneId, $this->bearer($token) + ['content-type' => 'application/json'], $body);
    }

    public function subscribe(string $token, string $wabaId): bool
    {
        $ready = $this->ready;
        $allowed = $ready === null ? WebhookReadiness::ready() : (is_callable($ready) ? $ready() === true : false);
        if ($allowed !== true) {
            return false;
        }
        $this->assertId($wabaId);
        try {
            $json = $this->request('POST', $wabaId . '/subscribed_apps', $this->bearer($token), '');
        } catch (MetaCallException) {
            return false;
        }
        return ($json['success'] ?? false) === true;
    }

    /** @return array<string,mixed> */
    public function smbAppData(string $token, string $phoneId, string $syncType): array
    {
        if (!$this->transport instanceof MetaTransport) {
            throw new MetaCallException('disabled', 409);
        }
        if (!in_array($syncType, ['smb_app_state_sync', 'history'], true)) {
            throw new MetaCallException('sync_type', 400);
        }
        $this->assertId($phoneId);
        return $this->request('POST', $phoneId . '/smb_app_data', [
            'authorization' => 'Bearer ' . $token,
            'content-type' => 'application/json',
        ], (string) json_encode([
            'messaging_product' => 'whatsapp',
            'sync_type' => $syncType,
        ], JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,string> $headers @param array<string,string> $query @return array<string,mixed> */
    private function request(string $method, string $path, array $headers, string $body, array $query = []): array
    {
        if (preg_match('#\A(?:oauth/access_token|debug_token|\d{5,32}(?:/phone_numbers|/subscribed_apps|/smb_app_data|/owned_whatsapp_business_accounts)?)\z#', $path) !== 1) {
            throw new MetaCallException('path', 502);
        }
        $url = 'https://graph.facebook.com/' . $this->version . '/' . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $transport = $this->transport ?? new StreamMetaTransport();
        try {
            $result = $transport->call($method, $url, $headers, $body);
        } catch (MetaCallException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new MetaCallException('transport', 502);
        }
        $status = (int) ($result['status'] ?? 0);
        $json = $result['json'] ?? [];
        if (!is_array($json)) {
            $json = [];
        }
        if ($status < 200 || $status >= 300 || isset($json['error'])) {
            $code = 'http';
            $error = $json['error'] ?? null;
            if (is_array($error) && isset($error['code']) && preg_match('/\A\d{1,6}\z/', (string) $error['code']) === 1) {
                $code = (string) $error['code'];
            }
            throw new MetaCallException($code, $status > 0 ? $status : 502);
        }
        return $json;
    }

    /** @return array{authorization:string} */
    private function bearer(string $token): array
    {
        return ['authorization' => 'Bearer ' . $token];
    }

    private function assertId(string $id): void
    {
        if (preg_match('/\A\d{5,32}\z/', $id) !== 1) {
            throw new MetaCallException('id', 400);
        }
    }

    private function requireTransport(): void
    {
        if (!$this->transport instanceof MetaTransport) {
            throw new MetaCallException('disabled', 409);
        }
    }
}
