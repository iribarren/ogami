<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/journal/{entryId}/push`. Documents the contract
 * only: the controller reads the fields from the request.
 */
#[OA\Schema]
final readonly class PushCheckRequest
{
    /**
     * @param ?list<string> $picks
     */
    public function __construct(
        #[OA\Property(
            description: 'The option the player picks for each mark without an option in the cost of the push, in cost order: each must be an unmarked option of its field at that point. A field whose options are all marked takes no pick. Omitted or null, no pick.',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'hurt'),
            nullable: true,
        )]
        public ?array $picks,
    ) {
    }
}
