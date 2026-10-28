<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlan;
use App\Play\Domain\GameSystem\GearEntry;

/**
 * The answer to PreviewReleaseUpgrade: no plan when the campaign already plays the latest release,
 * else the Upgrade plan the player can confirm (its fingerprint stands for exactly that change).
 */
final readonly class ReleaseUpgradePreview
{
    /**
     * @param list<GearEntry> $targetGear the gear catalog of the release the plan moves to, in order; empty without a plan
     */
    public function __construct(
        public int $currentVersion,
        public ?ReleaseUpgradePlan $plan,
        public array $targetGear = [],
    ) {
    }

    public function upToDate(): bool
    {
        return !$this->plan instanceof ReleaseUpgradePlan;
    }
}
