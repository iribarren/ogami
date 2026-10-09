<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Adds to or sets a Tracker, with a literal or another Tracker's value.
 */
final readonly class TrackerEffect implements Effect
{
    public function __construct(
        public string $tracker,
        public TrackerOperation $op,
        public int|TrackerReference $value,
    ) {
    }
}
