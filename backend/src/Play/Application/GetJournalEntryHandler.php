<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class GetJournalEntryHandler implements QueryHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private JournalEntryRepository $entries,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws JournalEntryNotFound when the id is invalid or unknown, or the entry belongs to another campaign
     */
    public function __invoke(GetJournalEntry $query): JournalEntryView
    {
        $campaign = $this->ownedCampaigns->get($query->campaignId, $query->userId);

        try {
            $entry = $this->entries->ofId(JournalEntryId::fromString($query->entryId));
        } catch (InvalidJournalEntryId) {
            throw JournalEntryNotFound::withId($query->entryId);
        }

        if (!$entry instanceof JournalEntry || !$entry->campaignId()->equals($campaign->id())) {
            throw JournalEntryNotFound::withId($query->entryId);
        }

        return JournalEntryView::of($entry);
    }
}
