<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * One option of a ConditionsField (ADR 0041): the key a Character's marked list holds and the label
 * players see.
 */
final readonly class ConditionOption
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
