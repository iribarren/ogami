<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain;

use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\DieGroup;
use App\Randomness\Domain\InvalidDicePool;
use App\Randomness\Domain\PoolDie;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\PoolRollNotPushable;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hunger dice, critical pairs and the capped push of a pool (ADR 0044). The dice are d10, success
 * at 6, critical bonus 2, as in VtM.
 */
#[CoversClass(DicePool::class)]
#[CoversClass(PoolRoll::class)]
#[CoversClass(PoolDie::class)]
#[CoversClass(DieGroup::class)]
#[CoversClass(InvalidDicePool::class)]
#[CoversClass(PoolRollNotPushable::class)]
final class HungerDicePoolTest extends TestCase
{
    /**
     * @param list<int> $faces in roll order: the regular dice, then the Hunger dice
     */
    private function roll(int $count, int $hunger, array $faces, int $bonus = 2): PoolRoll
    {
        return new DicePool($count, 10, 6, $hunger, $bonus)->roll(new ScriptedRandomNumberGenerator(...$faces));
    }

    /**
     * @return list<string>
     */
    private function diceGroups(PoolRoll $roll): array
    {
        return array_map(static fn (PoolDie $die): string => $die->group()->value, $roll->dice());
    }

    #[Test]
    public function theRegularDiceAreRolledBeforeTheHungerDice(): void
    {
        $roll = $this->roll(5, 2, [7, 3, 9, 1, 10]);

        self::assertSame(['pool', 'pool', 'pool', 'hunger', 'hunger'], $this->diceGroups($roll));
        self::assertSame([7, 3, 9, 1, 10], array_map(static fn (PoolDie $die): int => $die->face(), $roll->dice()));
        self::assertSame(3, $roll->successes());
    }

    #[Test]
    public function aPoolWithoutHungerOrCriticalsRollsAsBefore(): void
    {
        $roll = new DicePool(3, 10, 6)->roll(new ScriptedRandomNumberGenerator(10, 10, 2));

        self::assertSame(['pool', 'pool', 'pool'], $this->diceGroups($roll));
        self::assertSame(0, new DicePool(3, 10, 6)->hunger);
        self::assertSame(2, $roll->successes());
        self::assertSame(2, $roll->total(), 'Without a critical bonus a pair adds nothing.');
        self::assertFalse($roll->hasCriticalPair());
    }

    /**
     * @return iterable<string, array{int, int, int}> pool size, Hunger asked, Hunger dice rolled
     */
    public static function hungerCounts(): iterable
    {
        yield 'within the pool' => [5, 2, 2];
        yield 'zero' => [5, 0, 0];
        yield 'a negative count rolls none' => [5, -3, 0];
        yield 'the whole pool' => [5, 5, 5];
        yield 'more than the pool is the whole pool' => [3, 9, 3];
    }

    #[Test]
    #[DataProvider('hungerCounts')]
    public function theHungerCountIsClampedToThePool(int $size, int $asked, int $rolled): void
    {
        $roll = $this->roll($size, $asked, array_fill(0, $size, 3));

        self::assertSame($rolled, new DicePool($size, 10, 6, $asked)->hunger);
        self::assertCount($rolled, array_filter($roll->dice(), static fn (PoolDie $die): bool => $die->isHunger()));
    }

    #[Test]
    public function aNegativeCriticalBonusIsRefused(): void
    {
        $this->expectException(InvalidDicePool::class);

        new DicePool(3, 10, 6, 0, -1);
    }

    /**
     * @return iterable<string, array{list<int>, int, int, bool, bool}> faces (4 regular, 2 Hunger), pairs, total, a pair counts, a pair holds a Hunger die
     */
    public static function pairs(): iterable
    {
        yield 'no top face' => [[6, 7, 2, 1, 9, 3], 0, 3, false, false];
        yield 'a lone top face is a plain success' => [[10, 7, 2, 1, 9, 3], 0, 3, false, false];
        yield 'a pair of regular dice' => [[10, 10, 2, 1, 3, 3], 1, 4, true, false];
        yield 'a regular and a Hunger die make a pair' => [[10, 2, 2, 1, 10, 3], 1, 4, true, true];
        yield 'two Hunger dice make a pair' => [[2, 2, 2, 1, 10, 10], 1, 4, true, true];
        yield 'Hunger dice pair first' => [[10, 10, 10, 1, 10, 10], 2, 9, true, true];
        yield 'three top faces make one pair' => [[10, 10, 10, 2, 3, 3], 1, 5, true, false];
        yield 'four top faces make two pairs' => [[10, 10, 10, 10, 3, 3], 2, 8, true, false];
        yield 'an odd Hunger top face pairs with a regular one' => [[10, 10, 10, 2, 10, 3], 2, 8, true, true];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('pairs')]
    public function everyPairOfTopFacesAddsTheBonus(array $faces, int $pairs, int $total, bool $counts, bool $hungerPair): void
    {
        $roll = $this->roll(6, 2, $faces);

        self::assertSame($pairs, $roll->criticalPairs());
        self::assertSame($total, $roll->total());
        self::assertSame($counts, $roll->hasCriticalPair());
        self::assertSame($hungerPair, $roll->hasHungerCriticalPair());
    }

