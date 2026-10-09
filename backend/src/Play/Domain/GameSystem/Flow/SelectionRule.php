<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

enum SelectionRule: string
{
    case Sequence = 'sequence';
    case Player = 'player';
    case Oracle = 'oracle';
}
