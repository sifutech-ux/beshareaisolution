<?php
declare(strict_types=1);

use BesharOs\BackupProof;
use BesharOs\CatalogState;
use BesharOs\Contract;
use BesharOs\ExportGuard;
use BesharOs\OwnershipAccess;
use BesharOs\RecoveryPolicy;
use BesharOs\SchemaDefinition;
use BesharOs\SqlMode;
use BesharOs\SqlScript;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Contract.php';
require dirname(__DIR__) . '/src/SqlMode.php';
require dirname(__DIR__) . '/src/SqlScript.php';
require dirname(__DIR__) . '/src/SchemaDefinition.php';
require dirname(__DIR__) . '/src/CatalogState.php';
require dirname(__DIR__) . '/src/ExportGuard.php';
require dirname(__DIR__) . '/src/BackupProof.php';
require dirname(__DIR__) . '/src/RecoveryPolicy.php';
require dirname(__DIR__) . '/src/OwnershipAccess.php';

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

$check('up sql has 13 statements', count($upStatements) === 13);
$check('up sql has 8 tables and 5 triggers', count($createdTables) === 8 && count($createdTriggers) === 5);
$triggersKeepSemicolons = true;
foreach ($upStatements as $statement) {
    if (SqlScript::objectName($statement, 'trigger') === null) {
        continue;
    }
    if (!str_contains($statement, 'BEGIN') || !str_contains($statement, ';') || !preg_match('/\bEND$/', $statement)) {
        $triggersKeepSemicolons = false;
    }
}
$check('semicolons inside triggers stay in the statement', $triggersKeepSemicolons);
$triggerFixture = <<<'SQL'
CREATE TRIGGER sample_owner
BEFORE UPDATE ON businesses
FOR EACH ROW
BEGIN
  IF NEW.status = 'active' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_change_requires_provisioning';
  END IF;
  IF NEW.status = 'suspended' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_change_requires_provisioning';
  END IF;
END;
SQL;
$parsedFixture = SqlScript::statements($triggerFixture);
$check(
    'fixture trigger with internal semicolons is one statement',
    count($parsedFixture) === 1
        && substr_count($parsedFixture[0], ';') === 4
        && substr_count($parsedFixture[0], 'END IF;') === 2
);
$stringFixture = <<<'SQL'
CREATE TRIGGER sample_text
BEFORE UPDATE ON businesses
FOR EACH ROW
BEGIN
  IF NEW.status = 'active' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'keep;this';
  END IF;
