<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

use App\Formula\Domain\Formula;

/**
 * A resource track {current, max}, such as hit points. A fixed max when the field sets one, else
 * each Character sets its own; it starts full. A start max ($startMax, ADR 0040) is a formula
 * evaluated once when a Character is created, never together with a fixed max.
 */
final readonly class TrackField extends Field
{
    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        public ?int $max = null,
        int $span = 1,
        public ?Formula $startMax = null,
    ) {
        if (null !== $max && $startMax instanceof Formula) {
            throw new \InvalidArgumentException(\sprintf('Track "%s" cannot have both a max and a start max.', $key));
        }

        parent::__construct($key, $label, $hint, $group, $span);
    }
}
