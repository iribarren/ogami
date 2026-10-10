<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\PickSceneType;
use App\Play\Application\PickSceneTypeHandler;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(PickSceneType::class)]
#[CoversClass(PickSceneTypeHandler::class)]
final class PickSceneTypeHandlerTest extends FlowRunHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->campaigns->add($this->guided('campaign-5', 'tour'));
    }

    #[Test]
    public function itStartsTheSceneOfAnOfferedSceneType(): void
    {
        $this->pick(new PickSceneType('campaign-5', 'user-1', 'quiet'));

        $campaign = $this->stored('campaign-5');
        self::assertSame(['Quiet 1', 'quiet'], [$campaign->currentScene()?->title(), $campaign->currentScene()?->sceneType()]);
        self::assertSame(FlowRunStage::Scene, $campaign->flowRun()?->stage());
    }

    #[Test]
    public function aSceneTypeTheScenePickDoesNotOfferStartsNothing(): void
    {
        $before = $this->stored('campaign-5');

        try {
            $this->pick(new PickSceneType('campaign-5', 'user-1', 'solo'));
            self::fail('A Scene Type that is not offered was picked.');
        } catch (SceneTypeNotOffered) {
            self::assertEquals($before, $this->stored('campaign-5'));
        }
    }

    #[Test]
    public function aFlowRunInASceneIsNotAtTheScenePick(): void
    {
        $this->expectException(FlowRunPositionMismatch::class);

        $this->pick(new PickSceneType('campaign-1', 'user-1', 'quiet'));
    }

    #[Test]
    public function aFreeCampaignHasNoScenePick(): void
    {
        $this->expectException(FlowRunNotActive::class);

        $this->pick(new PickSceneType('campaign-2', 'user-1', 'quiet'));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->pick(new PickSceneType('campaign-5', 'user-2', 'quiet'));
    }

    private function pick(PickSceneType $command): void
    {
        new PickSceneTypeHandler($this->ownedCampaigns, $this->campaigns, $this->releases, $this->clock)($command);
    }
}
