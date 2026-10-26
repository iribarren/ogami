<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\CheckStepPending;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\NoPendingCheck;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
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
 * A pushable roll of a check step pends until the player keeps or pushes it (ADR 0041). The step
 * "sneak" rolls the pool check "grit" (3d6, successes on 5+: miss 0, weak 1, strong 2 and up); it
 * adds 1 to the Tracker "heat" (10), and its "weak" band adds 5 more and goes on at "fumble". Ada is
 * in the scene "job".
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(PendingCheck::class)]
#[CoversClass(Campaign::class)]
final class FlowRunPendingCheckTest extends FlowRunTestCase
{
    private const string ENTRY = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001';

    #[Test]
    public function aPushableRollPendsWithoutAnAnswerEffectsOrAMove(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->inScene($release);

        $changes = $campaign->completeFlowStep('sneak', $this->rolled($release, 'grit', 1, 5, 2), $release, self::at(), entryId: self::ENTRY);

        $pending = new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak');
        self::assertSame([], $changes);
        self::assertSame([['setup', 'sneak'], [], 10], [self::position($campaign), self::flowRunOf($campaign)->answers(), $this->heat($campaign, $release)]);
        self::assertEquals($pending, self::flowRunOf($campaign)->pendingCheck());
        self::assertEquals($pending, $campaign->flowRunView($release)?->step?->pending);
        self::assertSame([], $campaign->takeFlowNotes());
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('rollsThatCannotBePushed')]
    public function aRollThatCannotBePushedCompletesTheStepAtOnce(string $check, array $faces, string $answer): void
    {
        $release = $this->gritRelease($check);
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, $check, ...$faces), $release, self::at(), entryId: self::ENTRY);

        self::assertNull(self::flowRunOf($campaign)->pendingCheck());
        self::assertSame(['sneak' => $answer], self::flowRunOf($campaign)->answers());
        self::assertNull($campaign->flowRunView($release)?->step?->pending);
    }

    /**
     * @return iterable<string, array{string, list<int>, string}>
     */
    public static function rollsThatCannotBePushed(): iterable
    {
        yield 'every die succeeded' => ['grit', [5, 6, 5], 'Strong'];
        yield 'a pool check without a push' => ['steady', [1, 5, 2], 'Weak'];
        yield 'a dice check' => ['flat', [2], 'Low'];
    }

    #[Test]
    public function keepCompletesTheStepOnTheRolledBand(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->pending($release);

        $campaign->resolvePendingFlowCheck('sneak', null, $release, self::at());

        self::assertSame([['setup', 'fumble'], ['sneak' => 'Weak'], 16], [self::position($campaign), self::flowRunOf($campaign)->answers(), $this->heat($campaign, $release)]);
        self::assertNull(self::flowRunOf($campaign)->pendingCheck());
        self::assertNull($campaign->flowRunView($release)?->step?->pending);
    }

    #[Test]
    public function aPushCompletesTheStepOnThePushedBand(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->pending($release);

        $campaign->resolvePendingFlowCheck('sneak', 'strong', $release, self::at());

        self::assertSame([['setup', 'pass'], ['sneak' => 'Strong'], 11], [self::position($campaign), self::flowRunOf($campaign)->answers(), $this->heat($campaign, $release)]);
        self::assertNull(self::flowRunOf($campaign)->pendingCheck());
    }

    #[Test]
    public function theStepWaitingAndThePendingRollAreTheCampaignsOwn(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->pending($release);

        self::assertEquals(new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak'), $campaign->pendingFlowCheck('sneak', $release, self::at()));

        $this->expectException(FlowRunPositionMismatch::class);
        $this->expectExceptionMessageIsOrContains('The FlowRun is at step "sneak", not at step "pass".');

        $campaign->pendingFlowCheck('pass', $release, self::at());
    }

    #[Test]
    public function keepOrPushOfAnotherStepIsRefusedAndNothingChanges(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->pending($release);
        $before = $this->deepCopy($campaign);

        try {
            $campaign->resolvePendingFlowCheck('pass', null, $release, self::at());
            self::fail('Another step was resolved.');
        } catch (FlowRunPositionMismatch $mismatch) {
            self::assertSame('The FlowRun is at step "sneak", not at step "pass".', $mismatch->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function pushOfAnotherStepIsRefusedAndNothingChanges(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->pending($release);
        $before = $this->deepCopy($campaign);

        try {
            $campaign->resolvePendingFlowCheck('pass', 'strong', $release, self::at());
            self::fail('Another step was resolved.');
        } catch (FlowRunPositionMismatch $mismatch) {
            self::assertSame('The FlowRun is at step "sneak", not at step "pass".', $mismatch->getMessage());
        }

        self::assertEquals($before, $campaign);
        self::assertEquals(new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak'), self::flowRunOf($campaign)->pendingCheck());
    }

    /**
     * Keep resolves on the rolled band "weak", Push on the pushed one "strong": the band Effect of
     * the band taken fails to evaluate.
     */
    #[Test]
    #[DataProvider('bandsWhoseEffectFails')]
    public function aBandEffectThatFailsLeavesThePendingRollAndTheCampaignUnchanged(?string $pushedBandKey): void
    {
        $failing = new TrackerEffect('heat', TrackerOperation::Add, Formula::fromString('{tracker:heat} / 0'));
        $release = CheckStepReleases::release(CheckStepReleases::step('grit', outcomes: ['weak' => new Outcome('fumble', [$failing]), 'strong' => new Outcome('pass', [$failing])]));
        $campaign = $this->pending($release);
        $before = $this->deepCopy($campaign);

        try {
            $campaign->resolvePendingFlowCheck('sneak', $pushedBandKey, $release, self::at());
            self::fail('The band Effect did not fail.');
        } catch (FlowFormulaFailed) {
            self::addToAssertionCount(1);
        }

        self::assertEquals($before, $campaign);
        self::assertEquals(new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak'), self::flowRunOf($campaign)->pendingCheck());
        self::assertSame([['setup', 'sneak'], [], 10], [self::position($campaign), self::flowRunOf($campaign)->answers(), $this->heat($campaign, $release)]);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function bandsWhoseEffectFails(): iterable
    {
        yield 'keep' => [null];
        yield 'push' => ['strong'];
    }

    #[Test]
    public function keepOrPushWithoutAPendingRollIsRefused(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->inScene($release);
        $before = $this->deepCopy($campaign);

        foreach ([static fn (): array => $campaign->resolvePendingFlowCheck('sneak', null, $release, self::at()), static fn (): PendingCheck => $campaign->pendingFlowCheck('sneak', $release, self::at())] as $command) {
            try {
                $command();
                self::fail('No roll pends.');
            } catch (NoPendingCheck $none) {
                self::assertSame('No roll of a check step waits for Keep or Push.', $none->getMessage());
            }
        }

        self::assertEquals($before, $campaign);
    }

    /**
     * @param \Closure(Campaign, GameSystemSnapshot): mixed $command
     */
    #[Test]
    #[DataProvider('commandsWhileARollPends')]
    public function nothingElseMovesTheFlowRunWhileARollPends(\Closure $command): void
    {
        $release = $this->gritRelease();
        $campaign = $this->pending($release);
        $before = $this->deepCopy($campaign);

        try {
            $command($campaign, $release);
            self::fail('The FlowRun moved while a roll pends.');
        } catch (CheckStepPending $pending) {
            self::assertSame('Keep or push the pending roll first.', $pending->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    /**
     * @return iterable<string, array{\Closure(Campaign, GameSystemSnapshot): mixed}>
     */
    public static function commandsWhileARollPends(): iterable
    {
        yield 'an answer to the same step' => [static fn (Campaign $campaign, GameSystemSnapshot $release): array => $campaign->completeFlowStep('sneak', StepResult::check('ada', ($release->check('grit') ?? self::fail('No grit.'))->perform(new ScriptedRandomNumberGenerator(6, 6, 6), SheetValues::of($release->sheet(), [], []), null, 0)), $release, self::at(), entryId: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8002')];
        yield 'the check difficulty before a roll' => [static fn (Campaign $campaign, GameSystemSnapshot $release): ?int => $campaign->flowCheckDifficulty('sneak', 'ada', null, $release, self::at())];
        yield 'a skip' => [static fn (Campaign $campaign, GameSystemSnapshot $release) => $campaign->skipFlowStep('sneak', $release, self::at())];
        yield 'end scene' => [static fn (Campaign $campaign, GameSystemSnapshot $release) => $campaign->endFlowScene(1, $release, self::at())];
        yield 'a scene pick' => [static fn (Campaign $campaign, GameSystemSnapshot $release) => $campaign->pickSceneType('job', $release, self::at())];
        yield 'move on' => [static fn (Campaign $campaign, GameSystemSnapshot $release) => $campaign->moveOn('roam', $release, self::at())];
        yield 'pause' => [static fn (Campaign $campaign) => $campaign->pauseGuidance(self::at())];
        yield 'resume' => [static fn (Campaign $campaign, GameSystemSnapshot $release) => $campaign->resumeGuidance($release, self::at())];
        yield 'end session' => [static fn (Campaign $campaign, GameSystemSnapshot $release): \App\Play\Domain\Campaign\Session => $campaign->endSession(self::at(), null, $release)];
        yield 'a Scene Type switch' => [static fn (Campaign $campaign, GameSystemSnapshot $release): \App\Play\Domain\Campaign\Scene => $campaign->switchSceneType($release->sceneType('job') ?? self::fail('No job.'), $release, self::at())];
    }

    #[Test]
    public function trackersTheCastAndCharactersCanChangeWhileARollPends(): void
    {
        $release = $this->gritRelease();
        $campaign = $this->pending($release);

        $campaign->setTrackerValue($release->tracker('heat') ?? self::fail('No heat.'), 12, self::at());
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ada'), CharacterId::fromString('bo')]);
        $campaign->editCharacter(CharacterId::fromString('ada'), 'Ada Lovelace', [], [], [], $release);

        self::assertEquals(new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak'), self::flowRunOf($campaign)->pendingCheck());
        self::assertSame(12, $this->heat($campaign, $release));
    }

    #[Test]
    public function anUpgradeDropsThePendingRollWithTheGuidance(): void
    {
        $release = $this->gritRelease();
        $target = CheckStepReleases::release($this->step('grit'), version: 2);
        $campaign = $this->pending($release);

        $campaign->upgradeRelease($release, $target, $campaign->planReleaseUpgrade($release, $target)->fingerprint(), self::at());

        self::assertNull(self::flowRunOf($campaign)->pendingCheck());
        self::assertSame(FlowRunStatus::Active, self::flowRunOf($campaign)->status());
        self::assertSame(2, $campaign->pinnedRelease()->releaseVersion());
    }

    #[Test]
    public function aRebuiltFlowRunHoldsAPendingCheckOnlyAtItsStepWhileActive(): void
    {
        $pending = new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak');
        $rebuild = static fn (FlowRunStatus $status, string $stepKey): FlowRun => FlowRun::reconstitute($status, 0, FlowRunStage::Scene, 1, 1, 'job', ScenePart::Setup, $stepKey, 0, 0, [], null, null, 0, [], pendingCheck: $pending);

        self::assertEquals($pending, $rebuild(FlowRunStatus::Active, 'sneak')->pendingCheck());

        foreach ([[FlowRunStatus::Active, 'pass'], [FlowRunStatus::Paused, 'sneak']] as [$status, $stepKey]) {
            try {
                $rebuild($status, $stepKey);
                self::fail('A pending check was rebuilt away from its step.');
            } catch (\InvalidArgumentException $invalid) {
                self::assertSame('A pending check of step "sneak" belongs to the current step of an active FlowRun.', $invalid->getMessage());
            }
        }
    }

    private function step(string $check): CheckStep
    {
        $heat = static fn (int $by): TrackerEffect => new TrackerEffect('heat', TrackerOperation::Add, $by);

        return CheckStepReleases::step($check, outcomes: ['weak' => new Outcome('fumble', [$heat(5)])], effects: [$heat(1)]);
    }

    private function gritRelease(string $check = 'grit'): GameSystemSnapshot
    {
        return CheckStepReleases::release($this->step($check));
    }

    private function rolled(GameSystemSnapshot $release, string $check, int ...$faces): StepResult
    {
        $result = ($release->check($check) ?? self::fail('No such check.'))->perform(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of($release->sheet(), [], ['heat' => 10]), null, 0);

        return StepResult::check('ada', $result);
    }

    private function heat(Campaign $campaign, GameSystemSnapshot $release): int
    {
        return $campaign->trackerValue($release->tracker('heat') ?? self::fail('No heat.'));
    }

    /**
     * Ada in the scene of session 1, Bo in the party only.
     */
    private function inScene(GameSystemSnapshot $release): Campaign
    {
        $campaign = self::guided($release, 'play');
        foreach ([['ada', 'Ada'], ['bo', 'Bo']] as [$id, $name]) {
            $campaign->addCharacter(CharacterId::fromString($id), $name, [], [], [], $release);
        }

        $campaign->startSession(self::at(), $release);
        $campaign->editCurrentSessionParty([CharacterId::fromString('bo')]);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ada')]);

        return $campaign;
    }

    /**
     * Ada rolled 1, 5 and 2 on "sneak": one success, "weak", with dice that failed.
     */
    private function pending(GameSystemSnapshot $release): Campaign
    {
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'grit', 1, 5, 2), $release, self::at(), entryId: self::ENTRY);

        return $campaign;
    }

    /**
     * A copy that shares no object with the campaign, so a change to the campaign's FlowRun cannot
     * reach it (clone is shallow).
     */
    private function deepCopy(Campaign $campaign): Campaign
    {
        $copy = unserialize(serialize($campaign));
        \assert($copy instanceof Campaign);

        return $copy;
    }
}
