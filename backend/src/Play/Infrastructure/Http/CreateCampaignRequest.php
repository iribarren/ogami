<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Campaign;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns`. Documents the contract only: the controller reads the
 * fields from the request.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field.
 */
#[OA\Schema(required: ['name', 'gameSystemKey'])]
final readonly class CreateCampaignRequest
{
    public function __construct(
        #[OA\Property(description: 'Trimmed; not blank.', example: 'The lost mine', maxLength: Campaign::MAX_NAME_LENGTH)]
        public string $name,
        #[OA\Property(description: 'A GameSystem from `GET /api/play/game-systems`; the campaign is pinned to its latest release.', example: 'ironsworn')]
        public string $gameSystemKey,
        #[OA\Property(description: 'A Flow of that release to play along; absent or null to play freely (the release\'s default Flow is not applied).', example: 'heist', nullable: true)]
        public ?string $flowKey,
    ) {
    }
}
