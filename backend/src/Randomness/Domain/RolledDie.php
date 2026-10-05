<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * One die of a roll: the face it landed on and whether it counts towards the total.
 */
final readonly class RolledDie
{
    private function __construct(
        private int $value,
        private bool $kept,
    ) {
    }

    public static function kept(int $value): self
    {
        return new self($value, true);
    }

    public static function dropped(int $value): self
    {
        return new self($value, false);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function isKept(): bool
    {
        return $this->kept;
    }
}