END;
SQL;
$parsedString = SqlScript::statements($stringFixture);
$check(
    'semicolon inside a trigger string stays in one statement',
    count($parsedString) === 1
        && str_contains($parsedString[0], "'keep;this'")
        && str_contains($parsedString[0], 'END IF;')
);
$expectParserError = static function (string $sql, string $message) use ($check): void {
    try {
        SqlScript::statements($sql);
        $check($message, false);
    } catch (InvalidArgumentException $exception) {
        $check($message, $exception->getMessage() === $message);
    }
};
$expectParserError("DELIMITER $$\n", 'sql_unsupported');
$expectParserError("CREATE PROCEDURE p()\nBEGIN\n  SELECT 1;\nEND;\n", 'sql_unsupported');
$expectParserError("CREATE FUNCTION f()\nRETURNS INT\nBEGIN\n  RETURN 1;\nEND;\n", 'sql_unsupported');
$expectParserError("CREATE TABLE t (\n  id INT\n", 'sql_unterminated_statement');
$expectParserError("CREATE TRIGGER t BEFORE INSERT ON businesses FOR EACH ROW\nBEGIN\n  SET @a = 1;\n", 'sql_unbalanced');
$expectParserError("SELECT 'unterminated\n", 'sql_unterminated_string');
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
foreach (['src/Contract.php', 'src/SqlMode.php', 'src/SqlScript.php', 'src/SchemaDefinition.php', 'src/CatalogState.php', 'src/ExportGuard.php', 'src/BackupProof.php', 'src/RecoveryPolicy.php', 'src/OwnershipAccess.php', 'src/FoundationMigrator.php', 'bin/migrate.php'] as $relative) {
    $php .= (string) file_get_contents($root . '/os/' . $relative) . "\n";
}
$check('runner has no global change', preg_match('/SET\s+GLOBAL/i', $php) !== 1);
$check('runner does not load down script', preg_match('/foundation\.down\.sql/', $php) !== 1);
$applyStart = strpos($php, 'function apply');
$applyEnd = strpos($php, 'private function run');
$apply = substr($php, (int) $applyStart, (int) $applyEnd - (int) $applyStart);
$check('runner locks before preflight', strpos($apply, 'acquireLock') < strpos($apply, 'run()'));
$check('runner records the version after exec', strpos($php, '->exec($statement)') < strpos($php, 'INSERT INTO schema_migrations'));
$runStart = strpos($php, 'private function run');
$runEnd = strpos($php, 'private function recordVersion');
$run = substr($php, (int) $runStart, (int) $runEnd - (int) $runStart);
$check('export checksum is repeated before ddl', strpos($run, 'ExportGuard::checksum') < strpos($run, '->exec($statement)'));
$check('backup proof is repeated before ddl', strpos($run, 'BackupProof::verify') < strpos($run, '->exec($statement)'));
$check('backup proof is not the checksum gate', strpos($apply, 'ExportGuard::checksum') < strpos($apply, 'BackupProof::verify') && strpos($apply, 'BackupProof::verify') < strpos($apply, 'connect()'));
$check('wrong definitions stop the runner', str_contains($run, 'definition_mismatch'));
$check('complete catalog is inspected before version insert', str_contains($run, "inspect() !== 'complete_unrecorded'"));
$migratorSource = (string) file_get_contents($root . '/os/src/FoundationMigrator.php');
$recoverySource = (string) file_get_contents($root . '/os/src/RecoveryPolicy.php');
$check('runner has no drop statement', preg_match('/\bDROP\s+(TABLE|TRIGGER|DATABASE)\b/i', $migratorSource) !== 1);
$check('recovery policy does not execute sql', preg_match('/\b(DROP|INSERT|exec)\b/i', $recoverySource) !== 1);
$check('processed payload must be purged', str_contains($up, 'payload_purged_at IS NOT NULL'));
$membershipGuards = 0;
$ownerMoveChecked = false;
$ownerIdOnlyWhileProvisioning = false;
foreach ($upStatements as $statement) {
    $triggerName = SqlScript::objectName($statement, 'trigger');
    if (in_array($triggerName, ['bi_business_users_owner', 'bu_business_users_owner', 'bd_business_users_owner'], true)
        && str_contains($statement, "IN ('active', 'suspended')")) {
        $membershipGuards++;
    }
    if ($triggerName === 'bu_business_users_owner'
        && str_contains($statement, 'OLD.business_id')
        && str_contains($statement, 'NEW.business_id')) {
        $ownerMoveChecked = true;
    }
    if ($triggerName === 'bu_businesses_active_owner'
        && str_contains($statement, "OLD.status = 'provisioning' AND NEW.status = 'provisioning'")) {
        $ownerIdOnlyWhileProvisioning = true;
    }
}
$check('active and suspended block owner membership changes', $membershipGuards === 3);
$check('owner membership move checks both businesses', $ownerMoveChecked);
$check('owner id changes only while provisioning', $ownerIdOnlyWhileProvisioning);
$check('no embedded password assignment', preg_match('/DB_PASSWORD\s*=\s*[\'\"][^\'\"]+[\'\"]/', $php) !== 1);

