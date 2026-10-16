<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunProgress;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\FlowRunView;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use App\Play\Domain\Campaign\FlowRun\SessionCannotEndWhileGuided;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Session, phase and world turn hooks in hook Scenes, on a test release with the Tracker "doom"
 * (counter 0-5, starting at 0), the Scene Types "job" (setup step "act") and "trouble" (setup step
 * "face"), and two phases: "start" (Act 1, a loop where the player picks either type) with every
 * hook (the session closing asks "notes"), and "finale" (once, "job" alone) with none. The world turn passes on doom 0; from doom 1 it
 * forces "trouble" and asks "news", a choice whose Effects try to switch the Scene Type and either
 * raise doom ("calm", what skipping takes) or end the phase ("end").
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
#[CoversClass(Hook::class)]
final class FlowRunHooksTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function aSessionOpensThenThePhaseOpensEachInItsHookSceneBeforeTheFirstPick(): void
    {
        $campaign = self::guided($this->release, 'hooked');
        self::assertSame('Session opening: Recap at doom 0', $this->view($campaign)->next);
        $campaign->startSession(self::at(), $this->release);

        $view = $this->view($campaign);
        self::assertSame([FlowRunStage::Hook, Hook::SessionOpening, 'recap', [1, 1]], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey(), self::flowRunOf($campaign)->scene()]);
        self::assertEquals(new FlowRunProgress('Act 1', 'start', 'The start', null, Hook::SessionOpening, 1, 1), $view->progress);
        self::assertSame(['Recap at doom 0', null, true, 'Phase opening: What draws you in?'], [$view->step?->title, $view->pick, $view->canMoveOn, $view->next]);
        $this->assertHookScene($campaign, Hook::SessionOpening, 'Session 1 begins');
        try {
            $campaign->skipFlowStep('recap', $this->release, self::at());
            self::fail('A mandatory hook step was skipped.');
        } catch (StepCannotBeSkipped) {
        }

        $campaign->completeFlowStep('recap', self::prompt('Nothing yet'), $this->release, self::at());
        $this->assertHookScene($campaign, Hook::PhaseOpening, 'Act 1: The start');
        self::assertSame('Next scene: choose a scene type', $this->view($campaign)->next);
        $campaign->completeFlowStep('lure', self::prompt('gold'), $this->release, self::at());

        self::assertSame([FlowRunStage::ScenePick, null, true], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->phaseOpened()]);
        self::assertSame(['Session 1 begins', 'Lured by gold'], $this->titles($campaign));
    }

    #[Test]
    public function aWorldTurnRunsAfterASceneAndItsConditionMayPassItWithoutAScene(): void
    {
        $campaign = $this->atTheFirstPick();
        $this->playJob($campaign);
        self::assertSame([FlowRunStage::ScenePick, 3], [self::flowRunOf($campaign)->stage(), \count($campaign->sessions()[0]->scenes())]);

        // From doom 1 the world turn forces trouble; a switch in a hook is ignored (recorded).
        $campaign->setTrackerValue($this->release->tracker('doom') ?? self::fail('No doom.'), 1);
        $campaign->pickSceneType('job', $this->release, self::at());
        $campaign->completeFlowStep('act', self::prompt('Sneak'), $this->release, self::at());
        self::assertSame([['open', null], 'World turn: Any news?'], [self::position($campaign), $this->view($campaign)->next]);
        $campaign->endFlowScene(4, $this->release, self::at());

        $this->assertHookScene($campaign, Hook::WorldTurn, 'The world moves');
        self::assertSame(['news', 'trouble'], [self::flowRunOf($campaign)->stepKey(), self::flowRunOf($campaign)->forcedNextSceneType()]);
        self::assertSame('Next scene: Trouble', $this->view($campaign)->next);
        $campaign->completeFlowStep('news', StepResult::choice('calm'), $this->release, self::at());

        self::assertSame([FlowRunStage::ScenePick, 2, ['trouble']], [self::flowRunOf($campaign)->stage(), $campaign->trackerValue($this->release->tracker('doom') ?? self::fail('No doom.')), array_map(static fn (SceneType $type): string => $type->key, $this->view($campaign)->pick->cards ?? [])]);
        self::assertEquals(FlowRunHistoryEntry::sceneTypeSwitchIgnored(self::at(), 5, 'job'), array_last(self::flowRunOf($campaign)->history()));
    }

    #[Test]
    public function aSwitchOnAConditionStepOfAHookDoesNotStartAHookSceneByItself(): void
    {
        $this->release = self::translate($this->content([
            ['key' => 'doomed', 'kind' => 'condition', 'title' => 'Is doom coming?', 'tracker' => 'doom', 'bands' => [['effects' => [['kind' => 'switchSceneType', 'sceneType' => 'job']]]]],
        ]));
        $campaign = $this->atTheFirstPick();

        $this->playJob($campaign);

        // The hook has no step that waits, so it leaves no Scene; the ignored switch is recorded without one.
        self::assertSame([FlowRunStage::ScenePick, 3, null], [self::flowRunOf($campaign)->stage(), \count($campaign->sessions()[0]->scenes()), self::flowRunOf($campaign)->hook()]);
        self::assertEquals(FlowRunHistoryEntry::sceneTypeSwitchIgnored(self::at(), null, 'job'), array_last(self::flowRunOf($campaign)->history()));
    }

    #[Test]
    public function anEndPhaseEffectInAHookClosesThePhaseWhereThePickWouldComeAndTheFlowCompletes(): void
    {
        $campaign = $this->inTheWorldTurn();

        $campaign->completeFlowStep('news', StepResult::choice('end'), $this->release, self::at());

        $this->assertHookScene($campaign, Hook::PhaseClosing, 'The start ends');
        self::assertSame(['How did Sneak end?', false, 'The start complete → Finale'], [$this->view($campaign)->step?->title, $this->view($campaign)->canMoveOn, $this->view($campaign)->next]);
        $campaign->skipFlowStep('wrap', $this->release, self::at());

        // The finale has no hooks; the world turn's forced trouble carries over, then its sequence
        // offers its only Scene Type, which waits after a scene (ADR 0020), and the Flow ends after it.
        self::assertSame([1, FlowRunStage::ScenePick, 'trouble', true], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->forcedNextSceneType(), self::flowRunOf($campaign)->phaseOpened()]);
        $campaign->pickSceneType('trouble', $this->release, self::at());
        $campaign->skipFlowStep('face', $this->release, self::at());
        $campaign->endFlowScene(6, $this->release, self::at());
        self::assertSame([FlowRunStage::ScenePick, null], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->forcedNextSceneType()]);
        $this->playJob($campaign);
        self::assertSame(FlowRunStatus::Completed, self::flowRunOf($campaign)->status());
        self::assertSame(['skip:lure', 'sceneTypeSwitchIgnored:4', 'skip:wrap', 'phaseEnded:start', 'skip:face', 'phaseEnded:finale', 'completed'], self::history($campaign));
        self::assertSame([['step' => 'wrap', 'part' => 'phaseClosing'], 'endPhase', 'finished'], [self::flowRunOf($campaign)->history()[2]->details, self::flowRunOf($campaign)->history()[3]->details['reason'], self::flowRunOf($campaign)->history()[5]->details['reason']]);
    }

    #[Test]
    public function moveOnInAHookEndsThePhaseAfterItAndNotWhileItCloses(): void
    {
        $campaign = $this->inTheWorldTurn();
        $this->assertStale('The FlowRun is at step "news", not at open play in scene 4.', fn () => $campaign->endFlowScene(4, $this->release, self::at()));

        $campaign->moveOn('start', $this->release, self::at());
        self::assertSame(['moveOn', false], [self::flowRunOf($campaign)->phaseEndingReason(), $this->view($campaign)->canMoveOn]);
        $campaign->skipFlowStep('news', $this->release, self::at());

        self::assertSame(Hook::PhaseClosing, self::flowRunOf($campaign)->hook());
        self::assertSame(['step' => 'news', 'part' => 'worldTurn'], self::flowRunOf($campaign)->history()[1]->details);
        $this->expectException(MoveOnNotAllowed::class);
        $this->expectExceptionMessageIsOrContains('Phase "start" is closing: it ends after its phase closing.');
        $campaign->moveOn('start', $this->release, self::at());
    }

    #[Test]
    public function moveOnAtThePickRunsThePhaseClosing(): void
    {
        $campaign = $this->atTheFirstPick();

        $campaign->moveOn('start', $this->release, self::at());

        $this->assertHookScene($campaign, Hook::PhaseClosing, 'The start ends');
        self::assertSame('moveOn', self::flowRunOf($campaign)->phaseEndingReason());
    }

    #[Test]
    public function aLaterSessionRunsItsOpeningButNotThePhaseOpeningAgain(): void
    {
        $campaign = $this->atTheFirstPick();
        $campaign->endSession(self::at());

        $campaign->startSession(self::at(), $this->release);
        $this->assertHookScene($campaign, Hook::SessionOpening, 'Session 2 begins');
        $campaign->completeFlowStep('recap', self::prompt('Last time'), $this->release, self::at());

        self::assertSame([FlowRunStage::ScenePick, ['Session 2 begins']], [self::flowRunOf($campaign)->stage(), $this->titles($campaign, 1)]);
    }

    #[Test]
    public function aPausedHookResumesAtItsStepAndAnAbandonedHookSceneGoesOnAsAfterIt(): void
    {
        $campaign = self::guided($this->release, 'hooked');
        $campaign->startSession(self::at(), $this->release);
        $campaign->pauseGuidance(self::at());
        $campaign->resumeGuidance($this->release, self::at());
        self::assertSame([Hook::SessionOpening, 'recap'], [self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey()]);

        $campaign->pauseGuidance(self::at());
        $campaign->startScene('By hand', self::at());
        $campaign->resumeGuidance($this->release, self::at());

        self::assertSame(['paused', 'resumed', 'paused', 'resumed', 'sceneAbandoned:1'], self::history($campaign));
        $this->assertHookScene($campaign, Hook::PhaseOpening, 'Act 1: The start');
    }

    #[Test]
    public function aHookThatComesUpWithoutASessionWaitsAndRunsBeforeTheNextSessionOpens(): void
    {
        // Paused mid-scene, the session ends; resumed before the next one, the scene is abandoned.
        $campaign = $this->atTheFirstPick();
        $campaign->setTrackerValue($this->release->tracker('doom') ?? self::fail('No doom.'), 1);
        $campaign->pickSceneType('job', $this->release, self::at());
        $campaign->pauseGuidance(self::at());
        $campaign->endSession(self::at());
        $campaign->resumeGuidance($this->release, self::at());

        $view = $this->view($campaign);
        self::assertSame([FlowRunStage::Hook, Hook::WorldTurn, null, null], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->scene(), $view->step]);
        self::assertSame([true, Hook::WorldTurn, 'World turn: Any news?'], [$view->waitsForSession, $view->progress?->part, $view->next]);
        $this->assertStale('The FlowRun is at the world turn, waiting for a session, not at step "news".', fn (): array => $campaign->completeFlowStep('news', StepResult::choice('calm'), $this->release, self::at()));

        $campaign->startSession(self::at(), $this->release);
        $this->assertHookScene($campaign, Hook::WorldTurn, 'The world moves');
        $campaign->skipFlowStep('news', $this->release, self::at());
        $this->assertHookScene($campaign, Hook::SessionOpening, 'Session 2 begins');
        self::assertSame(['The world moves', 'Session 2 begins'], $this->titles($campaign, 1));
    }

    #[Test]
    public function aSessionStartedWhilePausedOpensOnResume(): void
    {
        $campaign = self::guided($this->release, 'hooked');
        $campaign->pauseGuidance(self::at());
        $campaign->startSession(self::at(), $this->release);
        self::assertSame([[], true], [$campaign->sessions()[0]->scenes(), self::flowRunOf($campaign)->sessionOpeningDue()]);

        $campaign->resumeGuidance($this->release, self::at());

        $this->assertHookScene($campaign, Hook::SessionOpening, 'Session 1 begins');
        self::assertFalse(self::flowRunOf($campaign)->sessionOpeningDue());
    }

    #[Test]
    public function aGuidedSessionEndsAtThePickAfterItsSessionClosingAndTheNextOneOpens(): void
    {
        $campaign = $this->atTheFirstPick();
        self::assertTrue($this->view($campaign)->canEndSession);

        $session = $campaign->endSession(self::at(), 1, $this->release);

        self::assertSame([1, null], [$session->number(), $session->endedAt()]);
        $this->assertHookScene($campaign, Hook::SessionClosing, 'Session 1 ends');
        self::assertSame([false, 'Session ends'], [$this->view($campaign)->canEndSession, $this->view($campaign)->next]);
        $campaign->skipFlowStep('notes', $this->release, self::at());

        $view = $this->view($campaign);
        self::assertSame([null, FlowRunStage::ScenePick, true, false, 'Session opening: Recap at doom 0'], [$campaign->currentSession(), self::flowRunOf($campaign)->stage(), $view->waitsForSession, $view->canEndSession, $view->next]);
        self::assertEquals(self::at(), $campaign->sessions()[0]->endedAt());
        $campaign->startSession(self::at(), $this->release);
        $this->assertHookScene($campaign, Hook::SessionOpening, 'Session 2 begins');
    }

    #[Test]
    public function aGuidedSessionDoesNotEndInASceneOrAHookButEndsAtOnceWhilePaused(): void
    {
        $campaign = $this->inTheWorldTurn();
        foreach (['the world turn' => null, 'a scene' => 'trouble'] as $where => $sceneType) {
            if (null !== $sceneType) {
                $campaign->skipFlowStep('news', $this->release, self::at());
                $campaign->pickSceneType($sceneType, $this->release, self::at());
            }

            $before = clone $campaign;
            try {
                $campaign->endSession(self::at(), 1, $this->release);
                self::fail('A guided session ended in '.$where);
            } catch (SessionCannotEndWhileGuided $exception) {
                self::assertSame(null === $sceneType ? 'The FlowRun is at step "news": a guided session ends between scenes, at the scene pick.' : 'The FlowRun is at step "face": a guided session ends between scenes, at the scene pick.', $exception->getMessage());
                self::assertEquals($before, $campaign);
            }
        }

        $campaign->pauseGuidance(self::at());
        self::assertEquals(self::at(), $campaign->endSession(self::at(), 1, $this->release)->endedAt());
    }

    #[Test]
    public function anAbandonedSessionClosingGoesBackToThePickWithoutEndingTheSession(): void
    {
        $campaign = $this->atTheFirstPick();
        $campaign->endSession(self::at(), 1, $this->release);
        $campaign->pauseGuidance(self::at());
        $campaign->startScene('By hand', self::at());

        $campaign->resumeGuidance($this->release, self::at());

        self::assertSame([FlowRunStage::ScenePick, 1, 'sceneAbandoned:1'], [self::flowRunOf($campaign)->stage(), $campaign->currentSession()?->number(), array_last(self::history($campaign))]);
    }

    /**
     * Characterization (written after the code): pausing during the session closing, ending the
     * session by hand while paused and resuming abandons the closing and waits for the next session.
     */
    #[Test]
    public function pausingDuringTheSessionClosingThenEndingTheSessionAndResumingAbandonsTheClosing(): void
    {
        $campaign = $this->atTheFirstPick();
        $campaign->endSession(self::at(), 1, $this->release);
        $this->assertHookScene($campaign, Hook::SessionClosing, 'Session 1 ends');
        $campaign->pauseGuidance(self::at());
        $campaign->endSession(self::at(), 1, $this->release);
        self::assertNull($campaign->currentSession());

        $campaign->resumeGuidance($this->release, self::at());

        self::assertSame([FlowRunStatus::Active, FlowRunStage::ScenePick, null, null, 'sceneAbandoned:1'], [self::flowRunOf($campaign)->status(), self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->scene(), array_last(self::history($campaign))]);

        $campaign->startSession(self::at(), $this->release);
        self::assertSame([Hook::SessionOpening, 'recap'], [self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey()]);
        $campaign->completeFlowStep('recap', self::prompt('Again'), $this->release, self::at());
        self::assertSame([FlowRunStage::ScenePick, 2], [self::flowRunOf($campaign)->stage(), $campaign->currentSession()?->number()]);
        $campaign->pickSceneType('job', $this->release, self::at());
        self::assertSame('act', self::flowRunOf($campaign)->stepKey());
    }

    private function atTheFirstPick(): Campaign
    {
        $campaign = self::guided($this->release, 'hooked');
        $campaign->startSession(self::at(), $this->release);
        $campaign->completeFlowStep('recap', self::prompt('Nothing yet'), $this->release, self::at());
        $campaign->skipFlowStep('lure', $this->release, self::at());

        return $campaign;
    }

    /**
     * At the world turn's choice "news" (scene 4), after a job (scene 3) on doom 1.
     */
    private function inTheWorldTurn(): Campaign
    {
        $campaign = $this->atTheFirstPick();
        $campaign->setTrackerValue($this->release->tracker('doom') ?? self::fail('No doom.'), 1);
        $this->playJob($campaign);

        return $campaign;
    }

    private function playJob(Campaign $campaign, bool $pick = true): void
    {
        if ($pick) {
            $campaign->pickSceneType('job', $this->release, self::at());
        }

        $campaign->completeFlowStep('act', self::prompt('Sneak'), $this->release, self::at());
        $campaign->endFlowScene((int) $campaign->currentScene()?->number(), $this->release, self::at());
    }

    private function assertHookScene(Campaign $campaign, Hook $hook, string $title): void
    {
        $scene = $campaign->currentScene();
        self::assertSame([SceneKind::Hook, $hook, $title, FlowRunStage::Hook, $hook, $scene?->number()], [$scene?->kind(), $scene?->hook(), $scene?->title(), self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->scene()[1] ?? null]);
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

    private function view(Campaign $campaign): FlowRunView
    {
        return $campaign->flowRunView($this->release) ?? self::fail('The campaign has no FlowRun.');
    }

    /**
     * @return list<string> the titles of a session's scenes
     */
    private function titles(Campaign $campaign, int $session = 0): array
    {
        return array_map(static fn (Scene $scene): string => $scene->title(), $campaign->sessions()[$session]->scenes());
    }

    /**
     * @param ?list<array<string, mixed>> $worldTurn the steps of the first phase's world turn, the default ones when null
     *
     * @return array<string, mixed>
     */
    private function content(?array $worldTurn = null): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'hooks', 'name' => 'Hooks'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [['key' => 'doom', 'name' => 'Doom', 'kind' => 'counter', 'min' => 0, 'max' => 5, 'initial' => 0]],
            'factSlots' => [],
            'sceneTypes' => [$sceneType('job', [$prompt('act', 'What do you do?')]), $sceneType('trouble', [$prompt('face', 'Face it')])],
            'flows' => [['key' => 'hooked', 'name' => 'Hooked', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['doom'], 'phases' => [
                ['key' => 'start', 'name' => 'The start', 'act' => 'Act 1', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['job', 'trouble']],
                    'sessionOpening' => [$prompt('recap', 'Recap at doom {tracker:doom}', ['mandatory' => true])],
                    'sessionClosing' => [$prompt('notes', 'Any notes?')],
                    'phaseOpening' => [$prompt('lure', 'What draws you in?', ['effects' => [['kind' => 'sceneTitle', 'title' => 'Lured by {answer}']]])],
                    'phaseClosing' => [$prompt('wrap', 'How did {step:act} end?')],
                    'worldTurn' => $worldTurn ?? [
                        ['key' => 'doomed', 'kind' => 'condition', 'title' => 'Is doom coming?', 'tracker' => 'doom', 'bands' => [['upTo' => 0, 'next' => 'end'], ['effects' => [['kind' => 'nextScene', 'sceneType' => 'trouble']]]]],
                        ['key' => 'news', 'kind' => 'choice', 'title' => 'Any news?', 'effects' => [['kind' => 'switchSceneType', 'sceneType' => 'job']], 'skip' => 'calm', 'options' => [
                            ['key' => 'calm', 'label' => 'Calm', 'effects' => [['kind' => 'tracker', 'tracker' => 'doom', 'op' => 'add', 'value' => 1]]],
                            ['key' => 'end', 'label' => 'The end', 'effects' => [['kind' => 'endPhase']]],
                        ]],
                    ],
                ],
                ['key' => 'finale', 'name' => 'Finale', 'mode' => 'once', 'selection' => ['rule' => 'sequence', 'sceneTypes' => ['job']]],
            ]]],
            'checks' => [],
        ];
    }
}
