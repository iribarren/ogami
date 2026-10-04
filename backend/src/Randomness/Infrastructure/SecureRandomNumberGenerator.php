<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure;

use App\Randomness\Domain\RandomNumberGenerator;

/**
 * Adapter: cryptographically secure randomness from the PHP engine.
 */
final readonly class SecureRandomNumberGenerator implements RandomNumberGenerator
{
    public function between(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
