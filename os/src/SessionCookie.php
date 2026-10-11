<?php
declare(strict_types=1);

namespace BesharOs;

final class SessionCookie
{
    public const NAME = 'beshare_os';

    /** @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string} */
    public static function parameters(bool $secure): array
    {
        return [
            'lifetime' => 0,
            'path' => '/whatsapp/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
