<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * What the Flow decided on its own: an Effect forced the Scene Type of the next scene.
 */
#[OA\Schema(required: ['kind', 'sceneType'])]
final readonly class FlowNoteNextSceneResponse
{
    public function __construct(
        #[OA\Property(enum: ['nextScene'])]
        public string $kind,
        #[OA\Property(description: 'The name of the Scene Type.', example: 'Briefing')]
        public string $sceneType,
    ) {
    }
}
