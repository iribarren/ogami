<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/journal/rolls`. Documents the contract only: the
 * controller reads the fields from the request.
 */
#[OA\Schema(required: ['expression'])]
final readonly class RecordRollRequest
{
    public function __construct(
        #[OA\Property(description: 'Dice notation, rolled on the server (same grammar as `POST /api/rolls`).', example: '2d6+1')]
        public string $expression,
    ) {
    }
}
