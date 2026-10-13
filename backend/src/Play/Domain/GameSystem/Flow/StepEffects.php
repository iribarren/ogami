<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Every Effect a step may apply, whichever way it continues: its own, those of its options, bands,
 * branches, "otherwise", pick outcomes, check outcomes and, for a table step, those of the entries of its table.
 */
final readonly class StepEffects
{
    /**
     * @param \Closure(string): list<Effect> $tableEffects the Effects of every entry of an oracle table, by table key
     *
     * @return list<Effect>
     */
    public static function of(Step $step, \Closure $tableEffects): array
    {
        $outcomes = match (true) {
            $step instanceof ChoiceStep => array_map(static fn (ChoiceOption $option): Outcome => $option->outcome, $step->options),
            $step instanceof OracleStep => [$step->branches->yes, $step->branches->no, $step->branches->exceptionalYes, $step->branches->exceptionalNo],
            $step instanceof RollStep, $step instanceof ConditionStep => array_map(static fn (Band $band): Outcome => $band->outcome, $step->bands),
            $step instanceof TableStep => [...array_map(static fn (TableBranch $branch): Outcome => $branch->outcome, $step->branches), $step->otherwise],
            $step instanceof PickStep => [$step->found, $step->none],
            $step instanceof CheckStep => array_values($step->outcomes),
            default => [],
        };

        return [
            ...$step->effects,
            ...$step instanceof TableStep ? $tableEffects($step->table) : [],
            ...array_merge([], ...array_map(static fn (?Outcome $outcome): array => $outcome->effects ?? [], $outcomes)),
        ];
    }
}
