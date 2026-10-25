<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\NoPendingCheck;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\InvalidCheckPicks;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Play\Domain\Journal\SheetChangeContent;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

/**
 * Keeps or pushes the roll the current check step waits on (ADR 0041), then completes the step the
 * way CompleteFlowStepHandler completes one at once: the step's sheet Effects are recorded right
 * after its check entry, and what the Flow decided on its own after that. A push goes through
 * CheckPusher (the cost applies before the step's Effects). Everything is computed on the Campaign
 * in memory and saved in the command bus transaction: the cost, the pushed entry and the completed
 * step are kept together or not at all.
 */
final readonly class ResolvePendingFlowCheckHandler implements CommandHandler
{
    public function __construct(
        private CampaignJournal $journal,
        private JournalEntryRepository $entries,
        private CampaignRepository $campaigns,
        private CheckPusher $pusher,
        private RandomNumberGenerator $random,
        private Clock $clock,
        private JournalEntryIdGenerator $entryIds,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws FlowRunNotActive                       when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws NoPendingCheck                         when no roll waits for Keep or Push
     * @throws FlowRunPositionMismatch                when the roll waits at another step
     * @throws CheckCannotBePushed             (push) when the roll has no failed die, the pinned check is gone, changed or has no push, or the Character is gone
     * @throws CheckAlreadyPushed              (push) when the roll was pushed already, also by a concurrent push
     * @throws CheckEvaluationFailed           (push) when a band of the check cannot be evaluated for the Character
     * @throws InvalidCheckPicks               (push) when the picks do not fit the cost
     * @throws FlowFormulaFailed                      when a value of the cost or an Effect's formula cannot be evaluated
     * @throws CampaignLimitReached                   when the next scene does not fit the session
     * @throws JournalEntryAlreadyExists
     * @throws CampaignModifiedConcurrently           when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound              when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws UnknownFlow                            when the pinned release has no Flow with the campaign's key
     */
    public function __invoke(ResolvePendingFlowCheck $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);
        $release = $this->journal->pinnedSnapshot($campaign);
        $at = $this->clock->now();
        $pending = $campaign->pendingFlowCheck($command->stepKey, $release, $at);
        $entry = $this->entries->ofId(JournalEntryId::fromString($pending->entryId)) ?? throw new \LogicException(\sprintf('The roll of step "%s" pends without its journal entry "%s".', $pending->stepKey, $pending->entryId));

        $pushed = null;
        $bandKey = null;
        if ($command->push) {
            [$pushed, $result] = $this->pusher->push($campaign, $release, $entry, $command->picks, fromTheSheet: false);
            $bandKey = $result->band->key;
        }

        $changes = $campaign->resolvePendingFlowCheck($command->stepKey, $bandKey, $release, $at, $this->random);
        $sheetEntry = [] === $changes ? null : $this->journal->following($entry, $this->entryIds->generate()->toString(), $this->sheetChangeContent($campaign, $pending->characterId, $changes));
        $outcomeEntry = $this->journal->flowOutcome($this->entryIds, $campaign, $sheetEntry ?? $entry);
        // The campaign is saved first (CheckPusher::keep() saves it with the push), as in CompleteFlowStepHandler.
        if ($pushed instanceof JournalEntry) {
            $this->pusher->keep($campaign, $pushed, fromTheSheet: false);
        } else {
            $this->campaigns->save($campaign);
        }

        if ($sheetEntry instanceof JournalEntry) {
            $this->journal->add($sheetEntry);
        }

        if ($outcomeEntry instanceof JournalEntry) {
            $this->journal->add($outcomeEntry);
        }
    }

    /**
     * @param non-empty-list<SheetChange> $changes
     */
    private function sheetChangeContent(Campaign $campaign, string $characterId, array $changes): SheetChangeContent
    {
        return SheetChangeContent::of($characterId, $campaign->itemNames(ItemKind::Character, [$characterId])[0] ?? '', array_map(static fn (SheetChange $change): array => $change->toArray(), $changes));
    }
}
