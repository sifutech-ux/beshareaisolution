<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

final class Db
{
    public static function connect(): PDO
    {
        $config = self::config();
        if (str_contains($config['dsn'], 'password=')) {
            throw new AuthException('Konfigurasi pangkalan tidak sah.', 500);
        }
        $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $current = $pdo->query('SELECT DATABASE() AS name');
        $row = $current === false ? false : $current->fetch();
        if (!is_array($row) || (string) $row['name'] !== Contract::DATABASE) {
            throw new AuthException('Pangkalan data ditolak.', 500);
        }
        return $pdo;
    }

    /** @return array{dsn:string,user:string,password:string} */
    public static function config(): array
    {
        $dsn = getenv('BESHARE_OS_DSN');
        $user = getenv('BESHARE_OS_DB_USER');
        $password = getenv('BESHARE_OS_DB_PASSWORD');
        if (is_string($dsn) && is_string($user) && is_string($password) && $dsn !== '' && $user !== '' && $password !== '') {
            return ['dsn' => $dsn, 'user' => $user, 'password' => $password];
        }
        $path = getenv('BESHARE_OS_DB_CONFIG');
        if (!is_string($path) || $path === '') {
            throw new AuthException('Pangkalan data belum dikonfigurasi.', 503);
        }
        $real = realpath($path);
        $repo = realpath(dirname(__DIR__, 2));
        if ($real === false || !is_file($real) || $repo === false) {
            throw new AuthException('Pangkalan data belum dikonfigurasi.', 503);
        }
        $prefix = rtrim($repo, '/') . '/';
        if ($real === $repo || str_starts_with($real, $prefix) || preg_match('#(?:^|/)public_html(?:/|$)#', $real) === 1) {
            throw new AuthException('Konfigurasi pangkalan ditolak.', 500);
        }
        $loaded = require $real;
        if (!is_array($loaded)) {
            throw new AuthException('Konfigurasi pangkalan ditolak.', 500);
        }
        $fileDsn = $loaded['dsn'] ?? '';
        $fileUser = $loaded['user'] ?? '';
        $filePassword = $loaded['password'] ?? '';
        if (!is_string($fileDsn) || !is_string($fileUser) || !is_string($filePassword) || $fileDsn === '' || $fileUser === '' || $filePassword === '') {
            throw new AuthException('Konfigurasi pangkalan ditolak.', 500);
        }
        return ['dsn' => $fileDsn, 'user' => $fileUser, 'password' => $filePassword];
    }
}
