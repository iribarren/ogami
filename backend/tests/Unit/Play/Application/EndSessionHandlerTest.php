<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\EndSession;
use App\Play\Application\EndSessionHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EndSession::class)]
#[CoversClass(EndSessionHandler::class)]
final class EndSessionHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private EndSessionHandler $handler;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $this->campaigns->add($campaign);
        $this->handler = new EndSessionHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new FixedClock('2026-10-06T12:00:00+00:00'));
    }

    #[Test]
    public function itEndsTheCurrentSessionAtTheClocksTime(): void
    {
        ($this->handler)(new EndSession('campaign-1', 'user-1'));

        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        self::assertEquals(new \DateTimeImmutable('2026-10-06T12:00:00+00:00'), $campaign->sessions()[0]->endedAt());
        self::assertNull($campaign->currentSession());
    }

    #[Test]
    public function anEndedSessionCannotEndAgain(): void
    {
        ($this->handler)(new EndSession('campaign-1', 'user-1'));

        $this->expectException(NoCurrentSession::class);

        ($this->handler)(new EndSession('campaign-1', 'user-1'));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        ($this->handler)(new EndSession('campaign-1', 'user-2'));
    }
}
