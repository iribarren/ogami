<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * One entry of an oracle table: ranged (selected when the roll falls in min…max) or weighted
 * (selected in proportion to its weight). It may nest another table of the same table set by key.
 *
 * Entries are validated by the oracle table that holds them.
 */
final readonly class OracleTableEntry
{
    private function __construct(
        private string $text,
        private ?int $min,
        private ?int $max,
        private ?int $weight,
        private ?string $nestedTableKey,
    ) {
    }

    public static function ranged(string $text, int $min, int $max, ?string $nestedTableKey = null): self
    {
        return new self(trim($text), $min, $max, null, $nestedTableKey);
    }

    public static function weighted(string $text, int $weight = 1, ?string $nestedTableKey = null): self
    {
        return new self(trim($text), null, null, $weight, $nestedTableKey);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function isRanged(): bool
    {
        return null === $this->weight;
    }

    /**
     * The lowest roll this entry covers; null for a weighted entry.
     */
    public function min(): ?int
    {
        return $this->min;
    }

    /**
     * The highest roll this entry covers; null for a weighted entry.
     */
    public function max(): ?int
    {
        return $this->max;
    }

    /**
     * Null for a ranged entry.
     */
    public function weight(): ?int
    {
        return $this->weight;
    }

    /**
     * The key of the table to roll next when this entry is selected, if any.
     */
    public function nestedTableKey(): ?string
    {
        return $this->nestedTableKey;
    }

    public function covers(int $roll): bool
    {
        return null !== $this->min && null !== $this->max && $this->min <= $roll && $roll <= $this->max;
    }
}
