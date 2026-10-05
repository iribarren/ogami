<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\QueryHandler;

final readonly class RollDiceHandler implements QueryHandler
{
    public function __construct(
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws InvalidDiceExpression when the expression is malformed, out of limits or divides by zero
     */
    public function __invoke(RollDice $query): RollView
    {
        return RollView::fromRoll(DiceExpression::fromString($query->expression)->roll($this->random));
    }
}
