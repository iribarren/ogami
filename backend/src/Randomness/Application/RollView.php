<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\Roll;

/**
 * A rolled dice expression: its total and every dice group rolled.
 */
final readonly class RollView
{
    /**
     * @param string              $expression the normalized notation, e.g. "2d6+1"
     * @param list<DiceGroupView> $groups     the dice groups in notation order
     */
    public function __construct(
        public string $expression,
        public int $total,
        public array $groups,
    ) {
    }

    public static function fromRoll(Roll $roll): self
    {
        return new self(
            $roll->expression()->notation(),
            $roll->total(),
            array_map(DiceGroupView::fromGroup(...), $roll->groups()),
        );
    }
}
