<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;

final class InMemoryCampaignRepository implements CampaignRepository
{
    /** @var array<string, Campaign> by id */
    private array $campaigns = [];

    /**
     * @throws \LogicException on a duplicate id, like the primary key
     */
    public function add(Campaign $campaign): void
    {
        $id = $campaign->id()->toString();
        if (isset($this->campaigns[$id])) {
            throw new \LogicException(\sprintf('A campaign with id "%s" already exists.', $id));
        }

        $this->campaigns[$id] = $campaign;
    }

    /**
     * @throws \LogicException when the campaign was never added
     */
    public function save(Campaign $campaign): void
    {
        $id = $campaign->id()->toString();
        if (!isset($this->campaigns[$id])) {
            throw new \LogicException(\sprintf('No campaign with id "%s" to save.', $id));
        }

        $this->campaigns[$id] = $campaign;
    }

    public function ofId(CampaignId $id): ?Campaign
    {
        return $this->campaigns[$id->toString()] ?? null;
    }

    public function ownedBy(string $ownerId): array
    {
        $owned = array_values(array_filter(
            $this->campaigns,
            static fn (Campaign $campaign): bool => $campaign->isOwnedBy($ownerId),
        ));

        usort($owned, static fn (Campaign $a, Campaign $b): int => [$b->createdAt(), $b->id()->toString()] <=> [$a->createdAt(), $a->id()->toString()]);

        return $owned;
    }
}
