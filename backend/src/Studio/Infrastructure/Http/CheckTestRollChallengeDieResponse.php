<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckTestChallengeDieView;
use OpenApi\Attributes as OA;

/**
 * A challenge die of a test roll (ADR 0049).
 */
#[OA\Schema(required: ['face', 'beaten'])]
final readonly class CheckTestRollChallengeDieResponse
{
    private function __construct(
        #[OA\Property(description: 'The face the die shows.', example: 5)]
        public int $face,
        #[OA\Property(description: 'Whether the score beat the die: the die shows a face strictly below it. A tie goes to the die. After a burn, against the burned score.', example: true)]
        public bool $beaten,
    ) {
    }

    public static function fromView(CheckTestChallengeDieView $view): self
    {
        return new self($view->face, $view->beaten);
    }
}