    #[Test]
    public function aPairWorthNothingWithoutABonusIsNotACriticalPair(): void
    {
        $roll = $this->roll(4, 1, [10, 10, 2, 3], 0);

        self::assertSame(1, $roll->criticalPairs());
        self::assertFalse($roll->hasCriticalPair());
        self::assertFalse($roll->hasHungerCriticalPair());
        self::assertSame($roll->successes(), $roll->total());
    }

    #[Test]
    public function theTopFaceIsTheSidesOfThePoolNotTen(): void
    {
        $roll = new DicePool(3, 6, 5, 0, 1)->roll(new ScriptedRandomNumberGenerator(6, 6, 5));

        self::assertSame(1, $roll->criticalPairs());
        self::assertSame(4, $roll->total());
    }

    #[Test]
    public function aHungerOneIsReportedOnlyOnAHungerDie(): void
    {
        self::assertTrue($this->roll(4, 1, [6, 6, 6, 1])->hasHungerOne());
        self::assertFalse($this->roll(4, 1, [1, 1, 1, 6])->hasHungerOne());
        self::assertFalse($this->roll(4, 0, [1, 1, 1, 1])->hasHungerOne());
    }

    #[Test]
    public function aPushRerollsOnlyFailedRegularDiceAndNeverHungerDice(): void
    {
        $roll = $this->roll(5, 2, [2, 8, 3, 1, 4]);

        $pushed = $roll->push(new ScriptedRandomNumberGenerator(9, 10));

        self::assertSame([9, 8, 10, 1, 4], array_map(static fn (PoolDie $die): int => $die->face(), $pushed->dice()));
        self::assertSame([true, false, true, false, false], array_map(static fn (PoolDie $die): bool => $die->isRerolled(), $pushed->dice()));
        self::assertSame(['pool', 'pool', 'pool', 'hunger', 'hunger'], $this->diceGroups($pushed));
    }

    #[Test]
    public function aPushCapRerollsTheFirstFailedRegularDiceInRollOrder(): void
    {
        $roll = $this->roll(6, 1, [2, 8, 3, 4, 1, 1]);

        $pushed = $roll->push(new ScriptedRandomNumberGenerator(9, 9), 2);

        self::assertSame([9, 8, 9, 4, 1, 1], array_map(static fn (PoolDie $die): int => $die->face(), $pushed->dice()));
        self::assertSame(2, $pushed->regularFailures(), 'The dice after the cap stay failed.');
    }

    #[Test]
    public function aCapAboveTheFailedDiceRerollsThemAll(): void
    {
        $pushed = $this->roll(4, 0, [2, 3, 8, 8])->push(new ScriptedRandomNumberGenerator(7, 7), 3);

        self::assertCount(2, array_filter($pushed->dice(), static fn (PoolDie $die): bool => $die->isRerolled()));
    }

    #[Test]
    public function aCapUnderOneIsRefused(): void
    {
        $this->expectException(InvalidDicePool::class);

        $this->roll(3, 0, [2, 3, 8])->push(new ScriptedRandomNumberGenerator(), 0);
    }

    #[Test]
    public function aRollWhoseFailedDiceAreAllHungerDiceCannotBePushed(): void
    {
        $roll = $this->roll(4, 2, [8, 9, 1, 2]);

        self::assertSame(0, $roll->regularFailures());
        self::assertSame(2, $roll->failures());
        self::assertFalse($roll->isPushable());

        $this->expectException(PoolRollNotPushable::class);
        $this->expectExceptionMessageIsOrContains('no regular die failed');

        $roll->push(new ScriptedRandomNumberGenerator());
    }

    #[Test]
    public function aPushRecomputesTheTotalAndThePairsFromTheDiceAsTheyStand(): void
    {
        $roll = $this->roll(4, 1, [10, 2, 3, 10]);
        self::assertSame(4, $roll->total());
        self::assertTrue($roll->hasHungerCriticalPair());

        $pushed = $roll->push(new ScriptedRandomNumberGenerator(10, 10));

        self::assertSame(2, $pushed->criticalPairs());
        self::assertSame(4 + 4, $pushed->total());
    }

    #[Test]
    public function aRollIsRebuiltFromItsStoredFacesWithItsGroups(): void
    {
        $pool = new DicePool(4, 10, 6, 1, 2);

        $roll = PoolRoll::ofFaces($pool, [10, 3, 7, 10]);

        self::assertEquals($pool->roll(new ScriptedRandomNumberGenerator(10, 3, 7, 10)), $roll);
        self::assertSame(['pool', 'pool', 'pool', 'hunger'], $this->diceGroups($roll));
        self::assertSame(5, $roll->total());
    }
}
