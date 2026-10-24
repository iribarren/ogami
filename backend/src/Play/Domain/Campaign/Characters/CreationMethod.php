<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

/**
 * How a player creates a Character on a sheet with a point budget (ADR 0040): the server rolls
 * the dice defaults, or the player spends the budget.
 */
enum CreationMethod: string
{
    case Roll = 'roll';
    case Points = 'points';
}
