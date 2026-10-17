<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * A group of the sheet layout (ADR 0031): a box of fields with its own columns, placed in the
 * sheet's columns. Its fields are the SheetTemplate fields that name it, in definition order.
 */
final readonly class SheetGroup
{
    public function __construct(
        public string $name,
        public int $columns = 1,
        public int $span = 1,
    ) {
    }
}
