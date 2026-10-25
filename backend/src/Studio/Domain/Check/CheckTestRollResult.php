<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Randomness\Domain\Roll;

/**
 * What a test roll produced: the dice or the pool rolled, the modifier, the situational modifier and
 * the difficulty that applied, their total (the successes of a pool), every band with the bound it
 * had for these samples (the last has none) and the band the total falls in. A pool check also tells
 * its push, and a pushed roll the successes it had before the push.
 */
final readonly class CheckTestRollResult
{
    /**
     * @param list<OutcomeBand> $bands  in band order
     * @param list<?int>        $bounds the evaluated upper bound of each band, in band order
     */
    public function __construct(
        public ?Roll $roll,
        public int $modifier,
        public int $situational,
        public ?int $difficulty,
        public int $total,
        public array $bands,
        public array $bounds,
        public OutcomeBand $band,
        public ?CheckTestPoolRoll $pool = null,
        public ?CheckTestPush $push = null,
        public ?int $rolledSuccesses = null,
    ) {
    }
}
