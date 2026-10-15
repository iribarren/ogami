<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/restore`. Documents the contract only.
 */
#[OA\Schema(required: ['version'])]
final readonly class RestoreGameSystemRequest
{
    public function __construct(
        #[OA\Property(description: 'The release version whose content replaces the draft.', example: 2)]
        public int $version,
    ) {
    }
}
