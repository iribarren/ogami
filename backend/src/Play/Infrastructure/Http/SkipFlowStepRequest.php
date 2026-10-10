<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/flow-run/skip`. Documents the contract only: the
 * controller reads the step from the request.
 */
#[OA\Schema(required: ['stepKey'])]
final readonly class SkipFlowStepRequest
{
    public function __construct(
        #[OA\Property(description: 'The step the player means to skip; refused when the FlowRun waits for another, or the step is mandatory.', example: 'dice')]
        public string $stepKey,
    ) {
    }
}
