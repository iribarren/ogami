<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/flow-run/pick`. Documents the contract only: the
 * controller reads the Scene Type from the request.
 */
#[OA\Schema(required: ['sceneType'])]
final readonly class PickSceneTypeRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of one of the Scene Types the scene pick offers.', example: 'tour')]
        public string $sceneType,
    ) {
    }
}
