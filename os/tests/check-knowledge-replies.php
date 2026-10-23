<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/KnowledgeReply.php';
require_once __DIR__ . '/../src/GeminiTransport.php';
require_once __DIR__ . '/../src/WhatsAppTextSender.php';

use BesharOs\KnowledgeReply;
use BesharOs\GeminiTransport;
use BesharOs\WhatsAppTextSender;

function krCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function krAnswer(array $answer): array
{
    return ['status' => 200, 'body' => json_encode(['candidates' => [[
        'finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($answer, JSON_THROW_ON_ERROR)]]],
    ]]], JSON_THROW_ON_ERROR)];
}
function krSent(): array
{
    return ['status' => 200, 'body' => json_encode(['messaging_product' => 'whatsapp',
        'contacts' => [['input' => '60123456789', 'wa_id' => '60123456789']],
        'messages' => [['id' => 'wamid.synthetic_knowledge_reply']]], JSON_THROW_ON_ERROR)];
}

$assetId = str_repeat('a', 32);
$mediaId = str_repeat('b', 32);
$qrId = str_repeat('c', 32);
$document = ['assets' => [[
    'id' => $assetId, 'media_id' => $mediaId, 'enabled' => true, 'category' => 'combo',
    'title' => 'Kombo contoh', 'facts' => 'Dua produk contoh.', 'caption' => 'Kombo contoh: dua produk.',
    'when_to_send' => 'Apabila pelanggan meminta gambar kombo contoh.',
]], 'payment' => ['enabled' => true, 'bank_name' => 'Bank Contoh', 'account_name' => 'Kedai Contoh',
    'account_number' => '000123456789', 'instructions' => 'Gunakan rujukan pesanan.', 'qr_media_id' => $qrId]];
$catalog = KnowledgeReply::catalog($document);
$encoded = json_encode($catalog, JSON_THROW_ON_ERROR);
krCheck(!str_contains($encoded, '000123456789') && !str_contains($encoded, $mediaId)
    && !str_contains($encoded, $qrId), 'Provider receives no payment coordinates, blob IDs or image bytes');

$payment = KnowledgeReply::plan($document, ['reply' => 'Invented bank number 9999999999',
    'handoff' => false, 'asset_id' => 'none', 'payment' => 'qr']);
krCheck($payment !== null && $payment['type'] === 'image' && $payment['media_id'] === $qrId
    && str_contains($payment['text'], '000123456789') && !str_contains($payment['text'], '9999999999'),
    'Exact approved account, including leading zeros, replaces model text');
krCheck(KnowledgeReply::stillApproved($document, $payment), 'Payment plan remains valid under unchanged publication');
$changed = $document;
$changed['payment']['account_number'] = '000987654321';
krCheck(!KnowledgeReply::stillApproved($changed, $payment), 'Changed payment details invalidate stale plan');
$disabled = $document;
$disabled['payment']['enabled'] = false;
krCheck(KnowledgeReply::plan($disabled, ['reply' => 'Bayar', 'handoff' => false,
    'asset_id' => 'none', 'payment' => 'bank']) === null, 'Disabled payment cannot be selected');
$image = KnowledgeReply::plan($document, ['reply' => 'Model caption', 'handoff' => false,
    'asset_id' => $assetId, 'payment' => 'none']);
krCheck($image !== null && $image['text'] === 'Kombo contoh: dua produk.' && $image['media_id'] === $mediaId,
    'Owner image and saved caption resolve deterministically');
$changed['assets'][0]['enabled'] = false;
krCheck(!KnowledgeReply::stillApproved($changed, $image), 'Disabled image is denied immediately before send');
krCheck(KnowledgeReply::plan($document, ['reply' => 'Photo', 'handoff' => false,
    'asset_id' => str_repeat('d', 32), 'payment' => 'none']) === null, 'Unknown or other-tenant asset choice fails closed');
krCheck(KnowledgeReply::plan($document, ['reply' => 'Photo', 'handoff' => false,
    'asset_id' => $assetId, 'payment' => 'bank']) === null, 'One response cannot combine two outbound actions');

$providerCalls = [];
$providerAnswer = ['reply' => 'Berikut kombo contoh.', 'handoff' => false, 'asset_id' => $assetId, 'payment' => 'none'];
$provider = new GeminiTransport('synthetic_key_for_knowledge_only', 'gemini-3.5-flash-lite',
    static function ($method, $url, $headers, $body) use (&$providerCalls, &$providerAnswer): array {
        $providerCalls[] = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        return krAnswer($providerAnswer);
    });
