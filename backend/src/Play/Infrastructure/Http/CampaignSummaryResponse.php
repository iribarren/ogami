<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CampaignSummaryView;
use OpenApi\Attributes as OA;

/**
 * One campaign in the player's campaign list.
 */
#[OA\Schema(required: ['id', 'name', 'gameSystemKey', 'gameSystemName', 'releaseVersion', 'createdAt'])]
final readonly class CampaignSummaryResponse
{
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'The lost mine')]
        public string $name,
        #[OA\Property(example: 'ironsworn')]
        public string $gameSystemKey,
        #[OA\Property(example: 'Ironsworn')]
        public string $gameSystemName,
        #[OA\Property(description: 'The release version the campaign is pinned to.', example: 3)]
        public int $releaseVersion,
        #[OA\Property(format: 'date-time')]
        public string $createdAt,
    ) {
    }

    public static function fromView(CampaignSummaryView $view): self
    {
        return new self(
            $view->id,
            $view->name,
            $view->gameSystemKey,
            $view->gameSystemName,
            $view->releaseVersion,
            $view->createdAt->format(\DATE_ATOM),
        );
    }
}
