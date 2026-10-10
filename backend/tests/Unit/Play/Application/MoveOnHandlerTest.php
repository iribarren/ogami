<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\MoveOn;
use App\Play\Application\MoveOnHandler;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MoveOn::class)]
#[CoversClass(MoveOnHandler::class)]
final class MoveOnHandlerTest extends FlowRunHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // At the scene pick of the loop phase "tour"; of the phase "draw", which plays once.
        $this->campaigns->add($this->guided('campaign-5', 'tour'));
        $this->campaigns->add($this->guided('campaign-6', 'draw'));
    }

    #[Test]
    public function itEndsTheLoopPhaseAtOnceAtTheScenePick(): void
    {
        $this->moveOn(new MoveOn('campaign-5', 'user-1', 'tour'));

        self::assertSame(FlowRunStatus::Completed, $this->stored('campaign-5')->flowRun()?->status());
    }

    #[Test]
    public function inASceneItEndsThePhaseOnceTheSceneFinishes(): void
    {
        $this->moveOn(new MoveOn('campaign-1', 'user-1', 'tour'));

        self::assertSame([FlowRunStatus::Active, true], [$this->stored('campaign-1')->flowRun()?->status(), $this->stored('campaign-1')->flowRun()?->phaseEnding()]);
    }

    #[Test]
    public function aPhaseThatPlaysOnceOrIsNotTheCurrentOneChangesNothing(): void
    {
        foreach ([['campaign-6', 'draw', MoveOnNotAllowed::class], ['campaign-5', 'draw', FlowRunPositionMismatch::class]] as [$id, $phase, $error]) {
            $before = $this->stored($id);

            try {
                $this->moveOn(new MoveOn($id, 'user-1', $phase));
                self::fail('Move on was accepted.');
            } catch (\DomainException $exception) {
                self::assertInstanceOf($error, $exception);
                self::assertEquals($before, $this->stored($id));
            }
        }
    }

    #[Test]
    public function aFreeCampaignHasNoPhase(): void
    {
        $this->expectException(FlowRunNotActive::class);

        $this->moveOn(new MoveOn('campaign-2', 'user-1', 'tour'));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->moveOn(new MoveOn('campaign-5', 'user-2', 'tour'));
    }

    private function moveOn(MoveOn $command): void
    {
        new MoveOnHandler($this->ownedCampaigns, $this->campaigns, $this->releases, $this->clock)($command);
    }
}
