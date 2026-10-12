<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/threads` and `PUT /api/campaigns/{campaignId}/threads/{threadId}`
 * (a full replacement: an omitted or null optional field takes its default). Documents the
 * contract only: the controller reads the body from the request.
 */
#[OA\Schema(required: ['name'])]
final readonly class ThreadRequest
{
    /**
     * @param ?list<string> $tags
     * @param ?list<string> $freeTags
     */
    public function __construct(
        #[OA\Property(description: '1 to 200 characters once trimmed.', example: 'Rob the vault')]
        public string $name,
        #[OA\Property(description: 'Keys of Tags of the campaign\'s pinned release, at most 10, each once; none when omitted or null.', type: 'array', items: new OA\Items(type: 'string'), example: ['heist'], nullable: true)]
        public ?array $tags,
        #[OA\Property(description: 'The player\'s own labels, at most 10 of 1 to 50 characters, unique ignoring case; none when omitted or null.', type: 'array', items: new OA\Items(type: 'string'), example: ['Vault'], nullable: true)]
        public ?array $freeTags,
        #[OA\Property(description: '1 to 3; 1 when omitted or null.', example: 2, nullable: true)]
        public ?int $weight,
    ) {
    }
}
