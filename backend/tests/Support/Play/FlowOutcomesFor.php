<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\CampaignJournal;
use App\Play\Application\Clock;
use App\Play\Application\FlowOutcomes;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PublishedGameSystemReleases;

/**
 * The journaling of Flow outcomes for a FlowRun handler under test: entries land in the given
 * repository with the ids "entry-001", "entry-002"….
 */
final class FlowOutcomesFor
{
    private function __construct()
    {
    }

    public static function of(InMemoryCampaignRepository $campaigns, InMemoryJournalEntryRepository $entries, PublishedGameSystemReleases $releases, Clock $clock): FlowOutcomes
    {
        return new FlowOutcomes(new CampaignJournal(new OwnedCampaigns($campaigns), $entries, $releases, $clock), new SequentialJournalEntryIdGenerator());
    }
}
