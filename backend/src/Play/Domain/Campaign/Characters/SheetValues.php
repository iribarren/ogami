<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Formula\Domain\ValueResolver;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TrackField;

/**
 * What a formula reads about one Character (ADR 0023): its number fields (stored, else default), its
 * tracks (current, and the max as {key.max}), its derived fields and the Campaign's Tracker values.
 * Derived fields are computed once, in dependency order; one whose formula fails has no value, and
 * neither has a formula that reads it. A check reference never reads the sheet.
 */
final class SheetValues implements ValueResolver
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
     */
    public static function of(SheetTemplate $sheet, array $values, array $trackerValues): self
    {
        $sheetValues = new self($sheet, $values, $trackerValues);
        foreach ($sheet->derivedOrder() as $key) {
            $field = $sheet->field($key);
            \assert($field instanceof DerivedField);
            try {
                $sheetValues->computed[$key] = $field->formula->evaluate($sheetValues);
            } catch (FormulaEvaluationFailed) {
                $sheetValues->computed[$key] = null;
            }
        }

        return $sheetValues;
    }

    /**
     * @return array<string, ?int> one per derived field, in sheet field order
     */
    public function derived(): array
    {
        $ordered = [];
        foreach ($this->sheet->fields() as $field) {
            if ($field instanceof DerivedField) {
                $ordered[$field->key] = $this->computed[$field->key];
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
            $field instanceof ConditionsField && ReferenceKind::Field === $reference->kind && \is_array($stored) => \count($stored),
            $field instanceof DerivedField && ReferenceKind::Field === $reference->kind => $this->computed[$field->key] ?? null,
            default => null,
        };

        return \is_int($value) ? $value : throw FormulaEvaluationFailed::unknownReference($reference);
    }
}
