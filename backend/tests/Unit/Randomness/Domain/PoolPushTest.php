<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain;

use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\InvalidDicePool;
use App\Randomness\Domain\PoolDie;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\PoolRollNotPushable;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolRoll::class)]
#[CoversClass(PoolDie::class)]
#[CoversClass(PoolRollNotPushable::class)]
#[CoversClass(InvalidDicePool::class)]
final class PoolPushTest extends TestCase
{
    #[Test]
    public function aPushRerollsEveryDieThatFailedOnceAndKeepsTheSuccessesInPlace(): void
    {
        $roll = new DicePool(5, 6, 5)->roll(new ScriptedRandomNumberGenerator(1, 6, 4, 5, 3));

        $pushed = $roll->push(new ScriptedRandomNumberGenerator(6, 2, 1));

        self::assertSame(5, $pushed->size());
        self::assertSame([6, 6, 2, 5, 1], array_map(static fn (PoolDie $die): int => $die->face(), $pushed->dice()));
        self::assertSame([true, true, false, true, false], array_map(static fn (PoolDie $die): bool => $die->isSuccess(), $pushed->dice()));
        self::assertSame([true, false, true, false, true], array_map(static fn (PoolDie $die): bool => $die->isRerolled(), $pushed->dice()));
        self::assertSame(3, $pushed->successes());
        self::assertSame($roll->pool(), $pushed->pool());
    }

    #[Test]
    public function aPushDoesNotChangeTheRollItPushes(): void
    {
        $roll = new DicePool(2, 6, 6)->roll(new ScriptedRandomNumberGenerator(1, 6));

        $roll->push(new ScriptedRandomNumberGenerator(6));

        self::assertSame(1, $roll->successes());
        self::assertSame([false, false], array_map(static fn (PoolDie $die): bool => $die->isRerolled(), $roll->dice()));
        self::assertSame(1, $roll->failures());
    }

    #[Test]
    public function aRollWithoutAFailedDieCannotBePushed(): void
    {
        $roll = new DicePool(2, 6, 5)->roll(new ScriptedRandomNumberGenerator(5, 6));

        $this->expectException(PoolRollNotPushable::class);
        $this->expectExceptionMessageIsOrContains('every die succeeded');

        $roll->push(new ScriptedRandomNumberGenerator());
    }

    #[Test]
    public function aPushedRollKeepsTheRerolledDiceMarkedAndCountsTheDiceStillFailing(): void
    {
        $pushed = new DicePool(1, 6, 6)->roll(new ScriptedRandomNumberGenerator(1))->push(new ScriptedRandomNumberGenerator(2));

        self::assertSame([true], array_map(static fn (PoolDie $die): bool => $die->isRerolled(), $pushed->dice()));
        self::assertSame(1, $pushed->failures());
    }

    #[Test]
    public function aRollIsRebuiltFromItsStoredFaces(): void
    {
        $pool = new DicePool(3, 6, 5);

        $roll = PoolRoll::ofFaces($pool, [6, 2, 5]);

        self::assertEquals($pool->roll(new ScriptedRandomNumberGenerator(6, 2, 5)), $roll);
        self::assertSame(2, $roll->successes());
        self::assertSame(1, $roll->failures());
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('badFaces')]
    public function storedFacesThatDoNotFitThePoolAreRefused(array $faces, string $message): void
    {
        $this->expectException(InvalidDicePool::class);
        $this->expectExceptionMessageIsOrContains($message);

        PoolRoll::ofFaces(new DicePool(2, 6, 5), $faces);
    }

    /**
     * @return iterable<string, array{list<int>, string}>
     */
    public static function badFaces(): iterable
    {
        yield 'too few' => [[6], 'holds 2 dice, 1 faces given'];
        yield 'too many' => [[6, 5, 4], 'holds 2 dice, 3 faces given'];
        yield 'a face over the sides' => [[6, 7], 'face between 1 and 6, 7 given'];
        yield 'a face under 1' => [[0, 6], 'face between 1 and 6, 0 given'];
    }
}
