<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Ends open play in a guided scene ("End scene"): its closing starts.
 */
final readonly class EndFlowScene implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public int $sceneNumber,
    ) {
    }
}
