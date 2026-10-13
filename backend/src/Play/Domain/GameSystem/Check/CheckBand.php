<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;

/**
 * An Outcome band of a check: totals up to "upTo" (a literal, or a Formula over the sheet and, when
 * the check declares one, {check:difficulty}); the last band has none and catches the rest.
 */
final readonly class CheckBand
{
    public function __construct(
        public string $key,
        public string $label,
        public int|Formula|null $upTo = null,
    ) {
    }
}
