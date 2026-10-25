<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\InvalidCheckPicks;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Shared\Application\Bus\CommandHandler;

/**
 * Pushes the pool roll of a journal entry from the sheet (ADR 0041), through CheckPusher: the roll is
 * pushed and the cost applied to the Campaign in memory, and only then are the Campaign and the
 * completed entry saved, in the command bus transaction. Any failure on the way keeps nothing, so
 * the push is all or nothing.
 */
final readonly class PushCheckHandler implements CommandHandler
{
    public function __construct(
        private CampaignJournal $journal,
        private JournalEntryRepository $entries,
        private CheckPusher $pusher,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws JournalEntryNotFound            when the id is invalid or unknown, or the entry belongs to another campaign
     * @throws CheckCannotBePushed             when the entry is not the latest (also when the push is kept), not a pool check,
     *                                         recorded by a Flow step, without a failed die, or the pinned check is gone,
     *                                         changed or has no push, or the Character is gone
     * @throws CheckAlreadyPushed              when the roll was pushed already, also by a concurrent push
     * @throws CheckEvaluationFailed           when a band of the check cannot be evaluated for the Character
     * @throws FlowFormulaFailed               when a value of the cost cannot be evaluated
     * @throws InvalidCheckPicks               when the picks do not fit the cost
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(PushCheck $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);
        $entry = $this->entry($command->entryId, $campaign);
        $release = $this->journal->pinnedSnapshot($campaign);
        [$pushed] = $this->pusher->push($campaign, $release, $entry, $command->picks, fromTheSheet: true);
        $this->pusher->keep($campaign, $pushed, fromTheSheet: true);
    }

    /**
     * @throws JournalEntryNotFound
     */
    private function entry(string $entryId, Campaign $campaign): JournalEntry
    {
        try {
            $entry = $this->entries->ofId(JournalEntryId::fromString($entryId));
        } catch (InvalidJournalEntryId) {
            throw JournalEntryNotFound::withId($entryId);
        }

        if (!$entry instanceof JournalEntry || !$entry->campaignId()->equals($campaign->id())) {
            throw JournalEntryNotFound::withId($entryId);
        }

        return $entry;
    }
}
