<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Domain\Oracle\OracleTable;
use OpenApi\Attributes as OA;

/**
 * An entry of an oracle table. Documents the contract only.
 */
#[OA\Schema(description: 'Ranged entries have "min" and "max"; weighted entries may have a "weight" (1 by default). An entry needs a "text" unless it nests a "table".')]
final readonly class OracleTableEntryDefinition
{
    public function __construct(
        #[OA\Property(description: 'Lowest total this entry covers, on a ranged table.', example: 1)]
        public ?int $min = null,
        #[OA\Property(description: 'Highest total this entry covers, on a ranged table.', example: 3)]
        public ?int $max = null,
        #[OA\Property(description: 'Relative chance of this entry, on a weighted table; 1 by default.', example: 2, minimum: 1)]
        public ?int $weight = null,
        #[OA\Property(example: 'Clear', maxLength: OracleTable::MAX_TEXT_LENGTH)]
        public ?string $text = null,
        #[OA\Property(description: 'The key of a table of the set to roll on next when this entry is selected.', example: 'storm-kind', maxLength: OracleTable::MAX_KEY_LENGTH)]
        public ?string $table = null,
    ) {
    }
}
