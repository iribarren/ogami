<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Adds an open Thread to one of the player's campaigns. The caller generates the id (see ThreadIdGenerator).
 */
final readonly class AddThread implements Command
{
    /**
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $threadId,
        public string $name,
        public array $tags,
        public array $freeTags,
        public int $weight,
    ) {
    }
}
