<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Starts the next scene in the current session of one of the player's campaigns.
 */
final readonly class StartScene implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $title,
    ) {
    }
}
