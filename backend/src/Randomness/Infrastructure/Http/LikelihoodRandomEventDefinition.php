<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The random event rule of a likelihood oracle. Documents the contract only.
 */
#[OA\Schema(
    description: 'A d100 roll that is a double (11, 22 … 99) whose digit is at most the chaos factor used triggers a random event on the table.',
    required: ['table'],
)]
final readonly class LikelihoodRandomEventDefinition
{
    public function __construct(
        #[OA\Property(description: 'The key of the oracle table to roll for the event.', example: 'random-event-focus')]
        public string $table,
    ) {
    }
}
