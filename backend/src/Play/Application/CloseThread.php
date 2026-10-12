<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Closes a Thread of one of the player's campaigns: it is no longer picked or counted. Closing a
 * closed one changes nothing.
 */
final readonly class CloseThread implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $threadId,
    ) {
    }
}
