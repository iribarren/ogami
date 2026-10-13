<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/campaigns/{campaignId}/scenes/current/cast`. Documents the contract only:
 * the controller reads the ids from the request.
 */
#[OA\Schema(required: ['npcIds'])]
final readonly class EditSceneCastRequest
{
    /**
     * @param list<string>  $npcIds
     * @param ?list<string> $characterIds
     */
    public function __construct(
        #[OA\Property(
            description: 'The whole new cast of the current scene, in cast order: NPCs of the campaign, closed ones allowed, none twice. An empty list empties the cast.',
            type: 'array',
            items: new OA\Items(type: 'string'),
        )]
        public array $npcIds,
        #[OA\Property(
            description: 'The whole new Characters part of the cast, in order: Characters of the campaign, none twice. Absent or null keeps the Characters in the cast.',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true,
        )]
        public ?array $characterIds,
    ) {
    }
}
