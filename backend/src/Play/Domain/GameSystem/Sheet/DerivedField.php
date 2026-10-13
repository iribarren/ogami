<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

use App\Formula\Domain\Formula;

/**
 * A DerivedValue computed from a Formula over the other fields of the sheet and the Campaign's
 * Trackers (ADR 0023). A Character stores no value for it.
 */
final readonly class DerivedField extends Field
{
    public function __construct(
        string $key,
        string $label,
        ?string $hint,
        ?string $group,
        public Formula $formula,
    ) {
        parent::__construct($key, $label, $hint, $group);
    }
}
