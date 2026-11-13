<?php
declare(strict_types=1);

namespace BesharOs;

final class RecoveryMail
{
    public static function send(string $recipient, string $url): bool
    {
        if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$recipient)
            || !str_starts_with($url,PasswordRecovery::URL)) { return false; }
        $subject = 'Tetapkan semula kata laluan BeShare AI OS';
        $body = "Salam,\r\n\r\nKami menerima permintaan untuk menetapkan semula kata laluan akaun BeShare AI OS anda.\r\n\r\n"
            . "Buka pautan ini untuk memilih kata laluan baharu:\r\n" . $url
            . "\r\n\r\nPautan ini hanya boleh digunakan sekali dan tamat 30 minit selepas diminta."
            . "\r\nJika anda tidak membuat permintaan ini, abaikan e-mel ini. Kata laluan anda kekal."
            . "\r\nJangan kongsi pautan ini dengan sesiapa.\r\n\r\nBeShare AI Solution\r\n";
        return mail($recipient,$subject,$body,[
            'From'=>'BeShare AI OS <noreply@beshareaisolution.com>',
            'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding'=>'8bit',
        ]);
    }
}
