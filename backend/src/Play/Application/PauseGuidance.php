<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Pauses the guidance of the campaign's FlowRun: the player plays freely until it resumes.
 */
final readonly class PauseGuidance implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
    ) {
    }
}
