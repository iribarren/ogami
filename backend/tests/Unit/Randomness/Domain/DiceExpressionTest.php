<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiceExpression::class)]
#[CoversClass(InvalidDiceExpression::class)]
final class DiceExpressionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int, int, string}>
     */
    public static function validNotations(): iterable
    {
        yield 'dice with positive modifier' => ['2d6+1', 2, 6, 1, '2d6+1'];
        yield 'single die without count' => ['d20', 1, 20, 0, '1d20'];
        yield 'dice with negative modifier' => ['3d8-2', 3, 8, -2, '3d8-2'];
        yield 'percentile die' => ['1d100', 1, 100, 0, '1d100'];
        yield 'upper case and spaces' => [' 2D10 + 3 ', 2, 10, 3, '2d10+3'];
        yield 'zero modifier is dropped' => ['4d4+0', 4, 4, 0, '4d4'];
    }

    #[Test]
    #[DataProvider('validNotations')]
    public function itParsesDiceNotation(string $notation, int $count, int $sides, int $modifier, string $canonical): void
    {
        $expression = DiceExpression::fromString($notation);

        self::assertSame($count, $expression->count());
        self::assertSame($sides, $expression->sides());
        self::assertSame($modifier, $expression->modifier());
        self::assertSame($canonical, $expression->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNotations(): iterable
    {
        yield 'empty' => [''];
        yield 'not dice' => ['abc'];
        yield 'missing sides' => ['2d'];
        yield 'missing modifier value' => ['2d6+'];
        yield 'two modifiers' => ['2d6+1+1'];
        yield 'fate dice are not supported yet' => ['4dF'];
        yield 'zero dice' => ['0d6'];
        yield 'one-sided die' => ['2d1'];
        yield 'too many dice' => ['101d6'];
        yield 'too many sides' => ['1d1001'];
    }

    #[Test]
    #[DataProvider('invalidNotations')]
    public function itRejectsInvalidNotation(string $notation): void
    {
        $this->expectException(InvalidDiceExpression::class);

        DiceExpression::fromString($notation);
    }

    #[Test]
    public function itRollsEachDieWithTheRandomNumberGeneratorAndAddsTheModifier(): void
    {
        $roll = DiceExpression::fromString('2d6+1')->roll(new ScriptedRandomNumberGenerator(3, 5));

        self::assertSame([3, 5], $roll->dice());
        self::assertSame(9, $roll->total());
        self::assertSame('2d6+1', $roll->expression()->toString());
    }

    #[Test]
    public function itSubtractsANegativeModifier(): void
    {
        $roll = DiceExpression::fromString('3d8-2')->roll(new ScriptedRandomNumberGenerator(1, 4, 8));

        self::assertSame([1, 4, 8], $roll->dice());
        self::assertSame(11, $roll->total());
    }

    #[Test]
    public function itAsksForNumbersWithinTheDieFaces(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('outside [1, 20]');

        DiceExpression::fromString('d20')->roll(new ScriptedRandomNumberGenerator(21));
    }
}
