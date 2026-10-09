<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * The campaign holds no value for this Tracker: its pinned release has no Tracker with this key.
 */
final class UnknownCampaignTracker extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('Tracker "%s" not found.', $key));
    }
}
