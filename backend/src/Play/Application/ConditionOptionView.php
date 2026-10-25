<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * One option of a conditions field, as the pinned release declares it (ADR 0041).
 */
final readonly class ConditionOptionView
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
