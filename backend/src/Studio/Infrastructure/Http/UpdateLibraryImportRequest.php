<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/library-imports/{section}/{item}/update`.
 * Documents the contract only: the controller reads the field from the request.
 */
#[OA\Schema(required: ['expectedVersion'])]
final readonly class UpdateLibraryImportRequest
{
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
    ) {
    }
}
