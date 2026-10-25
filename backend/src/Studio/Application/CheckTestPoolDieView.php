<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Domain\PoolDie;

/**
 * A die of a test roll's pool: its face, whether it succeeded, and whether a push re-rolled it.
 */
final readonly class CheckTestPoolDieView
{
    public function __construct(
        public int $face,
        public bool $success,
        public bool $rerolled,
    ) {
    }

    public static function fromDie(PoolDie $die): self
    {
        return new self($die->face(), $die->isSuccess(), $die->isRerolled());
    }
}
