<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Sets the value of a Tracker of one of the player's campaigns by hand, clamped to its range.
 */
final readonly class SetTrackerValue implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $trackerKey,
        public int $value,
    ) {
    }
}
