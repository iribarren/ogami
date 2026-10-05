<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Application;

use App\Randomness\Application\DiceGroupView;
use App\Randomness\Application\RollDice;
use App\Randomness\Application\RollDiceHandler;
use App\Randomness\Application\RolledDieView;
use App\Randomness\Application\RollView;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RollDice::class)]
#[CoversClass(RollDiceHandler::class)]
#[CoversClass(RollView::class)]
#[CoversClass(DiceGroupView::class)]
#[CoversClass(RolledDieView::class)]
final class RollDiceHandlerTest extends TestCase
{
    #[Test]
    public function itRollsTheExpressionWithAPerDieBreakdown(): void
    {
        $handler = new RollDiceHandler(new ScriptedRandomNumberGenerator(3, 5, 2, 6, 4));

        $view = $handler(new RollDice('4d6kh3 + 1d4 - 2'));

        self::assertEquals(
            new RollView('4d6kh3+1d4-2', 16, [
                new DiceGroupView('4d6kh3', 6, [
                    new RolledDieView(3, true),
                    new RolledDieView(5, true),
                    new RolledDieView(2, false),
                    new RolledDieView(6, true),
                ], 14),
                new DiceGroupView('1d4', 4, [new RolledDieView(4, true)], 4),
            ]),
            $view,
        );
    }

    #[Test]
    public function aConstantExpressionHasNoGroups(): void
    {
        $handler = new RollDiceHandler(new ScriptedRandomNumberGenerator());

        self::assertEquals(new RollView('10/3', 3, []), $handler(new RollDice('10/3')));
    }

    #[Test]
    public function anInvalidExpressionIsRejected(): void
    {
        $handler = new RollDiceHandler(new ScriptedRandomNumberGenerator());

        $this->expectException(InvalidDiceExpression::class);

        $handler(new RollDice('2d'));
    }

    #[Test]
    public function aDivisionByZeroIsRejectedWhenRolling(): void
    {
        $handler = new RollDiceHandler(new ScriptedRandomNumberGenerator(1));

        $this->expectException(InvalidDiceExpression::class);
        $this->expectExceptionMessageIsOrContains('divides by zero');

        $handler(new RollDice('1d6/0'));
    }
}
