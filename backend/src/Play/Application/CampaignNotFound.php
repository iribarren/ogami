<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * No campaign with this id belongs to the requesting player. A campaign of another player is
 * reported exactly like an unknown one, so its existence does not leak.
 */
final class CampaignNotFound extends \RuntimeException
{
    public static function withId(string $campaignId): self
    {
        return new self(\sprintf('Campaign "%s" not found.', $campaignId));
    }
}
