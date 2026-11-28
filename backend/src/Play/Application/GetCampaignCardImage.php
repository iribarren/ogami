<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the bytes of an image the release one of the player's campaigns is pinned to pinned.
 *
 * @implements Query<CardImageBytes>
 */
final readonly class GetCampaignCardImage implements Query
{
    public function __construct(
        public string $userId,
        public string $campaignId,
        public string $sha256,
    ) {
    }
}
