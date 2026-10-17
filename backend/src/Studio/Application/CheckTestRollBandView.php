<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * An Outcome band of a test-rolled check with the upper bound it had for the samples; the last
 * band has none.
 */
final readonly class CheckTestRollBandView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?int $bound,
    ) {
    }
}
