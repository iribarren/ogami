<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * One option of a SelectField (ADR 0048): the key a Character's value holds and the label players see.
 */
final readonly class SelectOption
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
