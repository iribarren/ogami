<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/likelihood-answers`. Documents the contract only: the controller
 * reads the request, and the domain validates the oracle and the question.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field. `required` lists the required ones.
 */
#[OA\Schema(required: ['oracle', 'likelihood'])]
final readonly class LikelihoodAnswerRequest
{
    public function __construct(
        #[OA\Property(ref: new Model(type: LikelihoodOracleDefinition::class))]
        public LikelihoodOracleDefinition $oracle,
        #[OA\Property(description: 'The key of the likelihood level to ask with.', example: 'likely')]
        public string $likelihood,
        #[OA\Property(description: 'Within the oracle\'s chaos range; the neutral one when absent. Only allowed when the oracle has chaos.', example: 5)]
        public ?int $chaosFactor,
    ) {
    }
}
