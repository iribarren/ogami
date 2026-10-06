<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Journal\JournalEntry;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\TestCase;

/**
 * A journal to record in: "campaign-1" of "user-1" is pinned to release v1 of "free-journal" (with
 * oracles) and plays scene 2 of session 1; a newer v2 without oracles is published. "campaign-2"
 * of "user-1" has a session but no scene.
 */
abstract class JournalTestCase extends TestCase
{
    protected const string NOW = '2026-10-06T09:30:00+00:00';

    protected InMemoryCampaignRepository $campaigns;
    protected InMemoryJournalEntryRepository $entries;
    protected InMemoryPublishedGameSystemReleases $releases;
    protected FixedClock $clock;
    protected OwnedCampaigns $ownedCampaigns;
    protected CampaignJournal $journal;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $this->entries = new InMemoryJournalEntryRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add(Snapshots::withOracles('free-journal', 'Free journal', 1));
        // A newer release with no oracles: the campaign must keep asking its pinned v1.
        $this->releases->add(Snapshots::bare('free-journal', 'Free journal, revised', 2));
        $this->clock = new FixedClock(self::NOW);
        $this->ownedCampaigns = new OwnedCampaigns($this->campaigns);
        $this->journal = new CampaignJournal($this->ownedCampaigns, $this->entries, $this->releases, $this->clock);

        $playing = $this->campaign('campaign-1');
        $playing->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $playing->startScene('At the gate', new \DateTimeImmutable('2026-10-06T09:05:00+00:00'));
        $playing->startScene('In the mine', new \DateTimeImmutable('2026-10-06T09:10:00+00:00'));
        $this->campaigns->add($playing);

        $sceneless = $this->campaign('campaign-2');
        $sceneless->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $this->campaigns->add($sceneless);
    }

    /**
     * @return list<JournalEntry>
     */
    protected function journalOf(string $campaignId): array
    {
        return $this->entries->ofCampaign(CampaignId::fromString($campaignId));
    }

    protected function onlyEntryOf(string $campaignId): JournalEntry
    {
        $entries = $this->journalOf($campaignId);
        self::assertCount(1, $entries);

        return $entries[0];
    }

    /**
     * The same journal, reading releases from another port double.
     */
    protected function journalReadingFrom(PublishedGameSystemReleases $releases): CampaignJournal
    {
        return new CampaignJournal($this->ownedCampaigns, $this->entries, $releases, $this->clock);
    }

    private function campaign(string $id): Campaign
    {
        return Campaign::create(CampaignId::fromString($id), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));
    }
}
