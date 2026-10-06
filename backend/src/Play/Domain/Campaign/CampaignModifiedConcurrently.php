<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * Another request saved the campaign after this one loaded it. Saving would overwrite that change
 * (a lost session or scene), so the save is refused; the caller reloads and tries again.
 */
final class CampaignModifiedConcurrently extends \DomainException
{
    public static function withId(CampaignId $id): self
    {
        return new self(\sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id->toString()));
    }
}
