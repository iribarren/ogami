<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\SelectionRule;
use App\Play\Domain\GameSystem\SceneType;

/**
 * The scene pick a FlowRun waits on: one card per Scene Type offered, or the oracle table to roll
 * for it.
 */
final readonly class ScenePickView
{
    /**
     * @param list<SceneType> $cards  in offer order; none when the table is to be rolled
     * @param ?string         $table  the key of the oracle table to roll for the Scene Type, else null
     * @param bool            $forced whether the only card is a forced next Scene Type
     */
    public function __construct(
        public SelectionRule $rule,
        public array $cards,
        public ?string $table,
        public bool $forced,
    ) {
    }
}
