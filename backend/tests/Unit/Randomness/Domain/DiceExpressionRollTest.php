<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\DiceGroup;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Roll;
use App\Randomness\Domain\RolledDie;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiceExpression::class)]
#[CoversClass(Roll::class)]
#[CoversClass(DiceGroup::class)]
#[CoversClass(RolledDie::class)]
#[CoversClass(InvalidDiceExpression::class)]
#[CoversNamespace('App\Randomness\Domain\Expression')]
final class DiceExpressionRollTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<int>, int}>
     */
    public static function totals(): iterable
    {
        yield 'dice and a modifier' => ['2d6+1', [3, 5], 9];
        yield 'single die' => ['d20', [17], 17];
        yield 'negative modifier' => ['3d8-2', [1, 4, 8], 11];
        yield 'hundred-sided die' => ['1d100', [42], 42];
        yield 'percentile die' => ['d%', [100], 100];
        yield 'keep highest' => ['4d6kh3', [3, 5, 2, 6], 14];
        yield 'keep lowest' => ['2d20kl1', [15, 4], 4];
        yield 'drop highest' => ['3d6dh1', [6, 2, 4], 6];
        yield 'drop lowest' => ['4d6dl1', [1, 6, 6, 3], 15];
        yield 'two groups' => ['2d6+1d4-2', [4, 6, 3], 11];
        yield 'parentheses' => ['(1d6+2)*3', [4], 18];
        yield 'subtraction is left associative' => ['1-2-3', [], -4];
        yield 'division is left associative' => ['100/10/5', [], 2];
        yield 'multiplication before addition' => ['2+3*4', [], 14];
        yield 'parentheses before multiplication' => ['(2+3)*4', [], 20];
        yield 'unary minus on dice' => ['-1d4', [3], -3];
        yield 'unary minus binds tighter than multiplication' => ['-2*3', [], -6];
        yield 'double unary minus' => ['--3', [], 3];
        yield 'subtracting a negation' => ['5--2', [], 7];
        yield 'integer division rounds down' => ['10/3', [], 3];
        yield 'negative division rounds down' => ['-7/2', [], -4];
        yield 'division by a negative rounds down' => ['7/-2', [], -4];
        yield 'exact negative division' => ['-8/2', [], -4];
        yield 'zero divided' => ['0/5', [], 0];
        yield 'dice divided' => ['1d6/2', [5], 2];
    }

    /**
     * @param list<int> $numbers
     */
    #[Test]
    #[DataProvider('totals')]
    public function itTotalsTheExpression(string $notation, array $numbers, int $total): void
    {
        $roll = DiceExpression::fromString($notation)->roll(new ScriptedRandomNumberGenerator(...$numbers));

        self::assertSame($total, $roll->total());
    }

    #[Test]
    public function itKeepsTheRolledExpression(): void
    {
        $expression = DiceExpression::fromString(' 2D6 + 1 ');

        $roll = $expression->roll(new ScriptedRandomNumberGenerator(3, 5));

        self::assertSame($expression, $roll->expression());
        self::assertSame('2d6+1', $roll->expression()->notation());
    }

    #[Test]
    public function itBreaksTheRollDownByDiceGroupFromLeftToRight(): void
    {
        $roll = DiceExpression::fromString('4d6kh3 + 2*d%')->roll(new ScriptedRandomNumberGenerator(3, 5, 2, 6, 37));

        self::assertEquals(
            [
                new DiceGroup('4d6kh3', 6, [RolledDie::kept(3), RolledDie::kept(5), RolledDie::dropped(2), RolledDie::kept(6)]),
                new DiceGroup('1d100', 100, [RolledDie::kept(37)]),
            ],
            $roll->groups(),
        );
        self::assertSame(14, $roll->groups()[0]->subtotal());
        self::assertSame(37, $roll->groups()[1]->subtotal());
        self::assertSame(88, $roll->total());
    }

    #[Test]
    public function itHasNoGroupsWithoutDice(): void
    {
        $roll = DiceExpression::fromString('2+3')->roll(new ScriptedRandomNumberGenerator());

        self::assertSame([], $roll->groups());
        self::assertSame(5, $roll->total());
    }

    #[Test]
    public function itListsTheGroupsOfNestedExpressionsInNotationOrder(): void
    {
        $roll = DiceExpression::fromString('(1d4+(2d6))*-1d8')->roll(new ScriptedRandomNumberGenerator(2, 3, 4, 5));

        self::assertSame(['1d4', '2d6', '1d8'], array_map(static fn (DiceGroup $group): string => $group->notation(), $roll->groups()));
        self::assertSame(-45, $roll->total());
    }

    #[Test]
    public function itRollsEachDieWithinItsFaces(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('outside [1, 20]');

        DiceExpression::fromString('d20')->roll(new ScriptedRandomNumberGenerator(21));
    }

    #[Test]
    public function itRollsEveryDieWithAFreshNumber(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('No scripted number left');

        DiceExpression::fromString('3d6')->roll(new ScriptedRandomNumberGenerator(1, 2));
    }

    /**
     * @return iterable<string, array{string, list<int>, list<bool>}>
     */
    public static function selections(): iterable
    {
        yield 'keep highest' => ['4d6kh3', [3, 5, 2, 6], [true, true, false, true]];
        yield 'keep lowest' => ['3d6kl2', [3, 5, 2], [true, false, true]];
        yield 'drop highest' => ['3d6dh1', [6, 2, 4], [false, true, true]];
        yield 'drop lowest' => ['4d6dl1', [1, 6, 6, 3], [false, true, true, true]];
        yield 'keep every die' => ['3d6kh3', [1, 2, 3], [true, true, true]];
        yield 'drop no die' => ['3d6dl0', [1, 2, 3], [true, true, true]];
        yield 'keep highest keeps the earlier of tied dice' => ['3d6kh1', [5, 5, 5], [true, false, false]];
        yield 'keep lowest keeps the earlier of tied dice' => ['3d6kl2', [2, 4, 2], [true, false, true]];
        yield 'drop lowest drops the later of tied dice' => ['4d6dl1', [2, 6, 2, 4], [true, true, false, true]];
        yield 'drop highest drops the later of tied dice' => ['3d6dh2', [6, 1, 6], [false, true, false]];
        yield 'keep highest across a tie' => ['4d6kh2', [4, 6, 4, 1], [true, true, false, false]];
    }

    /**
     * @param list<int>  $numbers
     * @param list<bool> $kept
     */
    #[Test]
    #[DataProvider('selections')]
    public function itFlagsKeptAndDroppedDiceInRollOrder(string $notation, array $numbers, array $kept): void
    {
        $roll = DiceExpression::fromString($notation)->roll(new ScriptedRandomNumberGenerator(...$numbers));

        $dice = $roll->groups()[0]->dice();
        self::assertSame($numbers, array_map(static fn (RolledDie $die): int => $die->value(), $dice));
        self::assertSame($kept, array_map(static fn (RolledDie $die): bool => $die->isKept(), $dice));

        $keptTotal = array_sum(array_map(static fn (RolledDie $die): int => $die->isKept() ? $die->value() : 0, $dice));
        self::assertSame($keptTotal, $roll->groups()[0]->subtotal());
        self::assertSame($keptTotal, $roll->total());
    }

    #[Test]
    public function itRejectsDivisionByZero(): void
    {
        $this->expectException(InvalidDiceExpression::class);
        $this->expectExceptionMessageIsOrContains('divides by zero');

        DiceExpression::fromString('1d6/(2-2)')->roll(new ScriptedRandomNumberGenerator(3));
    }

    #[Test]
    public function itRejectsResultsTooLargeForAnInteger(): void
    {
        $this->expectException(InvalidDiceExpression::class);
        $this->expectExceptionMessageIsOrContains('too large');

        DiceExpression::fromString('1000000*1000000*1000000*1000000')->roll(new ScriptedRandomNumberGenerator());
    }
}
