<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A tracker of the draft as the formula editor lists it: a counter's max or a clock's segments is
 * its max.
 */
final readonly class SheetTrackerView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?int $max,
    ) {
    }
}
