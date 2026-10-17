<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * The Check difficulty a check declares: its default and the optional min..max a test roll may set.
 */
final readonly class CheckDifficultyRange
{
    public function __construct(
        public int $default,
        public ?int $min = null,
        public ?int $max = null,
    ) {
    }

    public function allows(int $value): bool
    {
        return (null === $this->min || $value >= $this->min) && (null === $this->max || $value <= $this->max);
    }
}
