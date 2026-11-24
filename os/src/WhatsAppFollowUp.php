<?php
declare(strict_types=1);

namespace BesharOs;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class WhatsAppFollowUp
{
    public const TEMPLATE = 'beshare_susulan_dipersetujui_v1';
    public const LANGUAGE = 'ms';
    public const MESSAGE = 'Hai, kami hubungi anda semula pada masa yang anda pilih. Masih berminat untuk sambung perbincangan kita?';
    public const GUIDE = 'Untuk satu WhatsApp susulan, balas SUSUL diikuti tarikh dan masa Malaysia dalam format DD/MM/YYYY HH:MM (24 jam). Untuk batalkan, balas BATAL SUSULAN.';

    public static function installed(PDO $pdo): bool
    {
        return (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ai_followups'")->fetchColumn();
    }

    /** A customer's standalone instruction is consent. Imported knowledge and model prose cannot schedule a send. */
    public static function command(string $text, int $received): ?array
    {
        $text = trim($text);
        if (preg_match('/^(?:stop|unsubscribe|batal(?:kan)?(?: susulan)?|cancel(?: (?:the |my )?follow[ -]?up)?|jangan (?:susul|follow[ -]?up|hubungi|mesej)(?: saya)?(?: lagi)?|tak (?:mahu|nak) (?:susulan|follow[ -]?up))(?:[.!])?$/iu', $text)) {
            return ['action'=>'cancel'];
        }
        if (preg_match('/\b(?:jangan (?:hubungi|mesej|ingatkan|susul|follow[ -]?up)|tak berminat|berhenti (?:mesej|hubungi)|batal(?:kan)? (?:mesej )?susulan|do not contact|don.t (?:contact|message|remind) me|stop messaging me)\b/iu',$text)) {
            return ['action'=>'cancel'];
        }
        $offer = false;
        if (preg_match('/^(?:boleh(?: tak)?|bolehkah|can you|could you)\s+(.+)$/iu',rtrim($text,' ?'),$request)) {
            $offer = true;
            $text = $request[1];
        }
        if (!preg_match('/^(?:susul(?:an)?(?: saya)?|ingatkan saya|follow[ -]?up(?: dengan saya| saya| with me)?|remind me)\s+(.+)$/iu', $text, $m)) {
            return null;
        }
        $when = trim($m[1], " \t\n\r.");
        $zone = new DateTimeZone('Asia/Kuala_Lumpur');
        $base = (new DateTimeImmutable('@'.$received))->setTimezone($zone);
        $due = null;
        if (preg_match('/^(?:dalam|in) ([1-9][0-9]{0,3}) (minit|minutes?|jam|hours?)$/iu', $when, $p)) {
            $due = $received + (int)$p[1] * (preg_match('/^(jam|hours?)$/i', $p[2]) ? 3600 : 60);
        } elseif (preg_match('/^(\d{1,2}\/\d{1,2}\/\d{4}|hari ini|esok|today|tomorrow)\s+(?:(?:pukul|jam|at)\s+)?(\d{1,2})(?:[:.](\d{2}))?\s*(pagi|petang|malam|tengah hari|am|pm)?(?:\s+MYT)?$/iu', $when, $p)) {
            $day = strtolower($p[1]);
            if (in_array($day,['hari ini','today','esok','tomorrow'],true)) {
                $day = (in_array($day,['esok','tomorrow'],true) ? $base->modify('+1 day') : $base)->format('d/m/Y');
            }
            $hour = (int)$p[2];
            $minute = $p[3] ?? '';
            $period = strtolower($p[4] ?? '');
            if (($period === '' && $minute === '') || ($period !== '' && ($hour < 1 || $hour > 12))) {
                return ['action'=>'clarify'];
            }
            if ($period !== '') {
                $hour = $hour % 12 + (in_array($period,['petang','malam','tengah hari','pm'],true) && !($hour===12 && $period==='malam') ? 12 : 0);
            }
            $date = DateTimeImmutable::createFromFormat('!j/n/Y H:i', sprintf('%s %02d:%02d', $day,$hour,(int)$minute), $zone);
            if ($date && !DateTimeImmutable::getLastErrors()) { $due = $date->getTimestamp(); }
        }
        return $due !== null && $due > $received ? ['action'=>$offer ? 'offer' : 'schedule','due'=>$due] : ['action'=>'clarify'];
    }

    public static function localTime(int $due): string
    {
        return (new DateTimeImmutable('@'.$due))->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->format('d/m/Y H:i').' MYT';
    }
}
