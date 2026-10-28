<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The currency of the draft's sheet (ADR 0045): the number field Characters spend, and the unit
 * its amounts are shown with, if any.
 */
final readonly class SheetCurrencyView
{
    public function __construct(
        public string $field,
        public ?string $unit,
    ) {
    }
}
