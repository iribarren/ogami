<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The chaos factor range of a likelihood oracle of a draft. Documents the contract only.
 */
#[OA\Schema(
    description: 'The effective target is the level\'s target + (chaosFactor − neutral) × shiftPerPoint, clamped to 0…sides; min ≤ neutral ≤ max.',
    required: ['min', 'max', 'neutral', 'shiftPerPoint'],
)]
final readonly class GameSystemLikelihoodChaos
{
    public function __construct(
        #[OA\Property(example: 1)]
        public int $min,
        #[OA\Property(example: 9)]
        public int $max,
        #[OA\Property(description: 'The chaos factor used when none is given.', example: 5)]
        public int $neutral,
        #[OA\Property(description: 'Target points added per chaos point above neutral; 0 to sides.', example: 5, minimum: 0)]
        public int $shiftPerPoint,
        #[OA\Property(description: 'The tracker holding the chaos factor (schema version 2). Not edited by the oracle editor.', example: 'chaos')]
        public ?string $tracker,
    ) {
    }
}
