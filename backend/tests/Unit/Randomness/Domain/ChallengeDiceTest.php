<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain;

use App\Randomness\Domain\ChallengeDice;
use App\Randomness\Domain\ChallengeRoll;
use App\Randomness\Domain\InvalidChallengeDice;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChallengeDice::class)]
#[CoversClass(ChallengeRoll::class)]
#[CoversClass(InvalidChallengeDice::class)]
final class ChallengeDiceTest extends TestCase
{
    #[Test]
    public function itRollsEveryDieInOrder(): void
    {
        $roll = new ChallengeDice(2, 10)->roll(new ScriptedRandomNumberGenerator(4, 9));

        self::assertSame([4, 9], $roll->faces());
        self::assertSame(10, $roll->dice()->sides);
    }

    /**
     * @return iterable<string, array{int, list<int>, int}> score, faces, dice beaten
     */
    public static function scores(): iterable
    {
        yield 'a score above both faces beats both' => [8, [3, 7], 2];
        yield 'a tie goes to the dice' => [7, [3, 7], 1];
        yield 'a score between the faces beats one' => [5, [3, 7], 1];
        yield 'a score under both faces beats none' => [2, [3, 7], 0];
        yield 'a score of zero beats none' => [0, [1, 1], 0];
        yield 'a negative score beats none' => [-3, [1, 5], 0];
        yield 'a score of one beats none, a face is at least 1' => [1, [1, 1], 0];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('scores')]
    public function theScoreBeatsTheFacesStrictlyBelowIt(int $score, array $faces, int $beaten): void
    {
        self::assertSame($beaten, ChallengeRoll::ofFaces(new ChallengeDice(2, 10), $faces)->beatenBy($score));
    }

    #[Test]
    public function itIsAMatchWhenEveryDieShowsTheSameFace(): void
    {
        $dice = new ChallengeDice(3, 6);

        self::assertTrue(ChallengeRoll::ofFaces($dice, [4, 4, 4])->isMatch());
        self::assertFalse(ChallengeRoll::ofFaces($dice, [4, 4, 5])->isMatch());
        self::assertFalse(ChallengeRoll::ofFaces($dice, [1, 4, 4])->isMatch());
    }

    /**
     * @return iterable<string, array{int, int}> count, sides
     */
    public static function badBounds(): iterable
    {
        yield 'one die' => [1, 10];
        yield 'eleven dice' => [11, 10];
        yield 'one side' => [2, 1];
        yield '1001 sides' => [2, 1001];
        yield 'negative count' => [-2, 10];
    }

    #[Test]
    #[DataProvider('badBounds')]
    public function itRefusesBoundsOutsideTheContract(int $count, int $sides): void
    {
        $this->expectException(InvalidChallengeDice::class);

        new ChallengeDice($count, $sides);
    }

    #[Test]
    public function itAcceptsTheLimits(): void
    {
        self::assertSame(10, new ChallengeDice(10, 1000)->count);
        self::assertSame(2, new ChallengeDice(2, 2)->count);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function badFaces(): iterable
    {
        yield 'too few faces' => [[3]];
        yield 'too many faces' => [[3, 4, 5]];
        yield 'a face of zero' => [[0, 4]];
        yield 'a face above the sides' => [[4, 11]];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('badFaces')]
    public function aStoredRollNeedsOneFaceInRangePerDie(array $faces): void
    {
        $this->expectException(InvalidChallengeDice::class);

        ChallengeRoll::ofFaces(new ChallengeDice(2, 10), $faces);
    }
}
