<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Randomness\Domain\DiceExpression;
use App\Studio\Domain\Check\CheckDifficultyRange;
use App\Studio\Domain\Check\CheckTestRoll;
use App\Studio\Domain\Check\CheckTestRollFailed;
use App\Studio\Domain\Check\CheckTestRollResult;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\Check\OutcomeBand;
use App\Studio\Domain\Check\SampleSheet;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test-rolling a check in Play's order (the cases of Play's CheckTest, which this must agree with):
 * hands 4 (default), no zero, edge = hands / 2, a skill check that rolls 1d10, adds {hands} + {edge}
 * (6) and the situational modifier, and compares the total with bands bounded by the difficulty
 * (default 13, 9 to 29).
 */
#[CoversClass(CheckTestRoll::class)]
#[CoversClass(CheckTestRollResult::class)]
#[CoversClass(CheckDifficultyRange::class)]
#[CoversClass(OutcomeBand::class)]
#[CoversClass(CheckTestRollFailed::class)]
#[CoversClass(InvalidTestRoll::class)]
final class CheckTestRollTest extends TestCase
{
    #[Test]
    public function itAddsTheModifierAndTheSituationalModifierAndPicksTheBand(): void
    {
        $result = $this->skill()->perform(new ScriptedRandomNumberGenerator(7), $this->sheet(), null, 2);

        self::assertSame(7, $result->roll->total());
        self::assertSame([6, 2, 13, 15], [$result->modifier, $result->situational, $result->difficulty, $result->total]);
        self::assertSame('success', $result->band->key, 'Fail up to 12, success up to 17.');
        self::assertSame([12, 17, null], $result->bounds);
        self::assertSame(['fail', 'success', 'crit'], array_map(static fn (OutcomeBand $band): string => $band->key, $result->bands));
    }

    /**
     * @return iterable<string, array{int, int, ?int, string}> dice, hands, difficulty, band
     */
    public static function totals(): iterable
    {
        yield 'at the bound of a band' => [6, 4, null, 'fail']; // 6 + 6 = 12 = 13 - 1
        yield 'just above it' => [7, 4, null, 'success'];
        yield 'at the bound of the next band' => [10, 5, null, 'success']; // 10 + 7 = 17 = 13 + 4
        yield 'above every bound' => [10, 10, null, 'crit']; // 10 + 15
        yield 'a higher difficulty moves the bounds' => [7, 4, 20, 'fail']; // 13 <= 19
    }

    #[Test]
    #[DataProvider('totals')]
    public function theFirstBandWhoseBoundIsAtLeastTheTotalWinsAndTheLastCatchesTheRest(int $dice, int $hands, ?int $difficulty, string $band): void
    {
        $result = $this->skill()->perform(new ScriptedRandomNumberGenerator($dice), $this->sheet($hands), $difficulty, 0);

        self::assertSame($band, $result->band->key);
    }

    #[Test]
    public function aDifficultyOfTheRangeIsUsedAsGivenAndTheBoundsFollowIt(): void
    {
        foreach ([9 => [8, 13], 29 => [28, 33]] as $difficulty => $bounds) {
            $result = $this->skill()->perform(new ScriptedRandomNumberGenerator(1), $this->sheet(), $difficulty, 0);

            self::assertSame($difficulty, $result->difficulty);
            self::assertSame([...$bounds, null], $result->bounds);
        }
    }

    #[Test]
    public function boundsThatDoNotIncreaseForTheSamplesAreReturnedAsTheyAre(): void
    {
        $check = new CheckTestRoll('c', DiceExpression::fromString('1d6'), null, null, [
            new OutcomeBand('a', 'A', Formula::fromString('{hands}+5')),
            new OutcomeBand('b', 'B', 3),
            new OutcomeBand('c', 'C'),
        ]);

        $result = $check->perform(new ScriptedRandomNumberGenerator(4), $this->sheet(), null, 0);

        self::assertSame([9, 3, null], $result->bounds);
        self::assertSame('a', $result->band->key, 'The first band whose bound is at least the total wins.');
    }

    #[Test]
    public function aCheckWithoutADifficultyOrModifierReportsNoDifficultyAndAZeroModifier(): void
    {
        $luck = new CheckTestRoll('luck', DiceExpression::fromString('d%'), null, null, [new OutcomeBand('bad', 'Bad', 20), new OutcomeBand('good', 'Good')]);

        $result = $luck->perform(new ScriptedRandomNumberGenerator(21), $this->sheet(), null, -3);

        self::assertSame([0, -3, null, 18, 'bad'], [$result->modifier, $result->situational, $result->difficulty, $result->total, $result->band->key]);
    }

