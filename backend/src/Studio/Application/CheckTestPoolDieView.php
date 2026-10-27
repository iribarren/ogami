<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Domain\PoolDie;

/**
 * A die of a test roll's pool: its face, whether it succeeded, and whether a push re-rolled it, and the group of dice it belongs to (`pool` or `hunger`, ADR 0044).
 */
final readonly class CheckTestPoolDieView
{
    public function __construct(
        public int $face,
        public bool $success,
        public bool $rerolled,
        public string $group,
    ) {
    }

    public static function fromDie(PoolDie $die): self
    {
        return new self($die->face(), $die->isSuccess(), $die->isRerolled(), $die->group()->value);
    }
}
