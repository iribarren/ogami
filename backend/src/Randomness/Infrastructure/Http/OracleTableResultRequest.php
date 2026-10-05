<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Domain\Oracle\OracleTable;
use App\Randomness\Domain\Oracle\OracleTableSet;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/oracle-table-results`. Documents the contract only: the controller
 * reads the request, and the domain validates the tables.
 */
#[OA\Schema(required: ['tables', 'table'])]
final readonly class OracleTableResultRequest
{
    /**
     * @param list<OracleTableDefinition> $tables
     */
    public function __construct(
        #[OA\Property(
            description: 'The table set: unique keys; nested tables must be in the set, never cycle and nest at most 10 levels deep.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: OracleTableDefinition::class)),
            maxItems: OracleTableSet::MAX_TABLES,
            minItems: 1,
        )]
        public array $tables,
        #[OA\Property(description: 'The key of the table to roll on.', example: 'weather', maxLength: OracleTable::MAX_KEY_LENGTH)]
        public string $table,
    ) {
    }
}
