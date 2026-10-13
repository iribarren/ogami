<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * A resource track {current, max}, such as hit points. A fixed max when the field sets one, else
 * each Character sets its own; it starts full.
 */
final readonly class TrackField extends Field
{
    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        public ?int $max = null,
    ) {
        parent::__construct($key, $label, $hint, $group);
    }
}
