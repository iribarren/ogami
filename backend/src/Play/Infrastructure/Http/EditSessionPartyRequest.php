<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/campaigns/{campaignId}/sessions/current/party`. Documents the contract
 * only: the controller reads the ids from the request.
 */
#[OA\Schema(required: ['characterIds'])]
final readonly class EditSessionPartyRequest
{
    /**
     * @param list<string> $characterIds
     */
    public function __construct(
        #[OA\Property(
            description: 'The whole new party of the session under way, in order: Characters of the campaign, none twice. An empty list empties the party. Scenes keep the Characters they have.',
            type: 'array',
            items: new OA\Items(type: 'string'),
        )]
        public array $characterIds,
    ) {
    }
}
