<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * One field of a SheetTemplate (ADR 0022): the key Character values are stored under, the label and
 * hint players see, and an optional group that lays consecutive fields out together. Each type adds
 * its own limits.
 */
abstract readonly class Field
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint = null,
        public ?string $group = null,
    ) {
    }
}
