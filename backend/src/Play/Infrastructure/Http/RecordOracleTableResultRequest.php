<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * Optional JSON body of `POST /api/campaigns/{campaignId}/journal/oracle-tables/{oracleKey}`.
 * Documents the contract only: the controller reads the fields from the request.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field.
 */
#[OA\Schema]
final readonly class RecordOracleTableResultRequest
{
    public function __construct(
        #[OA\Property(description: 'The id of the likelihood journal entry (the fate question) whose random event this roll answers; omitted or null for any other roll. The entry must be of the campaign and its random event must be this oracle table, and the event is rolled only once.', example: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001')]
        public ?string $forEntry,
    ) {
    }
}
