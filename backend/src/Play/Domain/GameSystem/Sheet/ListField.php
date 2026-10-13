<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * A list of short texts, such as an inventory or cyberware. Items are trimmed strings of 1 to 200
 * characters.
 */
final readonly class ListField extends Field
{
    public const int DEFAULT_ITEMS = 50;

    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        public ?string $itemLabel = null,
        public ?int $maxItems = null,
    ) {
        parent::__construct($key, $label, $hint, $group);
    }

    public function limit(): int
    {
        return $this->maxItems ?? self::DEFAULT_ITEMS;
    }
}
