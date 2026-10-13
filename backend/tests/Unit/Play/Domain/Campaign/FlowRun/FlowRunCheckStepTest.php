<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\CheckView;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The check step while a FlowRun plays (ADR 0024): the Character must be in the current Scene (in the
 * session party outside one), the Outcome of the band rolled branches by band key, a band without
 * one continues, and a difficulty the step fixes is the step's alone. The "skill" check adds 8 for
 * Ada (hands 4): fail up to a total of 12, success up to 17, crit above, at the default difficulty 13.
 * Ada is in the scene "job" of the session; Bo is in the party only, Vi in neither.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(StepResult::class)]
#[CoversClass(CheckStep::class)]
final class FlowRunCheckStepTest extends FlowRunTestCase
{
    #[Test]
    public function aBandWithAnOutcomeBranchesToItsNextStepAndTheAnswerIsTheBandLabel(): void
    {
        $step = CheckStepReleases::step(outcomes: ['fail' => new Outcome('fumble')]);
        $release = CheckStepReleases::release($step);
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, 3), $release, self::at());

        self::assertSame([['setup', 'fumble'], ['sneak' => 'Fail']], [self::position($campaign), self::flowRunOf($campaign)->answers()]);
    }

    #[Test]
    public function aBandWithoutAnOutcomeContinuesWithTheFollowingStep(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(outcomes: ['fail' => new Outcome('fumble')]));
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, 6), $release, self::at());

        self::assertSame([['setup', 'pass'], ['sneak' => 'Success']], [self::position($campaign), self::flowRunOf($campaign)->answers()]);
    }

    #[Test]
    public function aBandWithoutAnOutcomeContinuesWithTheStepsOwnTarget(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(outcomes: ['crit' => new Outcome('pass')], next: 'fumble'));
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, 6), $release, self::at());

        self::assertSame(['setup', 'fumble'], self::position($campaign));
    }

    #[Test]
    public function theStepsEffectsApplyThenTheOutcomesOfTheBand(): void
    {
        $heat = static fn (int $by): TrackerEffect => new TrackerEffect('heat', TrackerOperation::Add, $by);
        $release = CheckStepReleases::release(CheckStepReleases::step(outcomes: ['crit' => new Outcome(null, [$heat(5)])], effects: [$heat(1)]));
        $crit = $this->inScene($release);
        $success = $this->inScene($release);

        $crit->completeFlowStep('sneak', $this->rolled($release, 10), $release, self::at());
        $success->completeFlowStep('sneak', $this->rolled($release, 6), $release, self::at());

        self::assertSame([16, 11], [$crit->trackerValuesOf($release)['heat'], $success->trackerValuesOf($release)['heat']]);
    }

    #[Test]
    public function theStepIsACheckStepWithTheCheckKindAndItsResultCarriesTheCharacter(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step());
        $campaign = $this->inScene($release);

        $result = $this->rolled($release, 6);

        self::assertSame([StepKind::Check, 'ada', 'Success'], [$result->kind, $result->characterId, $result->answer]);
        self::assertSame(StepKind::Check, $campaign->flowRunView($release)?->step?->kind);
    }

    #[Test]
    #[DataProvider('viewedDifficulties')]
    public function theViewShowsTheDifficultyTheStepFixesAndTheCharactersInTheScene(int|Formula|null $fixed, ?int $expected): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(difficulty: $fixed));
        $campaign = $this->inScene($release);

        $check = $campaign->flowRunView($release)?->step?->check;

        self::assertEquals(new CheckView($expected, [new PickedItem('ada', 'Ada')]), $check);
    }

    /**
     * @return iterable<string, array{int|Formula|null, ?int}>
     */
    public static function viewedDifficulties(): iterable
    {
        yield 'none fixed' => [null, null];
        yield 'a literal' => [15, 15];
        yield 'a formula over Trackers' => [Formula::fromString('{tracker:heat} + 5'), 15];
        yield 'a formula that cannot be evaluated' => [Formula::fromString('{tracker:heat} / 0'), null];
    }

    #[Test]
    public function inAHookTheViewShowsTheSessionsParty(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(), true);
        $campaign = $this->atTheWorldTurn($release);

        self::assertEquals([new PickedItem('bo', 'Bo')], $campaign->flowRunView($release)?->step?->check?->characters);
    }

    #[Test]
    #[DataProvider('charactersOutOfTheScene')]
    public function aCharacterOutOfTheSceneCannotRollAndNothingChanges(string $id, string $message): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step());
        $campaign = $this->inScene($release);
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('sneak', $this->rolled($release, 6, $id), $release, self::at());
            self::fail('The step was completed.');
        } catch (InvalidStepResult $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function charactersOutOfTheScene(): iterable
    {
        $message = 'Check step "sneak" rolls for a Character in the scene (in the session party outside one): Character "%s" is not.';

        yield 'one in the party only' => ['bo', \sprintf($message, 'bo')];
        yield 'one in neither' => ['vi', \sprintf($message, 'vi')];
        yield 'an unknown one' => ['ghost', 'Character step "sneak" has no Character "ghost" in the campaign.'];
    }

    #[Test]
    public function inAHookTheCharactersOfThePartyCanRoll(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(), true);
        $campaign = $this->atTheWorldTurn($release);

        try {
            $campaign->completeFlowStep('sneak', $this->rolled($release, 6, 'vi'), $release, self::at());
            self::fail('Vi is not in the party.');
        } catch (InvalidStepResult) {
        }

        $campaign->completeFlowStep('sneak', $this->rolled($release, 6, 'bo'), $release, self::at());

        self::assertSame('Success', self::flowRunOf($campaign)->answers()['sneak']);
    }

    #[Test]
    #[DataProvider('fixedDifficulties')]
    public function aStepThatFixesTheDifficultyRollsAtItAndTakesNoOther(int|Formula $fixed, int $expected): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(difficulty: $fixed));
        $campaign = $this->inScene($release);

        self::assertSame($expected, $campaign->flowCheckDifficulty('sneak', 'ada', null, $release, self::at()));
        try {
            $campaign->flowCheckDifficulty('sneak', 'ada', 12, $release, self::at());
            self::fail('A difficulty was taken.');
        } catch (InvalidStepResult $e) {
            self::assertSame(\sprintf('Step "sneak" fixes the check difficulty at %d: it takes no other.', $expected), $e->getMessage());
        }

        $campaign->completeFlowStep('sneak', $this->rolled($release, 6, 'ada', $expected), $release, self::at());

        self::assertSame(['setup', 'pass'], self::position($campaign));
    }

    /**
     * @return iterable<string, array{int|Formula, int}>
     */
    public static function fixedDifficulties(): iterable
    {
        yield 'a literal' => [15, 15];
        yield 'a Tracker' => [Formula::fromString('{tracker:heat}'), 10];
        yield 'a formula over Trackers' => [Formula::fromString('{tracker:heat} + 5'), 15];
    }

    #[Test]
    public function aResultRolledAtAnotherDifficultyThanTheStepFixesIsRefused(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(difficulty: 15));
        $campaign = $this->inScene($release);

        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains('Step "sneak" fixes the check difficulty at 15: it takes no other.');

        $campaign->completeFlowStep('sneak', $this->rolled($release, 6, 'ada', 12), $release, self::at());
    }

    #[Test]
    public function aDifficultyFormulaThatCannotBeEvaluatedFailsBeforeAnyRoll(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(difficulty: Formula::fromString('{tracker:heat} / 0')));
        $campaign = $this->inScene($release);

        $this->expectException(FlowFormulaFailed::class);

        $campaign->flowCheckDifficulty('sneak', 'ada', null, $release, self::at());
    }

    #[Test]
    public function withoutAFixedDifficultyTheGivenOneIsTheOneToRollAt(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step());
        $campaign = $this->inScene($release);

        self::assertSame([null, 20], [$campaign->flowCheckDifficulty('sneak', 'ada', null, $release, self::at()), $campaign->flowCheckDifficulty('sneak', 'ada', 20, $release, self::at())]);
    }

    #[Test]
    public function theDifficultyIsCheckedOnTheCurrentCheckStepOnly(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step());
        $campaign = $this->inScene($release);

        try {
            $campaign->flowCheckDifficulty('pass', 'ada', null, $release, self::at());
            self::fail('Another step was accepted.');
        } catch (FlowRunPositionMismatch) {
        }

        $campaign->completeFlowStep('sneak', $this->rolled($release, 6), $release, self::at());

        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains('Step "pass" is a prompt step: a check result does not complete it.');

        $campaign->flowCheckDifficulty('pass', 'ada', null, $release, self::at());
    }

    #[Test]
    public function aResultOfAnotherKindDoesNotCompleteTheStep(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step());
        $campaign = $this->inScene($release);

        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains('Step "sneak" is a check step: a prompt result does not complete it.');

        $campaign->completeFlowStep('sneak', self::prompt('ok'), $release, self::at());
    }

    private function rolled(GameSystemSnapshot $release, int $die, string $character = 'ada', ?int $difficulty = null): StepResult
    {
        $check = $release->check('skill') ?? self::fail('No skill check.');
        $result = $check->perform(new ScriptedRandomNumberGenerator($die), SheetValues::of($release->sheet(), [], $release->trackers() ? ['heat' => 10] : []), $difficulty, 0);

        return StepResult::check($character, $result);
    }

    private function campaign(GameSystemSnapshot $release): Campaign
    {
        $campaign = self::guided($release, 'play');
        foreach ([['ada', 'Ada'], ['bo', 'Bo'], ['vi', 'Vi']] as [$id, $name]) {
            $campaign->addCharacter(CharacterId::fromString($id), $name, [], [], [], $release);
        }

        // The only Scene Type of the selection is picked at once, with an empty party.
        $campaign->startSession(self::at(), $release);
        $campaign->editCurrentSessionParty([CharacterId::fromString('bo')]);

        return $campaign;
    }

    /**
     * Ada in the scene, Bo in the party only.
     */
    private function inScene(GameSystemSnapshot $release): Campaign
    {
        $campaign = $this->campaign($release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ada')]);

        return $campaign;
    }

    /**
     * Bo in the party, the world turn asking the check.
     */
    private function atTheWorldTurn(GameSystemSnapshot $release): Campaign
    {
        $campaign = $this->campaign($release);
        $campaign->completeFlowStep('wrap', self::prompt('ok'), $release, self::at());
        $campaign->endFlowScene(1, $release, self::at());

        return $campaign;
    }
}
