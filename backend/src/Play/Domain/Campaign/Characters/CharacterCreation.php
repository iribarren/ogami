<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\PointBudget;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * The values a new Character starts with (ADR 0040). The server rolls the dice default of every
 * number field the player does not spend points on (clamped to the field's bounds) and starts a
 * track with a start max full, once the other values are in. A value given for one of those is
 * refused. By points the player sends a value for every budget field: each inside the field's own
 * bounds and the budget's, all together at most the total.
 *
 * A sheet without these properties creates exactly as CharacterValues::resolve() does.
 */
final class CharacterCreation
{
    /**
     * @param array<mixed> $given field key to value
     *
     * @return array<string, int|string|list<string>|array{current: int, max: int}> one value per stored field of the sheet
     *
     * @throws InvalidCharacter when the method does not fit the sheet, a value is set by the server, breaks the budget or does not fit its field
     */
    public static function values(SheetTemplate $sheet, array $given, ?CreationMethod $method, ?RandomNumberGenerator $random): array
    {
        $budget = $sheet->budget();
        self::assertMethod($sheet, $budget, $method);

        $spent = CreationMethod::Points === $method && $budget instanceof PointBudget;
        if ($spent) {
            self::assertBudget($sheet, $budget, $given);
        }

        return self::create($sheet, $given, $spent ? $budget : null, $random);
    }

    /**
     * The values of an NPC promoted to a Character: it has none of its own, so the server rolls
     * every dice default, whatever the budget.
     *
     * @return array<string, int|string|list<string>|array{current: int, max: int}>
     */
    public static function promoted(SheetTemplate $sheet, ?RandomNumberGenerator $random): array
    {
        return self::create($sheet, [], null, $random);
    }

    private static function assertMethod(SheetTemplate $sheet, ?PointBudget $budget, ?CreationMethod $method): void
    {
        if (!$budget instanceof PointBudget) {
            if (CreationMethod::Points === $method) {
                throw InvalidCharacter::refused(CreationRefusal::MethodUnavailable, 'This sheet has no point budget to spend; create the Character without a method or by "roll".');
            }

            return;
        }

        $rolls = array_any($budget->fields, static fn (string $key): bool => ($sheet->field($key) instanceof NumberField) && $sheet->field($key)->roll instanceof DiceExpression);
        if (!$rolls) {
            if (CreationMethod::Roll === $method) {
                throw InvalidCharacter::refused(CreationRefusal::MethodUnavailable, 'No field of the point budget has a dice default to roll; spend the points.');
            }

            return;
        }

        if (!$method instanceof CreationMethod) {
            throw InvalidCharacter::refused(CreationRefusal::MethodRequired, 'Choose how to create the Character: "roll" or "points".');
        }
    }

    /**
     * @param array<mixed> $given
     */
    private static function assertBudget(SheetTemplate $sheet, PointBudget $budget, array $given): void
    {
        $total = 0;
        foreach ($budget->fields as $key) {
            $field = $sheet->field($key);
            \assert($field instanceof NumberField);
            if (!\array_key_exists($key, $given)) {
                throw InvalidCharacter::refused(CreationRefusal::OutsideBounds, \sprintf('Field "%s" is part of the point budget: give it a value.', $key));
            }

            // A value of another type is left to the field's own check.
            if (!\is_int($given[$key])) {
                continue;
            }

            [$min, $max] = $budget->boundsOf($field);
            if ($given[$key] < $min || $given[$key] > $max) {
                throw InvalidCharacter::refused(CreationRefusal::OutsideBounds, \sprintf('Field "%s" must be %d to %d when spending points, got %d.', $key, $min, $max, $given[$key]));
            }

            $total += $given[$key];
        }

        if ($total > $budget->total) {
            throw InvalidCharacter::refused(CreationRefusal::BudgetExceeded, \sprintf('The point budget allows %d points, %d spent.', $budget->total, $total));
        }
    }

    /**
     * @param array<mixed> $given
     * @param ?PointBudget $spent the budget the player spends, whose fields are not rolled
     *
     * @return array<string, int|string|list<string>|array{current: int, max: int}>
     */
    private static function create(SheetTemplate $sheet, array $given, ?PointBudget $spent, ?RandomNumberGenerator $random): array
    {
        $rolled = [];
        foreach ($sheet->fields() as $field) {
            $setByServer = ($field instanceof NumberField && $field->roll instanceof DiceExpression && (!$spent instanceof PointBudget || !$spent->covers($field->key)))
                || ($field instanceof TrackField && $field->startMax instanceof Formula);
            if (!$setByServer) {
                continue;
            }

            if (\array_key_exists($field->key, $given)) {
                throw InvalidCharacter::refused(CreationRefusal::ValueSetAtCreation, \sprintf('Field "%s" is set when the Character is created; send no value for it.', $field->key));
            }

            if ($field instanceof NumberField) {
                $rolled[$field->key] = self::roll($field, $random);
            }
        }

        $values = CharacterValues::resolve($sheet, $given + $rolled);

        // Every start max reads number and derived fields only, so they all see the same values.
        $reader = null;
        foreach ($sheet->fields() as $field) {
            if (!$field instanceof TrackField || !$field->startMax instanceof Formula) {
                continue;
            }

            $reader ??= SheetValues::of($sheet, $values, []);
            try {
                $max = max(0, min(CharacterValues::MAX_TRACK, $field->startMax->evaluate($reader)));
            } catch (FormulaEvaluationFailed $failure) {
                throw InvalidCharacter::because(\sprintf('The start max of field "%s" cannot be computed: %s', $field->key, $failure->getMessage()));
            }

            $values[$field->key] = ['current' => $max, 'max' => $max];
        }

        return $values;
    }

    private static function roll(NumberField $field, ?RandomNumberGenerator $random): int
    {
        \assert($field->roll instanceof DiceExpression);
        $random ??= throw new \LogicException('Creating a Character rolls dice without a random source.');
        try {
            return $field->clamp($field->roll->roll($random)->total());
        } catch (InvalidDiceExpression $invalid) {
            throw InvalidCharacter::because(\sprintf('The dice default of field "%s" cannot be rolled: %s', $field->key, $invalid->getMessage()));
        }
    }
}
