<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * One die of a pool roll: the face it landed on and whether it succeeded.
 */
final readonly class PoolDie
{
    public function __construct(
        private int $face,
        private bool $success,
    ) {
    }

    public function face(): int
    {
        return $this->face;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}
