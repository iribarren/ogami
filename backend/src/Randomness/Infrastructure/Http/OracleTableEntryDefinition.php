<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Domain\Oracle\OracleTable;
use OpenApi\Attributes as OA;

/**
 * An entry of an oracle table. Documents the contract only.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field. The schema has no required field.
 */
#[OA\Schema(description: 'Ranged entries have "min" and "max"; weighted entries may have a "weight" (1 by default). An entry needs a "text" unless it nests a "table".')]
final readonly class OracleTableEntryDefinition
{
    public function __construct(
        #[OA\Property(description: 'Lowest total this entry covers, on a ranged table.', example: 1)]
        public ?int $min,
        #[OA\Property(description: 'Highest total this entry covers, on a ranged table.', example: 3)]
        public ?int $max,
        #[OA\Property(description: 'Relative chance of this entry, on a weighted table; 1 by default.', example: 2, minimum: 1)]
        public ?int $weight,
        #[OA\Property(example: 'Clear', maxLength: OracleTable::MAX_TEXT_LENGTH)]
        public ?string $text,
        #[OA\Property(description: 'The key of a table of the set to roll on next when this entry is selected.', example: 'storm-kind', maxLength: OracleTable::MAX_KEY_LENGTH)]
        public ?string $table,
    ) {
    }
}
