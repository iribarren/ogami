<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

enum TokenType
{
    case Integer;
    case Reference;
    case Function;
    case Operator;
    case OpenParenthesis;
    case CloseParenthesis;
    case Comma;
    case End;
}
