<?php
declare(strict_types=1);

namespace BesharOs;

final class SchemaDefinition
{
    /**
     * Structure expected by the migration text: columns, indexes, foreign keys,
     * CHECK clauses, generated expressions, and trigger bodies.
     *
     * @return array{tables: array<string, array<string, mixed>>, triggers: array<string, array<string, mixed>>}
     */
    public static function fromSql(string $sql): array
    {
        $tables = [];
        $triggers = [];
        foreach (SqlScript::statements($sql) as $statement) {
            $table = SqlScript::objectName($statement, 'table');
            if ($table !== null) {
                $tables[$table] = self::tableFromSql($statement);
                continue;
            }
            $trigger = SqlScript::objectName($statement, 'trigger');
            if ($trigger !== null) {
                $triggers[$trigger] = self::triggerFromSql($statement);
                continue;
            }
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        return ['tables' => $tables, 'triggers' => $triggers];
    }

    /**
     * Structure read from information_schema rows. Charset and collation are
     * recorded only when they differ from the table default, matching fromSql.
     *
     * @param list<array<string,mixed>> $columns
     * @param list<array<string,mixed>> $indexes
     * @param list<array<string,mixed>> $foreignKeys
     * @param list<array<string,mixed>> $checks
     * @param list<array<string,mixed>> $triggers
     * @param list<array<string,mixed>> $tables
     * @return array{tables: array<string, array<string, mixed>>, triggers: array<string, array<string, mixed>>}
     */
    public static function fromCatalog(
        array $columns,
        array $indexes,
        array $foreignKeys,
        array $checks,
        array $triggers,
        array $tables
    ): array {
        $meta = [];
        foreach ($tables as $row) {
            $name = (string) $row['table_name'];
            $meta[$name] = [
                'engine' => strtolower((string) $row['engine']),
                'collation' => strtolower((string) $row['table_collation']),
            ];
        }
        $built = [];
        foreach ($columns as $row) {
            $table = (string) $row['table_name'];
            if (!isset($meta[$table])) {
                throw new \InvalidArgumentException('definition_unreadable');
            }
            if (!isset($built[$table])) {
                $built[$table] = [
                    'engine' => $meta[$table]['engine'],
                    'collation' => $meta[$table]['collation'],
                    'columns' => [],
                    'indexes' => [],
                    'foreign_keys' => [],
                    'checks' => [],
                ];
            }
            $collation = strtolower((string) ($row['collation_name'] ?? ''));
            $charset = strtolower((string) ($row['character_set_name'] ?? ''));
            $explicit = $collation !== '' && $collation !== $meta[$table]['collation'];
            $generated = trim((string) ($row['generation_expression'] ?? ''));
            $built[$table]['columns'][] = [
                'name' => strtolower((string) $row['column_name']),
                'type' => self::normalizeType((string) $row['column_type']),
                'nullable' => strtoupper((string) $row['is_nullable']) === 'YES',
                'charset' => $explicit ? $charset : null,
                'collation' => $explicit ? $collation : null,
                'default' => self::defaultValue($row['column_default'] ?? null),
                'auto_increment' => str_contains(strtolower((string) ($row['extra'] ?? '')), 'auto_increment'),
                'generated' => $generated === '' ? null : self::normalize($generated),
            ];
        }
        $indexGroups = [];
        foreach ($indexes as $row) {
            $table = (string) $row['table_name'];
            $name = (string) $row['index_name'];
            $indexGroups[$table][$name][(int) $row['seq_in_index']] = strtolower((string) $row['column_name']);
            $indexGroups[$table][$name]['unique'] = (int) $row['non_unique'] === 0;
        }
        foreach ($indexGroups as $table => $groups) {
            if (!isset($built[$table])) {
                throw new \InvalidArgumentException('definition_unreadable');
            }
            foreach ($groups as $name => $group) {
                $unique = $group['unique'];
                unset($group['unique']);
                ksort($group);
                $built[$table]['indexes'][] = [
                    'name' => (string) $name,
                    'unique' => $unique,
                    'columns' => array_values($group),
                ];
            }
        }
        $fkGroups = [];
        foreach ($foreignKeys as $row) {
            $table = (string) $row['table_name'];
            $name = (string) $row['constraint_name'];
            $position = (int) $row['ordinal_position'];
            $fkGroups[$table][$name]['columns'][$position] = strtolower((string) $row['column_name']);
            $fkGroups[$table][$name]['ref_columns'][$position] = strtolower((string) $row['referenced_column']);
            $fkGroups[$table][$name]['ref_table'] = strtolower((string) $row['referenced_table']);
        }
        foreach ($fkGroups as $table => $groups) {
            if (!isset($built[$table])) {
                throw new \InvalidArgumentException('definition_unreadable');
            }
            foreach ($groups as $name => $group) {
                ksort($group['columns']);
                ksort($group['ref_columns']);
                $built[$table]['foreign_keys'][] = [
                    'name' => (string) $name,
                    'columns' => array_values($group['columns']),
                    'ref_table' => $group['ref_table'],
                    'ref_columns' => array_values($group['ref_columns']),
                ];
            }
        }
        foreach ($checks as $row) {
            $table = (string) $row['table_name'];
            if (!isset($built[$table])) {
                throw new \InvalidArgumentException('definition_unreadable');
            }
            $built[$table]['checks'][] = [
                'name' => (string) $row['constraint_name'],
                'clause' => self::normalizeCheck((string) $row['check_clause']),
            ];
        }
        foreach (array_keys($built) as $name) {
            $built[$name] = self::absorbJsonColumns($built[$name]);
            $built[$name]['indexes'] = self::sortedByName($built[$name]['indexes']);
            $built[$name]['foreign_keys'] = self::sortedByName($built[$name]['foreign_keys']);
            $built[$name]['checks'] = self::sortedByName($built[$name]['checks']);
        }
        $triggerMap = [];
        foreach ($triggers as $row) {
            $triggerMap[(string) $row['trigger_name']] = [
                'timing' => strtoupper((string) $row['timing']),
                'event' => strtoupper((string) $row['event_name']),
                'table' => strtolower((string) $row['table_name']),
                'body' => self::normalize((string) $row['statement']),
            ];
        }
        return ['tables' => $built, 'triggers' => $triggerMap];
    }

    public static function same(array $left, array $right): bool
    {
        $encodedLeft = self::canonical($left);
        $encodedRight = self::canonical($right);
        return strlen($encodedLeft) === strlen($encodedRight) && hash_equals($encodedLeft, $encodedRight);
    }

    /** @param array<string,mixed> $table */
    private static function tableFromSql(string $statement): array
    {
        $open = strpos($statement, '(');
        if ($open === false
            || preg_match('/\)\s*ENGINE\s*=\s*([A-Za-z0-9]+)\s+DEFAULT\s+CHARSET\s*=\s*([A-Za-z0-9]+)\s+COLLATE\s*=\s*([A-Za-z0-9_]+)/i', $statement, $engine) !== 1) {
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        $table = [
            'engine' => strtolower($engine[1]),
            'collation' => strtolower($engine[3]),
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'checks' => [],
        ];
        foreach (self::splitComma(self::insideParens($statement, $open)) as $part) {
            $part = trim($part);
            if (preg_match('/\APRIMARY\s+KEY\s*\(/i', $part) === 1) {
                $table['indexes'][] = ['name' => 'PRIMARY', 'unique' => true, 'columns' => self::columnList($part)];
            } elseif (preg_match('/\AUNIQUE\s+KEY\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $part, $matches) === 1) {
                $table['indexes'][] = ['name' => $matches[1], 'unique' => true, 'columns' => self::columnList($part)];
            } elseif (preg_match('/\AKEY\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $part, $matches) === 1) {
                $table['indexes'][] = ['name' => $matches[1], 'unique' => false, 'columns' => self::columnList($part)];
            } elseif (preg_match('/\ACONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+FOREIGN\s+KEY\b/i', $part, $matches) === 1) {
                $table['foreign_keys'][] = self::foreignKey($part, $matches[1]);
            } elseif (preg_match('/\ACONSTRAINT\s+`?([A-Za-z0-9_]+)`?\s+CHECK\s*\(/i', $part, $matches) === 1) {
                $paren = strpos($part, '(');
                if ($paren === false) {
                    throw new \InvalidArgumentException('sql_definition_unreadable');
                }
                $table['checks'][] = ['name' => $matches[1], 'clause' => self::normalizeCheck(self::insideParens($part, $paren))];
            } else {
                $table['columns'][] = self::column($part);
            }
        }
        self::addImplicitForeignKeyIndexes($table);
        $table['indexes'] = self::sortedByName($table['indexes']);
        $table['foreign_keys'] = self::sortedByName($table['foreign_keys']);
        $table['checks'] = self::sortedByName($table['checks']);
        return $table;
    }

    /** @return array{timing:string,event:string,table:string,body:string} */
    private static function triggerFromSql(string $statement): array
    {
        if (preg_match('/\ACREATE\s+TRIGGER\s+`?[A-Za-z0-9_]+`?\s+(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+`?([A-Za-z0-9_]+)`?\s+FOR\s+EACH\s+ROW\s*(.*)\z/is', ltrim($statement), $matches) !== 1) {
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        return [
            'timing' => strtoupper($matches[1]),
            'event' => strtoupper($matches[2]),
            'table' => strtolower($matches[3]),
            'body' => self::normalize($matches[4]),
        ];
    }

    /** @return array{name:string,columns:list<string>,ref_table:string,ref_columns:list<string>} */
    private static function foreignKey(string $part, string $name): array
    {
        $paren = strpos($part, '(');
        if ($paren === false || preg_match('/\bREFERENCES\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $part, $referenced, PREG_OFFSET_CAPTURE) !== 1) {
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        $refParen = strpos($part, '(', $referenced[0][1]);
        if ($refParen === false) {
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        return [
            'name' => $name,
            'columns' => self::names(self::insideParens($part, $paren)),
            'ref_table' => strtolower($referenced[1][0]),
            'ref_columns' => self::names(self::insideParens($part, $refParen)),
        ];
    }

    /** @return array<string,mixed> */
    private static function column(string $part): array
    {
        if (preg_match('/\A`?([A-Za-z0-9_]+)`?\s+/', $part, $matches) !== 1) {
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        $rest = trim(substr($part, strlen($matches[0])));
        $generated = null;
        if (preg_match('/\bGENERATED\s+ALWAYS\s+AS\s*\(/i', $rest, $marker, PREG_OFFSET_CAPTURE) === 1) {
            $paren = strpos($rest, '(', $marker[0][1]);
            if ($paren === false) {
                throw new \InvalidArgumentException('sql_definition_unreadable');
            }
            $generated = self::normalize(self::insideParens($rest, $paren));
            $rest = trim(substr($rest, 0, $marker[0][1]));
        }
        $charset = null;
        $collation = null;
        if (preg_match('/\bCHARACTER\s+SET\s+([A-Za-z0-9_]+)/i', $rest, $set) === 1) {
            $charset = strtolower($set[1]);
        }
        if (preg_match('/\bCOLLATE\s+([A-Za-z0-9_]+)/i', $rest, $collate) === 1) {
            $collation = strtolower($collate[1]);
        }
        $default = null;
        if (preg_match("/\bDEFAULT\s+(NULL|'(?:''|[^'])*'|-?[0-9]+)/i", $part, $defaultMatch) === 1) {
            $default = self::defaultValue($defaultMatch[1]);
        }
        return [
            'name' => strtolower($matches[1]),
            'type' => self::normalizeType(self::cutType($rest)),
            'nullable' => preg_match('/\bNOT\s+NULL\b/i', $part) !== 1,
            'charset' => $charset,
            'collation' => $collation,
            'default' => $default,
            'auto_increment' => preg_match('/\bAUTO_INCREMENT\b/i', $part) === 1,
            'generated' => $generated,
        ];
    }

    private static function cutType(string $rest): string
    {
        $length = strlen($rest);
        $depth = 0;
        $inSingle = false;
        $type = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $rest[$i];
            $next = $i + 1 < $length ? $rest[$i + 1] : '';
            if ($char === "'") {
                if ($inSingle && $next === "'") {
                    $type .= "''";
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                $type .= $char;
                continue;
            }
            if (!$inSingle && $depth === 0 && self::modifierAt($rest, $i)) {
                break;
            }
            $type .= $char;
            if (!$inSingle && $char === '(') {
                $depth++;
            } elseif (!$inSingle && $char === ')') {
                $depth--;
            }
        }
        return $type;
    }

    private static function modifierAt(string $sql, int $index): bool
    {
        if ($index > 0 && preg_match('/[A-Za-z0-9_]/', $sql[$index - 1]) === 1) {
            return false;
        }
        if (preg_match('/\G[A-Za-z_]+/', $sql, $matches, 0, $index) !== 1) {
            return false;
        }
        return in_array(strtolower($matches[0]), [
            'not', 'null', 'default', 'auto_increment', 'character', 'collate',
            'comment', 'primary', 'unique', 'generated', 'constraint', 'references',
        ], true);
    }

    /** @return list<string> */
    private static function columnList(string $part): array
    {
        $paren = strpos($part, '(');
        if ($paren === false) {
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        return self::names(self::insideParens($part, $paren));
    }

    /** @return list<string> */
    private static function names(string $list): array
    {
        $names = [];
        foreach (explode(',', $list) as $name) {
            $name = strtolower(trim(str_replace('`', '', $name)));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private static function insideParens(string $sql, int $open): string
    {
        if (!isset($sql[$open]) || $sql[$open] !== '(') {
            throw new \InvalidArgumentException('sql_definition_unreadable');
        }
        $depth = 0;
        $inSingle = false;
        $length = strlen($sql);
        for ($i = $open; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($char === "'") {
                if ($inSingle && $next === "'") {
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                continue;
            }
            if ($inSingle) {
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $open + 1, $i - $open - 1);
                }
            }
        }
        throw new \InvalidArgumentException('sql_definition_unreadable');
    }

    /** @return list<string> */
    private static function splitComma(string $sql): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inSingle = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($char === "'") {
                if ($inSingle && $next === "'") {
                    $buffer .= "''";
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                $buffer .= $char;
                continue;
            }
            if (!$inSingle && $char === '(') {
                $depth++;
            } elseif (!$inSingle && $char === ')') {
                $depth--;
            } elseif (!$inSingle && $depth === 0 && $char === ',') {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }
        return $parts;
    }

    private static function normalize(string $sql): string
    {
        $sql = strtolower(str_replace('`', '', $sql));
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/\s*([(),])\s*/', '$1', $sql) ?? $sql;
        $sql = trim($sql);
        if (str_starts_with($sql, '(') && str_ends_with($sql, ')') && self::fullyWrapped($sql)) {
            $sql = trim(substr($sql, 1, -1));
        }
        return $sql;
    }

    private static function fullyWrapped(string $sql): bool
    {
        $depth = 0;
        $inSingle = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($char === "'") {
                if ($inSingle && $next === "'") {
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                continue;
            }
            if ($inSingle) {
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0 && $i !== $length - 1) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /**
     * MariaDB 11.8 writes the historical default display width into COLUMN_TYPE
     * when the migration omits one. An explicit non-default width is kept.
     */
    private static function normalizeType(string $sql): string
    {
        return self::stripDefaultIntegerDisplayWidth(self::normalize($sql));
    }

    private static function stripDefaultIntegerDisplayWidth(string $sql): string
    {
        $unsigned = [
            'tinyint(3)unsigned' => 'tinyint unsigned',
            'smallint(5)unsigned' => 'smallint unsigned',
            'mediumint(8)unsigned' => 'mediumint unsigned',
            'integer(10)unsigned' => 'integer unsigned',
            'int(10)unsigned' => 'int unsigned',
            'bigint(20)unsigned' => 'bigint unsigned',
        ];
        $sql = strtr($sql, $unsigned);
        $signed = [
            'tinyint(4)' => 'tinyint',
            'smallint(6)' => 'smallint',
            'mediumint(9)' => 'mediumint',
            'integer(11)' => 'integer',
            'int(11)' => 'int',
            'bigint(20)' => 'bigint',
        ];
        return preg_replace_callback(
            '/\b(?:tinyint\(4\)|smallint\(6\)|mediumint\(9\)|integer\(11\)|int\(11\)|bigint\(20\))(?!unsigned)/',
            static fn (array $match): string => $signed[$match[0]] ?? $match[0],
            $sql
        ) ?? $sql;
    }

    /**
     * CHECK text is compared after redundant grouping parentheses are removed.
     * Parentheses that change AND/OR precedence stay in place.
     */
    private static function normalizeCheck(string $sql): string
    {
        $sql = self::normalize($sql);
        return self::canonicalBoolean($sql) ?? $sql;
    }

    private static function canonicalBoolean(string $sql): ?string
    {
        $tokens = self::booleanTokens($sql);
        if ($tokens === null) {
            return null;
        }
        $index = 0;
        $expr = self::parseOr($tokens, $index);
        if ($expr === null || $index !== count($tokens)) {
            return null;
        }
        return self::printBoolean($expr, 0);
    }

    /** @return list<array{k:string,v?:string}>|null */
    private static function booleanTokens(string $sql): ?array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];
            if ($char === ' ') {
                $i++;
                continue;
            }
            if ($char === '(') {
                $tokens[] = ['k' => 'lp'];
                $i++;
                continue;
            }
            if ($char === ')') {
                $tokens[] = ['k' => 'rp'];
                $i++;
                continue;
            }
            if ($char === ',') {
                $tokens[] = ['k' => 'comma'];
                $i++;
                continue;
            }
            if ($char === "'") {
                $value = '';
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === "'") {
                        if ($i + 1 < $length && $sql[$i + 1] === "'") {
                            $value .= "'";
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    $value .= $sql[$i];
                    $i++;
                }
                if ($i >= $length || $sql[$i] !== "'") {
                    return null;
                }
                $tokens[] = ['k' => 'str', 'v' => $value];
                $i++;
                continue;
            }
            if (preg_match('/\G(?:<>|!=|>=|<=|=|>|<)/', $sql, $match, 0, $i) === 1) {
                $tokens[] = ['k' => 'op', 'v' => $match[0]];
                $i += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G[0-9]+/', $sql, $match, 0, $i) === 1) {
                $tokens[] = ['k' => 'num', 'v' => $match[0]];
                $i += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G[a-z_][a-z0-9_]*/', $sql, $match, 0, $i) === 1) {
                $word = $match[0];
                $tokens[] = in_array($word, ['and', 'or', 'not', 'in', 'is', 'null'], true)
                    ? ['k' => 'kw', 'v' => $word]
                    : ['k' => 'id', 'v' => $word];
                $i += strlen($word);
                continue;
            }
            return null;
        }
        return $tokens;
    }

    /** @param list<array{k:string,v?:string}> $tokens */
    private static function parseOr(array $tokens, int &$index): ?array
    {
        $left = self::parseAnd($tokens, $index);
        if ($left === null) {
            return null;
        }
        $items = [$left];
        while (self::keywordAt($tokens, $index, 'or')) {
            $index++;
            $right = self::parseAnd($tokens, $index);
            if ($right === null) {
                return null;
            }
            $items[] = $right;
        }
        return count($items) === 1 ? $left : ['t' => 'or', 'prec' => 1, 'items' => $items];
    }

    /** @param list<array{k:string,v?:string}> $tokens */
    private static function parseAnd(array $tokens, int &$index): ?array
    {
        $left = self::parseNot($tokens, $index);
        if ($left === null) {
            return null;
        }
        $items = [$left];
        while (self::keywordAt($tokens, $index, 'and')) {
            $index++;
            $right = self::parseNot($tokens, $index);
            if ($right === null) {
                return null;
            }
            $items[] = $right;
        }
        return count($items) === 1 ? $left : ['t' => 'and', 'prec' => 2, 'items' => $items];
    }

    /** @param list<array{k:string,v?:string}> $tokens */
    private static function parseNot(array $tokens, int &$index): ?array
    {
        if (self::keywordAt($tokens, $index, 'not') && !self::keywordAt($tokens, $index + 1, 'in')) {
            $index++;
            $item = self::parseNot($tokens, $index);
            if ($item === null) {
                return null;
            }
            return ['t' => 'not', 'prec' => 3, 'item' => $item];
        }
        return self::parseComparison($tokens, $index);
    }

    /** @param list<array{k:string,v?:string}> $tokens */
    private static function parseComparison(array $tokens, int &$index): ?array
    {
        $left = self::parsePrimary($tokens, $index);
        if ($left === null) {
            return null;
        }
        if (self::keywordAt($tokens, $index, 'is')) {
            $index++;
            $negated = false;
            if (self::keywordAt($tokens, $index, 'not')) {
                $negated = true;
                $index++;
            }
            if (!self::keywordAt($tokens, $index, 'null')) {
                return null;
            }
            $index++;
            return ['t' => 'isnull', 'prec' => 4, 'not' => $negated, 'item' => $left];
        }
        $negated = false;
        if (self::keywordAt($tokens, $index, 'not')) {
            if (!self::keywordAt($tokens, $index + 1, 'in')) {
                return null;
            }
            $negated = true;
            $index++;
        }
        if (self::keywordAt($tokens, $index, 'in')) {
            $index++;
            if (($tokens[$index]['k'] ?? '') !== 'lp') {
                return null;
            }
            $index++;
            $list = [];
            while (true) {
                $item = self::parsePrimary($tokens, $index);
                if ($item === null) {
                    return null;
                }
                $list[] = $item;
                if (($tokens[$index]['k'] ?? '') === 'comma') {
                    $index++;
                    continue;
                }
                break;
            }
            if (($tokens[$index]['k'] ?? '') !== 'rp') {
                return null;
            }
            $index++;
            return ['t' => 'in', 'prec' => 4, 'not' => $negated, 'item' => $left, 'list' => $list];
        }
        if (($tokens[$index]['k'] ?? '') === 'op') {
            $operator = (string) $tokens[$index]['v'];
            $index++;
            $right = self::parsePrimary($tokens, $index);
            if ($right === null) {
                return null;
            }
            return ['t' => 'cmp', 'prec' => 4, 'op' => $operator, 'left' => $left, 'right' => $right];
        }
        return $left;
    }

    /** @param list<array{k:string,v?:string}> $tokens */
    private static function parsePrimary(array $tokens, int &$index): ?array
    {
        $kind = $tokens[$index]['k'] ?? '';
        if ($kind === 'lp') {
            $index++;
            $expr = self::parseOr($tokens, $index);
            if ($expr === null || ($tokens[$index]['k'] ?? '') !== 'rp') {
                return null;
            }
            $index++;
            return $expr;
        }
        if ($kind === 'str') {
            $value = (string) $tokens[$index]['v'];
            $index++;
            return ['t' => 'atom', 'prec' => 5, 'text' => "'" . str_replace("'", "''", $value) . "'"];
        }
        if ($kind === 'num' || $kind === 'id') {
            $text = (string) $tokens[$index]['v'];
            $index++;
            return ['t' => 'atom', 'prec' => 5, 'text' => $text];
        }
        return null;
    }

    /** @param list<array{k:string,v?:string}> $tokens */
    private static function keywordAt(array $tokens, int $index, string $word): bool
    {
        return ($tokens[$index]['k'] ?? '') === 'kw' && ($tokens[$index]['v'] ?? '') === $word;
    }

    private static function printBoolean(array $node, int $parentPrec): string
    {
        $prec = (int) $node['prec'];
        $text = match ($node['t']) {
            'or', 'and' => implode(
                $node['t'] === 'or' ? ' or ' : ' and ',
                array_map(static fn (array $child): string => self::printBoolean($child, $prec), $node['items'])
            ),
            'not' => 'not ' . self::printBoolean($node['item'], $prec),
            'isnull' => self::printBoolean($node['item'], $prec) . ($node['not'] ? ' is not null' : ' is null'),
            'in' => self::printBoolean($node['item'], $prec)
                . ($node['not'] ? ' not in(' : ' in(')
                . implode(',', array_map(static fn (array $child): string => self::printBoolean($child, 0), $node['list']))
                . ')',
            'cmp' => self::printBoolean($node['left'], $prec) . ' ' . $node['op'] . ' ' . self::printBoolean($node['right'], $prec),
            'atom' => $node['text'],
            default => '',
        };
        if ($text === '') {
            return '';
        }
        if ($prec < $parentPrec) {
            return '(' . $text . ')';
        }
        return $text;
    }

    /** @param array<string,mixed> $table
     *  @return array<string,mixed>
     */
    private static function absorbJsonColumns(array $table): array
    {
        $checks = $table['checks'];
        $columns = $table['columns'];
        $drop = [];
        $convert = [];
        foreach ($checks as $index => $check) {
            if (preg_match('/\Ajson_valid\(([a-z0-9_]+)\)\z/', (string) $check['clause'], $match) !== 1) {
                continue;
            }
            $column = $match[1];
            if (strtolower((string) $check['name']) !== $column) {
                continue;
            }
            foreach ($table['columns'] as $columnIndex => $stored) {
                if ($stored['name'] !== $column) {
                    continue;
                }
                if ($stored['type'] !== 'longtext' || $stored['charset'] !== 'utf8mb4' || $stored['collation'] !== 'utf8mb4_bin') {
                    continue;
                }
                $convert[$columnIndex] = true;
                $drop[$index] = true;
            }
        }
        foreach (array_keys($convert) as $columnIndex) {
            $columns[$columnIndex]['type'] = 'json';
            $columns[$columnIndex]['charset'] = null;
            $columns[$columnIndex]['collation'] = null;
        }
        if ($drop === []) {
            return $table;
        }
        $kept = [];
        foreach ($checks as $index => $check) {
            if (!isset($drop[$index])) {
                $kept[] = $check;
            }
        }
        $table['columns'] = $columns;
        $table['checks'] = $kept;
        return $table;
    }

    /**
     * MariaDB creates a non-unique index named after a foreign key when no
     * existing index already starts with the referenced columns.
     *
     * @param array<string,mixed> $table
     */
    private static function addImplicitForeignKeyIndexes(array &$table): void
    {
        foreach ($table['foreign_keys'] as $foreignKey) {
            if (self::indexCovers($table['indexes'], $foreignKey['columns'])) {
                continue;
            }
            $table['indexes'][] = [
                'name' => $foreignKey['name'],
                'unique' => false,
                'columns' => $foreignKey['columns'],
            ];
        }
    }

    /**
     * @param list<array{columns:list<string>}> $indexes
     * @param list<string> $columns
     */
    private static function indexCovers(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }
        return false;
    }

    private static function defaultValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (preg_match("/\A'(.*)'\z/s", $value, $matches) === 1) {
            return str_replace("''", "'", $matches[1]);
        }
        return $value;
    }

    /**
     * @param list<array{name:string}> $items
     * @return list<array{name:string}>
     */
    private static function sortedByName(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        return $items;
    }

    private static function canonical(mixed $value): string
    {
        $encoded = json_encode(self::sortValue($value));
        if (!is_string($encoded)) {
            throw new \InvalidArgumentException('definition_unreadable');
        }
        return $encoded;
    }

    private static function sortValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortValue'], $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortValue($item);
        }
        return $value;
    }
}
