<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Campaign;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/transfer`. Documents the contract only: the
 * controller reads the fields from the request.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field.
 */
#[OA\Schema(required: ['name', 'characterIds'])]
final readonly class TransferCampaignRequest
{
    /**
     * @param list<string> $characterIds
     */
    public function __construct(
        #[OA\Property(description: 'The new campaign\'s name. Trimmed; not blank.', example: 'Making a Name', maxLength: Campaign::MAX_NAME_LENGTH)]
        public string $name,
        #[OA\Property(description: 'The ids of the source campaign\'s Characters to carry over, each at most once; empty to carry none.', type: 'array', items: new OA\Items(type: 'string'), example: ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'])]
        public array $characterIds,
        #[OA\Property(description: 'A Flow of the source\'s GameSystem release to play along in the new campaign; absent or null to play freely (the release\'s default Flow is not applied).', example: 'campaign', nullable: true)]
        public ?string $flowKey,
    ) {
    }
}
