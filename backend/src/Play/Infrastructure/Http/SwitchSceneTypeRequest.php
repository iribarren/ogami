<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/scenes/current/scene-type`. Documents the
 * contract only: the controller reads the Scene Type from the request.
 */
#[OA\Schema(required: ['sceneType'])]
final readonly class SwitchSceneTypeRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of a Scene Type of the campaign\'s pinned release.', example: 'firefight')]
        public string $sceneType,
    ) {
    }
}
