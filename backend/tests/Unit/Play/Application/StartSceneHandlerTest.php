<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\StartScene;
use App\Play\Application\StartSceneHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartScene::class)]
#[CoversClass(StartSceneHandler::class)]
final class StartSceneHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private StartSceneHandler $handler;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00')));
        $this->handler = new StartSceneHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new FixedClock('2026-10-06T09:15:00+00:00'));
    }

    #[Test]
    public function itStartsAnumberedSceneInTheCurrentSessionAndKeepsIt(): void
    {
        $this->startSession();

        ($this->handler)(new StartScene('campaign-1', 'user-1', ' At the gate '));
        ($this->handler)(new StartScene('campaign-1', 'user-1', 'In the mine'));

        $scene = $this->campaigns->ofId(CampaignId::fromString('campaign-1'))?->currentScene();
        self::assertNotNull($scene);
        self::assertSame(2, $scene->number());
        self::assertSame('In the mine', $scene->title());
        self::assertEquals(new \DateTimeImmutable('2026-10-06T09:15:00+00:00'), $scene->startedAt());
        self::assertSame('At the gate', $this->campaigns->ofId(CampaignId::fromString('campaign-1'))?->currentSession()?->scenes()[0]->title());
    }

    #[Test]
    public function aSceneNeedsASession(): void
    {
        $this->expectException(NoCurrentSession::class);

        ($this->handler)(new StartScene('campaign-1', 'user-1', 'At the gate'));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->startSession();

        $this->expectException(CampaignNotFound::class);

        ($this->handler)(new StartScene('campaign-1', 'user-2', 'At the gate'));
    }

    private function startSession(): void
    {
        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $this->campaigns->save($campaign);
    }
}
