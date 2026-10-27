<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Randomness\Domain\PoolRoll;

/**
 * What pushing a pool check produced: the roll after the push, its total and the Outcome band that
 * total falls in, and the special result the roll has now (ADR 0044).
 */
final readonly class PushResult
{
    public function __construct(
        public PoolRoll $roll,
        public int $total,
        public CheckBand $band,
        public ?SpecialResult $special = null,
    ) {
    }
}
