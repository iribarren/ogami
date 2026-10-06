<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\LikelihoodChaosView;
use OpenApi\Attributes as OA;

/**
 * The chaos factor range a likelihood oracle accepts, and its neutral value.
 */
#[OA\Schema(required: ['min', 'max', 'neutral'])]
final readonly class LikelihoodChaosResponse
{
    private function __construct(
        #[OA\Property(example: 1)]
        public int $min,
        #[OA\Property(example: 9)]
        public int $max,
        #[OA\Property(example: 5)]
        public int $neutral,
    ) {
    }

    public static function fromView(LikelihoodChaosView $view): self
    {
        return new self($view->min, $view->max, $view->neutral);
    }
}
