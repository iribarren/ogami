<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A check step that offers a check set (ADR 0042): the player rolls one check of the set, named by
 * its key, which the FlowRun validates as it does the Character and the difficulty. The step
 * "sneak" offers "skill" and "knack" (dice) or "grit" and "nerve" (pools that can be pushed); a
 * single-check step takes no key. Ada is in the scene "job".
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(CheckStep::class)]
#[CoversClass(PendingCheck::class)]
final class FlowRunCheckSetTest extends FlowRunTestCase
{
    private const string ENTRY = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001';

    #[Test]
    public function theViewShowsTheStepAsASetAndTheCheckPickedBranchesLikeASingleOne(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(['skill', 'knack'], outcomes: ['fail' => new Outcome('fumble')]));
        $campaign = $this->inScene($release);

        $step = $campaign->flowRunView($release)?->step?->step;
        // "knack" adds 4 to the 1d10: a 6 totals 10, a fail (up to 12), where "skill" would pass.
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'knack', 'knack', 6), $release, self::at());

        self::assertInstanceOf(CheckStep::class, $step);
        self::assertSame([['skill', 'knack'], true, null], [$step->checks, $step->isSet(), $step->singleCheck()]);
        self::assertSame([['setup', 'fumble'], ['sneak' => 'Fail']], [self::position($campaign), self::flowRunOf($campaign)->answers()]);
    }

    /**
     * @return iterable<string, array{string|list<string>, ?string, string}> the check or set of the step, the key sent and the message
     */
    public static function refusedKeys(): iterable
    {
        yield 'none for a set' => [['skill', 'knack'], null, 'Step "sneak" is a check step: it needs "checkKey".'];
        yield 'one outside the set' => [['skill', 'knack'], 'flat', 'Check step "sneak" has no check "flat" in its set.'];
        yield 'one the release lacks' => [['skill', 'knack'], 'ghost', 'Check step "sneak" has no check "ghost" in its set.'];
        yield 'one for a single check' => ['skill', 'skill', 'Step "sneak" is a check step: it takes no "checkKey".'];
    }

    /**
     * @param string|non-empty-list<string> $check
     */
    #[Test]
    #[DataProvider('refusedKeys')]
    public function aMissingUnknownOrUnexpectedCheckKeyIsRefusedAndNothingChanges(string|array $check, ?string $key, string $message): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step($check));
        $campaign = $this->inScene($release);
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('sneak', $this->rolled($release, 'skill', $key, 6), $release, self::at());
            self::fail('The step was completed.');
        } catch (InvalidStepResult $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function aPushableRollOfASetPendsWithThePickedCheckAndKeepResolvesIt(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(['grit', 'nerve']));
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, 'nerve', 'nerve', 1, 5, 2), $release, self::at(), entryId: self::ENTRY);
        $pending = self::flowRunOf($campaign)->pendingCheck();
        $campaign->resolvePendingFlowCheck('sneak', null, $release, self::at());

        self::assertEquals(new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak', 'nerve'), $pending);
        self::assertSame([['setup', 'pass'], ['sneak' => 'Weak'], null], [self::position($campaign), self::flowRunOf($campaign)->answers(), self::flowRunOf($campaign)->pendingCheck()]);
    }

    #[Test]
    public function aPushOfASetRollResolvesOnThePushedBand(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step(['grit', 'nerve']));
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'nerve', 'nerve', 1, 5, 2), $release, self::at(), entryId: self::ENTRY);

        $campaign->resolvePendingFlowCheck('sneak', 'strong', $release, self::at());

        self::assertSame(['sneak' => 'Strong'], self::flowRunOf($campaign)->answers());
    }

    #[Test]
    public function aRollOfACheckWithoutAPushPendsNothingEvenInASet(): void
    {
        // "steady" has no push, so its failed dice end the step at once, whichever check the set also offers.
        $release = CheckStepReleases::release(CheckStepReleases::step(['grit', 'steady']));
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, 'steady', 'steady', 1, 5, 2), $release, self::at(), entryId: self::ENTRY);

        self::assertSame([null, ['sneak' => 'Weak']], [self::flowRunOf($campaign)->pendingCheck(), self::flowRunOf($campaign)->answers()]);
    }

    #[Test]
    public function anUpgradeDropsAPendingRollOfASetWithTheGuidance(): void
    {
        // ADR 0035: the planner keeps no step state, so guidance restarts and the pending roll goes.
        $release = CheckStepReleases::release(CheckStepReleases::step(['grit', 'nerve']));
        $target = CheckStepReleases::release(CheckStepReleases::step(['grit', 'nerve']), version: 2);
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'nerve', 'nerve', 1, 5, 2), $release, self::at(), entryId: self::ENTRY);
        self::assertInstanceOf(PendingCheck::class, self::flowRunOf($campaign)->pendingCheck());

        $campaign->upgradeRelease($release, $target, $campaign->planReleaseUpgrade($release, $target)->fingerprint(), self::at());

        self::assertSame([null, FlowRunStatus::Active, 2], [self::flowRunOf($campaign)->pendingCheck(), self::flowRunOf($campaign)->status(), $campaign->pinnedRelease()->releaseVersion()]);
    }

    #[Test]
    public function aPendingRollStoredWithoutACheckKeyResolvesOnTheStepsOwnCheck(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step('grit'));
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'grit', null, 1, 5, 2), $release, self::at(), entryId: self::ENTRY);

        // A single-check step keeps no key, which is the shape of every row stored before check sets.
        self::assertEquals(new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak'), self::flowRunOf($campaign)->pendingCheck());
        $campaign->resolvePendingFlowCheck('sneak', 'strong', $release, self::at());

        self::assertSame(['sneak' => 'Strong'], self::flowRunOf($campaign)->answers());
    }

    #[Test]
    public function aPendingRollOfASetThatKeptNoCheckIsRefusedAsCorrupt(): void
    {
        $step = CheckStepReleases::step(['grit', 'nerve']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The pending roll of check step "sneak" names no check of its set.');

        new PendingCheck('sneak', self::ENTRY, 'ada', null, 'weak')->checkKeyFor($step);
    }

    private function rolled(GameSystemSnapshot $release, string $check, ?string $key, int ...$faces): StepResult
    {
        $result = ($release->check($check) ?? self::fail('No such check.'))->perform(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of($release->sheet(), [], ['heat' => 10]), null, 0);

        return StepResult::check('ada', $result, $key);
    }

    private function inScene(GameSystemSnapshot $release): Campaign
    {
        $campaign = self::guided($release, 'play');
        $campaign->addCharacter(CharacterId::fromString('ada'), 'Ada', [], [], [], $release);
        $campaign->startSession(self::at(), $release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ada')]);

        return $campaign;
    }
}
