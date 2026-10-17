<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * A bounded integer. Without a bound the value ranges over the contract's limit of one million.
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
}
