<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The FlowRun's rules on a test release: Flow "test" with the phases "draw" (once, oracle table
 * "scene-kinds"), "roam" (loop, the player picks Talk or Fight, a scene closing), "rounds" (loop,
 * the sequence Talk → Fight) and "duel" (once, the player picks). Talk plays a suggested choice
 * whose skip option ends the part, then a roll, and closes with a prompt; Fight opens with a
 * condition and a mandatory prompt, then plays a table step.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(StepResult::class)]
#[CoversClass(StepKind::class)]
#[CoversClass(ScenePart::class)]
final class FlowRunTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function aCampaignWithAFlowStartsItsFlowRunAndOnePlayedFreelyHasNone(): void
    {
        $guided = self::guided($this->release, 'test');
        $free = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'Free', PinnedRelease::of('flow-run-test', 1, 'Test'), self::at());

        self::assertSame([FlowRunStatus::Active, 0, FlowRunStage::ScenePick, null, null], [self::flowRunOf($guided)->status(), self::flowRunOf($guided)->phaseIndex(), self::flowRunOf($guided)->stage(), self::flowRunOf($guided)->scene(), self::flowRunOf($guided)->part()]);
        self::assertNull($free->flowRun());
        $this->expectException(FlowRunNotActive::class);
        $this->expectExceptionMessageIsOrContains('The campaign plays freely: it follows no Flow.');
        $free->moveOn($this->release, self::at());
    }

    #[Test]
    public function aPickNeedsASessionUnderWay(): void
    {
        $campaign = self::guided($this->release, 'test');
        $campaign->startSession(self::at());
        $campaign->endSession(self::at());

        $this->expectException(NoCurrentSession::class);
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
    }

    #[Test]
    public function anOracleSelectionPicksTheSceneTypeOfTheRolledEntry(): void
    {
        $campaign = $this->inASession();
        $view = $campaign->flowRunView($this->release);
        self::assertSame(['Next scene: roll on Scene kinds', [], 'scene-kinds', false], [$view?->next, $view?->pick?->cards, $view?->pick?->table, $view?->canMoveOn]);
        foreach ([
            'pick by hand' => fn () => $campaign->pickSceneType('talk', $this->release, self::at()),
            'another table' => fn () => $campaign->pickSceneTypeByOracle($this->rolled('other', 2), $this->release, self::at()),
            'an entry without a Scene Type' => fn () => $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 5), $this->release, self::at()),
        ] as $case => $pick) {
            try {
                $pick();
                self::fail($case.' picked a scene.');
            } catch (SceneTypeNotOffered $exception) {
                self::assertContains($exception->getMessage(), ['This scene pick rolls on table "scene-kinds".', 'The rolled entry of table "scene-kinds" names no Scene Type.'], $case);
            }
        }

        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());

        self::assertSame(['Talk 1', 'talk', 'play', 'mood'], [$campaign->currentScene()?->title(), self::flowRunOf($campaign)->sceneType(), ...self::position($campaign)]);
        self::assertSame([1, 1], self::flowRunOf($campaign)->scene());
    }

    #[Test]
    public function aOncePhaseWithAPlayerSelectionPlaysOneSceneAndAOneTypeSelectionPicksItself(): void
    {
        $campaign = $this->inPhase('duel');

        try {
            $campaign->pickSceneType('legwork', $this->release, self::at());
            self::fail('A Scene Type the phase does not offer was picked.');
        } catch (SceneTypeNotOffered $exception) {
            self::assertSame('The scene pick does not offer Scene Type "legwork".', $exception->getMessage());
        }

        try {
            $campaign->moveOn($this->release, self::at());
            self::fail('A once phase moved on.');
        } catch (MoveOnNotAllowed $exception) {
            self::assertSame('Phase "duel" plays once: it ends after its scenes, not by moving on.', $exception->getMessage());
        }

        $campaign->pickSceneType('fight', $this->release, self::at());
        try {
            $campaign->moveOn($this->release, self::at());
            self::fail('A once phase moved on during a scene.');
        } catch (MoveOnNotAllowed) {
        }

        $this->playFight($campaign);

        self::assertSame([FlowRunStatus::Completed, 4], [self::flowRunOf($campaign)->status(), self::flowRunOf($campaign)->phaseIndex()]);
        self::assertSame(['skip:mood', 'skip:wrap', 'phaseEnded:draw', 'phaseEnded:roam', 'phaseEnded:rounds', 'skip:response', 'phaseEnded:duel', 'completed'], self::history($campaign));
    }

    #[Test]
    public function aLoopPhaseRepeatsItsScenePickUntilThePlayerMovesOnWhichDuringASceneEndsThePhaseAfterIt(): void
    {
        $campaign = $this->inPhase('roam');
        $campaign->pickSceneType('fight', $this->release, self::at());
        $this->playFight($campaign);
        self::assertSame(['sceneClosing', 'after'], self::position($campaign));
        $campaign->skipFlowStep('after', $this->release, self::at());
        $campaign->pickSceneType('talk', $this->release, self::at());
        self::assertSame([1, 2, FlowRunStage::Scene], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->scenesPlayed(), self::flowRunOf($campaign)->stage()]);

        // Move on during a scene: the scene goes on to its closing parts, then the phase ends.
        $campaign->moveOn($this->release, self::at());
        self::assertSame([true, 'play', 'mood'], [self::flowRunOf($campaign)->phaseEnding(), ...self::position($campaign)]);
        $this->playTalk($campaign);
        self::assertSame(['sceneClosing', 'after'], self::position($campaign));
        $campaign->skipFlowStep('after', $this->release, self::at());

        self::assertSame([2, false, FlowRunStage::ScenePick], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->phaseEnding(), self::flowRunOf($campaign)->stage()]);
        self::assertSame(['phaseEnded', 'roam', 'moveOn'], [array_last(self::flowRunOf($campaign)->history())?->event->value, ...array_values(array_last(self::flowRunOf($campaign)->history())->details ?? [])]);
    }

    #[Test]
    public function aSequenceLoopCyclesItsSceneTypesAndOffersOnlyTheNext(): void
    {
        $campaign = $this->inPhase('rounds');
        $picked = [];
        foreach (['talk', 'fight', 'talk'] as $sceneType) {
            try {
                $campaign->pickSceneType('talk' === $sceneType ? 'fight' : 'talk', $this->release, self::at());
                self::fail('A sequence offered a Scene Type out of turn.');
            } catch (SceneTypeNotOffered) {
            }

            $campaign->pickSceneType($sceneType, $this->release, self::at());
            $picked[] = $campaign->currentScene()?->title();
            'talk' === $sceneType ? $this->playTalk($campaign) : $this->playFight($campaign);
        }

        self::assertSame(['Talk 2', 'Fight 1', 'Talk 3'], $picked);
        self::assertSame([2, 3, 3], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->sequencePosition(), self::flowRunOf($campaign)->scenesPlayed()]);
    }

    #[Test]
    public function aForcedNextSceneTypeIsTheOnlyPickAndDoesNotAdvanceASequence(): void
    {
        $campaign = $this->inPhase('rounds');
        self::flowRunOf($campaign)->forceNextSceneType('fight');

        try {
            $campaign->pickSceneType('talk', $this->release, self::at());
            self::fail('A forced pick offered another Scene Type.');
        } catch (SceneTypeNotOffered) {
        }

        $campaign->pickSceneType('fight', $this->release, self::at());

        self::assertSame([null, 0, 'Fight 1'], [self::flowRunOf($campaign)->forcedNextSceneType(), self::flowRunOf($campaign)->sequencePosition(), $campaign->currentScene()?->title()]);
        $this->playFight($campaign);
        self::flowRunOf($campaign)->forceNextSceneType('fight');
        $this->expectException(SceneTypeNotOffered::class);
        $this->expectExceptionMessageIsOrContains('This scene pick does not roll on a table.');
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
    }

    #[Test]
    public function aForcedSceneTypeWaitsForThePlayerEvenWhenThePhaseCouldPickItself(): void
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        self::flowRunOf($campaign)->forceNextSceneType('fight');
        $this->playTalk($campaign);

        self::assertSame([1, FlowRunStage::ScenePick], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage()]);
        $campaign->pickSceneType('fight', $this->release, self::at());
        self::assertSame('Fight 1', $campaign->currentScene()?->title());
    }

    #[Test]
    public function aPhaseEndingEndsALoopPhaseAfterTheCurrentScene(): void
    {
        $campaign = $this->inPhase('roam');
        $campaign->pickSceneType('talk', $this->release, self::at());
        self::flowRunOf($campaign)->endPhaseAfterScene();
        self::assertTrue(self::flowRunOf($campaign)->phaseEnding());
        $this->playTalk($campaign);
        $campaign->skipFlowStep('after', $this->release, self::at());

        self::assertSame([2, false, FlowRunStage::ScenePick], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->phaseEnding(), self::flowRunOf($campaign)->stage()]);
        self::assertSame(['phaseEnded', 'roam', 'endPhase'], [array_last(self::flowRunOf($campaign)->history())?->event->value, ...array_values(array_last(self::flowRunOf($campaign)->history())->details ?? [])]);
    }

    #[Test]
    public function stepsFollowTheirNextTheFollowingStepAndEnd(): void
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());

        // A chosen option without "next" goes on with the following step.
        $campaign->completeFlowStep('mood', StepResult::choice('angry'), $this->release, self::at());
        self::assertSame(['play', 'luck'], self::position($campaign));
        $campaign->completeFlowStep('luck', self::roll('1d6', 6), $this->release, self::at());
        self::assertSame(['open', null], self::position($campaign));
        $campaign->endFlowScene(1, $this->release, self::at());
        self::assertSame(['closing', 'wrap'], self::position($campaign));
        $campaign->completeFlowStep('wrap', self::prompt('Nothing'), $this->release, self::at());

        self::assertSame(['mood' => 'Angry', 'luck' => '6', 'wrap' => 'Nothing'], self::flowRunOf($campaign)->answers());
        self::assertSame([1, FlowRunStage::ScenePick, null], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->sceneType()]);
    }

    #[Test]
    public function aSkippedChoiceFollowsItsSkipOptionAndTheSkipIsInTheHistory(): void
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        $campaign->skipFlowStep('mood', $this->release, self::at());

        // The "calm" option ends the play part: the roll is not met.
        self::assertSame(['open', null], self::position($campaign));
        self::assertSame([], self::flowRunOf($campaign)->answers());
        self::assertSame(['step' => 'mood', 'part' => 'play'], self::flowRunOf($campaign)->history()[0]->details);
        self::assertEquals(self::at(), self::flowRunOf($campaign)->history()[0]->at);
    }

    #[Test]
    public function aConditionStepAdvancesOnItsOwnAndAMandatoryStepCannotBeSkipped(): void
    {
        $campaign = $this->inPhase('roam');
        $campaign->pickSceneType('fight', $this->release, self::at());

        self::assertSame(['setup', 'opener'], self::position($campaign));
        $this->expectExceptionMessageIsOrContains('Step "opener" is mandatory: complete it.');
        $campaign->skipFlowStep('opener', $this->release, self::at());
    }

    /**
     * @return iterable<string, array{string, StepResult, string}>
     */
    public static function wrongResults(): iterable
    {
        yield 'another kind' => ['mood', StepResult::prompt('Calm'), 'Step "mood" is a choice step: a prompt result does not complete it.'];
        yield 'an unknown option' => ['mood', StepResult::choice('bored'), 'Choice step "mood" has no option "bored".'];
        yield 'other dice' => ['luck', self::roll('1d8', 3), 'Step "luck" rolls 1d6.'];
        yield 'a blank answer' => ['wrap', StepResult::prompt('  '), 'Step "wrap" needs an answer.'];
        yield 'another table' => ['response', self::table('other', 'Guards'), 'Step "response" rolls on table "scene-kinds".'];
    }

    #[Test]
    #[DataProvider('wrongResults')]
    public function aResultMustCompleteItsStep(string $step, StepResult $result, string $message): void
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        if ('luck' === $step || 'wrap' === $step) {
            $campaign->completeFlowStep('mood', StepResult::choice('angry'), $this->release, self::at());
        }

        if ('wrap' === $step) {
            $campaign->completeFlowStep('luck', self::roll('1d6', 1), $this->release, self::at());
            $campaign->endFlowScene(1, $this->release, self::at());
        }

        if ('response' === $step) {
            $campaign = $this->inPhase('roam');
            $campaign->pickSceneType('fight', $this->release, self::at());
            $campaign->completeFlowStep('opener', self::prompt('Ada'), $this->release, self::at());
        }

        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains($message);
        $campaign->completeFlowStep($step, $result, $this->release, self::at());
    }

    #[Test]
    public function aCommandNamesThePositionItActsOn(): void
    {
        $campaign = $this->inASession();
        $this->assertStale('The FlowRun is at the scene pick, not at step "mood".', fn () => $campaign->completeFlowStep('mood', StepResult::choice('calm'), $this->release, self::at()));
        $this->assertStale('The FlowRun is at the scene pick, not at open play in scene 1.', fn () => $campaign->endFlowScene(1, $this->release, self::at()));
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        $this->assertStale('The FlowRun is at step "mood", not at step "luck".', fn () => $campaign->skipFlowStep('luck', $this->release, self::at()));
        $this->assertStale('The FlowRun is at step "mood", not at the scene pick.', fn () => $campaign->pickSceneType('talk', $this->release, self::at()));
        $this->assertStale('The FlowRun is at step "mood", not at open play in scene 1.', fn () => $campaign->endFlowScene(1, $this->release, self::at()));

        $campaign->skipFlowStep('mood', $this->release, self::at());
        $this->expectExceptionMessageIsOrContains('The FlowRun is at open play in scene 1, not at open play in scene 2.');
        $campaign->endFlowScene(2, $this->release, self::at());
    }

    #[Test]
    public function aGuidedSceneThatIsNoLongerCurrentTakesNoCommand(): void
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        $campaign->startScene('By hand', self::at());

        $this->expectException(FlowRunPositionMismatch::class);
        $this->expectExceptionMessageIsOrContains('The guided scene is no longer the current scene: pause and resume guidance to go on at the scene pick.');
        $campaign->skipFlowStep('mood', $this->release, self::at());
    }

    #[Test]
    public function pausedGuidanceTakesNoFlowRunCommandAndResumesWhereItStopped(): void
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        $campaign->pauseGuidance(self::at());

        self::assertSame(FlowRunStatus::Paused, self::flowRunOf($campaign)->status());
        foreach ([
            'complete' => fn () => $campaign->completeFlowStep('mood', StepResult::choice('calm'), $this->release, self::at()),
            'skip' => fn () => $campaign->skipFlowStep('mood', $this->release, self::at()),
            'end the scene' => fn () => $campaign->endFlowScene(1, $this->release, self::at()),
            'pick' => fn () => $campaign->pickSceneType('talk', $this->release, self::at()),
            'move on' => fn () => $campaign->moveOn($this->release, self::at()),
            'pause again' => static fn () => $campaign->pauseGuidance(self::at()),
        ] as $command => $run) {
            try {
                $run();
                self::fail('Paused guidance took a command: '.$command);
            } catch (FlowRunNotActive $exception) {
                self::assertSame('pause again' === $command ? 'Guidance is already paused.' : 'Guidance is paused: resume it first.', $exception->getMessage(), $command);
            }
        }

        $campaign->resumeGuidance($this->release, self::at());

        self::assertSame([FlowRunStatus::Active, FlowRunStage::Scene, 'play', 'mood'], [self::flowRunOf($campaign)->status(), self::flowRunOf($campaign)->stage(), ...self::position($campaign)]);
        self::assertSame(['paused', 'resumed'], self::history($campaign));
        $this->expectException(FlowRunNotActive::class);
        $this->expectExceptionMessageIsOrContains('Guidance is not paused.');
        $campaign->resumeGuidance($this->release, self::at());
    }

    #[Test]
    public function aSceneStartedByHandWhilePausedAbandonsTheGuidedSceneOnResume(): void
    {
        $campaign = $this->inPhase('roam');
        $campaign->pickSceneType('talk', $this->release, self::at());
        $campaign->pauseGuidance(self::at());
        $campaign->startScene('By hand', self::at());
        $campaign->resumeGuidance($this->release, self::at());

        self::assertSame([1, FlowRunStage::ScenePick, null], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->scene()]);
        self::assertSame(['paused', 'resumed', 'sceneAbandoned:1'], \array_slice(self::history($campaign), -3));
        self::assertSame(['session' => 1, 'scene' => 2], array_last(self::flowRunOf($campaign)->history())?->details);
        $campaign->pickSceneType('fight', $this->release, self::at());
        self::assertSame('Fight 1', $campaign->currentScene()?->title());
    }

    #[Test]
    public function aNewSessionAbandonsTheGuidedSceneOfTheLastOne(): void
    {
        // Ended in the middle of the draw's scene: the once phase played it, the next one waits.
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        $campaign->endSession(self::at());
        self::assertSame([1, 1], self::flowRunOf($campaign)->scene());
        $campaign->startSession(self::at(), $this->release);

        self::assertSame([1, FlowRunStage::ScenePick], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage()]);
        self::assertSame(['sceneAbandoned:1', 'phaseEnded:draw'], self::history($campaign));

        // A session started while the last one is under way: the loop phase picks again.
        $campaign->pickSceneType('fight', $this->release, self::at());
        $campaign->startSession(self::at(), $this->release);

        self::assertSame([1, FlowRunStage::ScenePick, ['session' => 2, 'scene' => 1]], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage(), array_last(self::flowRunOf($campaign)->history())?->details]);
    }

    #[Test]
    public function handTrackerEditsAndSceneTypeSwitchesAreInTheHistoryAndTheGuidedSceneFollowsItsSwitch(): void
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        $heat = $this->release->tracker('heat') ?? self::fail('No heat tracker.');

        self::assertSame(5, $campaign->setTrackerValue($heat, 9, self::at()));
        $campaign->switchSceneType($this->release->sceneType('fight') ?? self::fail('No fight.'), $this->release, self::at());

        self::assertSame([
            ['trackerEdit', ['tracker' => 'heat', 'from' => 0, 'to' => 5]],
            ['sceneTypeSwitch', ['scene' => 1, 'from' => 'talk', 'to' => 'fight']],
        ], array_map(static fn (FlowRunHistoryEntry $entry): array => [$entry->event->value, $entry->details], self::flowRunOf($campaign)->history()));
        // The guided scene goes on at the new type's setup; the condition step passes on its own.
        self::assertSame(['fight', 'setup', 'opener'], [self::flowRunOf($campaign)->sceneType(), ...self::position($campaign)]);

        $campaign->completeFlowStep('opener', self::prompt('Ada'), $this->release, self::at());
        self::assertSame(['play', 'response'], self::position($campaign));
    }

    /**
     * @return iterable<string, array{string, \Closure(Campaign, GameSystemSnapshot): (\Closure(): void)}>
     */
    public static function commandsStartingTheNextScene(): iterable
    {
        yield 'completing a step' => ['talk', static function (Campaign $campaign, GameSystemSnapshot $release): \Closure {
            self::closeTalk($campaign, $release);

            return static fn () => $campaign->completeFlowStep('wrap', self::prompt('Nothing'), $release, self::at());
        }];
        yield 'skipping a step' => ['talk', static function (Campaign $campaign, GameSystemSnapshot $release): \Closure {
            self::closeTalk($campaign, $release);

            return static fn () => $campaign->skipFlowStep('wrap', $release, self::at());
        }];
        yield 'ending a scene' => ['fight', static function (Campaign $campaign, GameSystemSnapshot $release): \Closure {
            $campaign->pickSceneType('fight', $release, self::at());
            $campaign->completeFlowStep('opener', self::prompt('Ada'), $release, self::at());
            $campaign->skipFlowStep('response', $release, self::at());

            return static fn () => $campaign->endFlowScene(Session::MAX_SCENES, $release, self::at());
        }];
        yield 'moving on' => ['talk', static function (Campaign $campaign, GameSystemSnapshot $release): \Closure {
            $campaign->startScene('By hand', self::at());

            return static fn () => $campaign->moveOn($release, self::at());
        }];
    }

    /**
     * Two loop phases of one Scene Type each, so the next scene starts on its own, in a session
     * one scene short of full.
     *
     * @param \Closure(Campaign, GameSystemSnapshot): (\Closure(): void) $prepare brings the campaign to the command
     */
    #[Test]
    #[DataProvider('commandsStartingTheNextScene')]
    public function aCommandThatCannotStartTheNextSceneChangesNothing(string $sceneType, \Closure $prepare): void
    {
        $release = self::translate($this->content(array_map(static fn (string $key): array => ['key' => $key, 'name' => ucfirst($key), 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => [$sceneType]]], ['alone', 'again'])));
        $campaign = self::guided($release, 'test');
        $campaign->startSession(self::at());
        for ($scene = 1; $scene < Session::MAX_SCENES; ++$scene) {
            $campaign->startScene('By hand', self::at());
        }

        $command = $prepare($campaign, $release);
        $before = clone $campaign;

        try {
            $command();
            self::fail('The next scene started in a full session.');
        } catch (CampaignLimitReached) {
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function thePartsRunInOrderAndAStepKindIsNamed(): void
    {
        self::assertSame(['sceneOpening', 'setup', 'play', 'open', 'closing', 'sceneClosing', null], array_map(static fn (?ScenePart $part): ?string => $part?->value, [ScenePart::SceneOpening, ...array_map(static fn (ScenePart $part): ?ScenePart => $part->next(), ScenePart::cases())]));
        self::assertSame(['Exceptional yes', 'Yes', 'No', 'Exceptional no'], array_map(static fn (YesNoAnswer $answer): string => self::oracle($answer)->answer, YesNoAnswer::cases()));
        self::assertSame('Storm › Hail', StepResult::table(new OracleTableResult([new OracleTableStep('weather', 'Weather', '1d6', 6, 'Storm', 'storm-kind'), new OracleTableStep('storm-kind', 'Storm kind', '1d2', 2, 'Hail', null)]))->answer);
    }

    private function assertStale(string $message, \Closure $command): void
    {
        try {
            $command();
            self::fail('A stale command was accepted: '.$message);
        } catch (FlowRunPositionMismatch $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function inASession(): Campaign
    {
        $campaign = self::guided($this->release, 'test');
        $campaign->startSession(self::at(), $this->release);

        return $campaign;
    }

    /**
     * A campaign at the scene pick of this phase, after one Talk scene in the draw and moving on.
     */
    private function inPhase(string $phase): Campaign
    {
        $campaign = $this->inASession();
        $campaign->pickSceneTypeByOracle($this->rolled('scene-kinds', 2), $this->release, self::at());
        $this->playTalk($campaign);
        foreach (['roam', 'rounds', 'duel'] as $next) {
            if ($next === $phase) {
                return $campaign;
            }

            $campaign->moveOn($this->release, self::at());
        }

        return self::fail('Unknown phase '.$phase);
    }

    private function playTalk(Campaign $campaign): void
    {
        $scene = (int) $campaign->currentScene()?->number();
        $campaign->skipFlowStep('mood', $this->release, self::at());
        $campaign->endFlowScene($scene, $this->release, self::at());
        $campaign->skipFlowStep('wrap', $this->release, self::at());
    }

    private function playFight(Campaign $campaign): void
    {
        $campaign->completeFlowStep('opener', self::prompt('Ada'), $this->release, self::at());
        $campaign->skipFlowStep('response', $this->release, self::at());
        $campaign->endFlowScene((int) $campaign->currentScene()?->number(), $this->release, self::at());
    }

    /**
     * Picks Talk as the last scene the session holds and plays it to its closing step "wrap".
     */
    private static function closeTalk(Campaign $campaign, GameSystemSnapshot $release): void
    {
        $campaign->pickSceneType('talk', $release, self::at());
        $campaign->skipFlowStep('mood', $release, self::at());
        $campaign->endFlowScene(Session::MAX_SCENES, $release, self::at());
    }

    private function rolled(string $table, int $total): OracleTableResult
    {
        return new OracleTableResult([new OracleTableStep($table, 'Scene kinds', '1d6', $total, 'A roll', null)]);
    }

    /**
     * @param ?list<array<string, mixed>> $phases the Flow's phases instead of the four described above
     *
     * @return array<string, mixed>
     */
    private function content(?array $phases = null): array
    {
        $prompt = static fn (string $key, string $title, bool $mandatory = false): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title, 'mandatory' => $mandatory];
        $sceneType = static fn (string $key, string $name, array $setup, array $play, array $closing): array => ['key' => $key, 'name' => $name, 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => $play, 'closing' => $closing];
        $phase = static fn (string $key, string $name, string $mode, array $selection, array $hooks = []): array => ['key' => $key, 'name' => $name, 'mode' => $mode, 'selection' => $selection] + $hooks;

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'flow-run-test', 'name' => 'Test'],
            'oracles' => ['tables' => [['key' => 'scene-kinds', 'name' => 'Scene kinds', 'dice' => '1d6', 'entries' => [
                ['min' => 1, 'max' => 3, 'text' => 'A talk', 'sceneType' => 'talk'],
                ['min' => 4, 'max' => 6, 'text' => 'Nothing'],
            ]]], 'likelihood' => []],
            'trackers' => [['key' => 'heat', 'name' => 'Heat', 'kind' => 'counter', 'min' => 0, 'max' => 5, 'initial' => 0]],
            'factSlots' => [],
            'sceneTypes' => [
                $sceneType('talk', 'Talk', [], [
                    ['key' => 'mood', 'kind' => 'choice', 'title' => 'How is the mood?', 'options' => [['key' => 'calm', 'label' => 'Calm', 'next' => 'end'], ['key' => 'angry', 'label' => 'Angry']], 'skip' => 'calm'],
                    ['key' => 'luck', 'kind' => 'roll', 'title' => 'Push your luck', 'dice' => '1d6'],
                ], [$prompt('wrap', 'What changed?')]),
                $sceneType('fight', 'Fight', [
                    ['key' => 'heat-check', 'kind' => 'condition', 'title' => 'How hot is it?', 'tracker' => 'heat', 'bands' => [[]]],
                    $prompt('opener', 'Who strikes first?', true),
                ], [['key' => 'response', 'kind' => 'table', 'title' => 'Who comes?', 'table' => 'scene-kinds']], []),
                $sceneType('legwork', 'Legwork', [], [], []),
            ],
            'flows' => [['key' => 'test', 'name' => 'Test', 'defaultView' => 'journal', 'oracles' => ['scene-kinds'], 'trackers' => ['heat'], 'phases' => $phases ?? [
                $phase('draw', 'The draw', 'once', ['rule' => 'oracle', 'table' => 'scene-kinds']),
                $phase('roam', 'Roaming', 'loop', ['rule' => 'player', 'sceneTypes' => ['talk', 'fight']], ['sceneClosing' => [$prompt('after', 'Anything else?')]]),
                $phase('rounds', 'Rounds', 'loop', ['rule' => 'sequence', 'sceneTypes' => ['talk', 'fight']]),
                $phase('duel', 'The duel', 'once', ['rule' => 'player', 'sceneTypes' => ['talk', 'fight']]),
            ]]],
            'checks' => [],
        ];
    }
}
