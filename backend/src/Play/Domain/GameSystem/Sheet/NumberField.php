<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

use App\Randomness\Domain\DiceExpression;

/**
 * A bounded integer. Without a bound the value ranges over the contract's limit of one million.
 * A dice default ($roll, ADR 0040) is rolled by the server when a Character is created.
 */
final readonly class NumberField extends Field
{
    public const int LIMIT = 1_000_000;

    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?int $default = null,
        int $span = 1,
        public ?DiceExpression $roll = null,
    ) {
        parent::__construct($key, $label, $hint, $group, $span);
    }

    /**
     * The value of a Character that has none: the default, else 0 moved inside min..max.
     */
    public function initial(): int
    {
        return $this->default ?? max($this->min ?? -self::LIMIT, min(0, $this->max ?? self::LIMIT));
    }

    /**
     * $value moved inside min..max, as a rolled value is.
     */
    public function clamp(int $value): int
    {
        return max($this->min ?? -self::LIMIT, min($value, $this->max ?? self::LIMIT));
    }
}
