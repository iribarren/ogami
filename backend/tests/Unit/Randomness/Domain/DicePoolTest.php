<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain;

use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\InvalidDicePool;
use App\Randomness\Domain\PoolDie;
use App\Randomness\Domain\PoolRoll;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DicePool::class)]
#[CoversClass(PoolRoll::class)]
#[CoversClass(PoolDie::class)]
#[CoversClass(InvalidDicePool::class)]
final class DicePoolTest extends TestCase
{
    #[Test]
    public function itRollsEveryDieAndCountsTheOnesAtOrAboveTheThreshold(): void
    {
        $roll = new DicePool(5, 6, 5)->roll(new ScriptedRandomNumberGenerator(1, 4, 5, 6, 3));

        self::assertSame(5, $roll->size());
        self::assertSame([1, 4, 5, 6, 3], array_map(static fn (PoolDie $die): int => $die->face(), $roll->dice()));
        self::assertSame([false, false, true, true, false], array_map(static fn (PoolDie $die): bool => $die->isSuccess(), $roll->dice()));
        self::assertSame(2, $roll->successes());
    }

    #[Test]
    public function itExposesWhatWasRolled(): void
    {
        $roll = new DicePool(2, 10, 8)->roll(new ScriptedRandomNumberGenerator(8, 7));

        self::assertSame(10, $roll->pool()->sides);
        self::assertSame(8, $roll->pool()->successAt);
        self::assertSame(1, $roll->successes());
    }

    /**
     * @return iterable<string, array{int, int, list<int>, int}> sides, successAt, faces, successes
     */
    public static function thresholds(): iterable
    {
        yield 'the threshold face succeeds' => [6, 4, [4], 1];
        yield 'one face under fails' => [6, 4, [3], 0];
        yield 'the highest face succeeds' => [6, 6, [6, 5], 1];
        yield 'successAt 1 makes every die a success' => [6, 1, [1, 2, 6], 3];
        yield 'the lowest sides' => [2, 2, [1, 2], 1];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('thresholds')]
    public function itSucceedsFromTheThresholdUp(int $sides, int $successAt, array $faces, int $successes): void
    {
        $roll = new DicePool(\count($faces), $sides, $successAt)->roll(new ScriptedRandomNumberGenerator(...$faces));

        self::assertSame($successes, $roll->successes());
    }

    #[Test]
    public function itAcceptsTheLimits(): void
    {
        self::assertSame(100, new DicePool(100, 1000, 1000)->count);
        self::assertSame(1, new DicePool(1, 2, 1)->count);
    }

    /**
     * @return iterable<string, array{int, int, int}> count, sides, successAt
     */
    public static function invalid(): iterable
    {
        yield 'no dice' => [0, 6, 6];
        yield 'too many dice' => [101, 6, 6];
        yield 'a one-sided die' => [3, 1, 1];
        yield 'too many sides' => [3, 1001, 6];
        yield 'a threshold under 1' => [3, 6, 0];
        yield 'a threshold above the sides' => [3, 6, 7];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function itRefusesAPoolOutOfBounds(int $count, int $sides, int $successAt): void
    {
        $this->expectException(InvalidDicePool::class);

        new DicePool($count, $sides, $successAt);
    }
}
