<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Upgrades one of the player's campaigns to a newer release of its GameSystem, exactly as the
 * previewed Upgrade plan with this fingerprint says.
 */
final readonly class UpgradeCampaignRelease implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public int $toVersion,
        public string $fingerprint,
    ) {
    }
}
