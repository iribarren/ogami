<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Performs a Check of the campaign's pinned release for one of its Characters and records the
 * result in the current scene. The caller generates the entry id with JournalEntryIdGenerator.
 */
final readonly class PerformCheck implements Command
{
    /**
     * @param string $check       the key of the check in the pinned release
     * @param string $character   the id of any Character of the campaign, in the Scene or not
     * @param ?int   $difficulty  the Check difficulty; null for the default of the check (and for a check with none)
     * @param int    $situational the Situational modifier, from -100 to 100
     */
    public function __construct(
        public string $entryId,
        public string $campaignId,
        public string $userId,
        public string $check,
        public string $character,
        public ?int $difficulty = null,
        public int $situational = 0,
    ) {
    }
}
