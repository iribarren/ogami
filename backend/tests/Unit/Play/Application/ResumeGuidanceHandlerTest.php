<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\ResumeGuidance;
use App\Play\Application\ResumeGuidanceHandler;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ResumeGuidance::class)]
#[CoversClass(ResumeGuidanceHandler::class)]
final class ResumeGuidanceHandlerTest extends FlowRunHandlerTestCase
{
    #[Test]
    public function itResumesPausedGuidanceWhereItStopped(): void
    {
        $paused = $this->stored('campaign-1');
        $paused->pauseGuidance(self::at('09:20'));
        $this->campaigns->save($paused);

        $this->resume(new ResumeGuidance('campaign-1', 'user-1'));

        $flowRun = $this->stored('campaign-1')->flowRun();
        self::assertSame([FlowRunStatus::Active, 'intro', ['paused', 'resumed']], [$flowRun?->status(), $flowRun?->stepKey(), array_map(static fn ($entry): string => $entry->event->value, $flowRun?->history() ?? [])]);
    }

    #[Test]
    public function guidanceThatIsNotPausedOrAFreeCampaignCannotBeResumed(): void
    {
        foreach (['campaign-1', 'campaign-2'] as $id) {
            $before = $this->stored($id);

            try {
                $this->resume(new ResumeGuidance($id, 'user-1'));
                self::fail('Guidance was resumed without a pause.');
            } catch (FlowRunNotActive) {
                self::assertEquals($before, $this->stored($id));
            }
        }
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->resume(new ResumeGuidance('campaign-1', 'user-2'));
    }

    private function resume(ResumeGuidance $command): void
    {
        new ResumeGuidanceHandler($this->ownedCampaigns, $this->campaigns, $this->releases, $this->clock)($command);
    }
}