$baseMode = 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
$check('strict mode is appended once', SqlMode::withStrict($baseMode) === $baseMode . ',STRICT_TRANS_TABLES');
$check('strict mode is not duplicated', SqlMode::withStrict('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION') === 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION');
$check('strict token is exact', SqlMode::hasStrict('NOT_STRICT_TRANS_TABLES') === false);
$check(
    'spaced sql mode is normalized',
    SqlMode::withStrict('NO_AUTO_CREATE_USER, NO_ENGINE_SUBSTITUTION') === 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES'
);
$check('session literal stays narrow', SqlMode::isSafeLiteral(SqlMode::withStrict($baseMode)));

$fullTables = Contract::TABLES;
$fullTriggers = Contract::TRIGGERS;
$checksum = str_repeat('a', 64);
$other = str_repeat('b', 64);
$blankDefinitions = ['tables' => [], 'triggers' => []];
$expected = SchemaDefinition::fromSql($up);
$usersOnly = ['tables' => ['users' => $expected['tables']['users']], 'triggers' => []];
$state = static function (array $tables, array $triggers, array $routines, array $events, array $views, array $versions, string $fileChecksum, array $definitions) use ($expected): string {
    return CatalogState::classify($tables, $triggers, $routines, $events, $views, $versions, $fileChecksum, $definitions, $expected);
};
$check('migration definition has 8 tables and 5 triggers', count($expected['tables']) === 8 && count($expected['triggers']) === 5);
$ownerGuard = null;
foreach ($expected['tables']['business_users']['columns'] as $column) {
    if ($column['name'] === 'owner_guard') {
        $ownerGuard = $column;
    }
}
$webhookFk = null;
foreach ($expected['tables']['webhook_events']['foreign_keys'] as $foreignKey) {
    if ($foreignKey['name'] === 'fk_webhook_connection') {
        $webhookFk = $foreignKey;
    }
}
$purged = null;
foreach ($expected['tables']['webhook_events']['checks'] as $checkClause) {
    if ($checkClause['name'] === 'chk_webhook_purged') {
        $purged = $checkClause;
    }
}
$ownerIndex = null;
foreach ($expected['tables']['business_users']['indexes'] as $index) {
    if ($index['name'] === 'uq_one_owner') {
        $ownerIndex = $index;
    }
}
$check('generated owner guard is part of the definition', is_array($ownerGuard) && str_contains((string) $ownerGuard['generated'], "role = 'owner'") && str_contains((string) $ownerGuard['generated'], 'business_id'));
$check('webhook foreign key is part of the definition', $webhookFk === [
    'name' => 'fk_webhook_connection',
    'columns' => ['business_id', 'connection_id'],
    'ref_table' => 'whatsapp_connections',
    'ref_columns' => ['business_id', 'id'],
]);
$check('payload check is part of the definition', is_array($purged) && str_contains($purged['clause'], 'payload_purged_at is not null'));
$check('owner index is part of the definition', $ownerIndex === ['name' => 'uq_one_owner', 'unique' => true, 'columns' => ['owner_guard']]);
$check('trigger definition keeps both business ids', str_contains($expected['triggers']['bu_business_users_owner']['body'], 'old.business_id') && str_contains($expected['triggers']['bu_business_users_owner']['body'], 'new.business_id'));
$check('empty catalog', $state([], [], [], [], [], [], $checksum, $blankDefinitions) === 'empty');
$check('complete without version', $state($fullTables, $fullTriggers, [], [], [], [], $checksum, $expected) === 'complete_unrecorded');
$check('applied catalog', $state($fullTables, $fullTriggers, [], [], [], [['version' => '0001', 'checksum' => $checksum]], $checksum, $expected) === 'applied');
$check('checksum mismatch stops', $state($fullTables, $fullTriggers, [], [], [], [['version' => '0001', 'checksum' => $other]], $checksum, $expected) === 'checksum_mismatch');
$check('partial catalog stops', $state(['users'], [], [], [], [], [], $checksum, $usersOnly) === 'partial');
$check('unknown table stops', $state([...$fullTables, 'ghost'], $fullTriggers, [], [], [], [], $checksum, $expected) === 'foreign_object');
$check('routine stops', $state([], [], ['migrate_now'], [], [], [], $checksum, $blankDefinitions) === 'foreign_object');
$check('version without full catalog stops', $state(['users'], [], [], [], [], [['version' => '0001', 'checksum' => $checksum]], $checksum, $usersOnly) === 'drift');
$check('names alone are not a complete migration', $state($fullTables, $fullTriggers, [], [], [], [], $checksum, $blankDefinitions) === 'definition_mismatch');
$wrongType = $expected;
$wrongType['tables']['users']['columns'][0]['type'] = 'int';
$check('right names and wrong column type are not complete', $state($fullTables, $fullTriggers, [], [], [], [], $checksum, $wrongType) === 'definition_mismatch');
$wrongCheck = $expected;
$wrongCheck['tables']['webhook_events']['checks'][0]['clause'] = 'status<>\'processed\'';
$check('right names and wrong check are not complete', $state($fullTables, $fullTriggers, [], [], [], [], $checksum, $wrongCheck) === 'definition_mismatch');
$wrongKey = $expected;
$wrongKey['tables']['webhook_events']['foreign_keys'][0]['ref_table'] = 'users';
$check('right names and wrong foreign key are not complete', $state($fullTables, $fullTriggers, [], [], [], [], $checksum, $wrongKey) === 'definition_mismatch');
$wrongGenerated = $expected;
foreach ($wrongGenerated['tables']['business_users']['columns'] as $index => $column) {
    if ($column['name'] === 'owner_guard') {
        $wrongGenerated['tables']['business_users']['columns'][$index]['generated'] = 'null';
    }
}
$check('right names and wrong generated column are not complete', $state($fullTables, $fullTriggers, [], [], [], [], $checksum, $wrongGenerated) === 'definition_mismatch');
$wrongIndex = $expected;
$wrongIndex['tables']['business_users']['indexes'] = array_values(array_filter(
    $wrongIndex['tables']['business_users']['indexes'],
    static fn (array $index): bool => $index['name'] !== 'uq_one_owner'
));
$check('right names and missing index are not complete', $state($fullTables, $fullTriggers, [], [], [], [], $checksum, $wrongIndex) === 'definition_mismatch');
$wrongTrigger = $expected;
$wrongTrigger['triggers']['bu_businesses_active_owner']['body'] = 'begin end';
$check('right names and wrong trigger body are not applied', $state($fullTables, $fullTriggers, [], [], [], [['version' => '0001', 'checksum' => $checksum]], $checksum, $wrongTrigger) === 'definition_mismatch');
$sampleSql = <<<'SQL'
CREATE TABLE sample_mail (
  email VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  owner_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (email),
  CONSTRAINT chk_sample_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
$sampleExpected = SchemaDefinition::fromSql($sampleSql);
$sampleActual = SchemaDefinition::fromCatalog(
    [
        ['table_name' => 'sample_mail', 'column_name' => 'email', 'column_type' => 'varchar(191)', 'is_nullable' => 'NO', 'column_default' => null, 'extra' => '', 'generation_expression' => '', 'character_set_name' => 'utf8mb4', 'collation_name' => 'utf8mb4_bin', 'ordinal_position' => 1],
        ['table_name' => 'sample_mail', 'column_name' => 'status', 'column_type' => 'varchar(16)', 'is_nullable' => 'NO', 'column_default' => 'active', 'extra' => '', 'generation_expression' => '', 'character_set_name' => 'utf8mb4', 'collation_name' => 'utf8mb4_unicode_ci', 'ordinal_position' => 2],
        ['table_name' => 'sample_mail', 'column_name' => 'owner_guard', 'column_type' => 'bigint unsigned', 'is_nullable' => 'YES', 'column_default' => null, 'extra' => 'STORED GENERATED', 'generation_expression' => 'case when `status` = \'active\' then 1 else null end', 'character_set_name' => null, 'collation_name' => null, 'ordinal_position' => 3],
    ],
    [
        ['table_name' => 'sample_mail', 'index_name' => 'PRIMARY', 'non_unique' => 0, 'column_name' => 'email', 'seq_in_index' => 1],
    ],
    [],
    [
        ['table_name' => 'sample_mail', 'constraint_name' => 'chk_sample_status', 'check_clause' => '(`status` in (\'active\',\'disabled\'))'],
    ],
    [],
    [
        ['table_name' => 'sample_mail', 'engine' => 'InnoDB', 'table_collation' => 'utf8mb4_unicode_ci'],
    ]
);
$check('catalog rows match the migration definition', SchemaDefinition::same($sampleExpected['tables']['sample_mail'], $sampleActual['tables']['sample_mail']));
$sampleActual['tables']['sample_mail']['columns'][1]['type'] = 'int';
$check('catalog rows with the right table name and wrong type do not match', SchemaDefinition::same($sampleExpected['tables']['sample_mail'], $sampleActual['tables']['sample_mail']) === false);
$approved = str_repeat('c', 64);
$check('recovery blocked when structure differs', RecoveryPolicy::assess('partial', false, $approved, $approved) === 'blocked');
$check('recovery blocked when migration checksum differs', RecoveryPolicy::assess('complete_unrecorded', true, $approved, str_repeat('d', 64)) === 'blocked');
$check('recovery blocked for a definition mismatch', RecoveryPolicy::assess('definition_mismatch', true, $approved, $approved) === 'blocked');
$check('matching structure remains a manual review', RecoveryPolicy::assess('complete_unrecorded', true, $approved, $approved) === 'manual_review');
$owner = ['user_id' => 7, 'business_id' => 3, 'role' => 'owner', 'owner_user_id' => 7, 'status' => 'active'];
$check('current owner may open provisioning without changing owner', OwnershipAccess::decide($owner, ['kind' => 'begin_transfer', 'next_status' => 'provisioning']) === 'allowed');
$check('opening provisioning cannot also change owner', OwnershipAccess::decide($owner, ['kind' => 'begin_transfer', 'next_status' => 'provisioning', 'next_owner_user_id' => 8]) === 'denied');
$check('staff cannot open provisioning', OwnershipAccess::decide(['user_id' => 9, 'business_id' => 3, 'role' => 'staff', 'owner_user_id' => 7, 'status' => 'active'], ['kind' => 'begin_transfer', 'next_status' => 'provisioning']) === 'denied');
$check('admin cannot reassign the owner', OwnershipAccess::decide(['user_id' => 4, 'business_id' => 3, 'role' => 'admin', 'owner_user_id' => 7, 'status' => 'provisioning'], ['kind' => 'reassign_owner', 'next_owner_user_id' => 8]) === 'denied');
$provisioning = $owner;
$provisioning['status'] = 'provisioning';
$check('owner reassignment stays inside provisioning', OwnershipAccess::decide($provisioning, ['kind' => 'reassign_owner', 'next_owner_user_id' => 8]) === 'allowed');
$check('active business rejects owner reassignment', OwnershipAccess::decide($owner, ['kind' => 'reassign_owner', 'next_owner_user_id' => 8]) === 'denied');
$check('request business id is ignored', OwnershipAccess::decide($owner, ['kind' => 'suspend_or_resume', 'business_id' => 99, 'next_status' => 'suspended']) === 'denied');
$check('suspend does not change the owner', OwnershipAccess::decide($owner, ['kind' => 'suspend_or_resume', 'next_status' => 'suspended', 'next_owner_user_id' => 8]) === 'denied');

$export = tempnam(sys_get_temp_dir(), 'beshare-export-');
if ($export === false) {
    $check('export fixture', false);
} else {
    file_put_contents($export, "empty-structure\n");
    $hash = hash_file('sha256', $export);
    try {
        $real = ExportGuard::realExport($export, $root);
        ExportGuard::checksum($real, (string) $hash);
        $checksumAccepted = true;
    } catch (Throwable $exception) {
        $checksumAccepted = false;
    }
    $check('export outside repository is accepted', $checksumAccepted);
    $identityRejected = false;
    try {
        BackupProof::verify((string) file_get_contents($export), [
            'database' => Contract::DATABASE,
            'server_version' => '11.8.9-MariaDB-log',
            'export_sha256' => (string) $hash,
            'tables' => '0',
            'triggers' => '0',
            'routines' => '0',
            'events' => '0',
            'views' => '0',
            'schema_migrations_rows' => '0',
        ], (string) $hash);
    } catch (InvalidArgumentException $exception) {
        $identityRejected = $exception->getMessage() === 'backup_header_missing';
    }
    $check('checksum match does not prove database identity', $checksumAccepted && $identityRejected);
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

$validDump = <<<'SQL'
-- phpMyAdmin SQL Dump
-- Server version: 11.8.9-MariaDB-log
-- Database: `u879723783_beshare_os`

SET SQL_MODE = "NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION";
SET time_zone = "+00:00";
COMMIT;
SQL;
$validExport = tempnam(sys_get_temp_dir(), 'beshare-proof-');
$validProof = tempnam(sys_get_temp_dir(), 'beshare-manifest-');
if ($validExport === false || $validProof === false) {
    $check('backup proof fixture', false);
} else {
    file_put_contents($validExport, $validDump);
    $validHash = (string) hash_file('sha256', $validExport);
    $manifest = "database=" . Contract::DATABASE . "\n"
        . "server_version=11.8.9-MariaDB-log\n"
        . "export_sha256=" . $validHash . "\n"
        . "tables=0\ntriggers=0\nroutines=0\nevents=0\nviews=0\nschema_migrations_rows=0\n";
    file_put_contents($validProof, $manifest);
    $proven = false;
    try {
        $read = BackupProof::read($validProof, $root);
        BackupProof::verify($validDump, $read, $validHash);
        $proven = true;
    } catch (Throwable $exception) {
        $proven = false;
    }
    $check('empty dump with bound manifest proves the database', $proven);
    $notEmpty = false;
    try {
        BackupProof::verify($validDump . "\nCREATE TABLE ghost (id INT);\n", $read, $validHash);
    } catch (InvalidArgumentException $exception) {
        $notEmpty = $exception->getMessage() === 'backup_not_empty_structure';
    }
    $check('dump that creates an object is not an empty backup', $notEmpty);
    $hidden = false;
    try {
        BackupProof::verify($validDump . "\n/*!50001 CREATE TABLE ghost (id INT) */;\n", $read, $validHash);
    } catch (InvalidArgumentException $exception) {
        $hidden = $exception->getMessage() === 'backup_not_empty_structure';
    }
    $check('conditional comment cannot hide a create', $hidden);
    $occupied = false;
    try {
        $occupiedManifest = $read;
        $occupiedManifest['tables'] = '8';
        BackupProof::verify($validDump, $occupiedManifest, $validHash);
    } catch (InvalidArgumentException $exception) {
        $occupied = $exception->getMessage() === 'backup_inventory_not_empty';
    }
    $check('non-empty inventory is not the preflight backup', $occupied);
    $otherDatabase = false;
    try {
        BackupProof::verify(str_replace(Contract::DATABASE, 'other_database', $validDump), [
            'database' => 'other_database',
            'server_version' => '11.8.9-MariaDB-log',
            'export_sha256' => $validHash,
            'tables' => '0',
            'triggers' => '0',
            'routines' => '0',
            'events' => '0',
            'views' => '0',
            'schema_migrations_rows' => '0',
        ], $validHash);
    } catch (InvalidArgumentException $exception) {
        $otherDatabase = $exception->getMessage() === 'backup_database_rejected';
    }
    $check('another database name is rejected before checksum can approve it', $otherDatabase);
    unlink($validExport);
    unlink($validProof);
}

if ($failures > 0) {
    fwrite(STDOUT, "FAILED " . $failures . "\n");
    exit(1);
}
fwrite(STDOUT, "STATIC_OK\n");
exit(0);
