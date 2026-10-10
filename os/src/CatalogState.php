<?php
declare(strict_types=1);

namespace BesharOs;

final class CatalogState
{
    /**
     * @param list<string> $tables
     * @param list<string> $triggers
     * @param list<string> $routines
     * @param list<string> $events
     * @param list<string> $views
     * @param list<array{version:string,checksum:string}> $versions
     * @param array{tables?:array<string,array<string,mixed>>,triggers?:array<string,array<string,mixed>>} $definitions
     * @param array{tables?:array<string,array<string,mixed>>,triggers?:array<string,array<string,mixed>>} $expected
     */
    public static function classify(
        array $tables,
        array $triggers,
        array $routines,
        array $events,
        array $views,
        array $versions,
        string $fileChecksum,
        array $definitions,
        array $expected
    ): string {
        $tables = self::sorted($tables);
        $triggers = self::sorted($triggers);
        $expectedTables = self::sorted(Contract::TABLES);
        $expectedTriggers = self::sorted(Contract::TRIGGERS);
        $known = array_fill_keys([...$expectedTables, ...$expectedTriggers], true);

        foreach ([...$tables, ...$triggers] as $name) {
            if (!isset($known[$name])) {
                return 'foreign_object';
            }
        }
        if ($routines !== [] || $events !== [] || $views !== []) {
            return 'foreign_object';
        }

        $tablesMatch = $tables === $expectedTables;
        $triggersMatch = $triggers === $expectedTriggers;
        $complete = $tablesMatch && $triggersMatch;
        $empty = $tables === [] && $triggers === [];

        if ($empty && $versions === []) {
            return 'empty';
        }
        if (!$empty && !self::definitionsAgree($tables, $triggers, $definitions, $expected)) {
            return 'definition_mismatch';
        }

        if (count($versions) > 1) {
            return 'drift';
        }
        if (count($versions) === 1) {
            $version = $versions[0];
            if ($version['version'] !== Contract::VERSION || !$complete) {
                return 'drift';
            }
            if (
                strlen($fileChecksum) !== strlen($version['checksum'])
                || !hash_equals($fileChecksum, $version['checksum'])
            ) {
                return 'checksum_mismatch';
            }
            return 'applied';
        }
        if ($complete) {
            return 'complete_unrecorded';
        }
        return 'partial';
    }

    /**
     * @param list<string> $tables
     * @param list<string> $triggers
     * @param array{tables?:array<string,array<string,mixed>>,triggers?:array<string,array<string,mixed>>} $definitions
     * @param array{tables?:array<string,array<string,mixed>>,triggers?:array<string,array<string,mixed>>} $expected
     */
    private static function definitionsAgree(array $tables, array $triggers, array $definitions, array $expected): bool
    {
        $actualTables = $definitions['tables'] ?? null;
        $actualTriggers = $definitions['triggers'] ?? null;
        $expectedTables = $expected['tables'] ?? null;
        $expectedTriggers = $expected['triggers'] ?? null;
        if (!is_array($actualTables) || !is_array($actualTriggers) || !is_array($expectedTables) || !is_array($expectedTriggers)) {
            return false;
        }
        $presentTables = self::sorted($tables);
        $presentTriggers = self::sorted($triggers);
        if (self::sorted(array_map('strval', array_keys($actualTables))) !== $presentTables) {
            return false;
        }
        if (self::sorted(array_map('strval', array_keys($actualTriggers))) !== $presentTriggers) {
            return false;
        }
        foreach ($presentTables as $name) {
            if (!isset($expectedTables[$name]) || !is_array($actualTables[$name]) || !is_array($expectedTables[$name])) {
                return false;
            }
            if (!SchemaDefinition::same($actualTables[$name], $expectedTables[$name])) {
                return false;
            }
        }
        foreach ($presentTriggers as $name) {
            if (!isset($expectedTriggers[$name]) || !is_array($actualTriggers[$name]) || !is_array($expectedTriggers[$name])) {
                return false;
            }
            if (!SchemaDefinition::same($actualTriggers[$name], $expectedTriggers[$name])) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        $names = array_values($names);
        sort($names, SORT_STRING);
        return $names;
    }
}
