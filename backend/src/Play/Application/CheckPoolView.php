<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;

/**
 * The pool of a pool check as a player rolls it: how many dice (a number, or a formula over the
 * sheet with its text spelled in the labels players know), their sides and the face a die succeeds from.
 */
final readonly class CheckPoolView
{
    public function __construct(
        public ?int $count,
        public ?string $countFormula,
        public ?string $countText,
        public int $sides,
        public int $successAt,
    ) {
    }

    public static function of(CheckPool $pool, ReferenceLabels $labels): self
    {
        $formula = $pool->count instanceof Formula ? $pool->count : null;

        return new self(
            $formula instanceof Formula ? null : $pool->count,
            $formula?->toString(),
            $formula instanceof Formula ? $labels->spell($formula) : null,
            $pool->sides,
            $pool->successAt,
        );
    }
}
