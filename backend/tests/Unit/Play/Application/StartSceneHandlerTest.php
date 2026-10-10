<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\SceneTypes;
use App\Play\Application\StartScene;
use App\Play\Application\StartSceneHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\GameSystem\UnknownSceneType;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartScene::class)]
#[CoversClass(StartSceneHandler::class)]
#[CoversClass(SceneTypes::class)]
final class StartSceneHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private StartSceneHandler $handler;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00')));
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(Snapshots::withSceneTypes('heist', 'Heist', 1));
        // A newer release without Scene Types: scenes follow the pinned v1.
        $releases->add(Snapshots::bare('heist', 'Heist, revised', 2));
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00')));
        // "free-journal" is not published here: a scene without a Scene Type does not read the release.
        $this->handler = new StartSceneHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $releases, new FixedClock('2026-10-06T09:15:00+00:00'));
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
    public function aSceneWithASceneTypeOfThePinnedReleaseIsNamedAfterIt(): void
    {
        $this->startSession('campaign-2');

        ($this->handler)(new StartScene('campaign-2', 'user-1', null, 'legwork'));
        ($this->handler)(new StartScene('campaign-2', 'user-1', 'Casing the bank', 'legwork'));
        ($this->handler)(new StartScene('campaign-2', 'user-1', null, 'legwork'));

        $scenes = $this->campaigns->ofId(CampaignId::fromString('campaign-2'))?->currentSession()?->scenes() ?? [];
        self::assertSame(
            [['Legwork 1', 'legwork'], ['Casing the bank', 'legwork'], ['Legwork 3', 'legwork']],
            array_map(static fn (Scene $scene): array => [$scene->title(), $scene->sceneType()], $scenes),
        );
    }

    #[Test]
    public function anUnknownSceneTypeStartsNoScene(): void
    {
        $this->startSession('campaign-2');

        try {
            ($this->handler)(new StartScene('campaign-2', 'user-1', null, 'heist'));
            self::fail('A scene started with an unknown Scene Type.');
        } catch (UnknownSceneType $exception) {
            self::assertSame('Scene Type "heist" not found.', $exception->getMessage());
            self::assertNull($this->campaigns->ofId(CampaignId::fromString('campaign-2'))?->currentScene());
        }
    }

    #[Test]
    public function aSceneWithoutASceneTypeNeedsATitle(): void
    {
        $this->startSession();

        $this->expectException(InvalidSceneTitle::class);

        ($this->handler)(new StartScene('campaign-1', 'user-1', null));
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

    private function startSession(string $campaignId = 'campaign-1'): void
    {
        $campaign = $this->campaigns->ofId(CampaignId::fromString($campaignId));
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $this->campaigns->save($campaign);
    }
}
