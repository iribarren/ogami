<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\RollContent;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RollContent::class)]
final class RollContentTest extends TestCase
{
    #[Test]
    public function aRollKeepsTheExpressionTotalAndEveryDieOfEachGroup(): void
    {
        $roll = DiceExpression::fromString('4D6k3 + 1d4 + 1')->roll(new ScriptedRandomNumberGenerator(6, 2, 5, 3, 4));

        $content = RollContent::fromRoll($roll);

        self::assertSame('roll', $content->kind());
        self::assertSame('4d6kh3+1d4+1', $content->expression());
        self::assertSame(19, $content->total());
        self::assertSame([
            'kind' => 'roll',
            'expression' => '4d6kh3+1d4+1',
            'total' => 19,
            'groups' => [
                [
                    'notation' => '4d6kh3',
                    'sides' => 6,
                    'dice' => [
                        ['value' => 6, 'kept' => true],
                        ['value' => 2, 'kept' => false],
                        ['value' => 5, 'kept' => true],
                        ['value' => 3, 'kept' => true],
                    ],
                    'subtotal' => 14,
                ],
                [
                    'notation' => '1d4',
                    'sides' => 4,
                    'dice' => [['value' => 4, 'kept' => true]],
                    'subtotal' => 4,
                ],
            ],
        ], $content->toArray());
        self::assertSame($content->toArray()['groups'], $content->groups());
    }

    #[Test]
    public function aRollWithoutDiceHasNoGroups(): void
    {
        $content = RollContent::fromRoll(DiceExpression::fromString('3')->roll(new ScriptedRandomNumberGenerator()));

        self::assertSame(['kind' => 'roll', 'expression' => '3', 'total' => 3, 'groups' => []], $content->toArray());
    }
}
