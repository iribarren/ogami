<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Rolls a dice expression on the server and records the roll in the current scene of the
 * player's campaign. The caller generates the entry id with JournalEntryIdGenerator.
 */
final readonly class RecordRoll implements Command
{
    public function __construct(
        public string $entryId,
        public string $campaignId,
        public string $userId,
        public string $expression,
    ) {
    }
}
