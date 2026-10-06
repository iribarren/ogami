<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Writes a note in the current scene of the player's campaign. The caller generates the entry id
 * with JournalEntryIdGenerator, so it can read the entry back once the command is handled.
 */
final readonly class RecordNote implements Command
{
    public function __construct(
        public string $entryId,
        public string $campaignId,
        public string $userId,
        public string $text,
    ) {
    }
}
