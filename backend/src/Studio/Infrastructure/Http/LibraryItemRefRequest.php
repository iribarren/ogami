<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * An item of a library pack in a request: its section and its key. Documents the contract only.
 */
#[OA\Schema(required: ['section', 'key'])]
final readonly class LibraryItemRefRequest
{
    public function __construct(
        #[OA\Property(description: 'Oracle tables and likelihood oracles share the oracles section.', example: 'scene-types', enum: ['oracles', 'trackers', 'tags', 'fact-slots', 'scene-types'])]
        public string $section,
        #[OA\Property(example: 'tavern')]
        public string $key,
    ) {
    }
}
