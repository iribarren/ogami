<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Skips the current suggested step of the guided campaign's FlowRun, a step the player names so
 * that a stale request is refused.
 */
final readonly class SkipFlowStep implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $stepKey,
    ) {
    }
}
