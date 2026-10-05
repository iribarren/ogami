<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * An oracle table definition breaks a rule, or a roll on it matches no entry.
 */
final class InvalidOracleTable extends \DomainException
{
    private const string EITHER_KIND = 'a table\'s entries are either all ranged or all weighted.';

    public static function tablesNotAList(): self
    {
        return new self('The oracle tables are a list.');
    }

    public static function tableCountOutOfRange(int $count, int $max): self
    {
        return new self(\sprintf('A table set has between 1 and %d oracle tables, %d given.', $max, $count));
    }

    public static function tableNotAnObject(int $position): self
    {
        return new self(\sprintf('Oracle table %d is not an object.', $position));
    }

    public static function missingKey(int $position): self
    {
        return new self(\sprintf('Oracle table %d has no "key" string.', $position));
    }

    public static function missingName(string $table): self
    {
        return new self(\sprintf('Oracle table "%s" has no "name" string.', $table));
    }

    public static function diceNotAString(string $table): self
    {
        return new self(\sprintf('The "dice" of oracle table "%s" is a string.', $table));
    }

    public static function missingEntries(string $table): self
    {
        return new self(\sprintf('Oracle table "%s" has no "entries" list.', $table));
    }

    public static function entryNotAnObject(string $table, int $entry): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" is not an object.', $entry, $table));
    }

    public static function entryFieldType(string $table, int $entry, string $field, string $type): self
    {
        return new self(\sprintf('The "%s" of entry %d of oracle table "%s" is %s.', $field, $entry, $table, $type));
    }

    public static function invalidKey(string $key, int $max): self
    {
        return new self(\sprintf('An oracle table key has 1 to %d characters among a-z, 0-9 and "-", "%s" given.', $max, $key));
    }

    public static function duplicateKey(string $key): self
    {
        return new self(\sprintf('Two oracle tables have the key "%s"; keys are unique within a table set.', $key));
    }

    public static function nameLength(string $table, int $length, int $max): self
    {
        return new self(\sprintf('Oracle table "%s" has a name of 1 to %d characters, %d given.', $table, $max, $length));
    }

    public static function entryCountOutOfRange(string $table, int $count, int $max): self
    {
        return new self(\sprintf('Oracle table "%s" has between 1 and %d entries, %d given.', $table, $max, $count));
    }

    public static function textLength(string $table, int $entry, int $length, int $max): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has a text of 1 to %d characters, %d given.', $entry, $table, $max, $length));
    }

    public static function nestingTextTooLong(string $table, int $entry, int $length, int $max): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has a text of at most %d characters, %d given.', $entry, $table, $max, $length));
    }

    public static function invalidDice(string $table, string $dice, string $reason): self
    {
        return new self(\sprintf('Oracle table "%s" has invalid dice "%s": %s', $table, $dice, $reason));
    }

    public static function incompleteRange(string $table, int $entry, string $given, string $missing): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has a "%s" but no "%s"; a range has both.', $entry, $table, $given, $missing));
    }

    public static function inverseRange(string $table, int $entry, int $min, int $max): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has a min (%d) greater than its max (%d).', $entry, $table, $min, $max));
    }

    public static function overlappingRanges(string $table, int $first, OracleTableEntry $a, int $second, OracleTableEntry $b): self
    {
        return new self(\sprintf(
            'Entries %d (%d to %d) and %d (%d to %d) of oracle table "%s" overlap.',
            $first,
            $a->min() ?? 0,
            $a->max() ?? 0,
            $second,
            $b->min() ?? 0,
            $b->max() ?? 0,
            $table,
        ));
    }

    public static function rangeAndWeight(string $table, int $entry): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has both a range and a weight; %s', $entry, $table, self::EITHER_KIND));
    }

    public static function unrangedEntryInRangedTable(string $table, int $entry): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has no range, but the table has dice; %s', $entry, $table, self::EITHER_KIND));
    }

    public static function rangedEntryInWeightedTable(string $table, int $entry): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has a range, but the table has no dice; %s', $entry, $table, self::EITHER_KIND));
    }

    public static function weightTooLow(string $table, int $entry, int $weight): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" has a weight of at least 1, %d given.', $entry, $table, $weight));
    }

    public static function totalWeightTooHigh(string $table, int $total, int $max): self
    {
        return new self(\sprintf('Oracle table "%s" has a total weight of at most %d, %d given.', $table, $max, $total));
    }

    public static function unknownNestedTable(string $table, int $entry, string $nested): self
    {
        return new self(\sprintf('Entry %d of oracle table "%s" nests "%s", but there is no oracle table "%s" in the table set.', $entry, $table, $nested, $nested));
    }

    /**
     * @param list<string> $keys the tables in nesting order, the first one repeated at the end
     */
    public static function cycle(array $keys): self
    {
        return new self('Oracle tables nest in a cycle: "'.implode('" > "', $keys).'".');
    }

    public static function tooDeep(string $table, int $depth, int $max): self
    {
        return new self(\sprintf('Oracle tables nest at most %d levels deep; "%s" nests %d levels.', $max, $table, $depth));
    }

    public static function unknownTable(string $table): self
    {
        return new self(\sprintf('There is no oracle table "%s" in the table set.', $table));
    }

    public static function uncoveredRoll(string $table, int $total): self
    {
        return new self(\sprintf('Rolled %d on table "%s", but no entry covers it.', $total, $table));
    }

    public static function unrollableDice(string $table, string $dice, string $reason): self
    {
        return new self(\sprintf('Table "%s" cannot roll "%s": %s', $table, $dice, $reason));
    }
}
