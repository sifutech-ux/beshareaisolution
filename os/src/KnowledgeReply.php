<?php
declare(strict_types=1);

namespace BesharOs;

/** Resolves model choices against the owner's published, tenant-bound document. */
final class KnowledgeReply
{
    /** Provider metadata deliberately excludes account numbers and image bytes. */
    public static function catalog(array $document): array
    {
        if ($document === []) { return []; }
        $assets = [];
        foreach ($document['assets'] ?? [] as $asset) {
            if (($asset['enabled'] ?? false) !== true || !self::identifier($asset['id'] ?? null)
                || !self::identifier($asset['media_id'] ?? null)) { continue; }
            $item = ['id' => $asset['id']];
            foreach (['title', 'category', 'facts', 'caption', 'when_to_send'] as $key) {
                $value = $asset[$key] ?? '';
                if (!is_string($value) || strlen($value) > 8000 || preg_match('//u', $value) !== 1) {
                    throw new \RuntimeException('catalog_unreadable');
                }
                $item[$key] = $value;
            }
            $assets[] = $item;
        }
        if (count($assets) > 20) { throw new \RuntimeException('catalog_too_large'); }
        $options = ['none'];
        if (self::payment($document) !== null) {
            $options[] = 'bank';
            if (self::identifier($document['payment']['qr_media_id'] ?? null)) { $options[] = 'qr'; }
        }
        return ['assets' => $assets, 'payment_options' => $options];
    }

    /** A plan is one text message OR one original image with a caption. */
    public static function plan(array $document, array $answer): ?array
    {
        if (($answer['handoff'] ?? true) !== false || !self::text($answer['reply'] ?? null, 4000)) { return null; }
        $assetId = $answer['asset_id'] ?? 'none';
        $payment = $answer['payment'] ?? 'none';
        if (!is_string($assetId) || !in_array($payment, ['none', 'bank', 'qr'], true)
            || ($payment !== 'none' && $assetId !== 'none')) { return null; }
        $plan = ['type' => 'text', 'text' => trim($answer['reply']), 'asset_id' => 'none', 'payment' => 'none'];
        if ($payment !== 'none') {
            $details = self::payment($document);
            if ($details === null) { return null; }
            // Never copy model-authored account coordinates into a payment response.
            $plan['text'] = self::paymentText($details);
            $plan['payment'] = $payment;
            if ($payment === 'qr') {
                if (!self::identifier($details['qr_media_id'] ?? null)) { return null; }
                $plan['type'] = 'image';
                $plan['media_id'] = $details['qr_media_id'];
            }
        } elseif ($assetId !== 'none') {
            $asset = self::asset($document, $assetId);
            if ($asset === null) { return null; }
            $plan['type'] = 'image';
            $plan['asset_id'] = $assetId;
            $plan['media_id'] = $asset['media_id'];
            // A saved customer-facing caption is reproduced without AI alterations.
            if (is_string($asset['caption'] ?? null) && trim($asset['caption']) !== '') {
                $plan['text'] = trim($asset['caption']);
            }
        }
        if (!self::text($plan['text'], 4000)
            || ($plan['type'] === 'image' && self::characters($plan['text']) > 1024)) { return null; }
        return $plan;
    }

    /** Rechecked under the existing settings/revision lock immediately before dispatch. */
    public static function stillApproved(array $document, array $plan): bool
    {
        if (!self::text($plan['text'] ?? null, 4000)) { return false; }
        $payment = $plan['payment'] ?? 'none';
        if ($payment !== 'none') {
            $details = self::payment($document);
            return $details !== null && in_array($payment, ['bank', 'qr'], true)
                && hash_equals(self::paymentText($details), $plan['text'])
                && ($payment === 'bank' ? ($plan['type'] ?? '') === 'text'
                    : (($plan['type'] ?? '') === 'image' && self::identifier($details['qr_media_id'] ?? null)
                        && ($plan['media_id'] ?? null) === $details['qr_media_id']));
        }
        if (($plan['type'] ?? '') === 'text') { return ($plan['asset_id'] ?? 'none') === 'none'; }
        $asset = self::asset($document, $plan['asset_id'] ?? '');
        return ($plan['type'] ?? '') === 'image' && $asset !== null
            && ($plan['media_id'] ?? null) === $asset['media_id'] && self::characters($plan['text']) <= 1024;
    }

    private static function asset(array $document, string $id): ?array
    {
        foreach ($document['assets'] ?? [] as $asset) {
            if (($asset['id'] ?? null) === $id && ($asset['enabled'] ?? false) === true
                && self::identifier($asset['media_id'] ?? null)) { return $asset; }
        }
        return null;
    }

    private static function payment(array $document): ?array
    {
        $payment = $document['payment'] ?? null;
        if (!is_array($payment) || ($payment['enabled'] ?? false) !== true) { return null; }
        foreach (['bank_name', 'account_name', 'account_number'] as $key) {
            if (!self::text($payment[$key] ?? null, 200)) { return null; }
        }
        if (!is_string($payment['instructions'] ?? null) || strlen($payment['instructions']) > 2000) { return null; }
        return $payment;
    }

    private static function paymentText(array $payment): string
    {
        $text = "Maklumat bayaran:\nBank: " . $payment['bank_name']
            . "\nNama penerima: " . $payment['account_name']
            . "\nNombor akaun: " . $payment['account_number'];
        if (trim($payment['instructions']) !== '') { $text .= "\n\n" . trim($payment['instructions']); }
        return $text;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/', $value) === 1;
    }

    private static function text(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $max
            && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    public static function characters(string $value): int
    {
        return preg_match_all('/./us', $value);
    }
}
