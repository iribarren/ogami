<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/campaigns/{campaignId}/trackers/{trackerKey}`. Documents the contract
 * only: the controller reads the value from the request.
 */
#[OA\Schema(required: ['value'])]
final readonly class SetTrackerValueRequest
{
    public function __construct(
        #[OA\Property(description: 'Clamped to the Tracker\'s range (min..max, 0..segments for a clock).', example: 3)]
        public int $value,
    ) {
    }
}
