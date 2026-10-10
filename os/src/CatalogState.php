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
     */
    public static function classify(
        array $tables,
        array $triggers,
        array $routines,
        array $events,
        array $views,
        array $versions,
        string $fileChecksum
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
        if ($empty) {
            return 'empty';
        }
        if ($complete) {
            return 'complete_unrecorded';
        }
        return 'partial';
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
