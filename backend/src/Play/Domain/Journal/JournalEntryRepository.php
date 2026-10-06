<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\CampaignId;

/**
 * Port: where journal entries are kept. Entries are immutable, so they are only added.
 */
interface JournalEntryRepository
{
    public function add(JournalEntry $entry): void;

    /**
     * The journal of one campaign, in recording order (recordedAt, then id, ascending).
     *
     * @return list<JournalEntry>
     */
    public function ofCampaign(CampaignId $campaignId): array;
}
