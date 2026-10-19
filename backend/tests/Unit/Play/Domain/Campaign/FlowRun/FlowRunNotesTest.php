<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowNote;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\FlowNotesReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the Flow decided on its own while a command ran (ADR 0027 style notes, N8 / P7): the label
 * of a band taken, a Tracker Effect that changed a value, a Scene Type switch, a forced next
 * Scene Type and the end of the phase, in apply order. The notes are taken once and never stored.
 * The test release has a counter "heat" (0-5) and "zero" (0-9), and one loop phase "Roaming".
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(FlowNote::class)]
#[CoversClass(Campaign::class)]
final class FlowRunNotesTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = FlowNotesReleases::snapshot();
    }

    #[Test]
    public function aLabelledBandTakenByARollIsNotedBeforeItsEffects(): void
    {
        $campaign = $this->inScene('gamble');

        $campaign->completeFlowStep('gamble', self::roll('1d6', 6), $this->release, self::at());

        self::assertSame(
            [['kind' => 'band', 'label' => 'Jackpot'], ['kind' => 'tracker', 'key' => 'heat', 'name' => 'Heat', 'from' => 0, 'to' => 2]],
            $this->arrays($campaign->takeFlowNotes()),
        );
    }

    #[Test]
    public function aBandWithoutALabelAndAnUnchangedTrackerNoteNothing(): void
    {
        $campaign = $this->inScene('gamble');

        $campaign->completeFlowStep('gamble', self::roll('1d6', 3), $this->release, self::at());

        self::assertSame([], $campaign->takeFlowNotes());
    }

    #[Test]
    public function aTrackerEffectThatLeavesTheValueWhereItWasNotesNothing(): void
    {
        $campaign = $this->inScene('gamble');
        $campaign->setTrackerValue($this->release->tracker('heat') ?? self::fail('No heat.'), 5);

        $campaign->completeFlowStep('gamble', self::roll('1d6', 6), $this->release, self::at());

        self::assertSame([['kind' => 'band', 'label' => 'Jackpot']], $this->arrays($campaign->takeFlowNotes()));
    }

    #[Test]
    public function aConditionStepNotesItsBandAndTheEndOfThePhaseWhenAPickSettlesIt(): void
    {
        $campaign = $this->inScene('watch', heat: 4);

        self::assertSame(
            [['kind' => 'band', 'label' => 'Hot'], ['kind' => 'phaseEnd', 'phase' => 'Roaming']],
            $this->arrays($campaign->takeFlowNotes()),
        );
    }

    #[Test]
    public function aNextSceneEffectNotesTheSceneTypeByItsName(): void
    {
        $campaign = $this->inScene('watch', heat: 2);

        self::assertSame([['kind' => 'nextScene', 'sceneType' => 'Gamble']], $this->arrays($campaign->takeFlowNotes()));
    }

    #[Test]
    public function aSceneTypeSwitchByAnEffectNotesBothNamesInApplyOrder(): void
    {
        $campaign = $this->inScene('heist');

        $campaign->completeFlowStep('plan', self::prompt('Go'), $this->release, self::at());

        self::assertSame(
            [['kind' => 'switch', 'from' => 'Heist', 'to' => 'Escape'], ['kind' => 'tracker', 'key' => 'heat', 'name' => 'Heat', 'from' => 0, 'to' => 1]],
            $this->arrays($campaign->takeFlowNotes()),
        );
    }

    #[Test]
    public function aSwitchByHandIsNoFlowDecisionButTheStepItSettlesIs(): void
    {
        $campaign = $this->inScene('heist');

        $campaign->switchSceneType($this->release->sceneType('watch') ?? self::fail('No watch.'), $this->release, self::at());

        // The player's own switch is no note; "watch" settles at heat 0 on its first band, "Calm".
        self::assertSame([['kind' => 'band', 'label' => 'Calm']], $this->arrays($campaign->takeFlowNotes()));
    }

    #[Test]
    public function theNotesAreTakenOnce(): void
    {
        $campaign = $this->inScene('gamble');
        $campaign->completeFlowStep('gamble', self::roll('1d6', 6), $this->release, self::at());

        self::assertNotSame([], $campaign->takeFlowNotes());
        self::assertSame([], $campaign->takeFlowNotes());
    }

    #[Test]
    public function aFailingCommandLeavesNoNotes(): void
    {
        $campaign = $this->inScene('broken');
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('p', self::prompt('Go'), $this->release, self::at());
            self::fail('The second Effect divides by zero.');
        } catch (FlowFormulaFailed) {
            // The first Effect changed Heat on the copy only.
            self::assertSame([[], 0], [$campaign->takeFlowNotes(), $campaign->trackerValuesOf($this->release)['heat']]);
            self::assertEquals($before, $campaign);
        }
    }

    /**
     * @param list<FlowNote> $notes
     *
     * @return list<array<string, mixed>>
     */
    private function arrays(array $notes): array
    {
        return array_map(static fn (FlowNote $note): array => $note->toArray(), $notes);
    }

    /**
     * A campaign with its session started and the Scene Type picked, heat set first when asked
     * (the picked scene's setup runs after it).
     */
    private function inScene(string $sceneType, ?int $heat = null): Campaign
    {
        $campaign = self::guided($this->release, 'notes');
        $campaign->startSession(self::at(), $this->release);
        if (null !== $heat) {
            $campaign->setTrackerValue($this->release->tracker('heat') ?? self::fail('No heat.'), $heat);
        }

        $campaign->pickSceneType($sceneType, $this->release, self::at());
        if (null === $heat) {
            // Whatever the pick settled is not under test.
            $campaign->takeFlowNotes();
        }

        return $campaign;
    }
}
