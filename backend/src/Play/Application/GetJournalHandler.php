<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Journal\JournalEntryRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class GetJournalHandler implements QueryHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private JournalEntryRepository $entries,
    ) {
    }

    /**
     * @return list<JournalEntryView> in recording order (recordedAt, then id)
     *
     * @throws CampaignNotFound
     */
    public function __invoke(GetJournal $query): array
    {
        $campaign = $this->ownedCampaigns->get($query->campaignId, $query->userId);

        return array_map(JournalEntryView::of(...), $this->entries->ofCampaign($campaign->id()));
    }
}
