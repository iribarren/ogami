<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

enum SheetOperation: string
{
    case Add = 'add';
    case Subtract = 'subtract';
    case Set = 'set';
}
