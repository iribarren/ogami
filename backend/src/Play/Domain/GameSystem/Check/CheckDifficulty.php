<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * The Check difficulty a check declares (ADR 0024): the player sets it at roll time, within min..max
 * when those are set, and band formulas read it as {check:difficulty}.
 */
final readonly class CheckDifficulty
{
    /**
     * @throws \InvalidArgumentException when min > default or max < default
     */
    public function __construct(
        public string $label,
        public int $default,
        public ?int $min = null,
        public ?int $max = null,
    ) {
        if (null !== $min && $min > $default) {
            throw new \InvalidArgumentException(\sprintf('The difficulty min (%d) must be at most its default (%d).', $min, $default));
        }

        if (null !== $max && $max < $default) {
            throw new \InvalidArgumentException(\sprintf('The difficulty max (%d) must be at least its default (%d).', $max, $default));
        }
    }

    public function allows(int $value): bool
    {
        return (null === $this->min || $value >= $this->min) && (null === $this->max || $value <= $this->max);
    }
}
