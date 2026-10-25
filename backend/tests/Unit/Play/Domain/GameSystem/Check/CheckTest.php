<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\CheckDifficultyNotDeclared;
use App\Play\Domain\GameSystem\Check\CheckDifficultyOutOfRange;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\CheckResult;
use App\Play\Domain\GameSystem\Check\CheckValues;
use App\Play\Domain\GameSystem\Check\ModifierTerm;
use App\Play\Domain\GameSystem\Check\SituationalModifierOutOfRange;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Performing a check (ADR 0024) for a Character with hands 4 (default), no zero and edge = hands / 2:
 * the skill check rolls 1d10, adds {hands} + {edge} (6) and the situational modifier, and compares
 * the total with bands bounded by the difficulty (default 13, 9 to 29).
 */
#[CoversClass(Check::class)]
#[CoversClass(CheckBand::class)]
#[CoversClass(CheckDifficulty::class)]
#[CoversClass(CheckResult::class)]
#[CoversClass(CheckValues::class)]
#[CoversClass(ModifierTerm::class)]
final class CheckTest extends TestCase
{
    #[Test]
    public function itAddsTheModifierAndTheSituationalModifierAndPicksTheBand(): void
    {
        $result = $this->skill()->perform(new ScriptedRandomNumberGenerator(7), $this->sheet(), null, 2);

        self::assertSame(7, $result->roll?->total());
        self::assertSame([6, 2, 13, 15], [$result->modifier, $result->situational, $result->difficulty, $result->total]);
        self::assertSame('success', $result->band->key, 'Fail up to 12, success up to 17.');
    }

    #[Test]
    public function itRecordsWhatEachReferenceOfTheModifierRead(): void
    {
        $result = $this->skill()->perform(new ScriptedRandomNumberGenerator(7), $this->sheet(['hands' => 6]), null, 0);

        self::assertSame(['{hands}', '{edge}'], array_map(static fn (ModifierTerm $term): string => $term->token(), $result->modifierTerms));
        self::assertSame([6, 3], array_map(static fn (ModifierTerm $term): int => $term->value, $result->modifierTerms));
        self::assertSame(9, $result->modifier);
    }

    #[Test]
    public function aCheckWithoutAModifierRecordsNoTerms(): void
    {
        $check = new Check('luck', 'Luck', null, DiceExpression::fromString('1d6'), null, null, [new CheckBand('only', 'Only')]);

        self::assertSame([], $check->perform(new ScriptedRandomNumberGenerator(3), $this->sheet(), null, 0)->modifierTerms);
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
        $result = $this->skill()->perform(new ScriptedRandomNumberGenerator($dice), $this->sheet(['hands' => $hands]), $difficulty, 0);

        self::assertSame($band, $result->band->key);
    }

    #[Test]
    public function aDifficultyOfTheRangeIsUsedAsGiven(): void
    {
        foreach ([9, 29] as $difficulty) {
            self::assertSame($difficulty, $this->skill()->perform(new ScriptedRandomNumberGenerator(1), $this->sheet(), $difficulty, 0)->difficulty);
        }
    }

    #[Test]
    public function aCheckWithoutADifficultyOrModifierReportsNoDifficultyAndAZeroModifier(): void
    {
        $luck = new Check('luck', 'Luck', null, DiceExpression::fromString('d%'), null, null, [new CheckBand('bad', 'Bad', 20), new CheckBand('good', 'Good')]);

        $result = $luck->perform(new ScriptedRandomNumberGenerator(21), $this->sheet(), null, -3);

        self::assertSame([0, -3, null, 18, 'bad'], [$result->modifier, $result->situational, $result->difficulty, $result->total, $result->band->key]);
    }

