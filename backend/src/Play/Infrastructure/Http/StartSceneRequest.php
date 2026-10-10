<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Scene;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/scenes`. Documents the contract only: the
 * controller reads the title and the Scene Type from the request.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field.
 */
#[OA\Schema(description: 'A title, a Scene Type, or both.', required: [])]
final readonly class StartSceneRequest
{
    public function __construct(
        #[OA\Property(description: 'Trimmed; not blank. Optional with a Scene Type: the scene is then named after it, numbered per Scene Type in the campaign ("Legwork 2").', example: 'At the gate', nullable: true, maxLength: Scene::MAX_TITLE_LENGTH)]
        public ?string $title,
        #[OA\Property(description: 'The key of a Scene Type of the campaign\'s pinned release.', example: 'legwork', nullable: true)]
        public ?string $sceneType,
    ) {
    }
}
