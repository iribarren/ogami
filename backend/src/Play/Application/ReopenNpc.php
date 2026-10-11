<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Reopens an NPC of one of the player's campaigns. Reopening an open one changes nothing.
 */
final readonly class ReopenNpc implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $npcId,
    ) {
    }
}
