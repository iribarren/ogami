<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Ends the current session of one of the player's campaigns; play goes on in the next session.
 * With a session number, only that session ends, and only while it is under way.
 */
final readonly class EndSession implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public ?int $sessionNumber = null,
    ) {
    }
}
