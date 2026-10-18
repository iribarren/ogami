<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * The campaign already plays the target release or a newer one: there is nothing to upgrade to.
 */
final class CampaignUpToDate extends \DomainException
{
    public static function at(int $pinnedVersion, int $targetVersion): self
    {
        return new self(\sprintf('The campaign plays release %d; release %d is not newer.', $pinnedVersion, $targetVersion));
    }
}
