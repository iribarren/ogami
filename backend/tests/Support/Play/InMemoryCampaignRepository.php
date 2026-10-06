<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;

/**
 * Keeps copies, like a database: a change to a loaded campaign is only kept once it is saved.
 * Cloning is deep enough because a Campaign holds scalars and immutable sessions and scenes.
 *
 * Mimics the Doctrine optimistic lock: each stored campaign has a version bumped by every save,
 * and saving a copy loaded at an older version fails.
 */
final class InMemoryCampaignRepository implements CampaignRepository
{
    /** @var array<string, Campaign> by id */
    private array $campaigns = [];

    /** @var array<string, int> the stored version, by id */
    private array $versions = [];

    /** @var \WeakMap<Campaign, int> the version each handed-out or added copy was taken at */
    private \WeakMap $copyVersions;

    public function __construct()
    {
        $this->copyVersions = new \WeakMap();
    }

    /**
     * @throws CampaignAlreadyExists on a duplicate id, like the primary key
     */
    public function add(Campaign $campaign): void
    {
        $id = $campaign->id()->toString();
        if (isset($this->campaigns[$id])) {
            throw CampaignAlreadyExists::withId($campaign->id());
        }

        $this->campaigns[$id] = clone $campaign;
        $this->versions[$id] = 1;
        $this->copyVersions[$campaign] = 1;
    }

    /**
     * @throws CampaignModifiedConcurrently when another copy was saved since this one was taken
     * @throws \LogicException              when the campaign was never added
     */
    public function save(Campaign $campaign): void
    {
        $id = $campaign->id()->toString();
        if (!isset($this->campaigns[$id], $this->versions[$id])) {
            throw new \LogicException(\sprintf('No campaign with id "%s" to save.', $id));
        }

        if (($this->copyVersions[$campaign] ?? null) !== $this->versions[$id]) {
            throw CampaignModifiedConcurrently::withId($campaign->id());
        }

        $this->campaigns[$id] = clone $campaign;
        $this->copyVersions[$campaign] = ++$this->versions[$id];
    }

    public function ofId(CampaignId $id): ?Campaign
    {
        $campaign = $this->campaigns[$id->toString()] ?? null;

        return null === $campaign ? null : $this->copyOf($campaign);
    }

    public function ownedBy(string $ownerId): array
    {
        $owned = array_map(
            $this->copyOf(...),
            array_values(array_filter(
                $this->campaigns,
                static fn (Campaign $campaign): bool => $campaign->isOwnedBy($ownerId),
            )),
        );

        usort($owned, static fn (Campaign $a, Campaign $b): int => [$b->createdAt(), $b->id()->toString()] <=> [$a->createdAt(), $a->id()->toString()]);

        return $owned;
    }

    private function copyOf(Campaign $stored): Campaign
    {
        $copy = clone $stored;
        $this->copyVersions[$copy] = $this->versions[$stored->id()->toString()] ?? 1;

        return $copy;
    }
}
