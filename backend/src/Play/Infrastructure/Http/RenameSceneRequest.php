<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/campaigns/{campaignId}/scenes/current/title`. Documents the contract
 * only: the controller reads the title from the request.
 */
#[OA\Schema(required: ['title'])]
final readonly class RenameSceneRequest
{
    public function __construct(
        #[OA\Property(description: 'The new title of the current scene, trimmed: not blank, at most 100 characters.', example: 'The letter')]
        public string $title,
    ) {
    }
}
