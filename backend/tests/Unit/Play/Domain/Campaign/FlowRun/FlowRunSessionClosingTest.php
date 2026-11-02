<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A phase's session closing runs only in the phase this session's opening ran in. The test release
 * has "zero" (once, Session Zero: its only Scene Type "job" is picked by itself) and "chronicle"
 * (a loop where the player picks "job" or "trouble"). The chronicle always asks "notes" when a session
 * closes; the other hooks differ per test (see content()).
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class FlowRunSessionClosingTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    #[Test]
    public function endingTheSessionAfterSessionZeroHandedOverSkipsTheChroniclesClosing(): void
    {
        $campaign = $this->atTheChroniclePickAfterSessionZero(zeroOpens: false);

        $scenes = \count($campaign->sessions()[0]->scenes());
        $campaign->endSession(self::at(), 1, $this->release);

        $this->assertSessionEndedWithoutClosing($campaign, $scenes);
    }

    #[Test]
    public function aSessionOpeningThatRanInAnEarlierPhaseSkipsTheClosingOfTheLaterOne(): void
    {
        $campaign = $this->atTheChroniclePickAfterSessionZero(zeroOpens: true);
        self::assertSame(0, self::flowRunOf($campaign)->sessionOpenedInPhase());

        $scenes = \count($campaign->sessions()[0]->scenes());
        $campaign->endSession(self::at(), 1, $this->release);

        $this->assertSessionEndedWithoutClosing($campaign, $scenes);
    }

    #[Test]
    public function aSessionOpeningThatRanInTheCurrentPhaseRunsItsClosing(): void
    {
        $campaign = self::guided($this->release = self::translate($this->content(zeroOpens: false)), 'flow');
        $this->startInTheChronicle($campaign);
        self::assertSame(1, self::flowRunOf($campaign)->sessionOpenedInPhase());

        $campaign->endSession(self::at(), 2, $this->release);

        self::assertSame([FlowRunStage::Hook, Hook::SessionClosing, 'notes'], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey()]);
        self::assertNull($campaign->sessions()[1]->endedAt());
    }

    #[Test]
    public function aPhaseWithAnEmptyOpeningStillCountsAsHavingOpenedTheSession(): void
    {
        $campaign = self::guided($this->release = self::translate($this->content(zeroOpens: false, chronicleOpens: false)), 'flow');
        $this->startInTheChronicle($campaign);
        self::assertSame(1, self::flowRunOf($campaign)->sessionOpenedInPhase());

        $campaign->endSession(self::at(), 2, $this->release);

        self::assertSame([Hook::SessionClosing, 'notes'], [self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey()]);
    }

    #[Test]
    public function theNextSessionStartingInTheChronicleOpensAndClosesIt(): void
    {
        $campaign = $this->atTheChroniclePickAfterSessionZero(zeroOpens: false);
        $campaign->endSession(self::at(), 1, $this->release);

        $campaign->startSession(self::at(), $this->release);

        self::assertSame([Hook::SessionOpening, 'recap'], [self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey()]);
        $campaign->completeFlowStep('recap', self::prompt('Again'), $this->release, self::at());
        self::assertSame([FlowRunStage::ScenePick, 1], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->sessionOpenedInPhase()]);

        $campaign->endSession(self::at(), 2, $this->release);

        self::assertSame([FlowRunStage::Hook, Hook::SessionClosing, 'notes'], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey()]);
        $campaign->skipFlowStep('notes', $this->release, self::at());
        self::assertNull($campaign->currentSession());
    }

    #[Test]
    public function aFlowRunThatDoesNotKnowWhereTheSessionOpeningRanRunsTheClosing(): void
    {
        $campaign = $this->atTheChroniclePickAfterSessionZero(zeroOpens: false);
        $known = self::flowRunOf($campaign);
        $legacy = FlowRun::reconstitute($known->status(), $known->phaseIndex(), $known->stage(), null, null, null, null, null, $known->sequencePosition(), $known->scenesPlayed(), $known->answers(), $known->forcedNextSceneType(), $known->phaseEndingReason(), $known->switchCount(), $known->history(), null, $known->phaseOpened(), $known->sessionOpeningDue(), null, $known->autoPickDue());
        $unknown = Campaign::reconstitute($campaign->id(), $campaign->ownerId(), $campaign->name(), $campaign->pinnedRelease(), $campaign->createdAt(), $campaign->sessions(), $campaign->trackerValuesOf($this->release), $campaign->flowKey(), $legacy);

        $unknown->endSession(self::at(), 1, $this->release);

        self::assertNull($legacy->sessionOpenedInPhase());
        self::assertSame([Hook::SessionClosing, 'notes'], [self::flowRunOf($unknown)->hook(), self::flowRunOf($unknown)->stepKey()]);
    }

    #[Test]
    public function theViewPromisesNoClosingThatWillBeSkipped(): void
    {
        $campaign = $this->atTheChroniclePickAfterSessionZero(zeroOpens: false);

        $view = $campaign->flowRunView($this->release) ?? self::fail('No view.');

        self::assertTrue($view->canEndSession);
        self::assertSame('Scene: choose a Scene Type', $view->next);
        $campaign->endSession(self::at(), 1, $this->release);
        self::assertNull(self::flowRunOf($campaign)->hook());
    }

    /**
     * Session 1 opens in "zero", plays its only scene and hands over: the run waits at the chronicle's pick.
     */
    private function atTheChroniclePickAfterSessionZero(bool $zeroOpens): Campaign
    {
        $this->release = self::translate($this->content($zeroOpens));
        $campaign = self::guided($this->release, 'flow');
        $campaign->startSession(self::at(), $this->release);
        if ($zeroOpens) {
            $campaign->completeFlowStep('zrecap', self::prompt('Zero'), $this->release, self::at());
        }

        $campaign->completeFlowStep('act', self::prompt('Sneak'), $this->release, self::at());
        $campaign->endFlowScene((int) $campaign->currentScene()?->number(), $this->release, self::at());
        self::assertSame([1, FlowRunStage::ScenePick, 1], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage(), $campaign->currentSession()?->number()]);

        return $campaign;
    }

    /**
     * Starts the session of a release whose first phase is already over.
     */
    private function startInTheChronicle(Campaign $campaign): void
    {
        $campaign->startSession(self::at(), $this->release);
        $campaign->completeFlowStep('act', self::prompt('Sneak'), $this->release, self::at());
        $campaign->endFlowScene((int) $campaign->currentScene()?->number(), $this->release, self::at());
        $campaign->endSession(self::at(), 1, $this->release);
        $campaign->startSession(self::at(), $this->release);
        if (null !== self::flowRunOf($campaign)->stepKey()) {
            $campaign->completeFlowStep('recap', self::prompt('Recap'), $this->release, self::at());
        }
    }

    private function assertSessionEndedWithoutClosing(Campaign $campaign, int $scenes): void
    {
        $flowRun = self::flowRunOf($campaign);
        self::assertSame([null, FlowRunStage::ScenePick, null, null], [$campaign->currentSession(), $flowRun->stage(), $flowRun->hook(), $flowRun->stepKey()]);
        self::assertEquals(self::at(), $campaign->sessions()[0]->endedAt());
        self::assertCount($scenes, $campaign->sessions()[0]->scenes(), 'No hook Scene was started for the closing.');
        self::assertSame(1, $flowRun->phaseIndex());
        self::assertCount(1, $campaign->sessions(), 'No next session opened by itself.');
        self::assertTrue(($campaign->flowRunView($this->release) ?? self::fail('No view.'))->waitsForSession);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(bool $zeroOpens = false, bool $chronicleOpens = true): array
    {
        $prompt = static fn (string $key, string $title): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title];
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'closing', 'name' => 'Closing'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'sceneTypes' => [$sceneType('job', [$prompt('act', 'What do you do?')]), $sceneType('trouble', [$prompt('face', 'Face it')])],
            'flows' => [['key' => 'flow', 'name' => 'Flow', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'zero', 'name' => 'Session Zero', 'mode' => 'once', 'selection' => ['rule' => 'sequence', 'sceneTypes' => ['job']]]
                + ($zeroOpens ? ['sessionOpening' => [$prompt('zrecap', 'Zero recap')], 'sessionClosing' => [$prompt('zbye', 'Zero closing')]] : []),
                ['key' => 'chronicle', 'name' => 'The chronicle', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['job', 'trouble']], 'sessionClosing' => [$prompt('notes', 'Did you get what you wanted?')]]
                + ($chronicleOpens ? ['sessionOpening' => [$prompt('recap', 'Recap')]] : []),
            ]]],
            'checks' => [],
        ];
    }
}
