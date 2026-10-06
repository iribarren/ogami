<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the journal of one of the player's campaigns.
 *
 * @implements Query<list<JournalEntryView>>
 */
final readonly class GetJournal implements Query
{
    public function __construct(
        public string $campaignId,
        public string $userId,
    ) {
    }
}
