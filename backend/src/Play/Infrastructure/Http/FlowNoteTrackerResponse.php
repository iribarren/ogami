<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * What the Flow decided on its own: an Effect changed a Tracker (noted only when its value changed).
 */
#[OA\Schema(required: ['kind', 'key', 'name', 'from', 'to'])]
final readonly class FlowNoteTrackerResponse
{
    public function __construct(
        #[OA\Property(enum: ['tracker'])]
        public string $kind,
        #[OA\Property(description: 'The key of the Tracker.', example: 'threat')]
        public string $key,
        #[OA\Property(description: 'The name of the Tracker when it changed.', example: 'Threat')]
        public string $name,
        #[OA\Property(description: 'The value before.', example: 3)]
        public int $from,
        #[OA\Property(description: 'The value after, clamped to the Tracker.', example: 4)]
        public int $to,
    ) {
    }
}
