<?php
declare(strict_types=1);

namespace BesharOs;

final class Identifiers
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function publicId(): string
    {
        $time = (int) floor(microtime(true) * 1000);
        $id = '';
        for ($i = 0; $i < 10; $i++) {
            $id = self::ALPHABET[$time % 32] . $id;
            $time = intdiv($time, 32);
        }
        for ($i = 0; $i < 16; $i++) {
            $id .= self::ALPHABET[random_int(0, 31)];
        }
        return $id;
    }

    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AuthException('E-mel tidak sah.');
        }
        return $email;
    }

    public static function personName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || strlen($name) > 191) {
            throw new AuthException('Nama diperlukan.');
        }
        return $name;
    }

    public static function businessName(string $name): string
    {
        return self::personName($name);
    }
}
