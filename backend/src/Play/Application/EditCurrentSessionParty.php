<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the party of the session under way of one of the player's campaigns with these
 * Characters, in this order.
 */
final readonly class EditCurrentSessionParty implements Command
{
    /**
     * @param list<string> $characterIds Characters of the campaign, none twice
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public array $characterIds,
    ) {
    }
}
