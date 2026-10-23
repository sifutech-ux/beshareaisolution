<?php
declare(strict_types=1);

use BesharOs\AiReplySchema;
use BesharOs\AuthException;
use BesharOs\Db;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/SqlScript.php';
require_once dirname(__DIR__) . '/src/AiReplySchema.php';

$apply = false;
$checksum = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') { $apply = true; }
    elseif (preg_match('/\A--checksum=([a-f0-9]{64})\z/', $arg, $matches) === 1) { $checksum = $matches[1]; }
    else { fwrite(STDERR, "Pilihan tidak sah. Gunakan semakan dahulu; --apply --checksum=HASH untuk memasang.\n"); exit(1); }
}
try {
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/0004_ai_replies.up.sql');
    if (!is_string($sql)) { throw new RuntimeException(); }
    $schema = new AiReplySchema(Db::connect(), $sql);
    $result = $apply ? $schema->apply($checksum) : $schema->inspect();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
} catch (AuthException $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Skema AI tidak dapat disiapkan. Semak pemasangan; tiada pemadaman automatik.\n"); exit(1);
}
