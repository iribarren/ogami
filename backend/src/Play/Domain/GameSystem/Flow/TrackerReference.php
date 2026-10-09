<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * The current value of a Tracker, where a number is expected ({"tracker": key}).
 */
final readonly class TrackerReference
{
    public function __construct(
        public string $tracker,
    ) {
    }
}
