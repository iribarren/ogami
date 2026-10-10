<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/flow-run/end-scene`. Documents the contract only:
 * the controller reads the scene number from the request.
 */
#[OA\Schema(required: ['sceneNumber'])]
final readonly class EndFlowSceneRequest
{
    public function __construct(
        #[OA\Property(description: 'The number of the guided scene in its session; refused when the FlowRun is not in open play in that scene.', example: 1)]
        public int $sceneNumber,
    ) {
    }
}
