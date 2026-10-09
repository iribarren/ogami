<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

enum TrackerOperation: string
{
    case Add = 'add';
    case Set = 'set';
}
