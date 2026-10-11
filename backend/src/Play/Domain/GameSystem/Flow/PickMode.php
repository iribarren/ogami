<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * How a pick step takes its items: "random" draws them (weighted), "choose" lets the player
 * select them.
 */
enum PickMode: string
{
    case Random = 'random';
    case Choose = 'choose';
}
