<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\SwitchSceneType;
use App\Play\Application\SwitchSceneTypeHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\HookSceneHasNoSceneType;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\UnknownSceneType;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwitchSceneType::class)]
#[CoversClass(SwitchSceneTypeHandler::class)]
final class SwitchSceneTypeHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private SwitchSceneTypeHandler $handler;

    protected function setUp(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(Snapshots::withSceneTypes('heist', 'Heist', 1));
        // A newer release without Scene Types: switches follow the pinned v1.
        $releases->add(Snapshots::bare('heist', 'Heist, revised', 2));
        $this->campaigns = new InMemoryCampaignRepository();
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:05:00+00:00'));
        $this->campaigns->add($campaign);
        $this->handler = new SwitchSceneTypeHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $releases);
    }

    #[Test]
    public function itSwitchesTheCurrentSceneToASceneTypeOfThePinnedRelease(): void
    {
        $this->startScene('At the gate');

        ($this->handler)(new SwitchSceneType('campaign-1', 'user-1', 'firefight'));

        $scene = $this->campaign()->currentScene();
        self::assertSame(['At the gate', 'firefight'], [$scene?->title(), $scene?->sceneType()]);
    }

    #[Test]
    public function anUnknownSceneTypeSwitchesNothing(): void
    {
        $this->startScene('At the gate');

        try {
            ($this->handler)(new SwitchSceneType('campaign-1', 'user-1', 'heist'));
            self::fail('An unknown Scene Type was switched to.');
        } catch (UnknownSceneType $exception) {
            self::assertSame('Scene Type "heist" not found.', $exception->getMessage());
            self::assertNull($this->campaign()->currentScene()?->sceneType());
        }
    }

    #[Test]
    public function itNeedsACurrentSceneOfPlay(): void
    {
        try {
            ($this->handler)(new SwitchSceneType('campaign-1', 'user-1', 'legwork'));
            self::fail('A Scene Type was switched without a scene.');
        } catch (NoCurrentScene) {
        }

        $campaign = $this->campaign();
        $campaign->startHookScene(Hook::SessionOpening, 'Session 1 begins', new \DateTimeImmutable());
        $this->campaigns->save($campaign);

        $this->expectException(HookSceneHasNoSceneType::class);

        ($this->handler)(new SwitchSceneType('campaign-1', 'user-1', 'legwork'));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->startScene('At the gate');

        $this->expectException(CampaignNotFound::class);

        ($this->handler)(new SwitchSceneType('campaign-1', 'user-2', 'legwork'));
    }

    private function startScene(string $title): void
    {
        $campaign = $this->campaign();
        $campaign->startScene($title, new \DateTimeImmutable('2026-10-10T09:10:00+00:00'));
        $this->campaigns->save($campaign);
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('No campaign.');
    }
}
