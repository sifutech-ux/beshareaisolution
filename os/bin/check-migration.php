<?php
declare(strict_types=1);

use BesharOs\CatalogState;
use BesharOs\Contract;
use BesharOs\ExportGuard;
use BesharOs\SqlMode;
use BesharOs\SqlScript;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Contract.php';
require dirname(__DIR__) . '/src/SqlMode.php';
require dirname(__DIR__) . '/src/SqlScript.php';
require dirname(__DIR__) . '/src/CatalogState.php';
require dirname(__DIR__) . '/src/ExportGuard.php';

$root = dirname(__DIR__, 2);
$failures = 0;

$check = static function (string $name, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures++;
        fwrite(STDOUT, "FAIL " . $name . "\n");
        return;
    }
    fwrite(STDOUT, "OK " . $name . "\n");
};

$upPath = $root . '/os/migrations/0001_foundation.up.sql';
$downPath = $root . '/os/migrations/0001_foundation.down.sql';
$up = (string) file_get_contents($upPath);
$down = (string) file_get_contents($downPath);
$upStatements = SqlScript::statements($up);
$downStatements = SqlScript::statements($down);

$createdTables = [];
$createdTriggers = [];
foreach ($upStatements as $statement) {
    $table = SqlScript::objectName($statement, 'table');
    $trigger = SqlScript::objectName($statement, 'trigger');
    if ($table !== null) {
        $createdTables[] = $table;
    }
    if ($trigger !== null) {
        $createdTriggers[] = $trigger;
    }
}
$droppedTables = [];
$droppedTriggers = [];
foreach ($downStatements as $statement) {
    $table = SqlScript::objectName($statement, 'drop_table');
    $trigger = SqlScript::objectName($statement, 'drop_trigger');
    if ($table !== null) {
        $droppedTables[] = $table;
    }
    if ($trigger !== null) {
        $droppedTriggers[] = $trigger;
    }
}

$check('up statements are tables and triggers only', count($upStatements) === count($createdTables) + count($createdTriggers));
$check('up tables match contract', $createdTables === Contract::TABLES);
$check('up triggers match contract', $createdTriggers === Contract::TRIGGERS);
$check('down tables match drop order', $droppedTables === Contract::DROP_TABLES);
$sortedDownTriggers = $droppedTriggers;
$sortedContractTriggers = Contract::TRIGGERS;
sort($sortedDownTriggers, SORT_STRING);
sort($sortedContractTriggers, SORT_STRING);
$check('down triggers match contract', $sortedDownTriggers === $sortedContractTriggers);
$check('down statements are drops only', count($downStatements) === count($droppedTables) + count($droppedTriggers));
$check('up has no global change', preg_match('/SET\s+GLOBAL/i', $up) !== 1);
$check('down has no global change', preg_match('/SET\s+GLOBAL/i', $down) !== 1);
$writesRows = false;
foreach ($upStatements as $statement) {
    if (preg_match('/\A(INSERT|UPDATE|DELETE|REPLACE)\b/i', ltrim($statement)) === 1) {
        $writesRows = true;
    }
}
$check('up statements do not write rows', $writesRows === false);

$php = '';
foreach (['src/Contract.php', 'src/SqlMode.php', 'src/SqlScript.php', 'src/CatalogState.php', 'src/ExportGuard.php', 'src/FoundationMigrator.php', 'bin/migrate.php'] as $relative) {
    $php .= (string) file_get_contents($root . '/os/' . $relative) . "\n";
}
$check('runner has no global change', preg_match('/SET\s+GLOBAL/i', $php) !== 1);
$check('runner does not load down script', preg_match('/foundation\.down\.sql/', $php) !== 1);
$applyStart = strpos($php, 'function apply');
$applyEnd = strpos($php, 'private function run');
$apply = substr($php, (int) $applyStart, (int) $applyEnd - (int) $applyStart);
$check('runner locks before preflight', strpos($apply, 'acquireLock') < strpos($apply, 'run()'));
$check('runner records the version after exec', strpos($php, '->exec($statement)') < strpos($php, 'INSERT INTO schema_migrations'));
$check('no embedded password assignment', preg_match('/DB_PASSWORD\s*=\s*[\'\"][^\'\"]+[\'\"]/', $php) !== 1);

$baseMode = 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
$check('strict mode is appended once', SqlMode::withStrict($baseMode) === $baseMode . ',STRICT_TRANS_TABLES');
$check('strict mode is not duplicated', SqlMode::withStrict('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION') === 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION');
$check('strict token is exact', SqlMode::hasStrict('NOT_STRICT_TRANS_TABLES') === false);
$check('session literal stays narrow', SqlMode::isSafeLiteral(SqlMode::withStrict($baseMode)));

$fullTables = Contract::TABLES;
$fullTriggers = Contract::TRIGGERS;
$checksum = str_repeat('a', 64);
$other = str_repeat('b', 64);
$check('empty catalog', CatalogState::classify([], [], [], [], [], [], $checksum) === 'empty');
$check('complete without version', CatalogState::classify($fullTables, $fullTriggers, [], [], [], [], $checksum) === 'complete_unrecorded');
$check('applied catalog', CatalogState::classify($fullTables, $fullTriggers, [], [], [], [['version' => '0001', 'checksum' => $checksum]], $checksum) === 'applied');
$check('checksum mismatch stops', CatalogState::classify($fullTables, $fullTriggers, [], [], [], [['version' => '0001', 'checksum' => $other]], $checksum) === 'checksum_mismatch');
$check('partial catalog stops', CatalogState::classify(['users'], [], [], [], [], [], $checksum) === 'partial');
$check('unknown table stops', CatalogState::classify([...$fullTables, 'ghost'], $fullTriggers, [], [], [], [], $checksum) === 'foreign_object');
$check('routine stops', CatalogState::classify([], [], ['migrate_now'], [], [], [], $checksum) === 'foreign_object');
$check('version without full catalog stops', CatalogState::classify(['users'], [], [], [], [], [['version' => '0001', 'checksum' => $checksum]], $checksum) === 'drift');

$export = tempnam(sys_get_temp_dir(), 'beshare-export-');
if ($export === false) {
    $check('export fixture', false);
} else {
    file_put_contents($export, "empty-structure\n");
    $hash = hash_file('sha256', $export);
    try {
        $real = ExportGuard::realExport($export, $root);
        ExportGuard::checksum($real, (string) $hash);
        $check('export outside repository is accepted', true);
    } catch (Throwable $exception) {
        $check('export outside repository is accepted', false);
    }
    $rejected = false;
    try {
        ExportGuard::realExport($upPath, $root);
    } catch (InvalidArgumentException $exception) {
        $rejected = $exception->getMessage() === 'export_inside_repository';
    }
    $check('export inside repository is rejected', $rejected);
    unlink($export);
}

$publicParent = sys_get_temp_dir() . '/beshare-guard-' . getmypid();
$public = $publicParent . '/public_html';
if (!mkdir($public, 0700, true) && !is_dir($public)) {
    $check('public_html fixture', false);
} else {
    $dump = $public . '/dump.sql';
    file_put_contents($dump, "structure\n");
    $rejected = false;
    try {
        ExportGuard::realExport($dump, $root);
    } catch (InvalidArgumentException $exception) {
        $rejected = $exception->getMessage() === 'export_inside_public_html';
    }
    $check('export inside public_html is rejected', $rejected);
    unlink($dump);
    rmdir($public);
    rmdir($publicParent);
}

if ($failures > 0) {
    fwrite(STDOUT, "FAILED " . $failures . "\n");
    exit(1);
}
fwrite(STDOUT, "STATIC_OK\n");
exit(0);
