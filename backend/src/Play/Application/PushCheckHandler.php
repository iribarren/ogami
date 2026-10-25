<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignEffects;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\CheckNotPushable;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\InvalidCheckPicks;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\CheckPoolContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryNotTheLatest;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

/**
 * Pushes the pool roll of a journal entry (ADR 0041). Everything is checked and computed before
 * anything is kept: the roll is pushed, the cost is applied to the Campaign in memory, and only then
 * are the Campaign and the completed entry saved, in the command bus transaction. Any failure on the
 * way keeps nothing, so the push is all or nothing.
 */
final readonly class PushCheckHandler implements CommandHandler
{
    public function __construct(
        private CampaignJournal $journal,
        private JournalEntryRepository $entries,
        private CampaignRepository $campaigns,
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws JournalEntryNotFound            when the id is invalid or unknown, or the entry belongs to another campaign
     * @throws CheckCannotBePushed             when the entry is not the latest (also when the push is kept), not a pool check, recorded by a Flow step, without a failed die,
     *                                         or the pinned check is gone, changed or has no push, or the Character is gone
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
        $content = $entry->content();
        $pool = $content instanceof CheckContent ? $content->pool() : null;
        if (!$content instanceof CheckContent || !$pool instanceof CheckPoolContent) {
            throw CheckCannotBePushed::notAPoolCheck();
        }

        $this->assertPushable($entry, $campaign, $pool);
        $check = $release->check($content->checkKey());
        if (!$check instanceof Check || !$check->pool instanceof CheckPool || !$check->push instanceof CheckPush || $check->pool->sides !== $pool->sides || $check->pool->successAt !== $pool->successAt) {
            throw CheckCannotBePushed::checkChanged($content->checkKey());
        }

        $character = $campaign->character(CharacterId::fromString($content->characterId())) ?? throw CheckCannotBePushed::characterGone($content->characterId());
        $roll = PoolRoll::ofFaces(new DicePool($pool->size, $pool->sides, $pool->successAt), array_map(static fn (array $die): int => $die['face'], $pool->dice));
        try {
            $result = $check->push($this->random, SheetValues::of($release->sheet(), $character->values(), $campaign->trackerValuesOf($release)), $roll, $content->difficulty());
        } catch (CheckNotPushable) {
            throw CheckCannotBePushed::noFailedDie();
        }

        $changes = $this->applyCost($check->push, new CampaignEffects($campaign, $release, $this->random), $content->characterId(), $command->picks);
        $pushed = $entry->pushedWith($content->pushed($check->push->label, $result, $changes));
        $this->campaigns->save($campaign);
        try {
            // The latest rule is checked again where the push is kept: an entry recorded meanwhile wins.
            $this->entries->savePush($pushed, asLatest: true);
        } catch (JournalEntryNotTheLatest) {
            throw CheckCannotBePushed::notTheLatestEntry();
        }
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

    /**
     * @throws CheckCannotBePushed
     * @throws CheckAlreadyPushed
     */
    private function assertPushable(JournalEntry $entry, Campaign $campaign, CheckPoolContent $pool): void
    {
        $latest = $this->entries->latestOfCampaign($campaign->id());
        if (!$latest instanceof JournalEntry || !$latest->id()->equals($entry->id())) {
            throw CheckCannotBePushed::notTheLatestEntry();
        }

        if ($entry->flowStep() instanceof \App\Play\Domain\Journal\FlowStepSnapshot) {
            throw CheckCannotBePushed::recordedByAFlowStep();
        }

        if ($pool->push instanceof \App\Play\Domain\Journal\CheckPushContent) {
            throw CheckAlreadyPushed::entry($entry->id());
        }

        if ($pool->successes === $pool->size) {
            throw CheckCannotBePushed::noFailedDie();
        }
    }

    /**
     * Applies the cost in order, to the campaign in memory. Each mark without an option whose field
     * has an unmarked option left, at that point of the cost, takes the next pick; with every option
     * marked it takes none and marks nothing.
     *
     * @param list<string> $picks
     *
     * @return list<SheetChange> the changes to the Character's sheet, in apply order
     *
     * @throws FlowFormulaFailed
     * @throws InvalidCheckPicks
     */
    private function applyCost(CheckPush $push, CampaignEffects $effects, string $characterId, array $picks): array
    {
        $changes = [];
        foreach ($push->effects as $effect) {
            if ($effect instanceof TrackerEffect) {
                $effects->applyTrackerEffect($effect);

                continue;
            }
            $pick = null;
            if (SheetOperation::Mark === $effect->op && null === $effect->value) {
                $available = $effects->unmarkedOptions($effect->field, $characterId);
                if ([] !== $available) {
                    $pick = array_shift($picks) ?? throw InvalidCheckPicks::missing($effect->field);
                    if (!\in_array($pick, $available, true)) {
                        throw InvalidCheckPicks::notAvailable($effect->field, $pick, $available);
                    }
                }
            }

            $changes[] = $effects->applySheetEffect($effect, $characterId, $pick);
        }

        if ([] !== $picks) {
            throw InvalidCheckPicks::unused($picks, $push->picks());
        }

        return $changes;
    }
}
