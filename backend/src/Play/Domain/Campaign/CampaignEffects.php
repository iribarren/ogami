<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\TrackerValues;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tracker;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * Applies the sheet and Tracker Effects of the pinned release to a Campaign (ADR 0027, 0041): what a
 * Flow step's Effects do inside a FlowRun, and what the cost of a pushed check does outside one.
 * It changes the Campaign it is given and saves nothing, so the caller decides whether the changes
 * are kept (all or nothing). Dice an Effect rolls come from the random source it is given.
 */
final readonly class CampaignEffects
{
    public function __construct(
        private Campaign $campaign,
        private GameSystemSnapshot $release,
        private ?RandomNumberGenerator $random = null,
    ) {
    }

    /**
     * The campaign's current value of a Tracker of the pinned release.
     */
    public function trackerValue(string $key): int
    {
        return $this->campaign->trackerValue($this->tracker($key));
    }

    /**
     * @return array<string, int> the campaign's current value of every Tracker of the pinned release, by key
     */
    public function trackerValues(): array
    {
        return $this->campaign->trackerValuesOf($this->release);
    }

    /**
     * Applies a tracker Effect: adds or sets a literal or a formula's value, clamped to the
     * Tracker's range. It is no hand edit, so no FlowRun history records it.
     *
     * @return array{from: int, to: int} the Tracker's value before and after
     *
     * @throws FlowFormulaFailed when the value's formula divides by zero or overflows
     */
    public function applyTrackerEffect(TrackerEffect $effect): array
    {
        $tracker = $this->tracker($effect->tracker);
        $from = $this->campaign->trackerValue($tracker);

        return ['from' => $from, 'to' => $this->campaign->applyTrackerChange($tracker, $effect->op, $this->evaluate($effect->value))];
    }

    /**
     * Applies a sheet Effect to a Character (ADR 0027): adds, subtracts or sets a literal, a formula
     * over the Character's sheet as it is now or a dice roll, clamped to the field (a track's current
     * value to 0..its max, a number to its min and max, else the contract's limit). Mark and clear
     * change a conditions field instead (ADR 0041): the change tells the count marked before and after.
     *
     * @param ?string $pick the option a mark without an option marks (a push cost, where the player
     *                      picks one); null marks nothing, as when every option is marked already
     *
     * @throws FlowFormulaFailed when the value's formula divides by zero, overflows or reads a value the sheet lacks
     */
    public function applySheetEffect(SheetEffect $effect, string $characterId, ?string $pick = null): SheetChange
    {
        $id = CharacterId::fromString($characterId);
        $character = $this->campaign->character($id) ?? throw new \LogicException(\sprintf('The campaign has no Character "%s".', $characterId));
        $field = $this->release->sheet()->field($effect->field);
        if ($field instanceof ConditionsField) {
            return $this->applyConditionEffect($effect, $id, $character, $field, $pick);
        }

        if (!$field instanceof NumberField && !$field instanceof TrackField) {
            throw new \LogicException(\sprintf('The pinned release has no number or track field "%s".', $effect->field));
        }

        $dice = null;
        $value = $effect->value;
        if ($value instanceof DiceExpression) {
            $dice = $value->roll($this->random ?? throw new \LogicException('A sheet Effect rolls dice without a random source.'));
            $value = $dice->total();
        } elseif ($value instanceof Formula) {
            try {
                $value = $value->evaluate(SheetValues::of($this->release->sheet(), $character->values(), $this->trackerValues()));
            } catch (FormulaEvaluationFailed $failure) {
                throw FlowFormulaFailed::of($value, $failure);
            }
        }

        if (!\is_int($value)) {
            throw new \LogicException(\sprintf('Field "%s" takes a number, not an option key.', $field->key));
        }

        $stored = $character->values()[$field->key] ?? CharacterValues::initial($field);
        $track = \is_array($stored) && \is_int($stored['current'] ?? null) && \is_int($stored['max'] ?? null) ? ['current' => $stored['current'], 'max' => $stored['max']] : null;
        $before = $track['current'] ?? (\is_int($stored) ? $stored : 0);
        $changed = match ($effect->op) {
            SheetOperation::Add => $before + $value,
            SheetOperation::Subtract => $before - $value,
            SheetOperation::Set => $value,
            SheetOperation::Mark, SheetOperation::Clear => throw new \LogicException(\sprintf('Field "%s" is changed with a number, not marked or cleared.', $field->key)),
        };
        // A sum past the integers is a float; clamping brings it back within the bounds.
        [$min, $max] = $field instanceof TrackField
            ? [0, $track['max'] ?? 0]
            : [$field->min ?? -NumberField::LIMIT, $field->max ?? NumberField::LIMIT];
        $after = max($min, min($max, $changed));
        $this->campaign->changeCharacterValue($id, $field->key, $field instanceof TrackField ? ['current' => $after, 'max' => $max] : $after);

        return new SheetChange($field->key, $before, $after, $dice?->expression()->notation(), $dice?->total());
    }

    /**
     * The options of a conditions field a Character has not marked, in declared order: the ones a
     * mark without an option can pick right now.
     *
     * @return list<string> empty when every option is marked
     */
    public function unmarkedOptions(string $fieldKey, string $characterId): array
    {
        $character = $this->campaign->character(CharacterId::fromString($characterId)) ?? throw new \LogicException(\sprintf('The campaign has no Character "%s".', $characterId));
        $field = $this->release->sheet()->field($fieldKey);
        if (!$field instanceof ConditionsField) {
            throw new \LogicException(\sprintf('The pinned release has no conditions field "%s".', $fieldKey));
        }

        $stored = $character->values()[$field->key] ?? CharacterValues::initial($field);

        return array_values(array_diff($field->optionKeys(), \is_array($stored) ? array_filter($stored, \is_string(...)) : []));
    }

    /**
     * A literal as it is, a formula over the campaign's current Tracker values.
     *
     * @throws FlowFormulaFailed when the formula divides by zero or overflows
     */
    public function evaluate(int|Formula $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        try {
            return $value->evaluate(new TrackerValues($this));
        } catch (FormulaEvaluationFailed $failure) {
            throw FlowFormulaFailed::of($value, $failure);
        }
    }

    /**
     * Marks an option of a conditions field (nothing changes when it is marked already), clears one
     * (nothing changes when it is not marked) or, without an option, clears them all. A mark without
     * an option marks the pick, or nothing without one.
     */
    private function applyConditionEffect(SheetEffect $effect, CharacterId $id, Character $character, ConditionsField $field, ?string $pick): SheetChange
    {
        $option = $effect->value;
        if (!\is_string($option) && null !== $option) {
            throw new \LogicException(\sprintf('Conditions field "%s" takes an option key, not a number or a formula.', $field->key));
        }

        if (null !== $option && !$field->has($option)) {
            throw new \LogicException(\sprintf('Conditions field "%s" has no option "%s".', $field->key, $option));
        }

        if (null !== $pick && (null !== $option || SheetOperation::Mark !== $effect->op || !$field->has($pick))) {
            throw new \LogicException(\sprintf('Conditions field "%s" takes a pick only for a mark without an option, among its own options.', $field->key));
        }

        $stored = $character->values()[$field->key] ?? CharacterValues::initial($field);
        $before = \is_array($stored) ? array_values(array_filter($stored, \is_string(...))) : [];
        $marked = $option ?? $pick;
        $after = match ($effect->op) {
            SheetOperation::Mark => null === $marked ? $before : $field->ordered([...$before, $marked]),
            SheetOperation::Clear => null === $option ? [] : array_values(array_diff($before, [$option])),
            default => throw new \LogicException(\sprintf('Conditions field "%s" is marked and cleared, not changed with "%s".', $field->key, $effect->op->value)),
        };
        $this->campaign->changeCharacterValue($id, $field->key, $after);

        return new SheetChange($field->key, \count($before), \count($after));
    }

    private function tracker(string $key): Tracker
    {
        return $this->release->tracker($key) ?? throw new \LogicException(\sprintf('The pinned release has no Tracker "%s".', $key));
    }
}
