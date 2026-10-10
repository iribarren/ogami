<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\SetTrackerValue;
use App\Play\Application\SetTrackerValueHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\UnknownCampaignTracker;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetTrackerValue::class)]
#[CoversClass(SetTrackerValueHandler::class)]
final class SetTrackerValueHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private SetTrackerValueHandler $handler;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(Snapshots::withTrackers('heist', 'Heist', 1));
        // A newer release without trackers: edits follow the pinned v1.
        $releases->add(Snapshots::bare('heist', 'Heist, revised', 2));
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(
            CampaignId::fromString('campaign-1'),
            'user-1',
            'The job',
            PinnedRelease::of('heist', 1, 'Heist'),
            new \DateTimeImmutable('2026-10-09T09:00:00+00:00'),
            Snapshots::withTrackers('heist', 'Heist', 1)->trackers(),
        ));
        $this->handler = new SetTrackerValueHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $releases, $this->clock = new FixedClock('2026-10-10T09:30:00+00:00'));
    }

    #[Test]
    public function itKeepsTheValueClampedToTheTrackerRange(): void
    {
        ($this->handler)(new SetTrackerValue('campaign-1', 'user-1', 'heat', 2));
        ($this->handler)(new SetTrackerValue('campaign-1', 'user-1', 'alarm', 9));

        self::assertSame(['alarm' => 6, 'heat' => 2, 'chaos' => 5], $this->campaigns->ofId(CampaignId::fromString('campaign-1'))?->trackerValues());
    }

    #[Test]
    public function aGuidedCampaignRecordsTheEditInItsFlowRunHistoryAtTheClockTime(): void
    {
        $release = Snapshots::withFlows('heist', 'Heist', 1);
        $campaign = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), Snapshots::withTrackers('heist', 'Heist', 1)->trackers(), $release->flow('one-shot'));
        $this->campaigns->add($campaign);

        ($this->handler)(new SetTrackerValue('campaign-2', 'user-1', 'heat', 2));

        $history = $this->campaigns->ofId(CampaignId::fromString('campaign-2'))?->flowRun()?->history() ?? [];
        self::assertSame([['trackerEdit', ['tracker' => 'heat', 'from' => -5, 'to' => 2]]], array_map(static fn (\App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry $entry): array => [$entry->event->value, $entry->details], $history));
        self::assertEquals($this->clock->now(), $history[0]->at);
    }

    #[Test]
    public function anUnknownTrackerChangesNothing(): void
    {
        try {
            ($this->handler)(new SetTrackerValue('campaign-1', 'user-1', 'luck', 2));
            self::fail('An unknown tracker was set.');
        } catch (UnknownCampaignTracker $exception) {
            self::assertSame('Tracker "luck" not found.', $exception->getMessage());
            self::assertSame(['alarm' => 0, 'heat' => -5, 'chaos' => 5], $this->campaigns->ofId(CampaignId::fromString('campaign-1'))?->trackerValues());
        }
    }

    #[Test]
    public function aCampaignStoredWithoutTrackerValuesKeepsTheEditedOne(): void
    {
        // A schema version 2 campaign stored before campaigns held tracker values.
        $this->campaigns->add(Campaign::reconstitute(
            CampaignId::fromString('campaign-2'),
            'user-1',
            'The old job',
            PinnedRelease::of('heist', 1, 'Heist'),
            new \DateTimeImmutable('2026-10-01T09:00:00+00:00'),
            [],
        ));

        ($this->handler)(new SetTrackerValue('campaign-2', 'user-1', 'heat', 9));

        self::assertSame(['heat' => 5], $this->campaigns->ofId(CampaignId::fromString('campaign-2'))?->trackerValues());
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        ($this->handler)(new SetTrackerValue('campaign-1', 'user-2', 'heat', 2));
    }
}
