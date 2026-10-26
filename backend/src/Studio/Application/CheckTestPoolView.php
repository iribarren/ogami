<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Check\CheckTestPoolRoll;

/**
 * The pool of a test roll: how many dice were rolled (the count plus the situational modifier, kept
 * in 1..100), their sides and success threshold, the count the check asked for and its formula, every
 * die in roll order and how many succeeded.
 */
final readonly class CheckTestPoolView
{
    /**
     * @param ?string                    $countFormula null when the check's count is a number
     * @param list<CheckTestPoolDieView> $dice
     */
    public function __construct(
        public int $size,
        public int $sides,
        public int $successAt,
        public int $count,
        public ?string $countFormula,
        public array $dice,
        public int $successes,
    ) {
    }

    public static function fromPoolRoll(CheckTestPoolRoll $pool): self
    {
        $roll = $pool->roll;

        return new self(
            $roll->size(),
            $roll->pool()->sides,
            $roll->pool()->successAt,
            $pool->count,
            $pool->countFormula,
            array_map(CheckTestPoolDieView::fromDie(...), $roll->dice()),
            $roll->successes(),
        );
    }
}
