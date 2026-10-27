<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\Check\SpecialResult;
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
 * A check step branches on the special result of its roll ahead of the band (ADR 0044), at once, after
 * Keep and after Push. The step "sneak" rolls "craving" (4d6, success on 5+, critical pairs worth 2,
 * 1 Hunger die after 3 regular dice, no push) or "dread" (the same with a push capped at 1 die);
 * the bands "miss" (0), "weak" (1) and "strong" (2 and up). Its outcomes: "miss" goes on at "pass" and
 * adds 1 heat; a bestial failure goes on at "fumble" and adds 7; a messy critical goes on at "fumble"
 * and adds 3; a critical adds 5 and follows the step. Heat starts at 10.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(CheckStep::class)]
#[CoversClass(PendingCheck::class)]
final class FlowRunSpecialOutcomeTest extends FlowRunTestCase
{
    private const string ENTRY = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001';

    /**
     * @return iterable<string, array{string, list<int>, string, int}> the check, its faces, the position it ends at and the heat
     */
    public static function rollsThatCompleteTheStepAtOnce(): iterable
    {
        yield 'a bestial failure takes its outcome ahead of the miss' => ['craving', [1, 1, 1, 1], 'fumble', 17];
        yield 'a messy critical takes its outcome ahead of the strong band' => ['craving', [6, 2, 2, 6], 'fumble', 13];
        yield 'a critical follows the step' => ['craving', [6, 6, 2, 2], 'pass', 15];
        yield 'a miss without a special result takes the band outcome' => ['craving', [1, 2, 3, 4], 'pass', 11];
        yield 'a result without an outcome of its own takes the band outcome' => ['craving', [5, 2, 2, 2], 'pass', 10];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('rollsThatCompleteTheStepAtOnce')]
    public function aRollTakesTheOutcomeOfItsSpecialResultAheadOfTheBand(string $check, array $faces, string $stepKey, int $heat): void
    {
        $release = $this->release_($check);
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, $check, ...$faces), $release, self::at(), entryId: self::ENTRY);

        self::assertSame([['setup', $stepKey], $heat, null], [self::position($campaign), $this->heat($campaign, $release), self::flowRunOf($campaign)->pendingCheck()]);
    }

    #[Test]
    public function aPendingRollKeepsItsSpecialResultAndKeepTakesItsOutcome(): void
    {
        $release = $this->release_('dread');
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'dread', 1, 1, 1, 1), $release, self::at(), entryId: self::ENTRY);

        self::assertEquals(new PendingCheck('sneak', self::ENTRY, 'ada', null, 'miss', special: SpecialResult::BestialFailure), self::flowRunOf($campaign)->pendingCheck());
        self::assertSame([['setup', 'sneak'], 10], [self::position($campaign), $this->heat($campaign, $release)]);

        $campaign->resolvePendingFlowCheck('sneak', null, $release, self::at());

        self::assertSame([['setup', 'fumble'], 17, ['sneak' => 'Miss']], [self::position($campaign), $this->heat($campaign, $release), self::flowRunOf($campaign)->answers()]);
    }

    #[Test]
    public function aPendingRollWithoutASpecialResultKeepsTheBandOutcome(): void
    {
        $release = $this->release_('dread');
        $campaign = $this->inScene($release);
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'dread', 1, 5, 2, 2), $release, self::at(), entryId: self::ENTRY);

        $campaign->resolvePendingFlowCheck('sneak', null, $release, self::at());

        self::assertSame([['setup', 'pass'], 10], [self::position($campaign), $this->heat($campaign, $release)]);
    }

    /**
     * @return iterable<string, array{string, ?SpecialResult, string, int}> the pushed band, the pushed roll's special result, the position and the heat
     */
    public static function pushedRolls(): iterable
    {
        yield 'a push that turns a bestial failure into a messy critical' => ['strong', SpecialResult::MessyCritical, 'fumble', 13];
        yield 'a push that still fails keeps the bestial failure' => ['miss', SpecialResult::BestialFailure, 'fumble', 17];
        yield 'a push that leaves no special result takes the band outcome' => ['miss', null, 'pass', 11];
    }

    #[Test]
    #[DataProvider('pushedRolls')]
    public function aPushTakesTheOutcomeOfTheSpecialResultOfThePushedRoll(string $pushedBandKey, ?SpecialResult $pushed, string $stepKey, int $heat): void
    {
        $release = $this->release_('dread');
        $campaign = $this->inScene($release);
        // The rolled special result (a bestial failure) is not the pushed one.
        $campaign->completeFlowStep('sneak', $this->rolled($release, 'dread', 1, 1, 1, 1), $release, self::at(), entryId: self::ENTRY);

        $campaign->resolvePendingFlowCheck('sneak', $pushedBandKey, $release, self::at(), pushedSpecial: $pushed);

        self::assertSame([['setup', $stepKey], $heat], [self::position($campaign), $this->heat($campaign, $release)]);
    }

    #[Test]
    public function aSpecialOutcomeNeverReachesAStepWithoutOne(): void
    {
        $release = CheckStepReleases::release(CheckStepReleases::step('craving', outcomes: ['miss' => new Outcome('pass')]));
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, 'craving', 1, 1, 1, 1), $release, self::at(), entryId: self::ENTRY);

        self::assertSame(['setup', 'pass'], self::position($campaign));
    }

    #[Test]
    public function aCheckStepAnswersWithTheBandLabelWhateverTheSpecialResult(): void
    {
        $release = $this->release_('craving');
        $campaign = $this->inScene($release);

        $campaign->completeFlowStep('sneak', $this->rolled($release, 'craving', 6, 2, 2, 6), $release, self::at(), entryId: self::ENTRY);

        self::assertSame(['sneak' => 'Strong'], self::flowRunOf($campaign)->answers());
    }

    private function release_(string $check): GameSystemSnapshot
    {
        $heat = static fn (int $by): TrackerEffect => new TrackerEffect('heat', TrackerOperation::Add, $by);

        return CheckStepReleases::release(CheckStepReleases::step($check, outcomes: ['miss' => new Outcome('pass', [$heat(1)])], specials: [
            'bestialFailure' => new Outcome('fumble', [$heat(7)]),
            'messyCritical' => new Outcome('fumble', [$heat(3)]),
            'critical' => new Outcome(null, [$heat(5)]),
        ]));
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
     * Ada in the scene of session 1.
     */
    private function inScene(GameSystemSnapshot $release): Campaign
    {
        $campaign = self::guided($release, 'play');
        $campaign->addCharacter(CharacterId::fromString('ada'), 'Ada', [], [], [], $release);
        $campaign->startSession(self::at(), $release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ada')]);

        return $campaign;
    }
}
