<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * The sheet's currency (ADR 0045): the number field a Character's money is kept in, and the unit
 * shown after amounts ("eb"). The SheetTemplate checks the field.
 */
final readonly class Currency
{
    public function __construct(
        public string $field,
        public ?string $unit = null,
    ) {
    }
}