    #[Test]
    public function aDifficultyGivenToACheckThatDeclaresNoneIsRefused(): void
    {
        $luck = new Check('luck', 'Luck', null, DiceExpression::fromString('d%'), null, null, [new CheckBand('only', 'Only')]);

        $this->expectException(CheckDifficultyNotDeclared::class);
        $this->expectExceptionMessageIsOrContains('Check "luck" declares no difficulty.');

        $luck->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), 5, 0);
    }

    #[Test]
    #[DataProvider('difficultiesOutOfRange')]
    public function aDifficultyOutsideTheDeclaredRangeIsRefusedBeforeRolling(int $difficulty, string $message): void
    {
        $this->expectException(CheckDifficultyOutOfRange::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->skill()->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), $difficulty, 0);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function difficultiesOutOfRange(): iterable
    {
        yield 'below the min' => [8, 'Difficulty 8 is outside 9 to 29 of check "skill".'];
        yield 'above the max' => [30, 'Difficulty 30 is outside 9 to 29 of check "skill".'];
    }

    /**
     * @return iterable<string, array{CheckDifficulty, int, string}>
     */
    public static function halfOpenRanges(): iterable
    {
        yield 'only a min' => [new CheckDifficulty('DV', 5, min: 3), 2, 'Difficulty 2 is outside the minimum 3 of check "c".'];
        yield 'only a max' => [new CheckDifficulty('DV', 5, max: 8), 9, 'Difficulty 9 is outside the maximum 8 of check "c".'];
    }

    #[Test]
    #[DataProvider('halfOpenRanges')]
    public function aDifficultyWithOnlyAMinOrOnlyAMaxNamesThatBound(CheckDifficulty $declared, int $difficulty, string $message): void
    {
        $check = new Check('c', 'C', null, DiceExpression::fromString('1d6'), null, $declared, [new CheckBand('only', 'Only')]);

        $this->expectException(CheckDifficultyOutOfRange::class);
        $this->expectExceptionMessageIsOrContains($message);

        $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), $difficulty, 0);
    }

    #[Test]
    #[DataProvider('situationalModifiersOutOfRange')]
    public function aSituationalModifierOutsideMinus100To100IsRefused(int $situational): void
    {
        $this->expectException(SituationalModifierOutOfRange::class);

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
        $check = new Check('huge', 'Huge', null, DiceExpression::fromString('9*1000000*1000000*1000000+223372*1000000*1000000+36854*1000000+773759'), Formula::fromString('{hands} * 1000000 * 1000'), null, [new CheckBand('only', 'Only')]);

        try {
            $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(['hands' => 1]), null, 0);
            self::fail('The total overflows.');
        } catch (CheckEvaluationFailed $failed) {
            self::assertSame('Check "huge" cannot be performed: the total of the check overflows.', $failed->getMessage());
        }
    }

    /**
     * @return iterable<string, array{?string, list<CheckBand>}>
     */
    public static function evaluationFailures(): iterable
    {
        $bands = [new CheckBand('low', 'Low', 5), new CheckBand('high', 'High')];

        yield 'a modifier dividing by zero' => ['{hands} / {zero}', $bands];
        yield 'a modifier reading the difficulty' => ['{check:difficulty}', $bands];
        yield 'a modifier reading a field the sheet lacks' => ['{ghost}', $bands];
        // Evaluation reads every reference (no short-circuit), so the breakdown never reads one evaluation did not.
        yield 'a missing field times zero' => ['0 * {ghost}', $bands];
        yield 'a missing field inside min()' => ['min({hands}, {ghost})', $bands];
        yield 'a missing field after a repeated one' => ['{hands} + {hands} + {ghost}', $bands];
        yield 'a band dividing by zero' => [null, [new CheckBand('low', 'Low', Formula::fromString('{hands} / {zero}')), new CheckBand('high', 'High')]];
        yield 'a later band that fails' => [null, [new CheckBand('low', 'Low', 1), new CheckBand('mid', 'Mid', Formula::fromString('{hands} / {zero}')), new CheckBand('high', 'High')]];
        yield 'a band reading another check value' => [null, [new CheckBand('low', 'Low', Formula::fromString('{check:other}')), new CheckBand('high', 'High')]];
        yield 'a band reading the difficulty of a check that has none' => [null, [new CheckBand('low', 'Low', Formula::fromString('{check:difficulty}')), new CheckBand('high', 'High')]];
        yield 'an overflow' => [null, [new CheckBand('low', 'Low', Formula::fromString('{hands} * 1000000 * 1000')), new CheckBand('high', 'High')]];
    }

    /**
     * @param list<CheckBand> $bands
     */
    #[Test]
    #[DataProvider('evaluationFailures')]
    public function aFormulaThatFailsRollsNothing(?string $modifier, array $bands): void
    {
        $check = new Check('broken', 'Broken', null, DiceExpression::fromString('1d6'), null === $modifier ? null : Formula::fromString($modifier), null, $bands);

        try {
            // A generator with no number left throws a LogicException if the dice are rolled.
            $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0);
            self::fail('The check cannot be evaluated.');
        } catch (CheckEvaluationFailed $failed) {
            self::assertStringStartsWith('Check "broken" cannot be performed: ', $failed->getMessage());
            self::assertNotNull($failed->getPrevious());
        }
    }

    #[Test]
    public function diceThatCannotBeEvaluatedAreAnEvaluationFailure(): void
    {
        $check = new Check('broken', 'Broken', null, DiceExpression::fromString('1d6/(1d6-1d6)'), null, null, [new CheckBand('only', 'Only')]);

        $this->expectException(CheckEvaluationFailed::class);

        $check->perform(new ScriptedRandomNumberGenerator(3, 3, 3), $this->sheet(), null, 0);
    }

    /**
     * @return iterable<string, array{list<CheckBand>, string}>
     */
    public static function invalidBands(): iterable
    {
        yield 'no band' => [[], 'needs at least one band'];
        yield 'a duplicate band key' => [[new CheckBand('a', 'A', 1), new CheckBand('a', 'A again')], 'lists band "a" twice'];
        yield 'a last band with a bound' => [[new CheckBand('a', 'A', 1)], 'catches the rest and has no upper bound'];
        yield 'a band without a bound before the last' => [[new CheckBand('a', 'A'), new CheckBand('b', 'B')], 'Band "a" of check "c" needs an upper bound'];
        yield 'literal bounds that do not increase' => [[new CheckBand('a', 'A', 5), new CheckBand('b', 'B', 5), new CheckBand('c', 'C')], 'Band "b" of check "c" must be above the previous upper bound 5'];
    }

    /**
     * @param list<CheckBand> $bands
     */
    #[Test]
    #[DataProvider('invalidBands')]
    public function itRefusesBandsThatBreakTheOrdering(array $bands, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new Check('c', 'C', null, DiceExpression::fromString('1d6'), null, null, $bands);
    }

    #[Test]
    public function aDifficultyDefaultMustBeWithinItsRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The difficulty min (6) must be at most its default (5).');

        new CheckDifficulty('DV', 5, min: 6);
    }

    #[Test]
    public function aDifficultyMaxMustBeAtLeastItsDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The difficulty max (4) must be at least its default (5).');

        new CheckDifficulty('DV', 5, max: 4);
    }

    private function skill(): Check
    {
        return new Check(
            'skill',
            'Skill check',
            null,
            DiceExpression::fromString('1d10'),
            Formula::fromString('{hands} + {edge}'),
            new CheckDifficulty('Difficulty Value', 13, 9, 29),
            [
                new CheckBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')),
                new CheckBand('success', 'Success', Formula::fromString('{check:difficulty} + 4')),
                new CheckBand('crit', 'Critical success'),
            ],
        );
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
