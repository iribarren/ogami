<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A tracker of the draft a check formula or band bound may read, with the value a test roll starts
 * it at: a counter's initial value, 0 for a clock, null when the stored tracker states none.
 */
final readonly class CheckTrackerView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?int $initial,
    ) {
    }
}
