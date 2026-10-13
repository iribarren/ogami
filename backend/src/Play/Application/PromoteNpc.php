<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Promotes an NPC of one of the player's campaigns to a Character. The caller generates the id of
 * the new Character (see CharacterIdGenerator).
 */
final readonly class PromoteNpc implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $npcId,
        public string $characterId,
    ) {
    }
}
