<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

enum TrackerKind: string
{
    case Counter = 'counter';
    case Clock = 'clock';
}
