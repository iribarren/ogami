<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the cast of the current scene of one of the player's campaigns with these NPCs, in this
 * order (guided or free play, hook Scenes too).
 */
final readonly class EditCurrentSceneCast implements Command
{
    /**
     * @param list<string> $npcIds NPCs of the campaign, closed ones allowed, none twice
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public array $npcIds,
    ) {
    }
}
