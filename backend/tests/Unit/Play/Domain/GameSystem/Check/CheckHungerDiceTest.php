<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\CheckNotPushable;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Randomness\Domain\PoolDie;
use App\Randomness\Domain\PoolRoll;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hunger dice, critical pairs, special results and the capped push of a pool check (ADR 0044): a
 * 5d10 pool succeeding on 6 with 2 Hunger dice and a critical bonus of 2, rolled with difficulty 3
 * (a total below it fails). The dice are scripted: the 3 regular dice first, then the 2 Hunger dice.
 */
#[CoversClass(Check::class)]
#[CoversClass(CheckPool::class)]
#[CoversClass(CheckPush::class)]
#[CoversClass(SpecialResult::class)]
final class CheckHungerDiceTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, int, ?SpecialResult}> faces, total, special result
     */
    public static function rolls(): iterable
    {
        yield 'a plain win has none' => [[7, 7, 2, 3, 3], 2, null];
        yield 'a failure without a Hunger 1 has none' => [[2, 3, 2, 3, 3], 0, null];
        yield 'a failure with a Hunger 1 is a bestial failure' => [[2, 3, 2, 1, 3], 0, SpecialResult::BestialFailure];
        yield 'a failure with a regular 1 and no Hunger 1 has none' => [[1, 3, 2, 3, 3], 0, null];
        yield 'a pair of regular tens is a critical' => [[10, 10, 2, 3, 3], 4, SpecialResult::Critical];
        yield 'a pair with a Hunger ten is a messy critical' => [[10, 2, 2, 10, 3], 4, SpecialResult::MessyCritical];
        yield 'a messy critical wins over a Hunger 1 of a roll that does not fail' => [[10, 2, 2, 10, 1], 4, SpecialResult::MessyCritical];
        yield 'a lone ten is no critical' => [[10, 2, 2, 3, 3], 1, null];
        yield 'a win with a Hunger 1 and no pair has none' => [[7, 7, 7, 1, 3], 3, null];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('rolls')]
    public function itPicksTheSpecialResultByPrecedence(array $faces, int $total, ?SpecialResult $special): void
    {
        $result = $this->vtm()->perform(new ScriptedRandomNumberGenerator(...$faces), $this->sheet(), 3, 0);

        self::assertSame($total, $result->total);
        self::assertSame($special, $result->special);
    }

    #[Test]
    public function aPairThatStillMissesTheDifficultyIsNoCritical(): void
    {
        // Two tens count 4 against difficulty 5: below it, so the roll fails; no Hunger die shows 1.
        $result = $this->vtm()->perform(new ScriptedRandomNumberGenerator(10, 10, 2, 3, 3), $this->sheet(), 5, 0);

        self::assertSame(4, $result->total);
        self::assertNull($result->special);
    }

    #[Test]
    public function aFailedRollWithAHungerPairAndAHungerOneIsABestialFailure(): void
    {
        // A regular ten and a Hunger ten make a pair (total 2 + 2 = 4), below difficulty 6, and a Hunger die shows 1.
        $result = $this->vtm()->perform(new ScriptedRandomNumberGenerator(10, 2, 2, 10, 1), $this->sheet(), 6, 0);

        self::assertSame(4, $result->total);
        self::assertSame(SpecialResult::BestialFailure, $result->special);
    }

    #[Test]
    public function aCheckWithoutADifficultyFailsOnItsFirstBandWhenItHasTwoOrMore(): void
    {
        $check = $this->check(new CheckPool(3, 10, 6, 2, 1), bands: [new CheckBand('fail', 'Fail', 0), new CheckBand('win', 'Win')]);

        self::assertSame(SpecialResult::BestialFailure, $check->perform(new ScriptedRandomNumberGenerator(2, 3, 1), $this->sheet(), null, 0)->special);
        self::assertNull($check->perform(new ScriptedRandomNumberGenerator(7, 3, 1), $this->sheet(), null, 0)->special);
    }

    #[Test]
    public function aSingleBandCheckNeverFails(): void
    {
        $check = $this->check(new CheckPool(3, 10, 6, 2, 1), bands: [new CheckBand('only', 'Only')]);

        self::assertNull($check->perform(new ScriptedRandomNumberGenerator(2, 3, 1), $this->sheet(), null, 0)->special);
    }

    #[Test]
    public function aPoolWithoutCriticalsOrHungerNeverHasASpecialResult(): void
    {
        $check = $this->check(new CheckPool(4, 10, 6), bands: [new CheckBand('fail', 'Fail', 0), new CheckBand('win', 'Win')]);

        self::assertNull($check->perform(new ScriptedRandomNumberGenerator(10, 10, 1, 1), $this->sheet(), null, 0)->special);
        self::assertSame(2, $check->perform(new ScriptedRandomNumberGenerator(10, 10, 1, 1), $this->sheet(), null, 0)->total);
    }

    #[Test]
    public function theHungerFormulaIsReadFromTheSheetAndClampedToThePool(): void
    {
        $check = $this->check(new CheckPool(3, 10, 6, null, Formula::fromString('{hunger}')));

        $dice = static fn (int $hunger): array => array_map(static fn (PoolDie $die): string => $die->group()->value, $check->perform(new ScriptedRandomNumberGenerator(3, 3, 3), self::sheetOf(['hunger' => $hunger]), null, 0)->pool?->roll->dice() ?? []);

        self::assertSame(['pool', 'pool', 'pool'], $dice(0));
        self::assertSame(['pool', 'hunger', 'hunger'], $dice(2));
        self::assertSame(['hunger', 'hunger', 'hunger'], $dice(5));
    }

    #[Test]
    public function aHungerFormulaBelowZeroRollsNoHungerDiceAndNeverFails(): void
    {
        $check = $this->check(new CheckPool(3, 10, 6, 2, Formula::fromString('{hunger} - 3')));

        $result = $check->perform(new ScriptedRandomNumberGenerator(10, 1, 10), self::sheetOf(['hunger' => 1]), null, 0);

        self::assertSame(['pool', 'pool', 'pool'], array_map(static fn (PoolDie $die): string => $die->group()->value, $result->pool?->roll->dice() ?? []));
        self::assertSame(SpecialResult::Critical, $result->special);
    }

    #[Test]
    public function aHungerFormulaThatFailsRefusesTheCheck(): void
    {
        $check = $this->check(new CheckPool(3, 10, 6, null, Formula::fromString('{hunger} / {zero}')));

        $this->expectException(CheckEvaluationFailed::class);

        $check->perform(new ScriptedRandomNumberGenerator(3, 3, 3), self::sheetOf(['hunger' => 1, 'zero' => 0]), null, 0);
    }

    #[Test]
    public function aPushRerollsFailedRegularDiceOnlyAndRecomputesTheTotalBandAndSpecial(): void
    {
        $check = $this->vtm();
        $roll = $this->rolled($check, [2, 3, 2, 1, 3]);
        self::assertSame(0, $roll->total());

        $pushed = $check->push(new ScriptedRandomNumberGenerator(10, 10, 3), $this->sheet(), $roll, 3);

        self::assertSame([10, 10, 3, 1, 3], array_map(static fn (PoolDie $die): int => $die->face(), $pushed->roll->dice()));
        self::assertSame(4, $pushed->total);
        self::assertSame('win', $pushed->band->key);
        self::assertSame(SpecialResult::Critical, $pushed->special);
    }

    #[Test]
    public function aPushThatStillFailsWithAHungerOneIsABestialFailure(): void
    {
        $check = $this->vtm();

        $pushed = $check->push(new ScriptedRandomNumberGenerator(2, 3, 4), $this->sheet(), $this->rolled($check, [2, 3, 2, 1, 3]), 3);

        self::assertSame('fail', $pushed->band->key);
        self::assertSame(SpecialResult::BestialFailure, $pushed->special);
    }

    #[Test]
    public function theCapLimitsHowManyFailedRegularDiceAPushRerolls(): void
    {
        $check = $this->vtm(maxDice: 2);

        $pushed = $check->push(new ScriptedRandomNumberGenerator(6, 6), $this->sheet(), $this->rolled($check, [2, 3, 2, 3, 3], 5), 3);

        self::assertSame([6, 6, 2, 3, 3], array_map(static fn (PoolDie $die): int => $die->face(), $pushed->roll->dice()));
        self::assertSame([true, true, false, false, false], array_map(static fn (PoolDie $die): bool => $die->isRerolled(), $pushed->roll->dice()));
    }

    #[Test]
    public function aRollWhoseFailedDiceAreAllHungerDiceCannotBePushed(): void
    {
        $check = $this->vtm();

        $this->expectException(CheckNotPushable::class);

        $check->push(new ScriptedRandomNumberGenerator(), $this->sheet(), $this->rolled($check, [7, 7, 7, 1, 3]), 3);
    }

    #[Test]
    public function aPoolRefusesABonusOrHungerOutOfBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('1 to 10');

        new CheckPool(3, 10, 6, 11);
    }

    #[Test]
    public function aPoolRefusesANegativeHungerCountAndAPushANegativeCap(): void
    {
        try {
            new CheckPool(3, 10, 6, null, -1);
            self::fail('A negative Hunger count is refused.');
        } catch (\InvalidArgumentException $invalid) {
            self::assertStringContainsString('0 to 100', $invalid->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('1 to 100');

        new CheckPush('Spend', [], 0);
    }

    /**
     * @param list<int> $faces
     */
    private function rolled(Check $check, array $faces, int $size = 5): PoolRoll
    {
        $result = $check->perform(new ScriptedRandomNumberGenerator(...$faces), $this->sheet(), 3, 0);
        self::assertSame($size, $result->pool?->roll->size());
        self::assertNotNull($result->pool);

        return $result->pool->roll;
    }

    /**
     * @param list<CheckBand>|null $bands
     */
    private function check(CheckPool $pool, ?array $bands = null, ?CheckDifficulty $difficulty = null, ?CheckPush $push = null): Check
    {
        return new Check('persuade', 'Persuade', null, null, null, $difficulty, $bands ?? [new CheckBand('fail', 'Fail', 0), new CheckBand('win', 'Win')], $pool, $push);
    }

    private function vtm(?int $maxDice = null): Check
    {
        return new Check(
            'persuade',
            'Persuade',
            null,
            null,
            null,
            new CheckDifficulty('Difficulty', 3, 1, 10),
            [new CheckBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')), new CheckBand('win', 'Win')],
            new CheckPool(5, 10, 6, 2, 2),
            new CheckPush('Spend Willpower', [], $maxDice),
        );
    }

    private function sheet(): SheetValues
    {
        return self::sheetOf(['hunger' => 1, 'zero' => 0]);
    }

    /**
     * @param array<string, int> $values
     */
    private static function sheetOf(array $values): SheetValues
    {
        return SheetValues::of(new SheetTemplate([new NumberField('hunger', 'Hunger'), new NumberField('zero', 'Zero')]), $values + ['hunger' => 1, 'zero' => 0], []);
    }
}
