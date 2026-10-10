<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\PauseGuidance;
use App\Play\Application\PauseGuidanceHandler;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(PauseGuidance::class)]
#[CoversClass(PauseGuidanceHandler::class)]
final class PauseGuidanceHandlerTest extends FlowRunHandlerTestCase
{
    #[Test]
    public function itPausesTheGuidanceAndRecordsItInTheHistory(): void
    {
        $this->pause(new PauseGuidance('campaign-1', 'user-1'));

        $flowRun = $this->stored('campaign-1')->flowRun();
        self::assertSame([FlowRunStatus::Paused, ['paused']], [$flowRun?->status(), array_map(static fn ($entry): string => $entry->event->value, $flowRun?->history() ?? [])]);
        self::assertEquals(self::at('09:30'), $flowRun?->history()[0]->at);
    }

    #[Test]
    public function guidanceAlreadyPausedOrAFreeCampaignCannotBePaused(): void
    {
        $this->pause(new PauseGuidance('campaign-1', 'user-1'));

        foreach (['campaign-1', 'campaign-2'] as $id) {
            try {
                $this->pause(new PauseGuidance($id, 'user-1'));
                self::fail('Guidance was paused twice.');
            } catch (FlowRunNotActive) {
                self::assertCount('campaign-1' === $id ? 1 : 0, $this->stored($id)->flowRun()?->history() ?? []);
            }
        }
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->pause(new PauseGuidance('campaign-1', 'user-2'));
    }

    private function pause(PauseGuidance $command): void
    {
        new PauseGuidanceHandler($this->ownedCampaigns, $this->campaigns, $this->clock)($command);
    }
}
