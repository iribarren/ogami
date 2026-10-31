<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Reopens a Place of one of the player's campaigns. Reopening an open one changes nothing.
 */
final readonly class ReopenPlace implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $placeId,
    ) {
    }
}
