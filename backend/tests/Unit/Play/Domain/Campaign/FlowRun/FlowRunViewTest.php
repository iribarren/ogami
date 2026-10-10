<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunProgress;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\FlowRunView;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\SelectionRule;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the player sees of a FlowRun, on flow examples 2 (Cyberpunk RED heist) and 4 (campaign in
 * acts): where it stands, the step or scene pick it waits on, and the next step named.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunView::class)]
#[CoversClass(Campaign::class)]
final class FlowRunViewTest extends FlowRunTestCase
{
    private GameSystemSnapshot $heist;

    protected function setUp(): void
    {
        $this->heist = self::release('examples/cpr-heist');
    }

    #[Test]
    public function aCampaignPlayedFreelyHasNoView(): void
    {
        $guided = self::guided($this->heist, 'heist');
        $free = Campaign::reconstitute($guided->id(), $guided->ownerId(), $guided->name(), $guided->pinnedRelease(), $guided->createdAt(), []);

        self::assertNull($free->flowRunView($this->heist));
    }

    #[Test]
    public function beforeTheFirstSessionTheScenePickWaitsForASessionAndNamesTheNextOfTheSequence(): void
    {
        $view = $this->view(self::guided($this->heist, 'heist'));

        self::assertSame([FlowRunStatus::Active, true, null, false, 'Next: Crew'], [$view->status, $view->waitsForSession, $view->step, $view->canMoveOn, $view->next]);
        self::assertEquals(new FlowRunProgress(null, 'The job', null, null, null, null), $view->progress);
        self::assertSame([SelectionRule::Sequence, ['crew'], null, false], [$view->pick?->rule, $this->keys($view->pick->cards ?? []), $view->pick?->table, $view->pick?->forced]);
    }

    #[Test]
    public function aStepViewShowsTheStepAndItsPositionAndNamesTheNextStepAcrossParts(): void
    {
        $campaign = self::guided($this->heist, 'heist');
        $campaign->startSession(self::at(), $this->heist);
        $campaign->pickSceneType('crew', $this->heist, self::at());
        $view = $this->view($campaign);

        self::assertSame([false, null, StepKind::Prompt, 'first', 'Who is your first crew member?', true], [$view->waitsForSession, $view->pick, $view->step?->kind, $view->step?->step->key, $view->step?->step->title, $view->step?->step->mandatory]);
        self::assertEquals(new FlowRunProgress(null, 'The job', 'Crew', ScenePart::Setup, 1, 2), $view->progress);
        self::assertSame('Setup: Who else is in?', $view->next);

        $campaign->completeFlowStep('first', self::prompt('Ada'), $this->heist, self::at());
        self::assertSame([2, 'Open play'], [$this->view($campaign)->progress?->stepNumber, $this->view($campaign)->next]);

        // After open play, the sequence's next Scene Type.
        $campaign->skipFlowStep('second', $this->heist, self::at());
        self::assertSame([null, ScenePart::Open, 'Next: Briefing'], [$this->view($campaign)->step, $this->view($campaign)->progress?->part, $this->view($campaign)->next]);
    }

