<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for one entry of the journal of one of the player's campaigns, e.g. the entry a Record*
 * command has just recorded.
 *
 * @implements Query<JournalEntryView>
 */
final readonly class GetJournalEntry implements Query
{
    public function __construct(
        public string $entryId,
        public string $campaignId,
        public string $userId,
    ) {
    }
}
