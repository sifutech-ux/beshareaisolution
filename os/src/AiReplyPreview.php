<?php
declare(strict_types=1);

namespace BesharOs;

require_once __DIR__ . '/AiReplyPolicy.php';
require_once __DIR__ . '/KnowledgeReply.php';

/** Owner-only simulation. No sender, webhook, job queue or activation dependency exists here. */
final class AiReplyPreview
{
    private \Closure $generate;

    public function __construct(private KnowledgeCentre $centre, callable $generate, private RateLimit $rates, private array $rollout)
    {
        $this->generate = \Closure::fromCallable($generate);
    }

    public function reply(array $actor, array $input): array
    {
        $this->centre->assertRequestScope($actor, $input['expected_business_public_id'] ?? null);
        $context = $this->centre->previewContext($actor);
        if (!AiReplyPolicy::businessAllowed($this->rollout, (int)$actor['business_id'])) { throw new AuthException('Ujian AI belum tersedia untuk perniagaan ini.', 403); }
        if (($input['expected_published_revision'] ?? null) !== $context['revision']) { throw new AuthException('Pengetahuan aktif telah berubah. Muat semula sebelum menguji.', 409); }
        $message = $this->text($input['message'] ?? null, 2000);
        $history = $input['history'] ?? [];
        if (!is_array($history) || !array_is_list($history) || count($history) > 8) { throw new AuthException('Sejarah ujian terlalu panjang. Mulakan ujian baharu.', 400); }
        $clean = []; $bytes = 0;
        foreach ($history as $item) {
            if (!is_array($item) || !in_array($item['role'] ?? null, ['user','assistant'], true)) { throw new AuthException('Sejarah ujian tidak sah.', 400); }
            $text = $this->text($item['text'] ?? null, 2000);
            $bytes += strlen($text);
            $clean[] = ['role'=>$item['role'],'text'=>$text];
        }
        if ($bytes > 12000) { throw new AuthException('Sejarah ujian terlalu panjang. Mulakan ujian baharu.', 400); }
        if (!$this->rates->consume('ai-preview|'.$actor['user_id'])) { throw new AuthException('Had 12 ujian dalam 15 minit dicapai. Cuba lagi kemudian.', 429); }
        $answer = ($this->generate)($context['knowledge'], $message, $clean, KnowledgeReply::catalog($context['document']));
        // Recheck ownership and the publication after the provider finishes.
        $current = $this->centre->previewContext($actor);
        if ($current['revision'] !== $context['revision'] || !hash_equals($current['hash'], $context['hash'])) {
            throw new AuthException('Pengetahuan berubah semasa ujian. Muat semula dan cuba lagi.', 409);
        }
        $plan = is_array($answer) ? KnowledgeReply::plan($context['document'], $answer) : null;
        return ['revision'=>$context['revision'],'handoff'=>$plan === null,'reply'=>$plan['text'] ?? '',
            'attachment'=>($plan['type'] ?? '') === 'image', 'sent'=>false];
    }

    private function text(mixed $value, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value)>$max || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1) { throw new AuthException('Gunakan mesej ujian ringkas, maksimum 2,000 bait.', 400); }
        return trim($value);
    }
}
