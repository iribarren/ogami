<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlan;

/**
 * The answer to PreviewReleaseUpgrade: no plan when the campaign already plays the latest release,
 * else the Upgrade plan the player can confirm (its fingerprint stands for exactly that change).
 */
final readonly class ReleaseUpgradePreview
{
    public function __construct(
        public int $currentVersion,
        public ?ReleaseUpgradePlan $plan,
    ) {
    }

    public function upToDate(): bool
    {
        return !$this->plan instanceof ReleaseUpgradePlan;
    }
}
