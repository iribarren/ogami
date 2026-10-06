<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * Port: where campaigns are kept, with their sessions and scenes.
 *
 * A change to a campaign is kept once save() is called with it, so callers save every change they
 * make. Until then it is not guaranteed either way: it is not kept when nothing else is written,
 * but an adapter that writes a whole unit of work (Doctrine) may keep it along with another write.
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
     * Keeps the changes of a campaign already added (new sessions and scenes). Every change must be
     * saved: only a saved change is guaranteed to be kept.
     *
     * @throws CampaignModifiedConcurrently when the campaign was saved elsewhere since this copy was
     *                                      loaded (or added): nothing of this copy is kept
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
