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
     * The Outcomes a step may continue with, one per result it distinguishes; a missing one (an
     * unset "otherwise", "found", "none" or oracle answer) is null and leaves the step's own
     * "next". An exceptional oracle answer without its own branch uses yes / no, so it adds none.
     *
     * @return list<?Outcome>
     */
    public static function outcomes(Step $step): array
    {
        return match (true) {
            $step instanceof ChoiceStep => array_map(static fn (ChoiceOption $option): Outcome => $option->outcome, $step->options),
            $step instanceof OracleStep => [$step->branches->yes, $step->branches->no, ...array_filter([$step->branches->exceptionalYes, $step->branches->exceptionalNo])],
            $step instanceof RollStep, $step instanceof ConditionStep => array_map(static fn (Band $band): Outcome => $band->outcome, $step->bands),
            $step instanceof TableStep => [...array_map(static fn (TableBranch $branch): Outcome => $branch->outcome, $step->branches), $step->otherwise],
            $step instanceof PickStep => [$step->found, $step->none],
            $step instanceof CheckStep => array_values($step->outcomes),
            default => [],
        };
    }

    /**
     * @param \Closure(string): list<Effect> $tableEffects the Effects of every entry of an oracle table, by table key
     *
     * @return list<Effect>
     */
    public static function of(Step $step, \Closure $tableEffects): array
    {
        return [
            ...$step->effects,
            ...$step instanceof TableStep ? $tableEffects($step->table) : [],
            ...array_merge([], ...array_map(static fn (?Outcome $outcome): array => $outcome->effects ?? [], self::outcomes($step))),
        ];
    }
}
