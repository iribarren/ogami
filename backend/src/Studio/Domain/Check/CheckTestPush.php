<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * The push a saved pool check declares (ADR 0041), as far as a test roll shows it: its label. The
 * cost is never applied in a test roll, so its effects are not read.
 */
final readonly class CheckTestPush
{
    public function __construct(
        public string $label,
    ) {
    }
}
