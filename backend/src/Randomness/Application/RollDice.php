<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for a roll of a dice expression such as "4d6kh3" or "2d6+1".
 * Fails with InvalidDiceExpression when the expression cannot be rolled.
 *
 * @implements Query<RollView>
 */
final readonly class RollDice implements Query
{
    public function __construct(
        public string $expression,
    ) {
    }
}
