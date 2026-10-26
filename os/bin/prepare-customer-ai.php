<?php
declare(strict_types=1);

use BesharOs\AiProductionSchema;
use BesharOs\AuthException;
use BesharOs\Db;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/AiProductionSchema.php';

$apply = false;
$checksum = '';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $apply = true; }
    elseif (preg_match('/\A--checksum=([a-f0-9]{64})\z/', $argument, $match) === 1) { $checksum = $match[1]; }
    else { fwrite(STDERR, "Pilihan tidak sah. Semak dahulu, kemudian --apply --checksum=HASH.\n"); exit(1); }
}
try {
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/0007_customer_ai.up.sql');
    if (!is_string($sql)) { throw new RuntimeException('migration_unavailable'); }
    $schema = new AiProductionSchema(Db::connect(), $sql);
    fwrite(STDOUT, json_encode($apply ? $schema->apply($checksum) : $schema->inspect(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
} catch (AuthException $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Pemasangan AI pelanggan tidak dapat diselesaikan. Semak pemasangan; tiada pengaktifan AI automatik.\n"); exit(1);
}
