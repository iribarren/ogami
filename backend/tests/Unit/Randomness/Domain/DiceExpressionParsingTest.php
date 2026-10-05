<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiceExpression::class)]
#[CoversClass(InvalidDiceExpression::class)]
#[CoversNamespace('App\Randomness\Domain\Expression')]
final class DiceExpressionParsingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function validNotations(): iterable
    {
        yield 'dice with a modifier' => ['2d6+1', '2d6+1', 2];
        yield 'single die without count' => ['d20', '1d20', 1];
        yield 'dice with a negative modifier' => ['3d8-2', '3d8-2', 3];
        yield 'hundred-sided die' => ['1d100', '1d100', 1];
        yield 'percentile die' => ['d%', '1d100', 1];
        yield 'percentile dice with count' => ['2d%', '2d100', 2];
        yield 'upper case and whitespace' => [' 2D10 + 3 ', '2d10+3', 2];
        yield 'keep highest' => ['4d6kh3', '4d6kh3', 4];
        yield 'keep is keep highest' => ['4d6k3', '4d6kh3', 4];
        yield 'keep lowest' => ['2d20kl1', '2d20kl1', 2];
        yield 'drop highest' => ['3d6dh1', '3d6dh1', 3];
        yield 'drop lowest' => ['4d6dl1', '4d6dl1', 4];
        yield 'drop none' => ['4d6dl0', '4d6dl0', 4];
        yield 'upper case selector' => ['4D6KH3', '4d6kh3', 4];
        yield 'two groups and a constant' => ['2d6 + 1d4 - 2', '2d6+1d4-2', 3];
        yield 'parentheses' => ['(1d6+2)*3', '(1d6+2)*3', 1];
        yield 'nested parentheses' => ['((2))', '((2))', 0];
        yield 'division' => ['10/3', '10/3', 0];
        yield 'unary minus' => ['-1d4', '-1d4', 1];
        yield 'double unary minus' => ['--3', '--3', 0];
        yield 'constant only' => ['7', '7', 0];
        yield 'zero literal' => ['0', '0', 0];
        yield 'leading zeros' => ['007d06', '7d6', 7];
    }

    #[Test]
    #[DataProvider('validNotations')]
    public function itParsesAndNormalizesDiceNotation(string $notation, string $normalized, int $diceCount): void
    {
        $expression = DiceExpression::fromString($notation);

        self::assertSame($normalized, $expression->notation());
        self::assertSame($normalized, (string) $expression);
        self::assertSame($diceCount, $expression->diceCount());
    }

    #[Test]
    public function aNormalizedNotationParsesToItself(): void
    {
        $normalized = DiceExpression::fromString(' -( 4D6K3 + d% ) * 2 / 3 ')->notation();

        self::assertSame('-(4d6kh3+1d100)*2/3', $normalized);
        self::assertSame($normalized, DiceExpression::fromString($normalized)->notation());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'empty' => ['', 'empty'];
        yield 'only whitespace' => ['   ', 'empty'];
        yield 'not dice' => ['abc', '"a" at position 1'];
        yield 'unknown character' => ['1d6!', '"!" at position 4'];
        yield 'missing sides' => ['2d', 'Unexpected end'];
        yield 'lonely d' => ['d', 'Unexpected end'];
        yield 'sides are not a number' => ['2d+', '"+" at position 3'];
        yield 'selector without count' => ['2d6kh', 'Unexpected end'];
        yield 'selector without dice' => ['6kh1', '"kh" at position 2'];
        yield 'two selectors' => ['4d6kh3kl1', '"kl" at position 7'];
        yield 'dangling operator' => ['1d6+', 'Unexpected end'];
        yield 'two binary operators' => ['1d6+*2', '"*" at position 5'];
        yield 'unclosed parenthesis' => ['(1d6', 'Unexpected end'];
        yield 'unopened parenthesis' => ['1d6)', '")" at position 4'];
        yield 'empty parentheses' => ['()', '")" at position 2'];
        yield 'missing operator' => ['2d6(1)', '"(" at position 4'];
        yield 'dice after dice' => ['1d6d6', '"d" at position 4'];
        yield 'fate dice' => ['4dF', '"f" at position 3'];
        yield 'position counts whitespace' => [' 2 d 6 + + 1', '"+" at position 10'];
    }

    #[Test]
    #[DataProvider('syntaxErrors')]
    public function itRejectsSyntaxErrors(string $notation, string $message): void
    {
        $this->expectException(InvalidDiceExpression::class);
        $this->expectExceptionMessageIsOrContains($message);

        DiceExpression::fromString($notation);
    }
}
