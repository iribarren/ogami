<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/npcs` and `PUT /api/campaigns/{campaignId}/npcs/{npcId}`
 * (a full replacement: an omitted or null optional field takes its default). Documents the
 * contract only: the controller reads the body from the request.
 */
#[OA\Schema(required: ['name'])]
final readonly class NpcRequest
{
    /**
     * @param ?list<string> $tags
     * @param ?list<string> $freeTags
     */
    public function __construct(
        #[OA\Property(description: '1 to 100 characters once trimmed.', example: 'Mira Vane')]
        public string $name,
        #[OA\Property(description: 'What the NPC wants, at most 2000 characters; none when omitted, null or blank.', example: 'Wants the ledger back.', nullable: true)]
        public ?string $agenda,
        #[OA\Property(description: 'Keys of Tags of the campaign\'s pinned release, at most 10, each once; none when omitted or null.', type: 'array', items: new OA\Items(type: 'string'), example: ['ally'], nullable: true)]
        public ?array $tags,
        #[OA\Property(description: 'The player\'s own labels, at most 10 of 1 to 50 characters, unique ignoring case; none when omitted or null.', type: 'array', items: new OA\Items(type: 'string'), example: ['Fence'], nullable: true)]
        public ?array $freeTags,
        #[OA\Property(description: '1 to 3; 1 when omitted or null.', example: 2, nullable: true)]
        public ?int $weight,
    ) {
    }
}
