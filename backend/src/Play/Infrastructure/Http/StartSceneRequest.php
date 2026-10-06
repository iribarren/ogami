<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Scene;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/scenes`. Documents the contract only: the
 * controller reads the title from the request.
 */
#[OA\Schema(required: ['title'])]
final readonly class StartSceneRequest
{
    public function __construct(
        #[OA\Property(description: 'Trimmed; not blank.', example: 'At the gate', maxLength: Scene::MAX_TITLE_LENGTH)]
        public string $title,
    ) {
    }
}
