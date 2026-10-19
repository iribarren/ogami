<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;

/**
 * Journals what the Flow decided on its own in a FlowRun command that records no entry of its own
 * (skipping a step, ending a scene, picking a Scene Type...): one flowOutcome entry in the scene the
 * command left current, when the command produced any note. A handler takes the entry before it
 * saves the campaign (so no note is left on what is stored) and records it after.
 */
final readonly class FlowOutcomes
{
    public function __construct(
        private CampaignJournal $journal,
        private JournalEntryIdGenerator $entryIds,
    ) {
    }

    /**
     * Takes the campaign's notes and builds their entry; null when there is none, or no scene to put it in.
     *
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists
     */
    public function take(Campaign $campaign): ?JournalEntry
    {
        return $this->journal->flowOutcome($this->entryIds, $campaign);
    }

    public function record(?JournalEntry $entry): void
    {
        if ($entry instanceof JournalEntry) {
            $this->journal->add($entry);
        }
    }
}