    #[Test]
    public function theNextStepIsNamedAfterTheSceneAtThePhaseEndAndAtTheFlowEnd(): void
    {
        $campaign = self::guided($this->heist, 'heist');
        $campaign->startSession(self::at(), $this->heist);
        $this->playCrew($campaign);
        $campaign->pickSceneType('briefing', $this->heist, self::at());
        foreach (['client', 'target', 'payout', 'plan', 'catch'] as $step) {
            $campaign->skipFlowStep($step, $this->heist, self::at());
        }

        self::assertSame('The job complete → Legwork', $this->view($campaign)->next);

        // A player pick with Move on; the phase's scene closing comes after the Scene Type's parts.
        $campaign->endFlowScene(2, $this->heist, self::at());
        $view = $this->view($campaign);
        self::assertSame(['Next scene: choose a scene type', true, ['social', 'exploration', 'netrun']], [$view->next, $view->canMoveOn, $this->keys($view->pick->cards ?? [])]);
        $campaign->pickSceneType('social', $this->heist, self::at());
        $campaign->skipFlowStep('talk', $this->heist, self::at());
        self::assertSame('Scene closing: Did you gain an edge?', $this->view($campaign)->next);
        $campaign->endFlowScene(3, $this->heist, self::at());
        $view = $this->view($campaign);
        self::assertInstanceOf(ChoiceStep::class, $view->step?->step);
        self::assertSame([StepKind::Choice, ['Yes', 'No'], 'Next scene: choose a scene type'], [$view->step->kind, array_map(static fn (\App\Play\Domain\GameSystem\Flow\ChoiceOption $option): string => $option->label, $view->step->step->options), $view->next]);

        // Once Move on is chosen in a scene, the phase ends after it and is not offered again.
        $campaign->moveOn('legwork', $this->heist, self::at());
        self::assertSame(['Legwork complete → The heist', false], [$this->view($campaign)->next, $this->view($campaign)->canMoveOn]);

        // A scene closing opening with a condition names the step after it.
        $campaign->skipFlowStep('gain-edge', $this->heist, self::at());
        $campaign->pickSceneType('netrun', $this->heist, self::at());
        $campaign->skipFlowStep('notice', $this->heist, self::at());
        $campaign->skipFlowStep('floor', $this->heist, self::at());
        self::assertSame('Scene closing: Spend an edge to avoid trouble?', $this->view($campaign)->next);
        $campaign->moveOn('the-heist', $this->heist, self::at());
        $campaign->endFlowScene(4, $this->heist, self::at());
        $campaign->skipFlowStep('spend-edge', $this->heist, self::at());
        $campaign->skipFlowStep('complication', $this->heist, self::at());

        // Escape picks its only Scene Type itself; the epilogue's Payday opens with a condition.
        self::assertSame(['Getaway', 'slip-out', [1, 1], 'Open play'], [$this->view($campaign)->progress?->sceneType, $this->view($campaign)->step?->step->key, [$this->view($campaign)->progress?->stepNumber, $this->view($campaign)->progress?->stepCount], $this->view($campaign)->next]);
        $campaign->skipFlowStep('slip-out', $this->heist, self::at());
        self::assertSame('Escape complete → Epilogue', $this->view($campaign)->next);
        $campaign->endFlowScene(5, $this->heist, self::at());
        self::assertSame(['no-pay', 'Open play'], [$this->view($campaign)->step?->step->key, $this->view($campaign)->next]);
        $campaign->skipFlowStep('no-pay', $this->heist, self::at());
        self::assertSame('Flow complete', $this->view($campaign)->next);
        $campaign->endFlowScene(6, $this->heist, self::at());

        $view = $this->view($campaign);
        self::assertSame([FlowRunStatus::Completed, null, null, null, false, 'Flow complete'], [$view->status, $view->progress, $view->step, $view->pick, $view->canMoveOn, $view->next]);
    }

    #[Test]
    public function aForcedSceneTypeIsTheOnlyCardAndAPausedFlowRunOffersNoMoveOn(): void
    {
        $campaign = self::guided($this->heist, 'heist');
        $campaign->startSession(self::at(), $this->heist);
        $this->playCrew($campaign);
        self::flowRunOf($campaign)->forceNextSceneType('chase');
        $view = $this->view($campaign);

        self::assertSame([['chase'], true, 'Next scene: Chase'], [$this->keys($view->pick->cards ?? []), $view->pick?->forced, $view->next]);

        $campaign->pauseGuidance(self::at());
        $campaign->endSession(self::at());
        $view = $this->view($campaign);
        self::assertSame([FlowRunStatus::Paused, true, false], [$view->status, $view->waitsForSession, $view->canMoveOn]);
    }

    #[Test]
    public function theProgressNamesTheActAndAPhaseEndNamesTheNextPhase(): void
    {
        $release = self::release('examples/cpr-campaign-in-acts');
        $campaign = self::guided($release, 'campaign');
        $campaign->startSession(self::at(), $release);
        foreach ([['character', ['who', 'want']], ['turf', ['block', 'crew-name']], ['fixer', ['fixer']]] as $scene => [$sceneType, $steps]) {
            $campaign->pickSceneType($sceneType, $release, self::at());
            foreach ($steps as $step) {
                'who' === $step ? $campaign->completeFlowStep($step, self::prompt('Ada'), $release, self::at()) : $campaign->skipFlowStep($step, $release, self::at());
            }

            if ('fixer' === $sceneType) {
                self::assertSame('Street Zero complete → Making a name', $this->view($campaign, $release)->next);
            }

            $campaign->endFlowScene($scene + 1, $release, self::at());
        }

        $campaign->pickSceneType('gig-offer', $release, self::at());
        self::assertEquals(new FlowRunProgress('Act 1: Making a name', 'Making a name', 'Gig offer', ScenePart::Setup, 1, 1), $this->view($campaign, $release)->progress);
    }

    private function view(Campaign $campaign, ?GameSystemSnapshot $release = null): FlowRunView
    {
        return $campaign->flowRunView($release ?? $this->heist) ?? self::fail('The campaign has no FlowRun.');
    }

    private function playCrew(Campaign $campaign): void
    {
        $campaign->pickSceneType('crew', $this->heist, self::at());
        $campaign->completeFlowStep('first', self::prompt('Ada'), $this->heist, self::at());
        $campaign->skipFlowStep('second', $this->heist, self::at());
        $campaign->endFlowScene(1, $this->heist, self::at());
    }

    /**
     * @param list<SceneType> $sceneTypes
     *
     * @return list<string>
     */
    private function keys(array $sceneTypes): array
    {
        return array_map(static fn (SceneType $sceneType): string => $sceneType->key, $sceneTypes);
    }
}
