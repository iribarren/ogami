<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\InvalidCampaignId;

/**
 * Loads a campaign on behalf of a player: only the owner can see or change it.
 */
final readonly class OwnedCampaigns
{
    public function __construct(
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @throws CampaignNotFound when the id is invalid or unknown, or the campaign belongs to someone else
     */
    public function get(string $campaignId, string $userId): Campaign
    {
        try {
            $campaign = $this->campaigns->ofId(CampaignId::fromString($campaignId));
        } catch (InvalidCampaignId) {
            throw CampaignNotFound::withId($campaignId);
        }

        if (!$campaign instanceof Campaign || !$campaign->isOwnedBy($userId)) {
            throw CampaignNotFound::withId($campaignId);
        }

        return $campaign;
    }
}
