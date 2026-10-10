<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Scene;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Flow examples 2 (Cyberpunk RED heist) and 3 (Mythic-style session) played through the Campaign,
 * without hooks, branches, bands and effects (play-flow-run slice 15): conditions advance on
 * their own and options follow their "next".
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class FlowRunExamplesTest extends FlowRunTestCase
{
    #[Test]
    public function theHeistPlaysEveryPhaseToCompleted(): void
    {
        $release = self::release('examples/cpr-heist');
        $campaign = self::guided($release, 'heist');
        $flowRun = self::flowRunOf($campaign);

        // The job: a sequence of two Scene Types, picked one after the other.
        $campaign->startSession(self::at(), $release);
        self::assertSame(FlowRunStage::ScenePick, $flowRun->stage());
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

        // Legwork: the player's pick in a loop, until "Move on".
        self::assertSame([1, FlowRunStage::ScenePick], [$flowRun->phaseIndex(), $flowRun->stage()]);
        $campaign->pickSceneType('social', $release, self::at());
        $campaign->completeFlowStep('talk', self::prompt('The bartender, for gossip'), $release, self::at());
        $campaign->endFlowScene(3, $release, self::at());
        self::assertSame(['sceneClosing', 'gain-edge'], self::position($campaign));
        $campaign->completeFlowStep('gain-edge', StepResult::choice('yes'), $release, self::at());
        self::assertSame([1, FlowRunStage::ScenePick], [$flowRun->phaseIndex(), $flowRun->stage()]);
        $campaign->moveOn($release, self::at());

        // The heist: the scene opening's condition advances on its own.
        $campaign->pickSceneType('infiltration', $release, self::at());
        self::assertSame(['sceneOpening', 'notice'], self::position($campaign));
        $campaign->skipFlowStep('notice', $release, self::at());
        $campaign->completeFlowStep('slip-past', self::oracle(YesNoAnswer::ExceptionalNo), $release, self::at());
        $campaign->endFlowScene(4, $release, self::at());
        // A skipped choice follows its skip option: "no" goes to the complication.
        self::assertSame(['sceneClosing', 'spend-edge'], self::position($campaign));
        $campaign->skipFlowStep('spend-edge', $release, self::at());
        self::assertSame(['sceneClosing', 'complication'], self::position($campaign));
        $campaign->completeFlowStep('complication', self::table('complications', 'Patrol'), $release, self::at());
        $campaign->moveOn($release, self::at());

        // Escape and epilogue: one Scene Type each, picked automatically.
        self::assertSame(['Getaway 1', 'setup', 'slip-out'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        $campaign->completeFlowStep('slip-out', self::prompt('Through the sewers'), $release, self::at());
        $campaign->endFlowScene(5, $release, self::at());
        // "no-pay" ends the setup.
        $campaign->completeFlowStep('no-pay', self::prompt('Nothing to show'), $release, self::at());
        self::assertSame(['open', null], self::position($campaign));
        $campaign->endFlowScene(6, $release, self::at());

        self::assertSame(FlowRunStatus::Completed, $flowRun->status());
        self::assertSame(['Crew 1', 'Briefing 1', 'Social 1', 'Infiltration 1', 'Getaway 1', 'Payday 1'], array_map(static fn (Scene $scene): string => $scene->title(), $campaign->sessions()[0]->scenes()));
        self::assertSame([
            'skip:second', 'skip:catch', 'phaseEnded:the-job', 'phaseEnded:legwork', 'skip:notice', 'skip:spend-edge',
            'phaseEnded:the-heist', 'phaseEnded:escape', 'phaseEnded:epilogue', 'completed',
        ], self::history($campaign));
        self::assertSame(['finished', 'moveOn'], [$flowRun->history()[2]->details['reason'], $flowRun->history()[3]->details['reason']]);
        self::assertSame(['first' => 'Ada, a netrunner', 'client' => 'A desperate fixer', 'gain-edge' => 'Yes', 'slip-past' => 'Exceptional no'], array_intersect_key($flowRun->answers(), array_flip(['first', 'client', 'gain-edge', 'slip-past'])));

        $this->expectException(FlowRunNotActive::class);
        $this->expectExceptionMessageIsOrContains('The Flow is complete: play goes on freely.');
        $campaign->moveOn($release, self::at());
    }

    #[Test]
    public function theMythicSessionPicksItsOnlySceneTypeItselfAndLoopsTheAdventure(): void
    {
        $release = self::release('examples/mythic-session');
        $campaign = self::guided($release, 'mythic');
        $flowRun = self::flowRunOf($campaign);
        $campaign->startSession(self::at(), $release);

        self::assertSame(['Scene 1', 'sceneOpening', 'premise'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        $campaign->completeFlowStep('premise', self::prompt('A heist gone wrong'), $release, self::at());
        $campaign->skipFlowStep('goal', $release, self::at());
        $campaign->skipFlowStep('involved', $release, self::at());
        $campaign->endFlowScene(1, $release, self::at());

        self::assertSame(['Scene 2', 'sceneOpening', 'expected'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        $campaign->completeFlowStep('expected', self::prompt('A quiet market'), $release, self::at());
        $campaign->completeFlowStep('check', self::roll('1d10', 7), $release, self::at());
        $campaign->completeFlowStep('twist', self::roll('1d6', 2), $release, self::at());
        $campaign->completeFlowStep('adjust', self::table('scene-adjustments', 'Someone unexpected is there'), $release, self::at());
        // "altered" ends the scene opening.
        $campaign->completeFlowStep('altered', self::prompt('The market is closed'), $release, self::at());
        self::assertSame(['open', null], self::position($campaign));
        $campaign->endFlowScene(2, $release, self::at());
        self::assertSame(['sceneClosing', 'control'], self::position($campaign));
        $campaign->completeFlowStep('control', StepResult::choice('no'), $release, self::at());
        $campaign->skipFlowStep('lists', $release, self::at());

        self::assertSame(['Scene 3', 'sceneOpening', 'expected'], [$campaign->currentScene()?->title(), ...self::position($campaign)]);
        self::assertSame([1, 2], [$flowRun->phaseIndex(), $flowRun->scenesPlayed()]);
        self::assertSame(['skip:goal', 'skip:involved', 'phaseEnded:premise', 'skip:lists'], self::history($campaign));
        self::assertSame(['7', 'No'], [$flowRun->answers()['check'], $flowRun->answers()['control']]);
    }
}
