<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The burn of a test-rolled challenge check (ADR 0049). The cost the check declares is never applied
 * in a test roll.
 */
#[OA\Schema(required: ['momentum', 'score'])]
final readonly class CheckTestRollBurnResponse
{
    private function __construct(
        #[OA\Property(description: 'The momentum burned: the value of the check\'s momentum field for the samples.', example: 9)]
        public int $momentum,
        #[OA\Property(description: 'The score the burn made: the momentum, capped at the check\'s score cap.', example: 9)]
        public int $score,
    ) {
    }

    public static function of(int $momentum, int $score): self
    {
        return new self($momentum, $score);
    }
}
