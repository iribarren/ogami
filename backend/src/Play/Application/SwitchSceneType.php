<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Switches the Scene Type of the current scene of one of the player's campaigns by hand (free
 * play), to a Scene Type of its pinned release.
 */
final readonly class SwitchSceneType implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $sceneType,
    ) {
    }
}
