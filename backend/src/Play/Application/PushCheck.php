<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Pushes the pool check recorded in a journal entry of the campaign (ADR 0041): the dice that failed
 * are re-rolled once, the cost of the push the pinned release declares is applied to the rolling
 * Character, and the same entry is completed with the result.
 */
final readonly class PushCheck implements Command
{
    /**
     * @param string       $entryId the journal entry holding the roll; it must be the campaign's latest entry
     * @param list<string> $picks   the option the player picks for each mark without an option of the cost
     *                              that has an unmarked option left, in cost order
     */
    public function __construct(
        public string $entryId,
        public string $campaignId,
        public string $userId,
        public array $picks = [],
    ) {
    }
}
