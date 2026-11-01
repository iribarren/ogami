<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * The burn of a test-rolled challenge check (ADR 0049): the momentum spent and the score it made.
 * The cost the check declares is never applied, as a test roll has no campaign.
 */
final readonly class CheckTestBurn
{
    public function __construct(
        public int $momentum,
        public int $score,
    ) {
    }
}
