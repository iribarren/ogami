<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain\Oracle;

use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\LikelihoodChaos;
use App\Randomness\Domain\Oracle\LikelihoodLevel;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LikelihoodOracle::class)]
#[CoversClass(LikelihoodLevel::class)]
#[CoversClass(LikelihoodChaos::class)]
#[CoversClass(LikelihoodAnswer::class)]
#[CoversClass(YesNoAnswer::class)]
#[CoversClass(InvalidLikelihoodOracle::class)]
final class LikelihoodOracleAskTest extends TestCase
{
    /**
     * A d100 oracle with a 1–9 chaos factor (neutral 5, 5 points per step) and 20% exceptional bands.
     *
     * @return array<string, mixed>
     */
    private static function definition(int $exceptionalPercent = 20, bool $chaos = true): array
    {
        $definition = [
            'sides' => 100,
            'levels' => [
                ['key' => 'impossible', 'label' => 'Impossible', 'target' => 0],
                ['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35],
                ['key' => 'likely', 'label' => 'Likely', 'target' => 65],
                ['key' => 'certain', 'label' => 'Certain', 'target' => 100],
            ],
            'exceptionalPercent' => $exceptionalPercent,
        ];

        if ($chaos) {
            $definition['chaos'] = ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5];
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function ask(array $definition, string $level, ?int $chaosFactor, int $roll): LikelihoodAnswer
    {
        return LikelihoodOracle::fromArray($definition)->ask($level, $chaosFactor, new ScriptedRandomNumberGenerator($roll));
    }

    /**
     * Likely (65) at neutral chaos, 20%: exceptional yes up to 13, yes up to 65, no up to 93,
     * exceptional no from 94.
     *
     * @return iterable<string, array{int, YesNoAnswer}>
     */
    public static function bands(): iterable
    {
        yield 'lowest roll' => [1, YesNoAnswer::ExceptionalYes];
        yield 'top of the exceptional yes band' => [13, YesNoAnswer::ExceptionalYes];
        yield 'just above the exceptional yes band' => [14, YesNoAnswer::Yes];
        yield 'roll equal to the target' => [65, YesNoAnswer::Yes];
        yield 'roll just above the target' => [66, YesNoAnswer::No];
        yield 'just below the exceptional no band' => [93, YesNoAnswer::No];
        yield 'bottom of the exceptional no band' => [94, YesNoAnswer::ExceptionalNo];
        yield 'highest roll' => [100, YesNoAnswer::ExceptionalNo];
    }

    #[Test]
    #[DataProvider('bands')]
    public function itAnswersByComparingTheRollWithTheTargetAndTheExceptionalBands(int $roll, YesNoAnswer $expected): void
    {
        $answer = $this->ask(self::definition(), 'likely', null, $roll);

        self::assertSame($expected, $answer->answer());
        self::assertSame($roll, $answer->roll());
        self::assertSame(65, $answer->effectiveTarget());
    }

    #[Test]
    public function itExposesTheLevelTheSidesAndTheChaosFactorUsed(): void
    {
        $answer = $this->ask(self::definition(), 'unlikely', 7, 30);

        self::assertSame('unlikely', $answer->levelKey());
        self::assertSame('Unlikely', $answer->levelLabel());
        self::assertSame(100, $answer->sides());
        self::assertSame(7, $answer->chaosFactor());
        self::assertSame(45, $answer->effectiveTarget());
        self::assertSame(YesNoAnswer::Yes, $answer->answer());
    }

    #[Test]
    public function itUsesTheNeutralChaosFactorWhenNoneIsGiven(): void
    {
        $answer = $this->ask(self::definition(), 'unlikely', null, 35);

        self::assertSame(5, $answer->chaosFactor());
        self::assertSame(35, $answer->effectiveTarget());
        self::assertSame(YesNoAnswer::Yes, $answer->answer());
    }

    #[Test]
    public function itHasNoChaosFactorWithoutChaos(): void
    {
        $answer = $this->ask(self::definition(chaos: false), 'unlikely', null, 36);

        self::assertNull($answer->chaosFactor());
        self::assertSame(35, $answer->effectiveTarget());
        self::assertSame(YesNoAnswer::No, $answer->answer());
    }

    /**
     * @return iterable<string, array{int, int, int, YesNoAnswer}>
     */
    public static function chaosShifts(): iterable
    {
        yield 'highest chaos, roll at the raised target' => [9, 85, 85, YesNoAnswer::Yes];
        yield 'highest chaos, roll above the raised target' => [9, 86, 85, YesNoAnswer::No];
        yield 'lowest chaos, roll at the lowered target' => [1, 45, 45, YesNoAnswer::Yes];
        yield 'lowest chaos, roll above the lowered target' => [1, 46, 45, YesNoAnswer::No];
        yield 'one step up' => [6, 70, 70, YesNoAnswer::Yes];
    }

    #[Test]
    #[DataProvider('chaosShifts')]
    public function theChaosFactorShiftsTheTargetLinearly(int $chaosFactor, int $roll, int $target, YesNoAnswer $expected): void
    {
        $answer = $this->ask(self::definition(exceptionalPercent: 0), 'likely', $chaosFactor, $roll);

        self::assertSame($target, $answer->effectiveTarget());
        self::assertSame($expected, $answer->answer());
    }

    #[Test]
    public function theExceptionalBandsFollowTheShiftedTarget(): void
    {
        // Likely at chaos 9: T = 85, exceptional yes up to floor(85 × 20 / 100) = 17.
        self::assertSame(YesNoAnswer::ExceptionalYes, $this->ask(self::definition(), 'likely', 9, 17)->answer());
        self::assertSame(YesNoAnswer::Yes, $this->ask(self::definition(), 'likely', 9, 18)->answer());
        // Exceptional no above 100 − floor(15 × 20 / 100) = 97.
        self::assertSame(YesNoAnswer::No, $this->ask(self::definition(), 'likely', 9, 97)->answer());
        self::assertSame(YesNoAnswer::ExceptionalNo, $this->ask(self::definition(), 'likely', 9, 98)->answer());
    }

    #[Test]
    public function theTargetIsClampedToTheSides(): void
    {
        $answer = $this->ask(self::definition(), 'certain', 9, 100);

        self::assertSame(100, $answer->effectiveTarget());
        self::assertSame(YesNoAnswer::Yes, $answer->answer());
        // T = sides: exceptional yes up to 20, and no exceptional no band.
        self::assertSame(YesNoAnswer::ExceptionalYes, $this->ask(self::definition(), 'certain', 9, 20)->answer());
        self::assertSame(YesNoAnswer::Yes, $this->ask(self::definition(), 'certain', 9, 21)->answer());
    }

    #[Test]
    public function theTargetIsClampedToZero(): void
    {
        $answer = $this->ask(self::definition(), 'impossible', 1, 1);

        self::assertSame(0, $answer->effectiveTarget());
        // T = 0: no exceptional yes band, exceptional no above 100 − floor(100 × 20 / 100) = 80.
        self::assertSame(YesNoAnswer::No, $answer->answer());
        self::assertSame(YesNoAnswer::No, $this->ask(self::definition(), 'impossible', 1, 80)->answer());
        self::assertSame(YesNoAnswer::ExceptionalNo, $this->ask(self::definition(), 'impossible', 1, 81)->answer());
    }

    /**
     * @return iterable<string, array{int, YesNoAnswer}>
     */
    public static function rollsWithoutExceptionalBands(): iterable
    {
        yield 'lowest roll' => [1, YesNoAnswer::Yes];
        yield 'roll at the target' => [65, YesNoAnswer::Yes];
        yield 'roll above the target' => [66, YesNoAnswer::No];
        yield 'highest roll' => [100, YesNoAnswer::No];
    }

    #[Test]
    #[DataProvider('rollsWithoutExceptionalBands')]
    public function anExceptionalPercentOfZeroNeverAnswersExceptionally(int $roll, YesNoAnswer $expected): void
    {
        self::assertSame($expected, $this->ask(self::definition(exceptionalPercent: 0), 'likely', null, $roll)->answer());
    }

    #[Test]
    public function theExceptionalPercentDefaultsToZero(): void
    {
        $definition = self::definition();
        unset($definition['exceptionalPercent']);

        self::assertSame(YesNoAnswer::Yes, $this->ask($definition, 'likely', null, 1)->answer());
        self::assertSame(YesNoAnswer::No, $this->ask($definition, 'likely', null, 100)->answer());
    }

    #[Test]
    public function theExceptionalBandsRoundDown(): void
    {
        $definition = ['sides' => 10, 'levels' => [['key' => 'odd', 'label' => 'Odd', 'target' => 3]], 'exceptionalPercent' => 50];

        // Exceptional yes up to floor(3 × 50 / 100) = 1; exceptional no above 10 − floor(7 × 50 / 100) = 7.
        self::assertSame(YesNoAnswer::ExceptionalYes, $this->ask($definition, 'odd', null, 1)->answer());
        self::assertSame(YesNoAnswer::Yes, $this->ask($definition, 'odd', null, 2)->answer());
        self::assertSame(YesNoAnswer::No, $this->ask($definition, 'odd', null, 7)->answer());
        self::assertSame(YesNoAnswer::ExceptionalNo, $this->ask($definition, 'odd', null, 8)->answer());
    }

    #[Test]
    public function itRollsOneDieWithTheOraclesSides(): void
    {
        $definition = ['sides' => 6, 'levels' => [['key' => 'even', 'label' => 'Even odds', 'target' => 3]]];

        // The scripted generator rejects a number outside the requested range.
        self::assertSame(YesNoAnswer::No, $this->ask($definition, 'even', null, 6)->answer());
        $this->expectException(\LogicException::class);
        $this->ask($definition, 'even', null, 7);
    }

    /**
     * @return iterable<string, array{string, YesNoAnswer, bool}>
     */
    public static function answerValues(): iterable
    {
        yield 'exceptional yes' => ['exceptional_yes', YesNoAnswer::ExceptionalYes, true];
        yield 'yes' => ['yes', YesNoAnswer::Yes, true];
        yield 'no' => ['no', YesNoAnswer::No, false];
        yield 'exceptional no' => ['exceptional_no', YesNoAnswer::ExceptionalNo, false];
    }

    #[Test]
    #[DataProvider('answerValues')]
    public function theAnswersHaveStableValues(string $value, YesNoAnswer $answer, bool $isYes): void
    {
        self::assertSame($answer, YesNoAnswer::from($value));
        self::assertSame($isYes, $answer->isYes());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, ?int, string}>
     */
    public static function invalidQuestions(): iterable
    {
        yield 'unknown level' => [self::definition(), 'maybe', null, 'There is no likelihood level "maybe"; the levels are "impossible", "unlikely", "likely", "certain".'];
        yield 'chaos factor below the minimum' => [self::definition(), 'likely', 0, 'The chaos factor is between 1 and 9, 0 given.'];
        yield 'chaos factor above the maximum' => [self::definition(), 'likely', 10, 'The chaos factor is between 1 and 9, 10 given.'];
        yield 'chaos factor without chaos' => [self::definition(chaos: false), 'likely', 5, 'This likelihood oracle has no chaos factor, 5 given.'];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[Test]
    #[DataProvider('invalidQuestions')]
    public function itRejectsInvalidQuestions(array $definition, string $level, ?int $chaosFactor, string $message): void
    {
        $oracle = LikelihoodOracle::fromArray($definition);

        $this->expectException(InvalidLikelihoodOracle::class);
        $this->expectExceptionMessageIsOrContains($message);

        $oracle->ask($level, $chaosFactor, new ScriptedRandomNumberGenerator(50));
    }

    #[Test]
    public function itAcceptsTheChaosBounds(): void
    {
        self::assertSame(1, $this->ask(self::definition(), 'likely', 1, 50)->chaosFactor());
        self::assertSame(9, $this->ask(self::definition(), 'likely', 9, 50)->chaosFactor());
    }
}
