<?php
declare(strict_types=1);

namespace BesharOs;

/**
 * One bounded text-send attempt. The caller owns authorization, consent,
 * customer-service-window checks, durable reservation, and reconciliation.
 */
final class WhatsAppTextSender
{
    private const RESPONSE_BYTES = 65536;
    private const MESSAGE_BYTES = 4000;

    private \Closure $http;

    /** The injected transport takes (method, url, headers, body), returning status + raw body. */
    public function __construct(private string $graphVersion, ?callable $http = null)
    {
        if (preg_match('/\Av[0-9]{1,3}\.[0-9]{1,2}\z/', $graphVersion) !== 1) {
            throw new \RuntimeException('sender_configuration');
        }
        $this->http = $http === null
            ? \Closure::fromCallable([$this, 'request'])
            : \Closure::fromCallable($http);
    }

    /**
     * No retries occur here. An ambiguous result MUST NOT be automatically retried:
     * Meta may have accepted the request even when the response was lost/invalid.
     *
     * @return array{sent:bool,message_id:?string,ambiguous:bool,error:?string}
     */
    public function send(
        #[\SensitiveParameter] string $token,
        string $phoneId,
        string $recipient,
        string $text,
        ?string $replyToMessageId = null
    ): array {
        $recipient = $this->recipient($recipient);
        if ($recipient === null || preg_match('/\A[0-9]{5,32}\z/', $phoneId) !== 1
            || preg_match('/\A[A-Za-z0-9._~+\/|:=-]{16,4096}\z/', $token) !== 1
            || trim($text) === '' || strlen($text) > self::MESSAGE_BYTES || preg_match('//u', $text) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1
            || ($replyToMessageId !== null && !$this->messageId($replyToMessageId))) {
            return $this->failed(false, 'invalid_input');
        }
        try {
            $request = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => trim($text)],
            ];
            if ($replyToMessageId !== null) {
                $request['context'] = ['message_id' => $replyToMessageId];
            }
            $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->failed(false, 'invalid_input');
        }

        try {
            $result = ($this->http)(
                'POST',
                'https://graph.facebook.com/' . $this->graphVersion . '/' . $phoneId . '/messages',
                ['content-type' => 'application/json', 'authorization' => 'Bearer ' . $token],
                $body
            );
        } catch (\Throwable) {
            return $this->failed(true, 'transport_uncertain');
        }
        if (!is_array($result) || !is_int($result['status'] ?? null)
            || !is_string($result['body'] ?? null) || strlen($result['body']) > self::RESPONSE_BYTES) {
            return $this->failed(true, 'response_uncertain');
        }
        try {
            $json = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            return $this->failed(true, 'response_uncertain');
        }
        if (!is_array($json)) {
            return $this->failed(true, 'response_uncertain');
        }
        $status = $result['status'];
        // A complete Graph client-error response confirms rejection. A timeout,
        // malformed response, redirect, or server failure does not prove no send.
        $graphError = $json['error'] ?? null;
        if ($status >= 400 && $status < 500 && $status !== 408
            && is_array($graphError) && is_int($graphError['code'] ?? null) && $graphError['code'] > 0
            && is_string($graphError['message'] ?? null) && is_string($graphError['type'] ?? null)
            && !array_key_exists('messages', $json) && !array_key_exists('contacts', $json)) {
            return $this->failed(false, 'rejected');
        }
        if ($status < 200 || $status >= 300 || array_key_exists('error', $json)
            || ($json['messaging_product'] ?? null) !== 'whatsapp') {
            return $this->failed(true, 'response_uncertain');
        }
        $contacts = $json['contacts'] ?? null;
        $messages = $json['messages'] ?? null;
        if (!is_array($contacts) || !array_is_list($contacts) || count($contacts) !== 1
            || !is_array($messages) || !array_is_list($messages) || count($messages) !== 1
            || !is_array($contacts[0]) || !is_array($messages[0])) {
            return $this->failed(true, 'response_uncertain');
        }
        $input = $contacts[0]['input'] ?? null;
        $waId = $contacts[0]['wa_id'] ?? null;
        $messageId = $messages[0]['id'] ?? null;
        if (!is_string($input) || !is_string($waId)
            || $this->recipient($input) !== $recipient || $this->recipient($waId) !== $recipient
            || !is_string($messageId) || !$this->messageId($messageId)) {
            return $this->failed(true, 'response_uncertain');
        }
        return ['sent' => true, 'message_id' => $messageId, 'ambiguous' => false, 'error' => null];
    }

    private function recipient(string $value): ?string
    {
        if (preg_match('/\A\+?([1-9][0-9]{6,14})\z/', $value, $match) !== 1) {
            return null;
        }
        return $match[1];
    }

    private function messageId(string $value): bool
    {
        // Match the worker's 180-byte bound for durable message identifiers.
        return preg_match('/\Awamid\.[A-Za-z0-9+\/_=-]{2,174}\z/', $value) === 1;
    }

    /** @return array{sent:bool,message_id:null,ambiguous:bool,error:string} */
    private function failed(bool $ambiguous, string $error): array
    {
        return ['sent' => false, 'message_id' => null, 'ambiguous' => $ambiguous, 'error' => $error];
    }

    /** @param array<string,string> $headers @return array{status:int,body:string} */
    private function request(string $method, string $url, array $headers, string $body): array
    {
        $prefix = 'https://graph.facebook.com/' . $this->graphVersion . '/';
        if ($method !== 'POST' || !str_starts_with($url, $prefix)
            || preg_match('/\A[0-9]{5,32}\/messages\z/', substr($url, strlen($prefix))) !== 1
            || !function_exists('curl_init')) {
            throw new \RuntimeException('sender_transport');
        }
        $lines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $response = '';
        $ch = null;
        set_error_handler(static fn (): bool => true);
        try {
            $ch = curl_init($url);
            if ($ch === false || !curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > self::RESPONSE_BYTES) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ])) {
                throw new \RuntimeException('sender_transport');
            }
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($ok === false) {
                throw new \RuntimeException('sender_transport');
            }
            return ['status' => $status, 'body' => $response];
        } finally {
            if ($ch !== null && $ch !== false) {
                curl_close($ch);
            }
            restore_error_handler();
            error_clear_last();
        }
    }
}
