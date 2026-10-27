<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\PoolDie;
use App\Studio\Domain\Check\CheckTestPool;
use App\Studio\Domain\Check\CheckTestPoolRoll;
use App\Studio\Domain\Check\CheckTestPush;
use App\Studio\Domain\Check\CheckTestRoll;
use App\Studio\Domain\Check\CheckTestRollFailed;
use App\Studio\Domain\Check\CheckTestRollNotPushable;
use App\Studio\Domain\Check\CheckTestSpecialResult;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\Check\OutcomeBand;
use App\Studio\Domain\Check\SampleSheet;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Test-rolling a pool check in Play's order (ADR 0041, the cases of Play's CheckPoolTest): hands 4,
 * no zero, a pool of {hands} dice of 6 sides that succeed on 5+, bands miss up to 0, weak up to 1 and
 * strong above; its push costs "Take a Condition". The sandbox pushes statelessly: the faces of the
 * roll come back with the request.
 */
#[CoversClass(CheckTestRoll::class)]
#[CoversClass(CheckTestPool::class)]
#[CoversClass(CheckTestPoolRoll::class)]
#[CoversClass(CheckTestPush::class)]
#[CoversClass(CheckTestRollNotPushable::class)]
final class CheckTestRollPoolTest extends TestCase
{
    #[Test]
    public function aPoolIsSizedFromTheSheetAndTheSuccessesAreTheTotal(): void
    {
        $result = $this->grit()->perform(new ScriptedRandomNumberGenerator(1, 5, 6, 2), $this->sheet(), null, 0);

        $pool = $result->pool ?? self::fail('A pool check rolls a pool.');
        self::assertNull($result->roll);
        self::assertSame([4, 4, '{hands}', 2], [$pool->roll->size(), $pool->count, $pool->countFormula, $result->total]);
        self::assertSame([1, 5, 6, 2], array_map(static fn (PoolDie $die): int => $die->face(), $pool->roll->dice()));
        self::assertSame([0, 0, null, 'strong'], [$result->modifier, $result->situational, $result->difficulty, $result->band->key]);
        self::assertSame([0, 1, null], $result->bounds);
        self::assertSame('Take a Condition', $result->push?->label);
        self::assertNull($result->rolledSuccesses);
    }

    /**
     * @return iterable<string, array{int|Formula, int, int, int}> count, situational, the size rolled, the count read
     */
    public static function sizes(): iterable
    {
        yield 'a number count' => [3, 0, 3, 3];
        yield 'the situational modifier adds dice' => [Formula::fromString('{hands}'), 3, 7, 4];
        yield 'or takes them' => [Formula::fromString('{hands}'), -2, 2, 4];
        yield 'never below one die' => [Formula::fromString('{hands}'), -100, 1, 4];
        yield 'a formula below one still rolls one die' => [Formula::fromString('{zero} - 5'), 0, 1, -5];
        yield 'never above a hundred dice' => [100, 100, 100, 100];
        yield 'a formula far above a hundred' => [Formula::fromString('{hands} * 1000'), 0, 100, 4000];
    }

    #[Test]
    #[DataProvider('sizes')]
    public function theSizeIsTheCountPlusTheSituationalModifierKeptInOneToAHundred(int|Formula $count, int $situational, int $size, int $read): void
    {
        $check = $this->check(new CheckTestPool($count, 6, 5));

        $result = $check->perform(new ScriptedRandomNumberGenerator(...array_fill(0, $size, 1)), $this->sheet(), null, $situational);

        $pool = $result->pool ?? self::fail('A pool check rolls a pool.');
        self::assertSame([$size, $read], [$pool->roll->size(), $pool->count]);
    }

