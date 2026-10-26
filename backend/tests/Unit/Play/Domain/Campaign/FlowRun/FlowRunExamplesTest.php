<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunEvent;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tracker;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Flow examples 2 (Cyberpunk RED heist) and 3 (Mythic-style session) played through the Campaign
 * to completed, with Outcomes, Effects, placeholders and hooks (neither has session or phase
 * hooks; the heist has world turns).
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class FlowRunExamplesTest extends FlowRunTestCase
{
    /**
     * FlowRun plays no pick step until the Threads and NPCs flow support (slice 6): Chase's
     * "wheel" is the prompt it was before the pick.
     */
    private function heistWithoutPick(): GameSystemSnapshot
    {
        $content = ReleaseViews::fixtureContent('examples/cpr-heist');
        /** @var list<array{key: string}> $sceneTypes */
        $sceneTypes = $content['sceneTypes'];
        $chase = array_search('chase', array_column($sceneTypes, 'key'), true);
        $wheel = ['key' => 'wheel', 'kind' => 'prompt', 'title' => 'Who takes the wheel?', 'tip' => 'A nomad in the crew is a good choice.'];
        /** @var array<string, mixed> $typed */
        $typed = ReleaseArrays::with($content, \sprintf('sceneTypes.%d.setup', $chase), [$wheel]);

        return self::translate($typed);
    }

    #[Test]
    public function theHeistPlaysEveryPhaseToCompleted(): void
    {
        $release = $this->heistWithoutPick();
        $campaign = self::guided($release, 'heist');

        // The job: a sequence of two Scene Types, picked one after the other.
        $campaign->startSession(self::at(), $release);
        self::assertSame(FlowRunStage::ScenePick, self::flowRunOf($campaign)->stage());
        $campaign->pickSceneType('crew', $release, self::at());
        self::assertSame(['Crew 1', 'setup', 'first'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        try {
            $campaign->skipFlowStep('first', $release, self::at());
            self::fail('A mandatory step was skipped.');
        } catch (StepCannotBeSkipped $exception) {
            self::assertSame('Step "first" is mandatory: complete it.', $exception->getMessage());
        }

        $campaign->completeFlowStep('first', self::prompt(' Ada, a netrunner '), $release, self::at());
        $campaign->skipFlowStep('second', $release, self::at());
        self::assertSame(['open', null], self::position($campaign));
        $campaign->endFlowScene(1, $release, self::at());

        $campaign->pickSceneType('briefing', $release, self::at());
        $campaign->completeFlowStep('client', self::table('job-clients', 'A desperate fixer'), $release, self::at());
        $campaign->completeFlowStep('target', self::table('job-targets', 'A corp lab'), $release, self::at());
        $campaign->completeFlowStep('payout', self::table('payouts', '5,000 eb'), $release, self::at());
        $campaign->completeFlowStep('plan', self::prompt('In through the roof'), $release, self::at());
        $campaign->skipFlowStep('catch', $release, self::at());
        $campaign->endFlowScene(2, $release, self::at());

        // Legwork: the player's pick in a loop, until "Move on"; gaining an edge adds to it.
        self::assertSame([1, FlowRunStage::ScenePick], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage()]);
        $campaign->pickSceneType('social', $release, self::at());
        $campaign->completeFlowStep('talk', self::prompt('The bartender, for gossip'), $release, self::at());
        $campaign->endFlowScene(3, $release, self::at());
        self::assertSame(['sceneClosing', 'gain-edge'], self::position($campaign));
        $campaign->completeFlowStep('gain-edge', StepResult::choice('yes'), $release, self::at());
        // The world turn runs in its hook Scene; word gets around on a 1. Moving on during it ends
        // the phase after it.
        self::assertSame([[1, FlowRunStage::Hook, 'word'], 1], [[self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->stepKey()], $this->value($campaign, $release, 'edge')]);
        self::assertSame([SceneKind::Hook, Hook::WorldTurn, 'The world moves'], [$campaign->currentScene()?->kind(), $campaign->currentScene()?->hook(), $campaign->currentScene()?->title()]);
        $campaign->moveOn('legwork', $release, self::at());
        $campaign->completeFlowStep('word', self::roll('1d6', 1), $release, self::at());
        self::assertSame([2, FlowRunStage::ScenePick, 1], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->stage(), $this->value($campaign, $release, 'alarm')]);

        // The heist: on a low alarm the scene opening's condition ends it. An exceptional no
        // switches the infiltration into a firefight, which starts at its setup.
        $campaign->pickSceneType('infiltration', $release, self::at());
        self::assertSame(['play', 'slip-past'], self::position($campaign));
        $campaign->completeFlowStep('slip-past', self::oracle(YesNoAnswer::ExceptionalNo), $release, self::at());
        self::assertSame(['setup', 'who-shoots', 'firefight'], [...self::position($campaign), $campaign->currentScene()?->sceneType()]);
        $campaign->completeFlowStep('who-shoots', self::prompt('Ada'), $release, self::at());
        self::assertSame('Firefight: Ada', $campaign->currentScene()?->title());
        $campaign->skipFlowStep('response', $release, self::at());
        $campaign->endFlowScene(5, $release, self::at());
        // With an edge left, spending it skips the complication and the lockdown passes; on a low
        // alarm the world turn's condition passes it without a hook Scene.
        self::assertSame(['sceneClosing', 'spend-edge'], self::position($campaign));
        $campaign->completeFlowStep('spend-edge', StepResult::choice('yes'), $release, self::at());
        self::assertSame([FlowRunStage::ScenePick, 0, 5], [self::flowRunOf($campaign)->stage(), $this->value($campaign, $release, 'edge'), \count($campaign->sessions()[0]->scenes())]);

        // On a raised alarm security is on edge; getting the objective ends the phase, and without
        // an edge the complication's entry raises the alarm.
        $campaign->setTrackerValue($this->trackerOf($release, 'alarm'), 4, self::at());
        $campaign->pickSceneType('grab', $release, self::at());
        self::assertSame(['sceneOpening', 'notice', 'The alarm is at 4. What do you notice?'], [...self::position($campaign), $campaign->flowRunView($release)?->step?->prompt]);
        $campaign->skipFlowStep('notice', $release, self::at());
        $campaign->skipFlowStep('reach', $release, self::at());
        $campaign->endFlowScene(6, $release, self::at());
        $campaign->completeFlowStep('got-it', StepResult::choice('yes'), $release, self::at());
        self::assertSame(['sceneClosing', 'complication', 'endPhase'], [...self::position($campaign), self::flowRunOf($campaign)->phaseEndingReason()]);
        $campaign->completeFlowStep('complication', self::table('complications', 'A patrol walks by'), $release, self::at());

        // Escape: on a high alarm the getaway turns into a chase.
        self::assertSame(['Getaway 1', 'chase', 'setup', 'wheel', 5, 1], [$campaign->currentScene()?->title(), $campaign->currentScene()?->sceneType(), ...self::position($campaign), $this->value($campaign, $release, 'alarm'), $this->value($campaign, $release, 'objective')]);
        $campaign->completeFlowStep('wheel', self::prompt('Ada'), $release, self::at());
        $campaign->skipFlowStep('lose-them', $release, self::at());
        $campaign->endFlowScene(7, $release, self::at());

        // Epilogue: with the objective in hand the fixer pays; a roll of 1 is a double-cross. {fact:payout} renders
        // the fact an earlier step filled.
        self::assertSame(['setup', 'paid', 'The fixer pays 5,000 eb. Was In through the roof worth it?'], [...self::position($campaign), $campaign->flowRunView($release)?->step?->prompt]);
        $campaign->completeFlowStep('paid', self::prompt('Barely'), $release, self::at());
        $campaign->completeFlowStep('double-cross', self::roll('1d6', 1), $release, self::at());
        self::assertSame(['setup', 'betrayed'], self::position($campaign));
        $campaign->completeFlowStep('betrayed', self::prompt('The fixer'), $release, self::at());
        $campaign->endFlowScene(8, $release, self::at());

        self::assertSame(FlowRunStatus::Completed, self::flowRunOf($campaign)->status());
        self::assertSame(['Crew 1', 'Briefing 1', 'Social 1', 'The world moves', 'Firefight: Ada', 'Grab 1', 'Getaway 1', 'Payday 1'], array_map(static fn (Scene $scene): string => $scene->title(), $campaign->sessions()[0]->scenes()));
        self::assertSame([
            'skip:second', 'skip:catch', 'phaseEnded:the-job', 'phaseEnded:legwork', 'sceneTypeSwitch:5', 'skip:response', 'trackerEdit:alarm',
            'skip:notice', 'skip:reach', 'phaseEnded:the-heist', 'sceneTypeSwitch:7', 'skip:lose-them', 'phaseEnded:escape', 'phaseEnded:epilogue', 'completed',
        ], self::history($campaign));
        $phaseEnds = array_filter(self::flowRunOf($campaign)->history(), static fn (FlowRunHistoryEntry $entry): bool => FlowRunEvent::PhaseEnded === $entry->event);
        self::assertSame(['finished', 'moveOn', 'endPhase', 'finished', 'finished'], array_values(array_map(static fn (FlowRunHistoryEntry $entry): int|string|null => $entry->details['reason'], $phaseEnds)));
        self::assertSame(['first' => 'Ada, a netrunner', 'client' => 'A desperate fixer', 'gain-edge' => 'Yes', 'slip-past' => 'Exceptional no'], array_intersect_key(self::flowRunOf($campaign)->answers(), array_flip(['first', 'client', 'gain-edge', 'slip-past'])));

        $this->expectException(FlowRunNotActive::class);
        $this->expectExceptionMessageIsOrContains('The Flow is complete: play goes on freely.');
        $campaign->moveOn('epilogue', $release, self::at());
    }

    #[Test]
    public function theMythicSessionPicksItsOnlySceneTypeWhenThePhaseOpensAndLoopsTheAdventure(): void
    {
        $release = self::release('examples/mythic-session');
        $campaign = self::guided($release, 'mythic');
        $campaign->startSession(self::at(), $release);

        self::assertSame(['Scene 1', 'sceneOpening', 'premise'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        $campaign->completeFlowStep('premise', self::prompt('A heist gone wrong'), $release, self::at());
        $campaign->skipFlowStep('goal', $release, self::at());
        $campaign->skipFlowStep('involved', $release, self::at());
        $campaign->endFlowScene(1, $release, self::at());

        // The expected scene names it; a check under the chaos factor alters or interrupts it.
        self::assertSame(['Scene 2', 'sceneOpening', 'expected'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        $campaign->completeFlowStep('expected', self::prompt('A quiet market'), $release, self::at());
        self::assertSame(['A quiet market', 'Roll under the chaos factor (5) and the scene changes.'], [$campaign->currentScene()?->title(), $campaign->flowRunView($release)?->step?->tip]);
        $campaign->completeFlowStep('check', self::roll('1d10', 5), $release, self::at());
        $campaign->completeFlowStep('twist', self::roll('1d6', 4), $release, self::at());
        self::assertSame(['sceneOpening', 'interrupt'], self::position($campaign));
        $campaign->completeFlowStep('interrupt', self::table('random-event-focus', 'Something happens far away', 7), $release, self::at());
        self::assertSame(['sceneOpening', 'action'], self::position($campaign));
        $campaign->skipFlowStep('action', $release, self::at());
        $campaign->skipFlowStep('subject', $release, self::at());
        $campaign->completeFlowStep('instead', self::prompt('A rival bursts in'), $release, self::at());
        self::assertSame(['open', null, 'A rival bursts in'], [...self::position($campaign), $campaign->currentScene()?->title()]);
        $campaign->endFlowScene(2, $release, self::at());
        self::assertSame(['sceneClosing', 'control'], self::position($campaign));
        $campaign->completeFlowStep('control', StepResult::choice('no'), $release, self::at());
        $campaign->skipFlowStep('lists', $release, self::at());

        // After a scene the pick waits (ADR 0020): the player plays the next scene or ends the session.
        self::assertSame([FlowRunStage::ScenePick, true], [self::flowRunOf($campaign)->stage(), $campaign->flowRunView($release)?->canEndSession]);
        $campaign->pickSceneType('scene', $release, self::at());

        // Losing control raised the chaos factor: a check over it plays the scene as expected.
        self::assertSame(['Scene 3', 'sceneOpening', 'expected'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        $campaign->completeFlowStep('expected', self::prompt('Rooftops'), $release, self::at());
        self::assertSame('Roll under the chaos factor (6) and the scene changes.', $campaign->flowRunView($release)?->step?->tip);
        $campaign->completeFlowStep('check', self::roll('1d10', 7), $release, self::at());
        self::assertSame(['open', null, 'Rooftops'], [...self::position($campaign), $campaign->currentScene()?->title()]);
        self::assertSame([1, 2, 6], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->scenesPlayed(), $this->value($campaign, $release, 'chaos')]);
        self::assertSame(['skip:goal', 'skip:involved', 'phaseEnded:premise', 'skip:action', 'skip:subject', 'skip:lists'], self::history($campaign));
        self::assertSame(['7', 'No'], [self::flowRunOf($campaign)->answers()['check'], self::flowRunOf($campaign)->answers()['control']]);

        // Moving on during the scene ends it too, then the adventure and the Flow, after its scene closing.
        $campaign->moveOn('adventure', $release, self::at());
        self::assertSame(['sceneClosing', 'control'], self::position($campaign));
        $campaign->completeFlowStep('control', StepResult::choice('yes'), $release, self::at());
        $campaign->skipFlowStep('lists', $release, self::at());
        self::assertSame([FlowRunStatus::Completed, 5, 3], [self::flowRunOf($campaign)->status(), $this->value($campaign, $release, 'chaos'), \count($campaign->sessions()[0]->scenes())]);
        self::assertSame(['skip:lists', 'phaseEnded:adventure', 'completed'], \array_slice(self::history($campaign), -3));
    }

    private function value(Campaign $campaign, GameSystemSnapshot $release, string $tracker): int
    {
        return $campaign->trackerValue($this->trackerOf($release, $tracker));
    }

    private function trackerOf(GameSystemSnapshot $release, string $key): Tracker
    {
        return $release->tracker($key) ?? self::fail('No Tracker '.$key);
    }
}
