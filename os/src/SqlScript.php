<?php
declare(strict_types=1);

namespace BesharOs;

final class SqlScript
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $length = strlen($sql);
        $buffer = '';
        $statements = [];
        $beginDepth = 0;
        $caseDepth = 0;
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLine = false;
        $inBlock = false;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLine) {
                if ($char === "\n") {
                    $inLine = false;
                    $buffer .= "\n";
                }
                $i++;
                continue;
            }
            if ($inBlock) {
                if ($char === '*' && $next === '/') {
                    $inBlock = false;
                    $buffer .= ' ';
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }
            if (!$inSingle && !$inDouble && !$inBacktick && self::startsComment($sql, $i)) {
                if ($char === '#') {
                    $inLine = true;
                    $i++;
                    continue;
                }
                if ($char === '-' && $next === '-') {
                    $inLine = true;
                    $i += 2;
                    continue;
                }
                $inBlock = true;
                $i += 2;
                continue;
            }
            if ($char === '`' && !$inSingle && !$inDouble) {
                if ($inBacktick && $next === '`') {
                    $buffer .= '``';
                    $i += 2;
                    continue;
                }
                $inBacktick = !$inBacktick;
                $buffer .= $char;
                $i++;
                continue;
            }
            if ($inBacktick) {
                $buffer .= $char;
                $i++;
                continue;
            }
            if ($char === "'" && !$inDouble) {
                if ($inSingle && $next === "'") {
                    $buffer .= "''";
                    $i += 2;
                    continue;
                }
                $inSingle = !$inSingle;
                $buffer .= $char;
                $i++;
                continue;
            }
            if ($char === '"' && !$inSingle) {
                if ($inDouble && $next === '"') {
                    $buffer .= '""';
                    $i += 2;
                    continue;
                }
                $inDouble = !$inDouble;
                $buffer .= $char;
                $i++;
                continue;
            }
            if (!$inSingle && !$inDouble && $char === ';' && $beginDepth === 0 && $caseDepth === 0) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                $i++;
                continue;
            }
            if (!$inSingle && !$inDouble && self::atWord($sql, $i)) {
                $word = self::readWord($sql, $i);
                $upper = strtoupper($word);
                if ($upper === 'DELIMITER' || $upper === 'PROCEDURE' || $upper === 'FUNCTION') {
                    throw new \InvalidArgumentException('sql_unsupported');
                }
                if ($upper === 'BEGIN') {
                    $beginDepth++;
                } elseif ($upper === 'CASE') {
                    $caseDepth++;
                } elseif ($upper === 'END') {
                    $following = strtoupper(self::nextWord($sql, $i + strlen($word)));
                    if ($following === 'IF') {
                        // END IF closes a conditional, not a BEGIN or CASE.
                    } elseif ($caseDepth > 0) {
                        $caseDepth--;
                    } elseif ($beginDepth > 0) {
                        $beginDepth--;
                    }
                }
                $buffer .= $word;
                $i += strlen($word);
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        if ($inSingle || $inDouble) {
            throw new \InvalidArgumentException('sql_unterminated_string');
        }
        if ($inBacktick) {
            throw new \InvalidArgumentException('sql_unterminated_identifier');
        }
        if ($inBlock) {
            throw new \InvalidArgumentException('sql_unterminated_comment');
        }
        if ($beginDepth !== 0 || $caseDepth !== 0) {
            throw new \InvalidArgumentException('sql_unbalanced');
        }
        if (trim($buffer) !== '') {
            throw new \InvalidArgumentException('sql_unterminated_statement');
        }
        return $statements;
    }

    public static function objectName(string $statement, string $kind): ?string
    {
        $patterns = [
            'table' => '/\ACREATE\s+TABLE\s+`?([a-z0-9_]+)`?/i',
            'trigger' => '/\ACREATE\s+TRIGGER\s+`?([a-z0-9_]+)`?/i',
            'drop_table' => '/\ADROP\s+TABLE\s+IF\s+EXISTS\s+`?([a-z0-9_]+)`?/i',
            'drop_trigger' => '/\ADROP\s+TRIGGER\s+IF\s+EXISTS\s+`?([a-z0-9_]+)`?/i',
        ];
        if (!isset($patterns[$kind]) || preg_match($patterns[$kind], ltrim($statement), $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    private static function startsComment(string $sql, int $index): bool
    {
        $char = $sql[$index];
        $next = $index + 1 < strlen($sql) ? $sql[$index + 1] : '';
        if ($char === '#') {
            return true;
        }
        if ($char === '/' && $next === '*') {
            return true;
        }
        if ($char === '-' && $next === '-') {
            $previous = $index === 0 ? "\n" : $sql[$index - 1];
            return preg_match('/\s/', $previous) === 1;
        }
        return false;
    }

    private static function atWord(string $sql, int $index): bool
    {
        $char = $sql[$index];
        if (preg_match('/[A-Za-z_]/', $char) !== 1) {
            return false;
        }
        if ($index === 0) {
            return true;
        }
        return preg_match('/[A-Za-z0-9_]/', $sql[$index - 1]) !== 1;
    }

    private static function readWord(string $sql, int $index): string
    {
        if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $matches, 0, $index) !== 1) {
            return $sql[$index];
        }
        return $matches[0];
    }

    private static function nextWord(string $sql, int $index): string
    {
        $length = strlen($sql);
        while ($index < $length) {
            if (self::startsComment($sql, $index)) {
                $char = $sql[$index];
                $next = $index + 1 < $length ? $sql[$index + 1] : '';
                if ($char === '/' && $next === '*') {
                    $end = strpos($sql, '*/', $index + 2);
                    $index = $end === false ? $length : $end + 2;
                    continue;
                }
                $line = strpos($sql, "\n", $index);
                $index = $line === false ? $length : $line + 1;
                continue;
            }
            if (preg_match('/\s/', $sql[$index]) === 1) {
                $index++;
                continue;
            }
            break;
        }
        if ($index >= $length || !self::atWord($sql, $index)) {
            return '';
        }
        return self::readWord($sql, $index);
    }
}
