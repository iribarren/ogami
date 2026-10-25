<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/flow-run/keep`. Documents the contract only: the
 * controller reads the step from the request.
 */
#[OA\Schema(required: ['stepKey'])]
final readonly class KeepFlowCheckRequest
{
    public function __construct(
        #[OA\Property(description: 'The check step whose roll the player keeps; refused when no roll pends, or it pends at another step.', example: 'sneak')]
        public string $stepKey,
    ) {
    }
}
