<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/checks/{check}/move`.
 * Documents the contract only: the controller reads the fields from the request.
 */
#[OA\Schema(required: ['expectedVersion', 'index'])]
final readonly class MoveCheckRequest
{
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(description: 'The check\'s new position in the whole check list, from 0 to the check count minus 1; out of range is refused (422).', example: 0)]
        public int $index,
    ) {
    }
}
