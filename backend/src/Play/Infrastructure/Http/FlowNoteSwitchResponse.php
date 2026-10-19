<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * What the Flow decided on its own: an Effect switched the Scene Type of the current scene.
 */
#[OA\Schema(required: ['kind', 'from', 'to'])]
final readonly class FlowNoteSwitchResponse
{
    public function __construct(
        #[OA\Property(enum: ['switch'])]
        public string $kind,
        #[OA\Property(description: 'The name of the Scene Type before.', example: 'Chase')]
        public string $from,
        #[OA\Property(description: 'The name of the Scene Type after.', example: 'Combat')]
        public string $to,
    ) {
    }
}