    #[Test]
    public function aDifficultyGivenToACheckThatDeclaresNoneIsRefusedOnItsPath(): void
    {
        $luck = new CheckTestRoll('luck', DiceExpression::fromString('d%'), null, null, [new OutcomeBand('only', 'Only')]);

        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains('difficulty: check "luck" declares no difficulty');

        $luck->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), 5, 0);
    }

    /**
     * @return iterable<string, array{CheckDifficultyRange, int, string}>
     */
    public static function difficultiesOutOfRange(): iterable
    {
        yield 'below the min' => [new CheckDifficultyRange(13, 9, 29), 8, 'must be 9 to 29, got 8'];
        yield 'above the max' => [new CheckDifficultyRange(13, 9, 29), 30, 'must be 9 to 29, got 30'];
        yield 'only a min' => [new CheckDifficultyRange(5, min: 3), 2, 'must be at least 3, got 2'];
        yield 'only a max' => [new CheckDifficultyRange(5, max: 8), 9, 'must be at most 8, got 9'];
    }

    #[Test]
    #[DataProvider('difficultiesOutOfRange')]
    public function aDifficultyOutsideTheDeclaredRangeIsRefusedBeforeRolling(CheckDifficultyRange $range, int $difficulty, string $message): void
    {
        $check = new CheckTestRoll('c', DiceExpression::fromString('1d6'), null, $range, [new OutcomeBand('only', 'Only')]);

        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains('difficulty: '.$message);

        // A generator with no number left throws a LogicException if the dice are rolled.
        $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), $difficulty, 0);
    }

    #[Test]
    #[DataProvider('situationalModifiersOutOfRange')]
    public function aSituationalModifierOutsideMinus100To100IsRefusedOnItsPath(int $situational): void
    {
        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains('situational: must be -100 to 100, got '.$situational);

        $this->skill()->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, $situational);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function situationalModifiersOutOfRange(): iterable
    {
        yield 'below' => [-101];
        yield 'above' => [101];
    }

    #[Test]
    public function aSituationalModifierAtTheLimitsIsAccepted(): void
    {
        self::assertSame(-100, $this->skill()->perform(new ScriptedRandomNumberGenerator(1), $this->sheet(), null, -100)->situational);
        self::assertSame(100, $this->skill()->perform(new ScriptedRandomNumberGenerator(1), $this->sheet(), null, 100)->situational);
    }

    #[Test]
    public function aTotalThatOverflowsAnIntegerFailsInsteadOfBecomingAFloat(): void
    {
        // The dice total stays below PHP_INT_MAX, the modifier (up to 10^9) pushes the sum past it.
        $check = new CheckTestRoll('huge', DiceExpression::fromString('9*1000000*1000000*1000000+223372*1000000*1000000+36854*1000000+773759'), Formula::fromString('{hands} * 1000000 * 1000'), null, [new OutcomeBand('only', 'Only')]);

        try {
            $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(1), null, 0);
            self::fail('The total overflows.');
        } catch (CheckTestRollFailed $failed) {
            self::assertSame('Check "huge" cannot be test-rolled: the total of the check overflows.', $failed->getMessage());
        }
    }

    /**
     * @return iterable<string, array{?string, list<OutcomeBand>}>
     */
    public static function evaluationFailures(): iterable
    {
        $bands = [new OutcomeBand('low', 'Low', 5), new OutcomeBand('high', 'High')];

        yield 'a modifier dividing by zero' => ['{hands} / {zero}', $bands];
        yield 'a modifier reading the difficulty' => ['{check:difficulty}', $bands];
        yield 'a modifier reading a field the sheet lacks' => ['{ghost}', $bands];
        yield 'a modifier reading a derived value that failed' => ['{broken}', $bands];
        yield 'a band dividing by zero' => [null, [new OutcomeBand('low', 'Low', Formula::fromString('{hands} / {zero}')), new OutcomeBand('high', 'High')]];
        yield 'a later band that fails' => [null, [new OutcomeBand('low', 'Low', 1), new OutcomeBand('mid', 'Mid', Formula::fromString('{hands} / {zero}')), new OutcomeBand('high', 'High')]];
        yield 'a band reading another check value' => [null, [new OutcomeBand('low', 'Low', Formula::fromString('{check:other}')), new OutcomeBand('high', 'High')]];
        yield 'a band reading the difficulty of a check that has none' => [null, [new OutcomeBand('low', 'Low', Formula::fromString('{check:difficulty}')), new OutcomeBand('high', 'High')]];
        yield 'an overflow' => [null, [new OutcomeBand('low', 'Low', Formula::fromString('{hands} * 1000000 * 1000')), new OutcomeBand('high', 'High')]];
    }

    /**
     * @param non-empty-list<OutcomeBand> $bands
     */
    #[Test]
    #[DataProvider('evaluationFailures')]
    public function aFormulaThatFailsRollsNothing(?string $modifier, array $bands): void
    {
        $check = new CheckTestRoll('broken', DiceExpression::fromString('1d6'), null === $modifier ? null : Formula::fromString($modifier), null, $bands);

        try {
            // A generator with no number left throws a LogicException if the dice are rolled.
            $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0);
            self::fail('The check cannot be evaluated.');
        } catch (CheckTestRollFailed $failed) {
            self::assertStringStartsWith('Check "broken" cannot be test-rolled: ', $failed->getMessage());
            self::assertNotNull($failed->getPrevious());
        }
    }

    #[Test]
    public function diceThatCannotBeEvaluatedAreAnEvaluationFailure(): void
    {
        $check = new CheckTestRoll('broken', DiceExpression::fromString('1d6/(1d6-1d6)'), null, null, [new OutcomeBand('only', 'Only')]);

        $this->expectException(CheckTestRollFailed::class);

        $check->perform(new ScriptedRandomNumberGenerator(3, 3, 3), $this->sheet(), null, 0);
    }

    #[Test]
    public function aStoredCheckIsReadLikeThePublisherReadsIt(): void
    {
        $check = CheckTestRoll::fromStored([
            'key' => 'skill',
            'label' => 'Skill',
            'dice' => '1d10',
            'modifier' => '{hands}',
            'difficulty' => ['label' => 'DV', 'default' => 13, 'min' => 9],
            'bands' => [
                ['key' => 'fail', 'label' => 'Fail', 'upTo' => 7],
                ['key' => 'mid', 'label' => 'Mid', 'upTo' => ['tracker' => 'alarm']],
                ['key' => 'ok', 'label' => 'Ok', 'upTo' => ['formula' => '{check:difficulty}']],
                ['key' => 'crit', 'label' => 'Crit'],
            ],
        ]);

        $result = $check->perform(new ScriptedRandomNumberGenerator(1), $this->sheet(), null, 0);

        self::assertSame([5, 13, [7, 3, 13, null]], [$result->total, $result->difficulty, $result->bounds]);
        self::assertSame('fail', $result->band->key);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function unreadableChecks(): iterable
    {
        $band = ['key' => 'only', 'label' => 'Only'];
        $check = ['key' => 'c', 'label' => 'C', 'dice' => '1d6', 'bands' => [$band]];

        yield 'no key' => [[...$check, 'key' => null]];
        yield 'dice that are not a string' => [[...$check, 'dice' => 6]];
        yield 'dice that do not parse' => [[...$check, 'dice' => 'banana']];
        yield 'a modifier that does not parse' => [[...$check, 'modifier' => '{']];
        yield 'a difficulty without a default' => [[...$check, 'difficulty' => ['label' => 'DV']]];
        yield 'a difficulty that is not an object' => [[...$check, 'difficulty' => 5]];
        yield 'no bands' => [[...$check, 'bands' => []]];
        yield 'bands that are not a list' => [[...$check, 'bands' => 'none']];
        yield 'a band without a key' => [[...$check, 'bands' => [['label' => 'Only']]]];
        yield 'a bound that is none of the three' => [[...$check, 'bands' => [['key' => 'a', 'label' => 'A', 'upTo' => 'x'], $band]]];
        yield 'a bound with neither tracker nor formula' => [[...$check, 'bands' => [['key' => 'a', 'label' => 'A', 'upTo' => []], $band]]];
        yield 'a bound formula that does not parse' => [[...$check, 'bands' => [['key' => 'a', 'label' => 'A', 'upTo' => ['formula' => '{']], $band]]];
    }

    /**
     * @param array<array-key, mixed> $stored
     */
    #[Test]
    #[DataProvider('unreadableChecks')]
    public function aStoredCheckThatIsNotReadableFailsClosedWithAClearError(array $stored): void
    {
        $this->expectException(CheckTestRollFailed::class);
        $this->expectExceptionMessageMatches('/^The saved draft cannot be test-rolled: .+\.$/');

        CheckTestRoll::fromStored($stored);
    }

    private function skill(): CheckTestRoll
    {
        return new CheckTestRoll(
            'skill',
            DiceExpression::fromString('1d10'),
            Formula::fromString('{hands} + {edge}'),
            new CheckDifficultyRange(13, 9, 29),
            [
                new OutcomeBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')),
                new OutcomeBand('success', 'Success', Formula::fromString('{check:difficulty} + 4')),
                new OutcomeBand('crit', 'Critical success'),
            ],
        );
    }

    private function sheet(int $hands = 4): SampleSheet
    {
        return SampleSheet::fromDocument([
            'sheet' => ['fields' => [
                ['key' => 'hands', 'label' => 'Hands', 'type' => 'number', 'default' => 4],
                ['key' => 'zero', 'label' => 'Zero', 'type' => 'number'],
                ['key' => 'edge', 'label' => 'Edge', 'type' => 'derived', 'formula' => '{hands} / 2'],
                ['key' => 'broken', 'label' => 'Broken', 'type' => 'derived', 'formula' => '{hands} / {zero}'],
            ]],
            'trackers' => [['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6]],
        ], ['hands' => $hands], ['alarm' => 3]);
    }
}
