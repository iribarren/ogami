<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/likelihood-oracles/{oracle}/test-answers`.
 * Documents the contract only: the controller reads the fields from the request.
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(required: ['likelihood'])]
final readonly class TestAnswerRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of the likelihood level to ask with.', example: 'likely')]
        public string $likelihood,
        #[OA\Property(description: 'Within the oracle\'s chaos range; the neutral one when absent or null. Only allowed when the oracle has chaos.', example: 5, nullable: true)]
        public ?int $chaosFactor,
    ) {
    }
}
