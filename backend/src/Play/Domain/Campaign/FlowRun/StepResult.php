<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Randomness\Domain\Roll;

/**
 * What the player brings to complete a step, typed by step kind: a prompt's answer, a roll, a
 * table result, a likelihood answer, a choice option, the items a pick step picked or the Character a
 * character step names. "answer" is the step's answer text kept for
 * placeholders: the prompt text, the roll total, the entry texts, the answer label, the option key
 * (the FlowRun keeps the option's label) or the picked names joined with ", " (empty when nothing was
 * picked).
 */
final readonly class StepResult
{
    /**
     * @param list<PickedItem> $picked
     */
    private function __construct(
        public StepKind $kind,
        public string $answer,
        public ?Roll $roll = null,
        public ?OracleTableResult $table = null,
        public ?LikelihoodAnswer $likelihood = null,
        public ?ItemKind $of = null,
        public array $picked = [],
        public ?string $characterId = null,
    ) {
    }

    public static function prompt(string $text): self
    {
        return new self(StepKind::Prompt, trim($text));
    }

    public static function roll(Roll $roll): self
    {
        return new self(StepKind::Roll, (string) $roll->total(), roll: $roll);
    }

    public static function table(OracleTableResult $result): self
    {
        $texts = array_filter(array_map(static fn (OracleTableStep $step): string => $step->text(), $result->steps()), static fn (string $text): bool => '' !== $text);

        return new self(StepKind::Table, implode(' › ', $texts), table: $result);
    }

    public static function oracle(LikelihoodAnswer $answer): self
    {
        $label = match ($answer->answer()) {
            YesNoAnswer::ExceptionalYes => 'Exceptional yes',
            YesNoAnswer::Yes => 'Yes',
            YesNoAnswer::No => 'No',
            YesNoAnswer::ExceptionalNo => 'Exceptional no',
        };

        return new self(StepKind::Oracle, $label, likelihood: $answer);
    }

    /**
     * The NPCs or Threads a pick step picked, none when nothing matched or was chosen.
     *
     * @param list<PickedItem> $picked
     */
    public static function pick(ItemKind $of, array $picked): self
    {
        return new self(StepKind::Pick, implode(', ', array_map(static fn (PickedItem $item): string => $item->name, $picked)), of: $of, picked: $picked);
    }

    /**
     * The Character a character step brings, by id. Its answer is the Character's name, which the
     * FlowRun reads from the campaign when it completes the step.
     */
    public static function character(string $characterId): self
    {
        return new self(StepKind::Character, '', characterId: $characterId);
    }

    public static function choice(string $optionKey): self
    {
        return new self(StepKind::Choice, $optionKey);
    }
}
