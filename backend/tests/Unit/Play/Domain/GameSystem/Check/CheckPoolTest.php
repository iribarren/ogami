<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\ModifierTerm;
use App\Play\Domain\GameSystem\Check\PoolResult;
use App\Play\Domain\GameSystem\Check\SituationalModifierOutOfRange;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\PoolDie;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Performing a pool check (ADR 0041) for a Character with hands 4 and edge = hands / 2: the pool is
 * {hands} + {edge} (6) d6, a die succeeds on 5 or more, the total is the successes, and the bands
 * are 0 successes (miss), up to 2 (weak) and the rest (strong).
 */
#[CoversClass(Check::class)]
#[CoversClass(CheckPool::class)]
#[CoversClass(PoolResult::class)]
final class CheckPoolTest extends TestCase
{
    #[Test]
    public function itRollsTheSizeTheFormulaGivesAndTotalsTheSuccesses(): void
    {
        $result = $this->formulaPool()->perform(new ScriptedRandomNumberGenerator(1, 5, 6, 2, 5, 4), $this->sheet(), null, 0);

        self::assertNull($result->roll);
        self::assertInstanceOf(PoolResult::class, $result->pool);
        self::assertSame(6, $result->pool->count);
        self::assertSame(6, $result->pool->roll->size());
        self::assertSame([1, 5, 6, 2, 5, 4], array_map(static fn (PoolDie $die): int => $die->face(), $result->pool->roll->dice()));
        self::assertSame([0, 0, 3, null, 'strong'], [$result->modifier, $result->situational, $result->total, $result->difficulty, $result->band->key]);
        self::assertSame(3, $result->pool->roll->successes());
    }

    #[Test]
    public function itRecordsWhatEachReferenceOfTheCountRead(): void
    {
        $result = $this->formulaPool()->perform(new ScriptedRandomNumberGenerator(...array_fill(0, 9, 1)), $this->sheet(['hands' => 6]), null, 0);

        self::assertInstanceOf(PoolResult::class, $result->pool);
        self::assertSame(['{hands}', '{edge}'], array_map(static fn (ModifierTerm $term): string => $term->token(), $result->pool->terms));
        self::assertSame([6, 3], array_map(static fn (ModifierTerm $term): int => $term->value, $result->pool->terms));
        self::assertSame(9, $result->pool->count);
        self::assertSame([], $result->modifierTerms);
    }

    #[Test]
    public function aNumberCountReadsNothingFromTheSheet(): void
    {
        $result = $this->check(new CheckPool(2, 6, 5))->perform(new ScriptedRandomNumberGenerator(5, 6), $this->sheet(), null, 0);

        self::assertInstanceOf(PoolResult::class, $result->pool);
        self::assertSame([2, []], [$result->pool->count, $result->pool->terms]);
        self::assertSame('weak', $result->band->key, 'Two successes are up to the second bound.');
    }

    /**
     * @return iterable<string, array{int, int, int}> count, situational, size
     */
    public static function sizes(): iterable
    {
        yield 'extra dice add to the count' => [3, 2, 5];
        yield 'a penalty takes dice away' => [3, -2, 1];
        yield 'at least one die' => [3, -3, 1];
        yield 'at least one die even far below' => [3, -100, 1];
        yield 'a count of zero still rolls one die' => [0, 0, 1];
        yield 'a negative count rolls one die' => [-5, 2, 1];
        yield 'at most a hundred dice' => [99, 2, 100];
        yield 'a hundred dice stays' => [100, 0, 100];
        yield 'at most a hundred dice from the biggest bonus' => [100, 100, 100];
        yield 'a huge count is bounded' => [\PHP_INT_MAX, 100, 100];
        yield 'a hugely negative count is bounded' => [\PHP_INT_MIN, -100, 1];
    }

    #[Test]
    #[DataProvider('sizes')]
    public function theSituationalModifierChangesTheSizeKeptInOneToAHundred(int $count, int $situational, int $size): void
    {
        self::assertSame($size, CheckPool::sizeFor($count, $situational));
    }

    #[Test]
    public function itRollsThatManyDiceWithTheSituationalModifier(): void
    {
        $result = $this->formulaPool()->perform(new ScriptedRandomNumberGenerator(...array_fill(0, 7, 6)), $this->sheet(), null, 1);

        self::assertInstanceOf(PoolResult::class, $result->pool);
        self::assertSame([6, 7, 1, 7], [$result->pool->count, $result->pool->roll->size(), $result->situational, $result->total]);
    }

    #[Test]
    public function aPenaltyCannotTakeThePoolUnderOneDie(): void
    {
        $result = $this->formulaPool()->perform(new ScriptedRandomNumberGenerator(6), $this->sheet(), null, -100);

        self::assertInstanceOf(PoolResult::class, $result->pool);
        self::assertSame([1, 1], [$result->pool->roll->size(), $result->total]);
    }

    #[Test]
    public function aBonusCannotTakeThePoolOverAHundredDice(): void
    {
        $result = $this->check(new CheckPool(100, 6, 6))->perform(new ScriptedRandomNumberGenerator(...array_fill(0, 100, 1)), $this->sheet(), null, 100);

        self::assertInstanceOf(PoolResult::class, $result->pool);
        self::assertSame([100, 0, 'miss'], [$result->pool->roll->size(), $result->total, $result->band->key]);
    }

