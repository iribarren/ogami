<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ListMyCampaignsHandler implements QueryHandler
{
    public function __construct(
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @return list<CampaignSummaryView> newest first
     */
    public function __invoke(ListMyCampaigns $query): array
    {
        return array_map(
            static fn (Campaign $campaign): CampaignSummaryView => new CampaignSummaryView(
                $campaign->id()->toString(),
                $campaign->name(),
                $campaign->pinnedRelease()->gameSystemKey(),
                $campaign->pinnedRelease()->gameSystemName(),
                $campaign->pinnedRelease()->releaseVersion(),
                $campaign->createdAt(),
            ),
            $this->campaigns->ownedBy($query->userId),
        );
    }
}
