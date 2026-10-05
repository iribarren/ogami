<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * Port: the source of randomness. Injected so that rolls are deterministic in tests.
 */
interface RandomNumberGenerator
{
    /**
     * Returns a uniformly distributed integer in [$min, $max], both inclusive.
     */
    public function between(int $min, int $max): int;
}
