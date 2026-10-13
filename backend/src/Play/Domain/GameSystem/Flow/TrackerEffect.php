<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

use App\Formula\Domain\Formula;

/**
 * Adds to or sets a Tracker, with a literal or a Formula over Trackers (another Tracker's value is the formula {tracker:key}).
 */
final readonly class TrackerEffect implements Effect
{
    public function __construct(
        public string $tracker,
        public TrackerOperation $op,
        public int|Formula $value,
    ) {
    }
}
