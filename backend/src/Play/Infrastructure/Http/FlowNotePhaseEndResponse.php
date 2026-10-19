<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * What the Flow decided on its own: an Effect ends the phase after the current scene.
 */
#[OA\Schema(required: ['kind', 'phase'])]
final readonly class FlowNotePhaseEndResponse
{
    public function __construct(
        #[OA\Property(enum: ['phaseEnd'])]
        public string $kind,
        #[OA\Property(description: 'The name of the phase.', example: 'Heist')]
        public string $phase,
    ) {
    }
}
