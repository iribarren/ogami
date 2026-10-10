<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\EndFlowScene;
use App\Play\Application\EndFlowSceneHandler;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EndFlowScene::class)]
#[CoversClass(EndFlowSceneHandler::class)]
final class EndFlowSceneHandlerTest extends FlowRunHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The first Solo scene is in open play.
        $this->campaigns->add($this->guided('campaign-3', 'chain'));
    }

    #[Test]
    public function itEndsOpenPlayAndStartsTheClosing(): void
    {
        $this->end(new EndFlowScene('campaign-3', 'user-1', 1));

        $flowRun = $this->stored('campaign-3')->flowRun();
        self::assertSame([ScenePart::Closing, 'wrap'], [$flowRun?->part(), $flowRun?->stepKey()]);
    }

    #[Test]
    public function aSceneTheFlowRunIsNotInOpenPlayOfChangesNothing(): void
    {
        foreach ([['campaign-3', 2], ['campaign-1', 1]] as [$id, $scene]) {
            $before = $this->stored($id);

            try {
                $this->end(new EndFlowScene($id, 'user-1', $scene));
                self::fail('Open play ended elsewhere.');
            } catch (FlowRunPositionMismatch) {
                self::assertEquals($before, $this->stored($id));
            }
        }
    }

    #[Test]
    public function aFreeCampaignHasNoSceneToEnd(): void
    {
        $this->expectException(FlowRunNotActive::class);

        $this->end(new EndFlowScene('campaign-2', 'user-1', 1));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->end(new EndFlowScene('campaign-3', 'user-2', 1));
    }

    private function end(EndFlowScene $command): void
    {
        new EndFlowSceneHandler($this->ownedCampaigns, $this->campaigns, $this->releases, $this->clock)($command);
    }
}
