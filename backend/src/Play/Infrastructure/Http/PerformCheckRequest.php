<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/journal/checks`. Documents the contract only: the
 * controller reads the fields from the request.
 */
#[OA\Schema(required: ['check', 'character'])]
final readonly class PerformCheckRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of a check of the campaign\'s pinned release.', example: 'skill')]
        public string $check,
        #[OA\Property(description: 'The id of any Character of the campaign, in the current scene or not.', format: 'uuid')]
        public string $character,
        #[OA\Property(description: 'The Check difficulty, within the min..max of the check; omitted or null, the default of the check applies. Refused for a check that declares none.', example: 15, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'The Situational modifier, from -100 to 100; omitted or null, 0.', example: -2, nullable: true)]
        public ?int $situational,
    ) {
    }
}
