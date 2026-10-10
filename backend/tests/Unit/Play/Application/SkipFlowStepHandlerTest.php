<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\SkipFlowStep;
use App\Play\Application\SkipFlowStepHandler;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SkipFlowStep::class)]
#[CoversClass(SkipFlowStepHandler::class)]
final class SkipFlowStepHandlerTest extends FlowRunHandlerTestCase
{
    #[Test]
    public function itSkipsTheCurrentStepAndRecordsItInTheHistoryButNotInTheJournal(): void
    {
        $this->skip(new SkipFlowStep('campaign-1', 'user-1', 'intro'));

        $flowRun = $this->stored('campaign-1')->flowRun();
        self::assertSame(['dice', ['skip']], [$flowRun?->stepKey(), array_map(static fn ($entry): string => $entry->event->value, $flowRun?->history() ?? [])]);
        self::assertEquals(self::at('09:30'), $flowRun?->history()[0]->at);
        self::assertSame([], $this->journalOf('campaign-1'));
    }

    #[Test]
    public function aStepTheFlowRunDoesNotWaitOnChangesNothing(): void
    {
        $before = $this->stored('campaign-1');

        try {
            $this->skip(new SkipFlowStep('campaign-1', 'user-1', 'dice'));
            self::fail('Another step was skipped.');
        } catch (FlowRunPositionMismatch) {
            self::assertEquals($before, $this->stored('campaign-1'));
        }
    }

    #[Test]
    public function aFreeCampaignHasNoStepToSkip(): void
    {
        $this->expectException(FlowRunNotActive::class);

        $this->skip(new SkipFlowStep('campaign-2', 'user-1', 'intro'));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->skip(new SkipFlowStep('campaign-1', 'user-2', 'intro'));
    }

    private function skip(SkipFlowStep $command): void
    {
        new SkipFlowStepHandler($this->ownedCampaigns, $this->campaigns, $this->releases, $this->clock)($command);
    }
}
