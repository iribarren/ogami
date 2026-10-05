<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * Oracle tables that may nest each other by key.
 *
 * Keys are unique, every nested key names a table of the set, nesting never cycles and goes at
 * most 10 levels deep below the table consulted, so a resolution rolls on at most 11 tables.
 */
final readonly class OracleTableSet
{
    public const int MAX_TABLES = 50;
    public const int MAX_NESTING_DEPTH = 10;

    /**
     * @param array<string, OracleTable> $tables by key, in definition order
     */
    private function __construct(
        private array $tables,
    ) {
    }

    /**
     * @throws InvalidOracleTable
     */
    public static function of(OracleTable ...$tables): self
    {
        $tables = array_values($tables);
        self::assertTableCount(\count($tables));

        $byKey = [];
        foreach ($tables as $table) {
            if (isset($byKey[$table->key()])) {
                throw InvalidOracleTable::duplicateKey($table->key());
            }

            $byKey[$table->key()] = $table;
        }

        foreach ($tables as $table) {
            foreach ($table->entries() as $index => $entry) {
                $nested = $entry->nestedTableKey();
                if (null !== $nested && !isset($byKey[$nested])) {
                    throw InvalidOracleTable::unknownNestedTable($table->key(), $index + 1, $nested);
                }
            }
        }

        $depths = [];
        foreach ($tables as $table) {
            self::nestingDepth($byKey, $table->key(), [], $depths);
        }

        return new self($byKey);
    }

    /**
     * Builds a table set from decoded data such as JSON:
     *
     *     [{"key": "weather", "name": "Weather", "dice": "1d6",
     *       "entries": [{"min": 1, "max": 3, "text": "Clear"},
     *                   {"min": 4, "max": 6, "text": "Storm", "table": "storm-kind"}]},
     *      {"key": "storm-kind", "name": "Storm kind",
     *       "entries": [{"weight": 2, "text": "Thunder"}, {"text": "Hail"}]}]
     *
     * A table with "dice" is ranged and its entries have "min" and "max"; a table without is
     * weighted and its entries have an optional "weight" (1 by default). "text" may be omitted
     * on an entry that nests a "table".
     *
     * @param array<mixed> $tables
     *
     * @throws InvalidOracleTable
     */
    public static function fromArray(array $tables): self
    {
        if (!array_is_list($tables)) {
            throw InvalidOracleTable::tablesNotAList();
        }

        self::assertTableCount(\count($tables));

        return self::of(...array_map(
            static fn (mixed $table, int $index): OracleTable => self::tableFromArray($table, $index + 1),
            $tables,
            array_keys($tables),
        ));
    }

    /**
     * @return list<string> the table keys, in definition order
     */
    public function keys(): array
    {
        return array_keys($this->tables);
    }

    /**
     * @throws InvalidOracleTable when no table has this key
     */
    public function table(string $key): OracleTable
    {
        return $this->tables[$key] ?? throw InvalidOracleTable::unknownTable($key);
    }

    /**
     * Rolls on the table with this key, then on each nested table the selected entries name.
     *
     * @throws InvalidOracleTable when no table has this key, or a roll matches no entry
     */
    public function resolve(string $key, RandomNumberGenerator $random): OracleTableResult
    {
        $steps = [$this->table($key)->roll($random)];
        while (null !== ($nested = $steps[\count($steps) - 1]->nestedTableKey())) {
            $steps[] = $this->table($nested)->roll($random);
        }

        return new OracleTableResult($steps);
    }

    private static function assertTableCount(int $count): void
    {
        if (0 === $count || $count > self::MAX_TABLES) {
            throw InvalidOracleTable::tableCountOutOfRange($count, self::MAX_TABLES);
        }
    }

    /**
     * How many levels of tables nest below this one, rejecting cycles and excessive depth.
     *
     * @param array<string, OracleTable> $tables
     * @param list<string>               $path   the tables being visited, outermost first
     * @param array<string, int>         $depths the depth of every table already visited
     */
    private static function nestingDepth(array $tables, string $key, array $path, array &$depths): int
    {
        if (isset($depths[$key])) {
            return $depths[$key];
        }

        $cycleStart = array_search($key, $path, true);
        if (false !== $cycleStart) {
            throw InvalidOracleTable::cycle([...\array_slice($path, $cycleStart), $key]);
        }

        $depth = 0;
        foreach ($tables[$key]->nestedTableKeys() as $nested) {
            $depth = max($depth, 1 + self::nestingDepth($tables, $nested, [...$path, $key], $depths));
        }

        if ($depth > self::MAX_NESTING_DEPTH) {
            throw InvalidOracleTable::tooDeep($key, $depth, self::MAX_NESTING_DEPTH);
        }

        return $depths[$key] = $depth;
    }

    private static function tableFromArray(mixed $table, int $position): OracleTable
    {
        if (!\is_array($table)) {
            throw InvalidOracleTable::tableNotAnObject($position);
        }

        $key = $table['key'] ?? null;
        if (!\is_string($key)) {
            throw InvalidOracleTable::missingKey($position);
        }

        $name = $table['name'] ?? null;
        if (!\is_string($name)) {
            throw InvalidOracleTable::missingName($key);
        }

        $dice = $table['dice'] ?? null;
        if (null !== $dice && !\is_string($dice)) {
            throw InvalidOracleTable::diceNotAString($key);
        }

        $entries = $table['entries'] ?? null;
        if (!\is_array($entries) || !array_is_list($entries)) {
            throw InvalidOracleTable::missingEntries($key);
        }

        if (\count($entries) > OracleTable::MAX_ENTRIES) {
            throw InvalidOracleTable::entryCountOutOfRange($key, \count($entries), OracleTable::MAX_ENTRIES);
        }

        $entries = array_map(
            static fn (mixed $entry, int $index): OracleTableEntry => self::entryFromArray($entry, $key, $index + 1),
            $entries,
            array_keys($entries),
        );

        if (null === $dice) {
            return OracleTable::weighted($key, $name, $entries);
        }

        try {
            $expression = DiceExpression::fromString($dice);
        } catch (InvalidDiceExpression $invalid) {
            throw InvalidOracleTable::invalidDice($key, $dice, $invalid->getMessage());
        }

        return OracleTable::ranged($key, $name, $expression, $entries);
    }

    private static function entryFromArray(mixed $entry, string $table, int $position): OracleTableEntry
    {
        if (!\is_array($entry)) {
            throw InvalidOracleTable::entryNotAnObject($table, $position);
        }

        $text = self::stringField($entry, 'text', $table, $position) ?? '';
        $nested = self::stringField($entry, 'table', $table, $position);
        $min = self::intField($entry, 'min', $table, $position);
        $max = self::intField($entry, 'max', $table, $position);
        $weight = self::intField($entry, 'weight', $table, $position);

        if (null === $min && null === $max) {
            return OracleTableEntry::weighted($text, $weight ?? 1, $nested);
        }

        if (null !== $weight) {
            throw InvalidOracleTable::rangeAndWeight($table, $position);
        }

        if (null === $max) {
            throw InvalidOracleTable::incompleteRange($table, $position, 'min', 'max');
        }

        if (null === $min) {
            throw InvalidOracleTable::incompleteRange($table, $position, 'max', 'min');
        }

        return OracleTableEntry::ranged($text, $min, $max, $nested);
    }

    /**
     * @param array<mixed> $entry
     */
    private static function stringField(array $entry, string $field, string $table, int $position): ?string
    {
        $value = $entry[$field] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw InvalidOracleTable::entryFieldType($table, $position, $field, 'a string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function intField(array $entry, string $field, string $table, int $position): ?int
    {
        $value = $entry[$field] ?? null;
        if (null !== $value && !\is_int($value)) {
            throw InvalidOracleTable::entryFieldType($table, $position, $field, 'an integer');
        }

        return $value;
    }
}
