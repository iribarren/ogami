<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\StartSession;
use App\Play\Application\StartSessionHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartSession::class)]
#[CoversClass(StartSessionHandler::class)]
#[CoversClass(OwnedCampaigns::class)]
#[CoversClass(CampaignNotFound::class)]
final class StartSessionHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private FixedClock $clock;
    private InMemoryPublishedGameSystemReleases $releases;
    private StartSessionHandler $handler;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00')));
        $this->clock = new FixedClock('2026-10-06T09:00:00+00:00');
        $this->handler = new StartSessionHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases = new InMemoryPublishedGameSystemReleases(), $this->clock);
    }

    #[Test]
    public function itStartsNumberedSessionsAndKeepsThem(): void
    {
        ($this->handler)(new StartSession('campaign-1', 'user-1'));
        $this->clock->moveTo('2026-10-07T09:00:00+00:00');
        ($this->handler)(new StartSession('campaign-1', 'user-1'));

        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        self::assertCount(2, $campaign->sessions());
        self::assertSame(2, $campaign->currentSession()?->number());
        self::assertEquals(new \DateTimeImmutable('2026-10-07T09:00:00+00:00'), $campaign->currentSession()->startedAt());
    }

    #[Test]
    public function aGuidedCampaignTellsItsFlowRunThePinnedReleaseSoItStartsGuidance(): void
    {
        $release = Snapshots::withFlows('heist', 'Heist', 1);
        $this->releases->add($release);
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'), [], $release->flow('one-shot')));

        ($this->handler)(new StartSession('campaign-2', 'user-1'));

        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-2'));
        $view = $campaign?->flowRunView($release);
        self::assertSame([false, ['legwork', 'firefight']], [$view?->waitsForSession, array_map(static fn ($sceneType): string => $sceneType->key, $view?->pick->cards ?? [])]);
    }

    #[Test]
    public function aCampaignPlayedFreelyNeedsNoRelease(): void
    {
        // campaign-1 is pinned to a release that is not published: free play never reads it.
        ($this->handler)(new StartSession('campaign-1', 'user-1'));

        self::assertSame(1, $this->campaigns->ofId(CampaignId::fromString('campaign-1'))?->currentSession()?->number());
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);
        $this->expectExceptionMessageIsOrContains('Campaign "campaign-1" not found.');

        ($this->handler)(new StartSession('campaign-1', 'user-2'));
    }

    #[Test]
    public function anUnknownCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);
        $this->expectExceptionMessageIsOrContains('Campaign "campaign-9" not found.');

        ($this->handler)(new StartSession('campaign-9', 'user-1'));
    }

    #[Test]
    public function aBlankCampaignIdIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        ($this->handler)(new StartSession(' ', 'user-1'));
    }
}
