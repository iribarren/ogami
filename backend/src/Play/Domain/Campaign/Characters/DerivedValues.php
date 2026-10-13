<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Formula\Domain\ValueResolver;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TrackField;

/**
 * The DerivedValues of a Character (ADR 0023): the formula of each derived field of the sheet over
 * the Character's values and the Campaign's Tracker values. They are computed on every read, never
 * stored. A field the Character holds no value for reads as its default. A formula that fails
 * (division by zero, overflow) gives null, and so does every formula that reads a null.
 */
final class DerivedValues implements ValueResolver
{
    /** @var array<string, ?int> */
    private array $computed = [];

    /**
     * @param array<string, mixed> $values        the Character's stored values by field key
     * @param array<string, int>   $trackerValues the Campaign's value of every Tracker by key
     */
    private function __construct(
        private readonly SheetTemplate $sheet,
        private readonly array $values,
        private readonly array $trackerValues,
    ) {
    }

    /**
     * @param array<string, mixed> $values        the Character's stored values by field key
     * @param array<string, int>   $trackerValues the Campaign's value of every Tracker by key
     *
     * @return array<string, ?int> one per derived field, in sheet field order
     */
    public static function of(SheetTemplate $sheet, array $values, array $trackerValues): array
    {
        $derived = new self($sheet, $values, $trackerValues);
        foreach ($sheet->derivedOrder() as $key) {
            $field = $sheet->field($key);
            \assert($field instanceof DerivedField);
            try {
                $derived->computed[$key] = $field->formula->evaluate($derived);
            } catch (FormulaEvaluationFailed) {
                $derived->computed[$key] = null;
            }
        }

        $ordered = [];
        foreach ($sheet->fields() as $field) {
            if ($field instanceof DerivedField) {
                $ordered[$field->key] = $derived->computed[$field->key];
            }
        }

        return $ordered;
    }

    public function value(Reference $reference): int
    {
        if (ReferenceKind::Check === $reference->kind) {
            // Only a check's bands read this; a sheet formula that holds one is invalid and has no value.
            throw FormulaEvaluationFailed::unknownReference($reference);
        }

        if (ReferenceKind::Tracker === $reference->kind) {
            return $this->trackerValues[$reference->key] ?? throw FormulaEvaluationFailed::unknownReference($reference);
        }

        $field = $this->sheet->field($reference->key);
        $stored = $field instanceof DerivedField ? null : ($this->values[$reference->key] ?? ($field instanceof Field ? CharacterValues::initial($field) : null));
        $value = match (true) {
            $field instanceof NumberField && ReferenceKind::Field === $reference->kind => $stored,
            $field instanceof TrackField && \is_array($stored) => $stored[ReferenceKind::TrackMax === $reference->kind ? 'max' : 'current'] ?? null,
            $field instanceof DerivedField && ReferenceKind::Field === $reference->kind => $this->computed[$field->key] ?? null,
            default => null,
        };

        return \is_int($value) ? $value : throw FormulaEvaluationFailed::unknownReference($reference);
    }
}
