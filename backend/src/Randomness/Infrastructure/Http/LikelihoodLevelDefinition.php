<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Domain\Oracle\LikelihoodLevel;
use App\Randomness\Domain\Oracle\OracleTable;
use OpenApi\Attributes as OA;

/**
 * A likelihood level of a likelihood oracle. Documents the contract only.
 */
#[OA\Schema(required: ['key', 'label', 'target'])]
final readonly class LikelihoodLevelDefinition
{
    public function __construct(
        #[OA\Property(description: 'a-z, 0-9 and "-".', pattern: '^[a-z0-9-]+$', example: 'likely', maxLength: OracleTable::MAX_KEY_LENGTH)]
        public string $key,
        #[OA\Property(example: 'Likely', maxLength: LikelihoodLevel::MAX_LABEL_LENGTH)]
        public string $label,
        #[OA\Property(description: 'The highest roll that answers yes at the neutral chaos factor; 0 to sides.', example: 65, minimum: 0)]
        public int $target,
    ) {
    }
}
