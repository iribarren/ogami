<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Ends the loop phase of the guided campaign's FlowRun ("Move on"): at once at the scene pick,
 * else after the current scene.
 */
final readonly class MoveOn implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $phaseKey,
    ) {
    }
}
