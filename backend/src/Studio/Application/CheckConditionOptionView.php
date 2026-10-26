<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * An option of a conditions sheet field.
 */
final readonly class CheckConditionOptionView
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
