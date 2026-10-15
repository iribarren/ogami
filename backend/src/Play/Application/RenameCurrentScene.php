<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Renames the current scene of one of the player's campaigns (guided or free play, hook Scenes
 * too).
 */
final readonly class RenameCurrentScene implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $title,
    ) {
    }
}
