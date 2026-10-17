<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Formula\Domain\Formula;

/**
 * An Outcome band of a check as a test roll reads it: its upper bound is an integer, or a formula
 * (a tracker bound is the formula {tracker:key}), or none for the last band, which catches the rest.
 */
final readonly class OutcomeBand
{
    public function __construct(
        public string $key,
        public string $label,
        public int|Formula|null $upTo = null,
    ) {
    }
}
