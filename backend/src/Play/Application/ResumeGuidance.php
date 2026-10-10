<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Resumes the guidance of the campaign's FlowRun where it stopped.
 */
final readonly class ResumeGuidance implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
    ) {
    }
}
