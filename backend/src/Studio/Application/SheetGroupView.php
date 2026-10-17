<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * One declared group of the sheet layout, with the defaults filled in.
 */
final readonly class SheetGroupView
{
    public function __construct(
        public string $name,
        public int $columns,
        public int $span,
    ) {
    }
}
