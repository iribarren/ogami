<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * What a Campaign fact links to: an NPC, a Thread or another fact of the same campaign. Documents
 * the contract only: the controller reads the body from the request.
 */
#[OA\Schema(required: ['kind', 'id'])]
final readonly class FactLinkRequest
{
    public function __construct(
        #[OA\Property(example: 'npc', enum: ['npc', 'thread', 'place', 'fact'])]
        public string $kind,
        #[OA\Property(description: 'The id of the NPC, Thread or fact; it must be one the campaign holds.', format: 'uuid')]
        public string $id,
    ) {
    }
}
