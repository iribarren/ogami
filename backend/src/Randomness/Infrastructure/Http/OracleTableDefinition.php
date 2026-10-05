<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Domain\Oracle\OracleTable;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * An oracle table of a table set. Documents the contract only.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field. `required` lists the required ones.
 */
#[OA\Schema(
    description: 'A ranged table has "dice" and entries with "min" and "max" that do not overlap; a weighted table has no "dice", entries with an optional "weight" and rolls 1dW for total weight W.',
    required: ['key', 'name', 'entries'],
)]
final readonly class OracleTableDefinition
{
    /**
     * @param list<OracleTableEntryDefinition> $entries
     */
    public function __construct(
        #[OA\Property(description: 'Unique within the table set: a-z, 0-9 and "-".', pattern: '^[a-z0-9-]+$', example: 'weather', maxLength: OracleTable::MAX_KEY_LENGTH)]
        public string $key,
        #[OA\Property(example: 'Weather', maxLength: OracleTable::MAX_NAME_LENGTH)]
        public string $name,
        #[OA\Property(
            type: 'array',
            items: new OA\Items(ref: new Model(type: OracleTableEntryDefinition::class)),
            maxItems: OracleTable::MAX_ENTRIES,
            minItems: 1,
        )]
        public array $entries,
        #[OA\Property(description: 'Dice notation rolled on a ranged table; absent on a weighted table.', example: '1d6')]
        public ?string $dice,
    ) {
    }
}
