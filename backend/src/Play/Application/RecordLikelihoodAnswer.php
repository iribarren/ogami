<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Asks a likelihood oracle of the campaign's pinned release a yes/no question and records the
 * answer in the current scene. The caller generates the entry id with JournalEntryIdGenerator.
 */
final readonly class RecordLikelihoodAnswer implements Command
{
    /**
     * @param string      $likelihood  the likelihood level key
     * @param int|null    $chaosFactor null for the oracle's neutral factor (or when it has no chaos)
     * @param string|null $question    trimmed; blank means no question
     */
    public function __construct(
        public string $entryId,
        public string $campaignId,
        public string $userId,
        public string $oracleKey,
        public string $likelihood,
        public ?int $chaosFactor = null,
        public ?string $question = null,
    ) {
    }
}
