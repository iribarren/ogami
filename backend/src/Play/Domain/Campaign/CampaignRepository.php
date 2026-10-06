<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * Port: where campaigns are kept, with their sessions and scenes.
 */
interface CampaignRepository
{
    /**
     * Keeps a new campaign.
     *
     * @throws CampaignAlreadyExists when a campaign with the same id is already kept
     */
    public function add(Campaign $campaign): void;

    /**
     * Keeps the changes of a campaign already added (new sessions and scenes).
     */
    public function save(Campaign $campaign): void;

    public function ofId(CampaignId $id): ?Campaign;

    /**
     * The campaigns of one owner, newest first (creation time, then id, descending).
     *
     * @return list<Campaign>
     */
    public function ownedBy(string $ownerId): array;
}
