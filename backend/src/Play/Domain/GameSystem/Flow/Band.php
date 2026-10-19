<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

use App\Formula\Domain\Formula;

/**
 * An Outcome band of a roll or Condition step: values up to "upTo" (a literal or a Formula over Trackers); the last band has none and catches the rest.
 * The optional label names the outcome (releases stored before bands could be labelled have none).
 */
final readonly class Band
{
    public function __construct(
        public int|Formula|null $upTo,
        public Outcome $outcome,
        public ?string $label = null,
    ) {
    }
}
