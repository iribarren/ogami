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
use App\Play\Domain\GameSystem\Check\PushResult;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\CheckPoolContent;
use App\Play\Domain\Journal\CheckPushContent;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryNotTheLatest;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\PoolRoll;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * Pushes the pool roll of a journal entry (ADR 0041), for the sheet (PushCheckHandler) and for a
 * check step whose roll pends (ResolvePendingFlowCheckHandler). push() checks and computes
 * everything and applies the cost to the Campaign in memory; keep() saves the Campaign and the
 * completed entry, in the command bus transaction. A failure on the way keeps nothing, so a push is
 * all or nothing, with whatever else the command changes.
 *
 * From the sheet, only the campaign's latest entry is pushed, never a Flow step's roll; the latest
 * rule is checked again where the push is kept. A step pushes its own roll wherever it is.
 */
final readonly class CheckPusher
{
    public function __construct(
        private JournalEntryRepository $entries,
        private CampaignRepository $campaigns,
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @param list<string> $picks the option the player picks for each mark without an option of the cost
     *                            that has an unmarked option left, in cost order
     *
     * @return array{JournalEntry, PushResult} the completed entry, still to keep, and the pushed roll with its band
     *
     * @throws CheckCannotBePushed   when the entry is not a pool check, (from the sheet) not the latest or recorded by a Flow step,
     *                               has no failed die, or the pinned check is gone, changed or has no push, or the Character is gone
     * @throws CheckAlreadyPushed    when the roll was pushed already
     * @throws CheckEvaluationFailed when a band of the check cannot be evaluated for the Character
     * @throws FlowFormulaFailed     when a value of the cost cannot be evaluated
     * @throws InvalidCheckPicks     when the picks do not fit the cost
     */
    public function push(Campaign $campaign, GameSystemSnapshot $release, JournalEntry $entry, array $picks, bool $fromTheSheet): array
    {
        $content = $entry->content();
        $pool = $content instanceof CheckContent ? $content->pool() : null;
        if (!$content instanceof CheckContent || !$pool instanceof CheckPoolContent) {
            throw CheckCannotBePushed::notAPoolCheck();
        }

        if ($fromTheSheet) {
            $this->assertPushableFromTheSheet($entry, $campaign);
        }

        if ($pool->push instanceof CheckPushContent) {
            throw CheckAlreadyPushed::entry($entry->id());
        }

        if ($pool->successes === $pool->size) {
            throw CheckCannotBePushed::noFailedDie();
        }

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

        $changes = $this->applyCost($check->push, new CampaignEffects($campaign, $release, $this->random), $content->characterId(), $picks);

        return [$entry->pushedWith($content->pushed($check->push->label, $result, $changes)), $result];
    }

    /**
     * Saves the Campaign, then the completed entry: the campaign first, since a later flush of the
     * unit of work would surface a concurrent save as a raw lock error.
     *
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     * @throws CheckAlreadyPushed           when a concurrent push got there first
     * @throws CheckCannotBePushed          from the sheet, when another request recorded a later entry meanwhile
     */
    public function keep(Campaign $campaign, JournalEntry $pushed, bool $fromTheSheet): void
    {
        if ($fromTheSheet) {
            // Before the Campaign save: the entry's guard needs the campaign lock, and taking it after
            // the save's weaker lock would be an upgrade that can deadlock with a concurrent record.
            $this->entries->lockCampaignForPush($campaign->id());
        }

        $this->campaigns->save($campaign);
        try {
            // The latest rule is checked again where the push is kept: an entry recorded meanwhile wins.
            $this->entries->savePush($pushed, asLatest: $fromTheSheet);
        } catch (JournalEntryNotTheLatest) {
            throw CheckCannotBePushed::notTheLatestEntry();
        }
    }

    /**
     * @throws CheckCannotBePushed
     */
    private function assertPushableFromTheSheet(JournalEntry $entry, Campaign $campaign): void
    {
        $latest = $this->entries->latestOfCampaign($campaign->id());
        if (!$latest instanceof JournalEntry || !$latest->id()->equals($entry->id())) {
            throw CheckCannotBePushed::notTheLatestEntry();
        }

        if ($entry->flowStep() instanceof FlowStepSnapshot) {
            throw CheckCannotBePushed::recordedByAFlowStep();
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
