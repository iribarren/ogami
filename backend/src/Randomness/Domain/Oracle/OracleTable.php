<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * A table of entries an oracle selects one of at random.
 *
 * A ranged table rolls its dice and selects the entry whose min…max covers the total; ranges
 * never overlap, and a total that no range covers fails at resolution. A weighted table rolls
 * 1dW, W being the total weight, and walks the cumulative weights in entry order.
 */
final readonly class OracleTable
{
    public const int MAX_KEY_LENGTH = 64;
    public const int MAX_NAME_LENGTH = 500;
    public const int MAX_TEXT_LENGTH = 500;
    public const int MAX_ENTRIES = 1000;
    public const int MAX_TOTAL_WEIGHT = 1_000_000;

    private string $name;
    private ?int $totalWeight;

    /**
     * @param list<OracleTableEntry> $entries
     *
     * @throws InvalidOracleTable
     */
    private function __construct(
        private string $key,
        string $name,
        private ?DiceExpression $dice,
        private array $entries,
    ) {
        self::assertKey($key);

        $this->name = trim($name);
        $nameLength = mb_strlen($this->name);
        if (0 === $nameLength || $nameLength > self::MAX_NAME_LENGTH) {
            throw InvalidOracleTable::nameLength($key, $nameLength, self::MAX_NAME_LENGTH);
        }

        if ([] === $entries || \count($entries) > self::MAX_ENTRIES) {
            throw InvalidOracleTable::entryCountOutOfRange($key, \count($entries), self::MAX_ENTRIES);
        }

        foreach ($entries as $index => $entry) {
            $this->assertEntry($index + 1, $entry);
        }

        if (!$dice instanceof DiceExpression) {
            $this->totalWeight = $this->sumWeights();
        } else {
            $this->totalWeight = null;
            $this->assertNoOverlap();
        }
    }

    /**
     * @param list<OracleTableEntry> $entries
     *
     * @throws InvalidOracleTable
     */
    public static function ranged(string $key, string $name, DiceExpression $dice, array $entries): self
    {
        return new self($key, $name, $dice, $entries);
    }

    /**
     * @param list<OracleTableEntry> $entries
     *
     * @throws InvalidOracleTable
     */
    public static function weighted(string $key, string $name, array $entries): self
    {
        return new self($key, $name, null, $entries);
    }

    /**
     * @throws InvalidOracleTable when the key is not 1 to 64 characters among a-z, 0-9 and "-"
     */
    public static function assertKey(string $key): void
    {
        if (1 !== preg_match('/^[a-z0-9-]{1,'.self::MAX_KEY_LENGTH.'}$/D', $key)) {
            throw InvalidOracleTable::invalidKey($key, self::MAX_KEY_LENGTH);
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The dice of a ranged table; null for a weighted table.
     */
    public function dice(): ?DiceExpression
    {
        return $this->dice;
    }

    public function isRanged(): bool
    {
        return $this->dice instanceof DiceExpression;
    }

    /**
     * @return list<OracleTableEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The sum of the entry weights of a weighted table; null for a ranged table.
     */
    public function totalWeight(): ?int
    {
        return $this->totalWeight;
    }

    /**
     * The keys of the tables this table's entries nest, without duplicates, in entry order.
     *
     * @return list<string>
     */
    public function nestedTableKeys(): array
    {
        $keys = [];
        foreach ($this->entries as $entry) {
            $nested = $entry->nestedTableKey();
            if (null !== $nested && !\in_array($nested, $keys, true)) {
                $keys[] = $nested;
            }
        }

        return $keys;
    }

    /**
     * Rolls once on this table, without following a nested table.
     *
     * @throws InvalidOracleTable when the dice cannot be evaluated or the total matches no entry
     */
    public function roll(RandomNumberGenerator $random): OracleTableStep
    {
        if (!$this->dice instanceof DiceExpression) {
            $weight = $this->totalWeight ?? 0;
            $dice = '1d'.$weight;
            $total = $random->between(1, $weight);
            $entry = $this->entryAtWeight($total);
        } else {
            $dice = $this->dice->notation();
            try {
                $total = $this->dice->roll($random)->total();
            } catch (InvalidDiceExpression $invalid) {
                throw InvalidOracleTable::unrollableDice($this->key, $dice, $invalid->getMessage());
            }

            $entry = $this->entryCovering($total);
        }

        return new OracleTableStep($this->key, $this->name, $dice, $total, $entry->text(), $entry->nestedTableKey());
    }

    private function assertEntry(int $position, OracleTableEntry $entry): void
    {
        if ($this->dice instanceof DiceExpression && !$entry->isRanged()) {
            throw InvalidOracleTable::unrangedEntryInRangedTable($this->key, $position);
        }

        if (!$this->dice instanceof DiceExpression && $entry->isRanged()) {
            throw InvalidOracleTable::rangedEntryInWeightedTable($this->key, $position);
        }

        $nested = $entry->nestedTableKey();
        $textLength = mb_strlen($entry->text());
        if (null === $nested && (0 === $textLength || $textLength > self::MAX_TEXT_LENGTH)) {
            throw InvalidOracleTable::textLength($this->key, $position, $textLength, self::MAX_TEXT_LENGTH);
        }

        if (null !== $nested) {
            self::assertKey($nested);
            if ($textLength > self::MAX_TEXT_LENGTH) {
                throw InvalidOracleTable::nestingTextTooLong($this->key, $position, $textLength, self::MAX_TEXT_LENGTH);
            }
        }

        $min = $entry->min();
        $max = $entry->max();
        if (null !== $min && null !== $max && $min > $max) {
            throw InvalidOracleTable::inverseRange($this->key, $position, $min, $max);
        }

        $weight = $entry->weight();
        if (null !== $weight && $weight < 1) {
            throw InvalidOracleTable::weightTooLow($this->key, $position, $weight);
        }
    }

    private function sumWeights(): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            // Checked after each entry, so the sum never exceeds the limit by more than one weight.
            $total += $entry->weight() ?? 0;
            if ($total > self::MAX_TOTAL_WEIGHT) {
                throw InvalidOracleTable::totalWeightTooHigh($this->key, $total, self::MAX_TOTAL_WEIGHT);
            }
        }

        return $total;
    }

    private function assertNoOverlap(): void
    {
        $positions = array_keys($this->entries);
        usort($positions, fn (int $a, int $b): int => [$this->entries[$a]->min(), $a] <=> [$this->entries[$b]->min(), $b]);

        // Sorted by min, a range overlaps an earlier one exactly when it starts at or before the highest max so far.
        $widest = null;
        foreach ($positions as $position) {
            $entry = $this->entries[$position];
            if (null !== $widest && $entry->min() <= $this->entries[$widest]->max()) {
                [$first, $second] = $widest < $position ? [$widest, $position] : [$position, $widest];

                throw InvalidOracleTable::overlappingRanges($this->key, $first + 1, $this->entries[$first], $second + 1, $this->entries[$second]);
            }

            if (null === $widest || $entry->max() > $this->entries[$widest]->max()) {
                $widest = $position;
            }
        }
    }

    private function entryCovering(int $total): OracleTableEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->covers($total)) {
                return $entry;
            }
        }

        throw InvalidOracleTable::uncoveredRoll($this->key, $total);
    }

    private function entryAtWeight(int $total): OracleTableEntry
    {
        $cumulative = 0;
        foreach ($this->entries as $entry) {
            $cumulative += $entry->weight() ?? 0;
            if ($total <= $cumulative) {
                return $entry;
            }
        }

        throw InvalidOracleTable::uncoveredRoll($this->key, $total);
    }
}