    /**
     * @return iterable<string, array{list<int>, string}> faces, band
     */
    public static function bands(): iterable
    {
        yield 'no success' => [[1, 2, 3, 4, 4, 1], 'miss'];
        yield 'on the upper bound of a band' => [[5, 5, 1, 1, 1, 1], 'weak'];
        yield 'over the upper bound' => [[5, 5, 6, 1, 1, 1], 'strong'];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('bands')]
    public function theBandIsPickedOnTheSuccesses(array $faces, string $band): void
    {
        $result = $this->formulaPool()->perform(new ScriptedRandomNumberGenerator(...$faces), $this->sheet(), null, 0);

        self::assertSame($band, $result->band->key);
    }

    #[Test]
    public function aDifficultyAppliesToTheBandsOfASuccessCount(): void
    {
        $check = new Check('hard', 'Hard', null, null, null, new CheckDifficulty('Needed', 2, 1, 5), [new CheckBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')), new CheckBand('pass', 'Pass')], new CheckPool(3, 6, 6));

        $result = $check->perform(new ScriptedRandomNumberGenerator(6, 6, 1), $this->sheet(), 3, 0);

        self::assertSame([2, 3, 'fail'], [$result->total, $result->difficulty, $result->band->key]);
    }

    /**
     * @return iterable<string, array{string, list<CheckBand>}>
     */
    public static function evaluationFailures(): iterable
    {
        $bands = [new CheckBand('miss', 'Miss', 0), new CheckBand('hit', 'Hit')];

        yield 'a count dividing by zero' => ['{hands} / {zero}', $bands];
        yield 'a count reading an unknown check value' => ['{check:other}', $bands];
        yield 'a count overflowing' => ['{hands} * 1000000 * 1000000 * 1000000', $bands];
    }

    /**
     * @param list<CheckBand> $bands
     */
    #[Test]
    #[DataProvider('evaluationFailures')]
    public function aCountThatFailsRollsNothing(string $count, array $bands): void
    {
        $check = new Check('broken', 'Broken', null, null, null, null, $bands, new CheckPool(Formula::fromString($count), 6, 6));

        try {
            // A generator with no number left throws a LogicException if a die is rolled.
            $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0);
            self::fail('The count cannot be evaluated.');
        } catch (CheckEvaluationFailed $failed) {
            self::assertStringStartsWith('Check "broken" cannot be performed: ', $failed->getMessage());
        }
    }

    #[Test]
    public function aBandThatFailsToEvaluateRollsNothing(): void
    {
        $check = new Check('broken', 'Broken', null, null, null, null, [new CheckBand('low', 'Low', Formula::fromString('{hands} / {zero}')), new CheckBand('high', 'High')], new CheckPool(3, 6, 6));

        $this->expectException(CheckEvaluationFailed::class);

        $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0);
    }

    #[Test]
    public function theSituationalModifierIsStillBoundedForAPool(): void
    {
        $this->expectException(SituationalModifierOutOfRange::class);

        $this->formulaPool()->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 101);
    }

    /**
     * @return iterable<string, array{callable(): Check}>
     */
    public static function brokenInvariants(): iterable
    {
        $bands = [new CheckBand('only', 'Only')];
        $pool = new CheckPool(3, 6, 6);

        yield 'neither dice nor a pool' => [static fn (): Check => new Check('c', 'C', null, null, null, null, $bands)];
        yield 'dice and a pool' => [static fn (): Check => new Check('c', 'C', null, DiceExpression::fromString('1d6'), null, null, $bands, $pool)];
        yield 'a pool with a modifier' => [static fn (): Check => new Check('c', 'C', null, null, Formula::fromString('{hands}'), null, $bands, $pool)];
    }

    /**
     * @param callable(): Check $build
     */
    #[Test]
    #[DataProvider('brokenInvariants')]
    public function aCheckHasExactlyOneOfDiceOrAPoolAndAPoolHasNoModifier(callable $build): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build();
    }

    /**
     * @return iterable<string, array{int|Formula, int, int}>
     */
    public static function brokenPools(): iterable
    {
        yield 'a count under 1' => [0, 6, 6];
        yield 'a count over 100' => [101, 6, 6];
        yield 'one side' => [3, 1, 1];
        yield 'a success threshold over the sides' => [3, 6, 7];
        yield 'a success threshold under 1' => [Formula::fromString('{hands}'), 6, 0];
    }

    #[Test]
    #[DataProvider('brokenPools')]
    public function aPoolOutOfBoundsIsRefused(int|Formula $count, int $sides, int $successAt): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CheckPool($count, $sides, $successAt);
    }

    private function formulaPool(): Check
    {
        return $this->check(new CheckPool(Formula::fromString('{hands} + {edge}'), 6, 5));
    }

    private function check(CheckPool $pool): Check
    {
        return new Check('pool', 'Pool', null, null, null, null, [new CheckBand('miss', 'Miss', 0), new CheckBand('weak', 'Weak', 2), new CheckBand('strong', 'Strong')], $pool);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function sheet(array $values = []): SheetValues
    {
        return SheetValues::of(new SheetTemplate([
            new NumberField('hands', 'Hands', default: 4),
            new NumberField('zero', 'Zero'),
            new DerivedField('edge', 'Edge', null, null, Formula::fromString('{hands} / 2')),
        ]), $values, []);
    }
}
