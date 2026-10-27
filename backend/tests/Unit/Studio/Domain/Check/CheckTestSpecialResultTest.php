<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Check;

use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\PoolRoll;
use App\Studio\Domain\Check\CheckTestSpecialResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The special result of a test roll in every case of ADR 0044, as Play's Check::specialOf() gives it:
 * a pool of four d10 dice, success on 6+, the last of them a Hunger die when the pool has one, and a
 * critical pair worth 2.
 */
#[CoversClass(CheckTestSpecialResult::class)]
final class CheckTestSpecialResultTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, int, int, bool, ?CheckTestSpecialResult}> faces in roll order, Hunger dice, critical bonus, failed, the special result
     */
    public static function rolls(): iterable
    {
        yield 'a critical pair of regular dice' => [[10, 10, 2, 3], 1, 2, false, CheckTestSpecialResult::Critical];
        yield 'a critical pair with a Hunger die' => [[10, 2, 3, 10], 1, 2, false, CheckTestSpecialResult::MessyCritical];
        yield 'a messy critical wins over a critical' => [[10, 10, 10, 10], 1, 2, false, CheckTestSpecialResult::MessyCritical];
        yield 'a failed roll with a Hunger 1' => [[2, 3, 4, 1], 1, 2, true, CheckTestSpecialResult::BestialFailure];
        yield 'a Hunger 1 on a roll that did not fail' => [[6, 6, 4, 1], 1, 2, false, null];
        yield 'a failed roll without a Hunger 1' => [[2, 3, 4, 5], 1, 2, true, null];
        yield 'no critical bonus, no critical' => [[10, 10, 2, 3], 1, 0, false, null];
        yield 'a pair of 10s on a failed roll is no critical' => [[10, 10, 2, 3], 0, 2, true, null];
        yield 'without Hunger dice there is no bestial failure' => [[2, 3, 4, 1], 0, 2, true, null];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('rolls')]
    public function theRollHasTheSpecialResultAdr0044Gives(array $faces, int $hunger, int $bonus, bool $failed, ?CheckTestSpecialResult $special): void
    {
        $roll = PoolRoll::ofFaces(new DicePool(4, 10, 6, $hunger, $bonus), $faces);

        self::assertSame($special, CheckTestSpecialResult::of($roll, $failed));
    }
}
