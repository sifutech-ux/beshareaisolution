<?php
declare(strict_types=1);

use BesharOs\Contract;
use BesharOs\FoundationMigrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Contract.php';
require dirname(__DIR__) . '/src/SqlMode.php';
require dirname(__DIR__) . '/src/SqlScript.php';
require dirname(__DIR__) . '/src/CatalogState.php';
require dirname(__DIR__) . '/src/ExportGuard.php';
require dirname(__DIR__) . '/src/FoundationMigrator.php';

exit(run($argv));

/** @param list<string> $argv */
function run(array $argv): int
{
    $command = $argv[1] ?? '';
    if ($command !== 'apply' || !in_array(Contract::CONFIRM, $argv, true)) {
        fwrite(STDOUT, "Penggunaan: php os/bin/migrate.php apply --confirm-foundation-0001 --export=LALUAN --export-sha256=SHA256\n");
        fwrite(STDOUT, "Tiada sambungan pangkalan data dibuka tanpa arahan ini.\n");
        return 1;
    }
    $export = option($argv, '--export');
    $checksum = option($argv, '--export-sha256');
    if ($export === null || $checksum === null) {
        fwrite(STDERR, "export_required\n");
        return 1;
    }
    try {
        return (new FoundationMigrator(dirname(__DIR__, 2)))->apply($export, $checksum);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        return 4;
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        return 2;
    }
}

/** @param list<string> $argv */
function option(array $argv, string $name): ?string
{
    $prefix = $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
}
