<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Rolls on an oracle table of the campaign's pinned release (and on the tables it nests) and
 * records the result in the current scene. The caller generates the entry id with
 * JournalEntryIdGenerator.
 */
final readonly class RecordOracleTableResult implements Command
{
    public function __construct(
        public string $entryId,
        public string $campaignId,
        public string $userId,
        public string $oracleKey,
    ) {
    }
}
