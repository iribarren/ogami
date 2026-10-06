<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The chaos factor range a likelihood oracle accepts, and its neutral value.
 */
final readonly class LikelihoodChaosView
{
    public function __construct(
        public int $min,
        public int $max,
        public int $neutral,
    ) {
    }
}
