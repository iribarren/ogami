<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\ChaosFactorBoundToTracker;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\CheckStepPending;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\PickDraw;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\ShopLine;
use App\Play\Domain\Campaign\FlowRun\ShopTrade;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\InsufficientFunds;
use App\Play\Domain\Campaign\InventoryFull;
use App\Play\Domain\Campaign\UnknownCampaignTracker;
use App\Play\Domain\GameSystem\Check\CheckDifficultyNotDeclared;
use App\Play\Domain\GameSystem\Check\CheckDifficultyOutOfRange;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Check\SituationalModifierOutOfRange;
use App\Play\Domain\GameSystem\Flow\CharacterStep;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\PickMode;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\ShopStep;
use App\Play\Domain\GameSystem\Flow\Step;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\ChoiceContent;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\PickContent;
use App\Play\Domain\Journal\RollContent;
use App\Play\Domain\Journal\SheetChangeContent;
use App\Play\Domain\Journal\ShopContent;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

final readonly class CompleteFlowStepHandler implements CommandHandler
{
    public function __construct(
        private CampaignJournal $journal,
        private CampaignRepository $campaigns,
        private RandomNumberGenerator $random,
        private Clock $clock,
        private JournalEntryIdGenerator $entryIds,
    ) {
    }

    /**
     * The entry names the step with its title and prompt as the player saw them (placeholders
     * rendered). It is built before the step completes, so it belongs to the scene the step was in even
     * when completing the step starts the next scene, and it is only added once the FlowRun accepts
     * the result: a refused step records nothing and leaves the campaign as it was. When the step's
     * sheet Effects changed its Character, a second entry records the changes right after it, and when the
     * Flow decided something on its own (a band label, a Tracker, a switch...) a last one tells it. A
     * check step's roll that can be pushed records its entry only: the step pends until Keep or Push
     * (ResolvePendingFlowCheckHandler).
     *
     * @throws CampaignNotFound
     * @throws FlowRunNotActive                when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending                when a check step's roll waits for Keep or Push, this step's included
     * @throws FlowRunPositionMismatch         when the FlowRun waits for another step, or for none
     * @throws InvalidStepResult               when a field does not belong to the step's kind, a required one is missing, the text is blank, the option unknown, a picked id is no candidate, the Character is unknown or (check step) not in the scene, or a difficulty is sent for a step that fixes one
     * @throws FlowFormulaFailed               when a check step's difficulty formula, or an Effect's formula, cannot be evaluated
     * @throws InsufficientFunds               when a choice option's, a prompt's or a character step's Effect, or a shop transaction, would take the currency under its floor
     * @throws InventoryFull                   when a shop purchase takes an inventory field over its slots or stacks
     * @throws CheckDifficultyNotDeclared      when a difficulty is sent for a check that declares none
     * @throws CheckDifficultyOutOfRange       when the difficulty is outside the min..max of the check
     * @throws SituationalModifierOutOfRange   when the Situational modifier is outside -100..100
     * @throws CheckEvaluationFailed           when the modifier or a band of the check cannot be evaluated for the Character
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists
     * @throws InvalidJournalEntryContent      when the text or the step's question is too long
     * @throws InvalidDiceExpression           when the step's dice cannot be rolled
     * @throws UnknownGameSystemOracle         when the pinned release has no oracle with the step's key
     * @throws InvalidLikelihoodOracle         when the likelihood level is unknown, or the chaos factor is out of range or not expected
     * @throws ChaosFactorBoundToTracker       when a chaos factor is sent for an oracle that takes it from a Tracker
     * @throws UnknownCampaignTracker          when the oracle is bound to a Tracker the pinned release does not declare
     * @throws CampaignLimitReached            when the next scene does not fit the session
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws UnknownFlow                     when the pinned release has no Flow with the campaign's key
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     */
    public function __invoke(CompleteFlowStep $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);
        $release = $this->journal->pinnedSnapshot($campaign);
        $view = $campaign->activeFlowRunView($release)->step;
        if (!$view instanceof FlowStepView || $view->step->key !== $command->stepKey) {
            throw FlowRunPositionMismatch::at(\sprintf('step "%s"', $command->stepKey), $view instanceof FlowStepView ? \sprintf('step "%s"', $view->step->key) : 'no step');
        }

        $step = $view->step;

        $at = $this->clock->now();
        [$result, $content] = $this->resultOf($command, $view, $campaign, $release, $at);
        $entry = $this->journal->entry($command->entryId, $campaign, $content, $at, FlowStepSnapshot::of($step->key, $view->title, $view->prompt));
        $changes = $campaign->completeFlowStep($step->key, $result, $release, $at, $this->random, $entry->id()->toString());
        $sheetEntry = [] === $changes ? null : $this->journal->following($entry, $this->entryIds->generate()->toString(), $this->sheetChangeContent($result, $campaign, $changes));
        $outcomeEntry = $this->journal->flowOutcome($this->entryIds, $campaign, $sheetEntry ?? $entry);
        // The campaign is saved first: adding the entry flushes the whole unit of work, so a concurrent
        // save would surface there as a raw lock error instead of CampaignModifiedConcurrently. Every
        // write belongs to the command bus transaction, so either all persist or none does.
        $this->campaigns->save($campaign);
        $this->journal->add($entry);
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
    private function sheetChangeContent(StepResult $result, Campaign $campaign, array $changes): SheetChangeContent
    {
        // Only a step that changes the sheet gets here, and each names its Character.
        $id = $result->characterId ?? throw new \LogicException('A sheet change without a Character.');

        return SheetChangeContent::of($id, $campaign->itemNames(ItemKind::Character, [$id])[0] ?? '', array_map(static fn (SheetChange $change): array => $change->toArray(), $changes));
    }

    /**
     * @return array{StepResult, JournalEntryContent} the result the FlowRun takes and the journal content
     */
    private function resultOf(CompleteFlowStep $command, FlowStepView $view, Campaign $campaign, GameSystemSnapshot $release, \DateTimeImmutable $at): array
    {
        $step = $view->step;

        return match (true) {
            $step instanceof PromptStep => $this->prompt($command, $step, $campaign),
            $step instanceof RollStep => $this->roll($command, $step),
            $step instanceof TableStep => $this->table($command, $step, $view->table ?? $step->table, $release),
            $step instanceof OracleStep => $this->oracle($command, $step, $campaign, $release),
            $step instanceof ChoiceStep => $this->choice($command, $step, $campaign),
            $step instanceof PickStep => $this->pick($command, $step, $campaign),
            $step instanceof CharacterStep => $this->character($command, $step, $campaign),
            $step instanceof CheckStep => $this->check($command, $step, $campaign, $release, $at),
            $step instanceof ShopStep => $this->shop($command, $step, $view, $campaign, $release),
            default => throw InvalidStepResult::conditionStep($step->key),
        };
    }

    /**
     * @return array{StepResult, JournalEntryContent}
     */
    private function prompt(CompleteFlowStep $command, PromptStep $step, Campaign $campaign): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'text', 'characterId');
        $text = $command->text ?? throw InvalidStepResult::missingField($step->key, StepKind::Prompt, 'text');
        $result = StepResult::prompt($text, $this->sheetCharacter($command, $step, $step->changesSheet(), $campaign));
        if ('' === $result->answer) {
            throw InvalidStepResult::blankAnswer($step->key);
        }

        return [$result, NoteContent::of($result->answer)];
    }

    /**
     * @return array{StepResult, JournalEntryContent}
     */
    private function roll(CompleteFlowStep $command, RollStep $step): array
    {
        $this->refuseFieldsOtherThan($command, $step);
        $roll = DiceExpression::fromString($step->dice)->roll($this->random);

        return [StepResult::roll($roll), RollContent::fromRoll($roll)];
    }

    /**
     * @return array{StepResult, JournalEntryContent}
     */
    private function table(CompleteFlowStep $command, TableStep $step, string $table, GameSystemSnapshot $release): array
    {
        $this->refuseFieldsOtherThan($command, $step);
        $result = $release->resolveOracleTable($table, $this->random);

        return [StepResult::table($result), OracleTableContent::fromResult($table, $release->oracleTableNames()[$table], $result, $release->rolledSceneTypes($result))];
    }

    /**
     * @return array{StepResult, JournalEntryContent}
     */
    private function oracle(CompleteFlowStep $command, OracleStep $step, Campaign $campaign, GameSystemSnapshot $release): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'likelihood', 'chaosFactor');
        if (null !== $step->likelihood && null !== $command->likelihood) {
            throw InvalidStepResult::fixedLikelihood($step->key, $step->likelihood);
        }

        $level = $step->likelihood ?? $command->likelihood ?? throw InvalidStepResult::missingField($step->key, StepKind::Oracle, 'likelihood');
        $oracle = $release->likelihoodOracle($step->oracle);
        // A chaos bound to a Tracker takes the campaign's value of it.
        $answer = $oracle->oracle()->ask($level, $campaign->chaosFactorFor($oracle, $command->chaosFactor, $release), $this->random);

        return [StepResult::oracle($answer), LikelihoodContent::fromAnswer($oracle->key(), $oracle->name(), $step->title, $answer)];
    }

    /**
     * @return array{StepResult, JournalEntryContent}
     */
    private function choice(CompleteFlowStep $command, ChoiceStep $step, Campaign $campaign): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'optionKey', 'characterId');
        $key = $command->optionKey ?? throw InvalidStepResult::missingField($step->key, StepKind::Choice, 'optionKey');
        $option = $step->option($key) ?? throw InvalidStepResult::unknownOption($step->key, $key);

        return [StepResult::choice($key, $this->sheetCharacter($command, $step, $option->changesSheet(), $campaign)), ChoiceContent::of($step->title, $key, $option->label)];
    }

    /**
     * The Character a choice option or a prompt that changes a sheet completes with: required and in
     * the campaign when it does, refused when it does not.
     *
     * @throws InvalidStepResult
     */
    private function sheetCharacter(CompleteFlowStep $command, Step $step, bool $changesSheet, Campaign $campaign): ?string
    {
        if (!$changesSheet) {
            if (null !== $command->characterId) {
                throw InvalidStepResult::unexpectedField($step->key, StepKind::of($step), 'characterId');
            }

            return null;
        }

        $id = $command->characterId ?? throw InvalidStepResult::missingField($step->key, StepKind::of($step), 'characterId');
        if (null === ($campaign->itemNames(ItemKind::Character, [$id])[0] ?? null)) {
            throw InvalidStepResult::unknownSheetCharacter($step->key, StepKind::of($step), $id);
        }

        return $id;
    }

    /**
     * A random pick draws on the server; a chosen one takes the ids the player sends, which must be
     * candidates. Both are validated again by the FlowRun.
     *
     * @return array{StepResult, JournalEntryContent}
     */
    private function pick(CompleteFlowStep $command, PickStep $step, Campaign $campaign): array
    {
        $candidates = $campaign->pickCandidates($step->of, $step->tags);
        if (PickMode::Random === $step->mode) {
            $this->refuseFieldsOtherThan($command, $step);
            $picked = new PickDraw($this->random)->draw($step, $candidates);
        } else {
            $this->refuseFieldsOtherThan($command, $step, 'pickedIds');
            $picked = $candidates->select($step, $command->pickedIds ?? throw InvalidStepResult::missingField($step->key, StepKind::Pick, 'pickedIds'));
        }

        return [
            StepResult::pick($step->of, $picked),
            PickContent::of($step->of, $step->tags, array_map(static fn (PickedItem $item): array => ['id' => $item->id, 'name' => $item->name], $picked)),
        ];
    }

    /**
     * The player brings an existing Character of the campaign; the journal notes its name.
     *
     * @return array{StepResult, JournalEntryContent}
     */
    private function character(CompleteFlowStep $command, CharacterStep $step, Campaign $campaign): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'characterId');
        $id = $command->characterId ?? throw InvalidStepResult::missingField($step->key, StepKind::Character, 'characterId');
        $name = $campaign->itemNames(ItemKind::Character, [$id])[0] ?? throw InvalidStepResult::unknownCharacter($step->key, $id);

        return [StepResult::character($id), NoteContent::of($name)];
    }

    /**
     * Performs the step's check (the one of its set the player picked) for the Character. The FlowRun validates first (the Character in the
     * scene, the difficulty the step fixes), then the check evaluates everything before it rolls, so a
     * refused or unevaluable check rolls nothing and records nothing.
     *
     * @return array{StepResult, JournalEntryContent}
     */
    private function check(CompleteFlowStep $command, CheckStep $step, Campaign $campaign, GameSystemSnapshot $release, \DateTimeImmutable $at): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'characterId', 'difficulty', 'situational', 'checkKey');
        $id = $command->characterId ?? throw InvalidStepResult::missingField($step->key, StepKind::Check, 'characterId');
        $checkKey = FlowRun::pickedCheck($step, $command->checkKey);
        $difficulty = $campaign->flowCheckDifficulty($step->key, $id, $command->difficulty, $release, $at);
        $check = $release->check($checkKey) ?? throw new \LogicException(\sprintf('The pinned release has no check "%s".', $checkKey));
        $character = $campaign->character(CharacterId::fromString($id)) ?? throw InvalidStepResult::unknownCharacter($step->key, $id);
        $result = $check->perform($this->random, SheetValues::of($release->sheet(), $character->values(), $campaign->trackerValuesOf($release)), $difficulty, $command->situational ?? 0);

        return [StepResult::check($id, $result, $step->isSet() ? $checkKey : null), CheckContent::fromResult($id, $character->name(), $check, $result, ReferenceLabels::of($release))];
    }

    /**
     * A Character's transaction at a shop (ADR 0045). The journal records it as the FlowRun will
     * make it: worked out on the Character's values now, so a refused transaction refuses here,
     * before anything changes, and the FlowRun works it out again when it completes the step.
     *
     * @return array{StepResult, JournalEntryContent}
     */
    private function shop(CompleteFlowStep $command, ShopStep $step, FlowStepView $view, Campaign $campaign, GameSystemSnapshot $release): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'characterId', 'buy', 'sell');
        $id = $command->characterId ?? throw InvalidStepResult::missingField($step->key, StepKind::Shop, 'characterId');
        $character = $campaign->character(CharacterId::fromString($id)) ?? throw InvalidStepResult::unknownSheetCharacter($step->key, StepKind::Shop, $id);
        $buy = array_map(static fn (array $line): ShopLine => ShopLine::buy($line['gear'], $line['quantity']), $command->buy ?? []);
        $sell = array_map(static fn (array $line): ShopLine => ShopLine::sell($line['field'], $line['gear'], $line['quantity']), $command->sell ?? []);
        $trade = ShopTrade::of($step, $view->shop->onSale ?? [], $release->sheet(), $character->values(), $buy, $sell);

        return [StepResult::shop($id, $buy, $sell), ShopContent::of($id, $character->name(), $trade->bought, $trade->sold, $trade->before, $trade->after)];
    }

    /**
     * Fails closed on a field the step's kind does not take, rather than ignoring it.
     *
     * @throws InvalidStepResult
     */
    private function refuseFieldsOtherThan(CompleteFlowStep $command, Step $step, string ...$allowed): void
    {
        $given = ['text' => $command->text, 'optionKey' => $command->optionKey, 'likelihood' => $command->likelihood, 'chaosFactor' => $command->chaosFactor, 'pickedIds' => $command->pickedIds, 'characterId' => $command->characterId, 'difficulty' => $command->difficulty, 'situational' => $command->situational, 'checkKey' => $command->checkKey, 'buy' => $command->buy, 'sell' => $command->sell];
        foreach ($given as $field => $value) {
            if (null !== $value && !\in_array($field, $allowed, true)) {
                throw InvalidStepResult::unexpectedField($step->key, StepKind::of($step), $field);
            }
        }
    }
}
