<?php
declare(strict_types=1);

namespace BesharOs;

use PDOException;

final class SqlGuard
{
    public static function translate(\Throwable $error): AuthException
    {
        if ($error instanceof AuthException) {
            return $error;
        }
        $text = $error->getMessage();
        $signals = [
            'business_must_start_provisioning',
            'owner_change_requires_provisioning',
            'owner_delete_requires_provisioning',
            'owner_mismatch',
            'active_owner_required',
        ];
        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                return new AuthException('Perubahan pemilikan ditolak.', 409);
            }
        }
        if ($error instanceof PDOException && (str_contains($text, '23000') || str_contains($text, 'Duplicate'))) {
            return new AuthException('Rekod itu sudah wujud.', 409);
        }
        return new AuthException('Permintaan tidak dapat diselesaikan.', 500);
    }
}