    #[Test]
    public function aCountThatCannotBeEvaluatedRollsNothing(): void
    {
        $check = $this->check(new CheckTestPool(Formula::fromString('{hands} / {zero}'), 6, 5));

        try {
            // A generator with no number left throws a LogicException if a die is rolled.
            $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0);
            self::fail('The count cannot be evaluated.');
        } catch (CheckTestRollFailed $failed) {
            self::assertStringStartsWith('Check "grit" cannot be test-rolled: ', $failed->getMessage());
        }
    }

    #[Test]
    public function aPushReRollsTheFailedDiceOnceAndBandsThePushedSuccesses(): void
    {
        $result = $this->grit()->push(new ScriptedRandomNumberGenerator(6, 3), $this->sheet(), null, 0, [1, 5, 6, 2]);

        $pool = $result->pool ?? self::fail('A pushed roll has its pool.');
        self::assertSame([6, 5, 6, 3], array_map(static fn (PoolDie $die): int => $die->face(), $pool->roll->dice()));
        self::assertSame([true, false, false, true], array_map(static fn (PoolDie $die): bool => $die->isRerolled(), $pool->roll->dice()));
        self::assertSame([3, 2, 'strong', 'Take a Condition'], [$result->total, $result->rolledSuccesses, $result->band->key, $result->push?->label]);
    }

    #[Test]
    public function aPushThatFindsNoBetterBandStaysOnTheLowerOne(): void
    {
        $result = $this->grit()->push(new ScriptedRandomNumberGenerator(1, 1, 1, 1), $this->sheet(), null, 0, [1, 2, 3, 4]);

        self::assertSame([0, 0, 'miss'], [$result->total, $result->rolledSuccesses, $result->band->key]);
    }

    /**
     * @return iterable<string, array{list<int>, int}> faces, situational
     */
    public static function invalidFaces(): iterable
    {
        yield 'fewer faces than the size' => [[1, 2, 3], 0];
        yield 'more faces than the size' => [[1, 2, 3, 4, 5], 0];
        yield 'a face of zero' => [[0, 2, 3, 4], 0];
        yield 'a face above the sides' => [[1, 2, 3, 7], 0];
        yield 'faces for the size without the situational modifier' => [[1, 2, 3, 4], 2];
        yield 'no faces' => [[], 0];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('invalidFaces')]
    public function facesThatAreNotTheRollOfThePoolAreRefusedAndNothingIsRolled(array $faces, int $situational): void
    {
        try {
            $this->grit()->push(new ScriptedRandomNumberGenerator(), $this->sheet(), null, $situational, $faces);
            self::fail('The faces were accepted.');
        } catch (InvalidTestRoll $invalid) {
            self::assertStringStartsWith('faces: ', $invalid->getMessage());
        }
    }

    #[Test]
    public function aRollWhoseDiceAllSucceededCannotBePushed(): void
    {
        $this->expectException(CheckTestRollNotPushable::class);
        $this->expectExceptionMessageIsOrContains('Check "grit" cannot be pushed: no regular die failed.');

        $this->grit()->push(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0, [5, 6, 6, 5]);
    }

    #[Test]
    public function aCheckWithoutAPushCannotBePushed(): void
    {
        $this->expectException(CheckTestRollNotPushable::class);
        $this->expectExceptionMessageIsOrContains('Check "grit" cannot be pushed: it declares no push.');

        $this->check(new CheckTestPool(Formula::fromString('{hands}'), 6, 5), push: false)->push(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0, [1, 2, 3, 4]);
    }

    #[Test]
    public function aDiceCheckCannotBePushed(): void
    {
        $check = new CheckTestRoll('grit', DiceExpression::fromString('1d6'), null, null, [new OutcomeBand('only', 'Only')]);

        $this->expectException(CheckTestRollNotPushable::class);

        $check->push(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0, [1]);
    }

    #[Test]
    public function aPushWhoseBandsCannotBeEvaluatedReRollsNothing(): void
    {
        $check = $this->check(new CheckTestPool(Formula::fromString('{hands}'), 6, 5), [new OutcomeBand('low', 'Low', Formula::fromString('{hands} / {zero}')), new OutcomeBand('high', 'High')]);

        $this->expectException(CheckTestRollFailed::class);

        $check->push(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0, [1, 2, 3, 4]);
    }

    #[Test]
    public function aPoolCheckAndItsPushAreReadFromTheDraft(): void
    {
        $check = CheckTestRoll::fromStored($this->stored());

        $result = $check->perform(new ScriptedRandomNumberGenerator(1, 5, 6, 2), $this->sheet(), null, 0);

        self::assertSame([2, 'strong', 'Take a Condition', '{hands}'], [$result->total, $result->band->key, $result->push?->label, $result->pool?->countFormula]);
    }

    #[Test]
    public function aNumberCountAndAPushWithEffectsAreRead(): void
    {
        $check = CheckTestRoll::fromStored([...$this->stored(), 'pool' => ['count' => 2, 'sides' => 10, 'successAt' => 8], 'push' => ['label' => 'Pay', 'effects' => [['kind' => 'sheet', 'field' => 'stress', 'op' => 'add', 'value' => 1]]]]);

        $result = $check->perform(new ScriptedRandomNumberGenerator(9, 3), $this->sheet(), null, 0);

        self::assertSame([1, 2, null, 'Pay'], [$result->total, $result->pool?->count, $result->pool?->countFormula, $result->push?->label]);
    }

    #[Test]
    public function aPoolCheckWithoutAPushIsRead(): void
    {
        $stored = $this->stored();
        unset($stored['push']);

        $result = CheckTestRoll::fromStored($stored)->perform(new ScriptedRandomNumberGenerator(1, 1, 1, 1), $this->sheet(), null, 0);

        self::assertNull($result->push);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}> the changes to the stored pool check
     */
    public static function unreadablePoolChecks(): iterable
    {
        yield 'dice and a pool' => [['dice' => '1d6']];
        yield 'neither dice nor a pool' => [['pool' => null]];
        yield 'a modifier with a pool' => [['modifier' => '{hands}']];
        yield 'a push without a pool' => [['pool' => null, 'dice' => '1d6', 'push' => ['label' => 'Pay']]];
        yield 'a pool that is not an object' => [['pool' => 4]];
        yield 'a pool that is a list' => [['pool' => [4, 6, 5]]];
        yield 'a count that is none of the two' => [['pool' => ['count' => 'four', 'sides' => 6, 'successAt' => 5]]];
        yield 'a count without a formula' => [['pool' => ['count' => [], 'sides' => 6, 'successAt' => 5]]];
        yield 'a count formula that is not a string' => [['pool' => ['count' => ['formula' => 4], 'sides' => 6, 'successAt' => 5]]];
        yield 'a count formula that does not parse' => [['pool' => ['count' => ['formula' => '{'], 'sides' => 6, 'successAt' => 5]]];
        yield 'a count of zero' => [['pool' => ['count' => 0, 'sides' => 6, 'successAt' => 5]]];
        yield 'a count above a hundred' => [['pool' => ['count' => 101, 'sides' => 6, 'successAt' => 5]]];
        yield 'sides below two' => [['pool' => ['count' => 3, 'sides' => 1, 'successAt' => 1]]];
        yield 'sides that are not an integer' => [['pool' => ['count' => 3, 'sides' => '6', 'successAt' => 5]]];
        yield 'a success above the sides' => [['pool' => ['count' => 3, 'sides' => 6, 'successAt' => 7]]];
        yield 'a success that is not an integer' => [['pool' => ['count' => 3, 'sides' => 6, 'successAt' => 5.5]]];
        yield 'criticals that are not an object' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'criticals' => 2]]];
        yield 'criticals without a bonus' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'criticals' => []]]];
        yield 'a criticals bonus of zero' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'criticals' => ['bonus' => 0]]]];
        yield 'a criticals bonus above ten' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'criticals' => ['bonus' => 11]]]];
        yield 'a criticals bonus that is not an integer' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'criticals' => ['bonus' => '2']]]];
        yield 'hunger that is not an object' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'hunger' => 1]]];
        yield 'hunger without a count' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'hunger' => ['count' => 'many']]]];
        yield 'a negative Hunger count' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'hunger' => ['count' => -1]]]];
        yield 'a Hunger count above a hundred' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'hunger' => ['count' => 101]]]];
        yield 'a Hunger formula that does not parse' => [['pool' => ['count' => 3, 'sides' => 10, 'successAt' => 6, 'hunger' => ['count' => ['formula' => '{']]]]];
        yield 'a push cap of zero' => [['push' => ['label' => 'Pay', 'maxDice' => 0]]];
        yield 'a push cap above a hundred' => [['push' => ['label' => 'Pay', 'maxDice' => 101]]];
        yield 'a push cap that is not an integer' => [['push' => ['label' => 'Pay', 'maxDice' => '3']]];
        yield 'a push that is not an object' => [['push' => 'Pay']];
        yield 'a push without a label' => [['push' => ['effects' => []]]];
        yield 'a blank push label' => [['push' => ['label' => ' ']]];
        yield 'a push label that is not a string' => [['push' => ['label' => 3]]];
        yield 'push effects that are not a list' => [['push' => ['label' => 'Pay', 'effects' => 'none']]];
        yield 'a push label above a hundred characters' => [['push' => ['label' => str_repeat('x', 101)]]];
        yield 'more than ten push effects' => [['push' => ['label' => 'Pay', 'effects' => array_fill(0, 11, ['kind' => 'sheet'])]]];
        yield 'a push effect that is not an object' => [['push' => ['label' => 'Pay', 'effects' => ['stress']]]];
    }

    /**
     * @param array<array-key, mixed> $change
     */
    #[Test]
    #[DataProvider('unreadablePoolChecks')]
    public function aPoolCheckThatIsNotReadableFailsClosedWithAClearError(array $change): void
    {
        $this->expectException(CheckTestRollFailed::class);
        $this->expectExceptionMessageMatches('/^The saved draft cannot be test-rolled: .+\.$/');

        CheckTestRoll::fromStored([...$this->stored(), ...$change]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}> the change to a valid pool check and the label of the error
     */
    public static function otherUnreadableParts(): iterable
    {
        yield 'a band without a string key' => [['bands' => [['key' => 4, 'label' => 'Only']]], 'has a band 0 without a string key and label'];
        yield 'a bound formula that does not parse' => [['bands' => [['key' => 'a', 'label' => 'A', 'upTo' => ['formula' => '{']], ['key' => 'b', 'label' => 'B']]], 'has a formula that does not parse'];
        yield 'a difficulty without an integer default' => [['difficulty' => ['default' => 'hard']], 'has a difficulty without an integer default, min and max'];
        yield 'a count formula that does not parse' => [['pool' => ['count' => ['formula' => '{'], 'sides' => 6, 'successAt' => 5]], 'has a formula that does not parse'];
        yield 'a pool outside the kernel bounds' => [['pool' => ['count' => 0, 'sides' => 6, 'successAt' => 5]], 'has a pool that breaks the contract'];
    }

    /**
     * @param array<array-key, mixed> $change
     */
    #[Test]
    #[DataProvider('otherUnreadableParts')]
    public function anErrorOutsideThePoolKeepsItsOwnLabel(array $change, string $label): void
    {
        try {
            CheckTestRoll::fromStored([...$this->stored(), ...$change]);
            self::fail('An unreadable check was read.');
        } catch (CheckTestRollFailed $failed) {
            self::assertStringContainsString($label, $failed->getMessage());
            self::assertSame(str_contains($label, 'pool'), str_contains($failed->getMessage(), 'pool that breaks the contract'));
        }
    }

    /**
     * @return iterable<string, array{\Closure(): CheckTestRoll}>
     */
    public static function impossibleChecks(): iterable
    {
        $bands = [new OutcomeBand('only', 'Only')];
        $pool = new CheckTestPool(3, 6, 5);
        $dice = DiceExpression::fromString('1d6');

        yield 'both dice and a pool' => [static fn (): CheckTestRoll => new CheckTestRoll('c', $dice, null, null, $bands, $pool)];
        yield 'neither dice nor a pool' => [static fn (): CheckTestRoll => new CheckTestRoll('c', null, null, null, $bands)];
        yield 'a modifier with a pool' => [static fn (): CheckTestRoll => new CheckTestRoll('c', null, Formula::fromString('{hands}'), null, $bands, $pool)];
        yield 'a push without a pool' => [static fn (): CheckTestRoll => new CheckTestRoll('c', $dice, null, null, $bands, null, new CheckTestPush('Pay'))];
        yield 'a blank push label' => [static fn (): CheckTestRoll => new CheckTestRoll('c', null, null, null, $bands, $pool, new CheckTestPush(' '))];
    }

    /**
     * @param \Closure(): CheckTestRoll $build
     */
    #[Test]
    #[DataProvider('impossibleChecks')]
    public function aCheckCannotBeBuiltWithBothOrNeitherOrAPoolWithAModifierOrAPushWithoutAPool(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build();
    }

    #[Test]
    #[TestWith([-1])]
    #[TestWith([11])]
    public function aCriticalPairsBonusIsZeroForNoneOrOneToTen(int $bonus): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must be 0 for none or in 1..10');

        new CheckTestPool(3, 10, 6, null, $bonus);
    }

    #[Test]
    public function aPoolCannotBeBuiltOutsideTheKernelBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CheckTestPool(3, 6, 7);
    }

    #[Test]
    public function criticalsHungerDiceAndThePushCapAreReadAsStored(): void
    {
        $check = CheckTestRoll::fromStored([...$this->stored(), 'pool' => ['count' => 5, 'sides' => 10, 'successAt' => 6, 'criticals' => ['bonus' => 2], 'hunger' => ['count' => ['formula' => '{hands} - 3']]], 'push' => ['label' => 'Spend Willpower', 'maxDice' => 3]]);

        $pool = $check->pool ?? self::fail('A pool check has a pool.');
        self::assertSame([2, 3], [$pool->criticalsBonus, $check->push?->maxDice]);
        self::assertSame('{hands} - 3', $pool->hunger instanceof Formula ? $pool->hunger->toString() : null);
        // A check without them reads with none.
        $plain = CheckTestRoll::fromStored($this->stored());
        self::assertSame([null, 0, null], [$plain->pool?->hunger, $plain->pool?->criticalsBonus, $plain->push?->maxDice]);
    }

    #[Test]
    public function aHungerRollTotalsItsCriticalPairsAndFlagsTheSpecialResult(): void
    {
        // Four dice of ten sides, success on 6+, two of them Hunger dice (hands 4 - 2): regular 10, 3 then Hunger 10, 1.
        $result = $this->vampire(Formula::fromString('{hands} - 2'))->perform(new ScriptedRandomNumberGenerator(10, 3, 10, 1), $this->sheet(), null, 0);

        $pool = $result->pool ?? self::fail('A pool check rolls a pool.');
        self::assertSame([2, 2, true], [$pool->roll->successes(), $pool->roll->pool()->hunger, $pool->roll->dice()[3]->isHunger()]);
        // Two successes and a pair of 10s worth two more; the pair holds a Hunger die.
        self::assertSame([4, CheckTestSpecialResult::MessyCritical, null], [$result->total, $result->special, $result->rolledSpecial]);
    }

    #[Test]
    public function aCheckWithOneBandNeverFailsSoItHasNoBestialFailure(): void
    {
        $check = $this->vampire(1, [new OutcomeBand('only', 'Only')]);

        $result = $check->perform(new ScriptedRandomNumberGenerator(2, 3, 4, 1), $this->sheet(), null, 0);

        self::assertNull($result->special);
    }

    #[Test]
    public function aPushRebuildsTheHungerDiceLastReRollsTheCappedFailedRegularDiceAndComputesTheSpecialAgain(): void
    {
        $check = $this->vampire(1, null, new CheckTestPush('Spend Willpower', 2));

        // Five dice (hands 4 + 1): four regular and one Hunger die; three regular dice failed, the cap re-rolls the first two.
        $result = $check->push(new ScriptedRandomNumberGenerator(10, 10), $this->sheet(), null, 1, [2, 3, 4, 5, 1]);

        $dice = $result->pool?->roll->dice() ?? self::fail('A pool check rolls a pool.');
        self::assertSame([[10, true], [10, true], [4, false], [5, false], [1, false]], array_map(static fn (PoolDie $die): array => [$die->face(), $die->isRerolled()], $dice));
        // Before: no success, failed with a Hunger 1. After: two 10s and a pair worth two more, which is no longer a failure.
        self::assertSame([0, 4, CheckTestSpecialResult::BestialFailure, CheckTestSpecialResult::Critical], [$result->rolledSuccesses, $result->total, $result->rolledSpecial, $result->special]);
    }

    #[Test]
    public function aRollWhoseOnlyFailedDiceAreHungerDiceCannotBePushed(): void
    {
        $this->expectException(CheckTestRollNotPushable::class);

        $this->vampire(1)->push(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0, [6, 7, 8, 1]);
    }

    /**
     * Three bands as the grit check; a pool of {hands} dice of ten sides, success on 6+, a pair of 10s worth 2 more.
     *
     * @param ?non-empty-list<OutcomeBand> $bands
     */
    private function vampire(int|Formula $hunger, ?array $bands = null, ?CheckTestPush $push = null): CheckTestRoll
    {
        return new CheckTestRoll('grit', null, null, null, $bands ?? [new OutcomeBand('miss', 'Miss', 0), new OutcomeBand('weak', 'Weak', 1), new OutcomeBand('strong', 'Strong')], new CheckTestPool(Formula::fromString('{hands}'), 10, 6, $hunger, 2), $push ?? new CheckTestPush('Spend Willpower'));
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return [
            'key' => 'grit',
            'label' => 'Grit',
            'pool' => ['count' => ['formula' => '{hands}'], 'sides' => 6, 'successAt' => 5],
            'push' => ['label' => 'Take a Condition'],
            'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'weak', 'label' => 'Weak', 'upTo' => 1], ['key' => 'strong', 'label' => 'Strong']],
        ];
    }

    /**
     * @param ?non-empty-list<OutcomeBand> $bands
     */
    private function check(CheckTestPool $pool, ?array $bands = null, bool $push = true): CheckTestRoll
    {
        return new CheckTestRoll('grit', null, null, null, $bands ?? [new OutcomeBand('miss', 'Miss', 0), new OutcomeBand('weak', 'Weak', 1), new OutcomeBand('strong', 'Strong')], $pool, $push ? new CheckTestPush('Take a Condition') : null);
    }

    private function grit(): CheckTestRoll
    {
        return $this->check(new CheckTestPool(Formula::fromString('{hands}'), 6, 5));
    }

    private function sheet(int $hands = 4): SampleSheet
    {
        return SampleSheet::fromDocument([
            'sheet' => ['fields' => [
                ['key' => 'hands', 'label' => 'Hands', 'type' => 'number', 'default' => 4],
                ['key' => 'zero', 'label' => 'Zero', 'type' => 'number'],
            ]],
            'trackers' => [],
        ], ['hands' => $hands], []);
    }
}
