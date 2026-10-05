<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

enum TokenType
{
    case Integer;
    case Dice;
    case Percent;
    case Selector;
    case Operator;
    case OpenParenthesis;
    case CloseParenthesis;
    case End;
}
