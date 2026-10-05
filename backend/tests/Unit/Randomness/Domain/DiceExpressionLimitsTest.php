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
final class DiceExpressionLimitsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function notationsAtTheLimits(): iterable
    {
        yield '100 characters' => [str_repeat('1+', 49).'10'];
        yield '100 characters including whitespace' => [str_repeat(' ', 97).'d20'];
        yield 'one die' => ['1d6'];
        yield '100 dice in one group' => ['100d6'];
        yield '100 dice across groups' => ['50d6+25d8+25d10'];
        yield 'two sides' => ['1d2'];
        yield '1000 sides' => ['1d1000'];
        yield 'largest literal' => ['1000000'];
        yield 'keep all dice' => ['4d6kh4'];
        yield 'keep one die' => ['4d6kl1'];
        yield 'drop no dice' => ['4d6dh0'];
        yield 'drop all but one die' => ['4d6dl3'];
    }

    #[Test]
    #[DataProvider('notationsAtTheLimits')]
    public function itAcceptsNotationsAtTheLimits(string $notation): void
    {
        $this->expectNotToPerformAssertions();

        DiceExpression::fromString($notation);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notationsOverTheLimits(): iterable
    {
        yield '101 characters' => [str_repeat('1+', 50).'1', 'at most 100 characters, 101 given'];
        yield '101 characters including whitespace' => [str_repeat(' ', 98).'d20', 'at most 100 characters, 101 given'];
        yield 'zero dice' => ['0d6', 'between 1 and 100 dice, 0 given'];
        yield '101 dice in one group' => ['101d6', 'between 1 and 100 dice, 101 given'];
        yield '101 dice across groups' => ['50d6+25d8+26d10', 'at most 100 dice in total, 101 given'];
        yield 'one side' => ['1d1', 'between 2 and 1000 sides, 1 given'];
        yield 'zero sides' => ['1d0', 'between 2 and 1000 sides, 0 given'];
        yield '1001 sides' => ['1d1001', 'between 2 and 1000 sides, 1001 given'];
        yield 'literal too large' => ['1000001', 'between 0 and 1000000, 1000001 given'];
        yield 'literal overflowing an integer' => ['99999999999999999999999', 'between 0 and 1000000'];
        yield 'keep more dice than rolled' => ['4d6kh5', '"kh" keeps between 1 and 4 dice, 5 given'];
        yield 'keep no dice' => ['4d6kl0', '"kl" keeps between 1 and 4 dice, 0 given'];
        yield 'drop every die' => ['4d6dl4', '"dl" drops between 0 and 3 dice, 4 given'];
        yield 'drop more dice than rolled' => ['4d6dh9', '"dh" drops between 0 and 3 dice, 9 given'];
    }

    #[Test]
    #[DataProvider('notationsOverTheLimits')]
    public function itRejectsNotationsOverTheLimits(string $notation, string $message): void
    {
        $this->expectException(InvalidDiceExpression::class);
        $this->expectExceptionMessageIsOrContains($message);

        DiceExpression::fromString($notation);
    }
}
