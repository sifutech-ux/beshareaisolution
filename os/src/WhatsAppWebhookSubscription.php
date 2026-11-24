<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** Subscribes this application to the WABA already bound to a customer's number. */
final class WhatsAppWebhookSubscription
{
    public function __construct(private PDO $pdo, private MetaGraph $graph, private TokenSeal $seal, private string $appId) {}

    public function connect(int $businessId, int $userId, int $connectionId, string $expectedPhone): array
    {
        PlatformAccess::assertBusinessActive($this->pdo, $businessId);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("SELECT waba_id, phone_number_id, display_phone, token_ciphertext, token_nonce,
                    token_tag, token_key_version, webhook_state
                FROM whatsapp_connections WHERE business_id=? AND id=? AND disconnected_at IS NULL
                    AND token_state='verified' AND (token_expires_at IS NULL OR token_expires_at>UTC_TIMESTAMP()) FOR UPDATE");
            $query->execute([$businessId, $connectionId]);
            $row = $query->fetch();
            if (!is_array($row) || (string)$row['display_phone'] !== $expectedPhone) {
                throw new AuthException('Sambungan telah berubah atau kebenaran Meta telah tamat. Muat semula butiran nombor.', 409);
            }
            $token = $this->seal->open((string)$row['token_ciphertext'], (string)$row['token_nonce'], (string)$row['token_tag'], (int)$row['token_key_version']);
            $waba = (string)$row['waba_id'];
            if (!$this->isSubscribed($token, $waba)) {
                $this->graph->subscribe($token, $waba);
                // A POST can time out after Meta accepts it. A retry starts with the same readback.
                if (!$this->isSubscribed($token, $waba)) {
                    throw new AuthException('Meta belum mengesahkan langganan webhook BeShare. Semak kebenaran WhatsApp Business dan cuba lagi.', 409);
                }
            }
            $phoneId = (string)$row['phone_number_id'];
            $phone = $this->graph->webhookConfiguration($token, $phoneId);
            $config = $phone['webhook_configuration'] ?? [];
            $callback = '';
            foreach (['phone_number', 'whatsapp_business_account', 'application'] as $level) {
                if (!empty($config[$level])) { $callback = $config[$level]; break; }
            }
            if ((string)($phone['id'] ?? '') !== $phoneId || !is_string($callback)
                || !in_array(rtrim($callback, '/'), [rtrim(PhoneOverride::APP_CALLBACK, '/'), PhoneOverride::OS_CALLBACK], true)) {
                throw new AuthException('Langganan Meta wujud, tetapi alamat webhook untuk nombor ini belum disahkan sebagai alamat BeShare. Hubungi pentadbir BeShare untuk menyemaknya.', 409);
            }
            if ($row['webhook_state'] !== 'subscribed') {
                $update = $this->pdo->prepare("UPDATE whatsapp_connections SET webhook_state='subscribed',
                    subscribed_at=COALESCE(subscribed_at, UTC_TIMESTAMP()),
                    last_error_code=CASE WHEN last_error_code='subscribe_failed' THEN NULL ELSE last_error_code END,
                    updated_at=UTC_TIMESTAMP() WHERE business_id=? AND id=?");
                $update->execute([$businessId, $connectionId]);
                Accounts::audit($this->pdo, $businessId, $userId, 'webhook_disambungkan', 'whatsapp_connection', $connectionId);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($error instanceof MetaCallException) {
                $message = in_array($error->metaCode, ['190','10','200'], true)
                    ? 'Meta memerlukan kebenaran baharu. Sambung semula akaun WhatsApp Business dan cuba lagi.'
                    : 'Status webhook belum dapat disahkan dengan Meta. Cuba lagi sebentar lagi.';
                throw new AuthException($message, 502);
            }
            throw $error;
        }
        return ['status'=>'subscribed', 'message'=>'Meta mengesahkan langganan webhook BeShare untuk nombor ini.'];
    }

    private function isSubscribed(string $token, string $waba): bool
    {
        $apps = $this->graph->ownerSubscribedApps($token, $waba);
        foreach ($apps['data'] as $app) {
            if ((string)($app['whatsapp_business_api_data']['id'] ?? $app['id'] ?? '') === $this->appId) {
                return true;
            }
        }
        return false;
    }
}
