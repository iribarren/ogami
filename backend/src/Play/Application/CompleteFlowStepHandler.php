<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\ChaosFactorBoundToTracker;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\UnknownCampaignTracker;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\Step;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\ChoiceContent;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RollContent;
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
    ) {
    }

    /**
     * The entry is built before the step completes, so it belongs to the scene the step was in even
     * when completing the step starts the next scene, and it is only added once the FlowRun accepts
     * the result: a refused step records nothing and leaves the campaign as it was.
     *
     * @throws CampaignNotFound
     * @throws FlowRunNotActive                when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch         when the FlowRun waits for another step, or for none
     * @throws InvalidStepResult               when a field does not belong to the step's kind, a required one is missing, the text is blank or the option unknown
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
        $step = $campaign->activeFlowRunView($release)->step?->step;
        if (!$step instanceof Step || $step->key !== $command->stepKey) {
            throw FlowRunPositionMismatch::at(\sprintf('step "%s"', $command->stepKey), $step instanceof Step ? \sprintf('step "%s"', $step->key) : 'no step');
        }

        [$result, $content] = $this->resultOf($command, $step, $campaign, $release);
        $at = $this->clock->now();
        $entry = $this->journal->entry($command->entryId, $campaign, $content, $at, FlowStepSnapshot::of($step->key, $step->title, $step->prompt));
        $campaign->completeFlowStep($step->key, $result, $release, $at);
        $this->journal->add($entry);
        $this->campaigns->save($campaign);
    }

    /**
     * @return array{StepResult, JournalEntryContent} the result the FlowRun takes and the journal content
     */
    private function resultOf(CompleteFlowStep $command, Step $step, Campaign $campaign, GameSystemSnapshot $release): array
    {
        return match (true) {
            $step instanceof PromptStep => $this->prompt($command, $step),
            $step instanceof RollStep => $this->roll($command, $step),
            $step instanceof TableStep => $this->table($command, $step, $release),
            $step instanceof OracleStep => $this->oracle($command, $step, $campaign, $release),
            $step instanceof ChoiceStep => $this->choice($command, $step),
            default => throw InvalidStepResult::conditionStep($step->key),
        };
    }

    /**
     * @return array{StepResult, JournalEntryContent}
     */
    private function prompt(CompleteFlowStep $command, PromptStep $step): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'text');
        $result = StepResult::prompt($command->text ?? throw InvalidStepResult::missingField($step->key, StepKind::Prompt, 'text'));
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
    private function table(CompleteFlowStep $command, TableStep $step, GameSystemSnapshot $release): array
    {
        $this->refuseFieldsOtherThan($command, $step);
        $result = $release->resolveOracleTable($step->table, $this->random);

        return [StepResult::table($result), OracleTableContent::fromResult($step->table, $release->oracleTableNames()[$step->table], $result)];
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
    private function choice(CompleteFlowStep $command, ChoiceStep $step): array
    {
        $this->refuseFieldsOtherThan($command, $step, 'optionKey');
        $key = $command->optionKey ?? throw InvalidStepResult::missingField($step->key, StepKind::Choice, 'optionKey');
        $option = $step->option($key) ?? throw InvalidStepResult::unknownOption($step->key, $key);

        return [StepResult::choice($key), ChoiceContent::of($step->title, $key, $option->label)];
    }

    /**
     * Fails closed on a field the step's kind does not take, rather than ignoring it.
     *
     * @throws InvalidStepResult
     */
    private function refuseFieldsOtherThan(CompleteFlowStep $command, Step $step, string ...$allowed): void
    {
        $given = ['text' => $command->text, 'optionKey' => $command->optionKey, 'likelihood' => $command->likelihood, 'chaosFactor' => $command->chaosFactor];
        foreach ($given as $field => $value) {
            if (null !== $value && !\in_array($field, $allowed, true)) {
                throw InvalidStepResult::unexpectedField($step->key, StepKind::of($step), $field);
            }
        }
    }
}
