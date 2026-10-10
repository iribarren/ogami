<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\OwnedCampaigns;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\GuidedReleases;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use PHPUnit\Framework\TestCase;

/**
 * Guided campaigns of "user-1" on release v1 of "guided" (GuidedReleases::tour()), stored in
 * memory: "campaign-1" plays Flow "tour" and is playing its first Tour scene, at the step "intro";
 * "campaign-2" plays freely. The clock stands at NOW.
 */
abstract class FlowRunHandlerTestCase extends TestCase
{
    protected const string NOW = '2026-10-10T09:30:00+00:00';

    protected InMemoryCampaignRepository $campaigns;
    protected InMemoryJournalEntryRepository $entries;
    protected InMemoryPublishedGameSystemReleases $releases;
    protected FixedClock $clock;
    protected OwnedCampaigns $ownedCampaigns;
    protected CampaignJournal $journal;
    protected GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = GuidedReleases::tour();
        $this->campaigns = new InMemoryCampaignRepository();
        $this->entries = new InMemoryJournalEntryRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add($this->release);
        $this->clock = new FixedClock(self::NOW);
        $this->ownedCampaigns = new OwnedCampaigns($this->campaigns);
        $this->journal = new CampaignJournal($this->ownedCampaigns, $this->entries, $this->releases, $this->clock);

        $campaign = $this->guided('campaign-1', 'tour');
        $campaign->pickSceneType('tour', $this->release, self::at('09:10'));
        $this->campaigns->add($campaign);
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'Free', $this->pinned(), self::at('09:00')));
    }

    /**
     * A campaign of "user-1" along the Flow, in a session just started; not stored.
     */
    protected function guided(string $id, string $flow): Campaign
    {
        $campaign = Campaign::create(CampaignId::fromString($id), 'user-1', 'The tour', $this->pinned(), self::at('09:00'), [], $this->release->flow($flow));
        $campaign->startSession(self::at('09:05'), $this->release);

        return $campaign;
    }

    /**
     * Stores "campaign-1" moved on to a step of the Tour scene, the steps before it skipped.
     */
    protected function atStep(string $stepKey): void
    {
        $campaign = $this->stored('campaign-1');
        while ($campaign->flowRun()?->stepKey() !== $stepKey) {
            $campaign->skipFlowStep($campaign->flowRun()?->stepKey() ?? throw new \LogicException('No step.'), $this->release, self::at('09:11'));
        }

        $this->campaigns->save($campaign);
    }

    protected function stored(string $id): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString($id)) ?? throw new \LogicException('No campaign '.$id);
    }

    /**
     * @return list<JournalEntry>
     */
    protected function journalOf(string $id): array
    {
        return $this->entries->ofCampaign(CampaignId::fromString($id));
    }

    protected function onlyEntryOf(string $id): JournalEntry
    {
        $entries = $this->journalOf($id);
        self::assertCount(1, $entries);

        return $entries[0];
    }

    protected static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-10T'.$time.':00+00:00');
    }

    private function pinned(): PinnedRelease
    {
        return PinnedRelease::of('guided', 1, 'Guided');
    }
}