$answer = $provider->reply('Kedai contoh menjual kombo contoh.', 'Boleh tengok gambar kombo?', [], $catalog);
krCheck($answer === $providerAnswer && count($providerCalls) === 1, 'Extended structured response accepted');
$schema = $providerCalls[0]['generationConfig']['responseSchema'];
krCheck($schema['properties']['asset_id']['enum'] === ['none', $assetId]
    && $schema['properties']['payment']['enum'] === ['none', 'bank', 'qr'], 'Provider schema contains only approved choices');
krCheck(!str_contains(json_encode($providerCalls, JSON_THROW_ON_ERROR), '000123456789'), 'Payment numbers do not enter provider prompt');
foreach ([
    ['reply' => 'Wrong', 'handoff' => false, 'asset_id' => str_repeat('d', 32), 'payment' => 'none'],
    ['reply' => 'Wrong', 'handoff' => false, 'asset_id' => $assetId, 'payment' => 'qr'],
    ['reply' => 'Wrong', 'handoff' => false, 'asset_id' => 'none', 'payment' => 'confirm_paid'],
    ['reply' => 'Wrong', 'handoff' => false, 'asset_id' => 'none', 'payment' => 'none', 'url' => 'https://invalid.example/'],
] as $invalid) {
    $providerAnswer = $invalid;
    krCheck($provider->reply('Facts.', 'Question', [], $catalog) === ['reply' => '', 'handoff' => true],
        'Invalid provider choice is rejected without send');
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
$calls = [];
$sender = new WhatsAppTextSender('v23.0', static function ($method, $url, $headers, $body) use (&$calls, $png): array {
    $calls[] = ['url' => $url, 'body' => $body];
    if (str_ends_with($url, '/media')) {
        krCheck($body['file']['bytes'] === $png && $body['type'] === 'image/png', 'Original image bytes are uploaded unchanged');
        return ['status' => 200, 'body' => '{"id":"1234567890123"}'];
    }
    return krSent();
});
$sent = $sender->send('synthetic_token_for_knowledge_only', '123456789012', '60123456789', $image['text'],
    'wamid.synthetic_inbound_knowledge', ['bytes' => $png, 'mime' => 'image/png']);
krCheck($sent['sent'] && count($calls) === 2, 'One upload followed by one image message');
$wire = json_decode($calls[1]['body'], true, 16, JSON_THROW_ON_ERROR);
krCheck($wire['type'] === 'image' && $wire['image']['id'] === '1234567890123'
    && $wire['image']['caption'] === $image['text'] && !isset($wire['text'])
    && $wire['context']['message_id'] === 'wamid.synthetic_inbound_knowledge', 'Image preserves reply context and caption');

$calls = [];
$failedUpload = new WhatsAppTextSender('v23.0', static function ($method, $url) use (&$calls): array {
    $calls[] = $url;
    throw new RuntimeException('synthetic upload timeout');
});
$result = $failedUpload->send('synthetic_token_for_knowledge_only', '123456789012', '60123456789', 'Photo', null,
    ['bytes' => $png, 'mime' => 'image/png']);
krCheck(!$result['sent'] && !$result['ambiguous'] && count($calls) === 1 && str_ends_with($calls[0], '/media'),
    'Upload uncertainty never triggers a customer message or fallback text');
$calls = [];
$uncertain = new WhatsAppTextSender('v23.0', static function ($method, $url) use (&$calls): array {
    $calls[] = $url;
    return str_ends_with($url, '/media') ? ['status' => 200, 'body' => '{"id":"1234567890123"}']
        : ['status' => 200, 'body' => '{invalid'];
});
$result = $uncertain->send('synthetic_token_for_knowledge_only', '123456789012', '60123456789', 'Photo', null,
    ['bytes' => $png, 'mime' => 'image/png']);
krCheck(!$result['sent'] && $result['ambiguous'] && count($calls) === 2, 'Ambiguous image send is not retried');
$before = count($calls);
foreach ([['bytes' => '<svg>bad</svg>', 'mime' => 'image/svg+xml'],
    ['bytes' => $png, 'mime' => 'image/jpeg'], ['bytes' => str_repeat('x', 2097153), 'mime' => 'image/png']] as $media) {
    $result = $uncertain->send('synthetic_token_for_knowledge_only', '123456789012', '60123456789', 'Photo', null, $media);
    krCheck(!$result['sent'] && !$result['ambiguous'], 'Unsupported, mismatched or oversized images are rejected');
}
$result = $uncertain->send('synthetic_token_for_knowledge_only', '123456789012', '60123456789', str_repeat('x', 1025), null,
    ['bytes' => $png, 'mime' => 'image/png']);
krCheck(!$result['sent'] && count($calls) === $before, 'Invalid media and captions make no network call');

echo "Knowledge replies: approved selections, exact payments and single image dispatch passed.\n";
