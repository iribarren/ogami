<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/flow-run/move-on`. Documents the contract only:
 * the controller reads the phase from the request.
 */
#[OA\Schema(required: ['phase'])]
final readonly class MoveOnRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of the phase the FlowRun is in; refused when it is in another.', example: 'tour')]
        public string $phase,
    ) {
    }
}
