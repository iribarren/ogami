<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the cast of the current scene of one of the player's campaigns with these NPCs, in this
 * order (guided or free play, hook Scenes too), and with these Characters when given.
 */
final readonly class EditCurrentSceneCast implements Command
{
    /**
     * @param list<string>  $npcIds       NPCs of the campaign, closed ones allowed, none twice
     * @param ?list<string> $characterIds Characters of the campaign, none twice; null keeps the Characters in the cast
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public array $npcIds,
        public ?array $characterIds = null,
    ) {
    }
}
