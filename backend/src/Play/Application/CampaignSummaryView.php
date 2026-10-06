<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * One campaign in a player's campaign list.
 */
final readonly class CampaignSummaryView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $gameSystemKey,
        public string $gameSystemName,
        public int $releaseVersion,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
