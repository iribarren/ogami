<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * One die of a pool roll: the face it landed on, whether it succeeded and, in a pushed roll, whether
 * the push re-rolled it (a die that failed before the push). The group says whether it is a regular
 * die or a Hunger die (ADR 0044).
 */
final readonly class PoolDie
{
    public function __construct(
        private int $face,
        private bool $success,
        private bool $rerolled = false,
        private DieGroup $group = DieGroup::Pool,
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

    public function isRerolled(): bool
    {
        return $this->rerolled;
    }

    public function group(): DieGroup
    {
        return $this->group;
    }

    public function isHunger(): bool
    {
        return DieGroup::Hunger === $this->group;
    }
}
