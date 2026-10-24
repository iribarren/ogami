<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * The point budget of a sheet (ADR 0040): a total a Character spends across number fields, with
 * creation-only bounds for them. A budget that cannot be spent is refused here, so a published
 * release never has one.
 *
 * @internal
 */
final class PointBudget
{
    public const int MAX_LABEL_LENGTH = 60;
    public const int MAX_TOTAL = 1_000_000;

    private const array PROPERTIES = ['label' => false, 'total' => true, 'fields' => true, 'min' => false, 'max' => false];

    /**
     * @param array<string, mixed> $sheet  the sheet, which ReleaseFields::object() already limited to its own properties
     * @param array<string, mixed> $fields the validated fields of the sheet by key
     *
     * @return array{pointBudget?: array<string, mixed>} the canonical budget; nothing without one
     */
    public static function validate(array $sheet, array $fields): array
    {
        if (!isset($sheet['pointBudget'])) {
            return [];
        }

        $budget = ReleaseFields::object($sheet['pointBudget'], 'sheet.pointBudget', self::PROPERTIES);
        if (isset($budget['label'])) {
            ReleaseFields::text($budget['label'], 'sheet.pointBudget.label', 1, self::MAX_LABEL_LENGTH);
        }

        $total = ReleaseFields::integer($budget['total'], 'sheet.pointBudget.total', 1, self::MAX_TOTAL);
        $min = isset($budget['min']) ? ReleaseFields::integer($budget['min'], 'sheet.pointBudget.min', -SheetFields::MAX_NUMBER, SheetFields::MAX_NUMBER) : null;
        $max = isset($budget['max']) ? ReleaseFields::integer($budget['max'], 'sheet.pointBudget.max', -SheetFields::MAX_NUMBER, SheetFields::MAX_NUMBER) : null;
        if (null !== $min && null !== $max && $max < $min) {
            throw InvalidReleaseContent::at('sheet.pointBudget.max', \sprintf('must be at least min (%d), %d given', $min, $max));
        }

        $lowest = 0;
        $keys = [];
        foreach (ReleaseFields::sizedList($budget['fields'], 'sheet.pointBudget.fields', 1, SheetFields::MAX_FIELDS, 'fields') as $index => $key) {
            $path = \sprintf('sheet.pointBudget.fields[%d]', $index);
            $key = ReleaseFields::key($key, $path);
            $lowest += self::lowestValue($key, $fields, $keys, $path, $min, $max);
            $keys[$key] = true;
        }

        if ($lowest > $total) {
            throw InvalidReleaseContent::at('sheet.pointBudget.total', \sprintf('%d is below %d, the sum of the lowest values the budget fields can take', $total, $lowest));
        }

        $budget['fields'] = array_keys($keys);

        return ['pointBudget' => $budget];
    }

    /**
     * The lowest value a budget field can take: inside its own bounds and the budget's.
     *
     * @param array<string, mixed> $fields
     * @param array<string, true>  $seen
     */
    private static function lowestValue(string $key, array $fields, array $seen, string $path, ?int $min, ?int $max): int
    {
        if (isset($seen[$key])) {
            throw InvalidReleaseContent::at($path, \sprintf('duplicate field "%s"', $key));
        }

        $field = $fields[$key] ?? null;
        if (!\is_array($field)) {
            throw InvalidReleaseContent::at($path, \sprintf('unknown field "%s"', $key));
        }

        if ('number' !== $field['type']) {
            throw InvalidReleaseContent::at($path, \sprintf('field "%s" is a %s field, a budget covers number fields', $key, \is_string($field['type']) ? $field['type'] : 'non-number'));
        }

        /** @var int|null $fieldMin */
        $fieldMin = $field['min'] ?? null;
        /** @var int|null $fieldMax */
        $fieldMax = $field['max'] ?? null;
        $lowest = max($fieldMin ?? -SheetFields::MAX_NUMBER, $min ?? -SheetFields::MAX_NUMBER);
        $highest = min($fieldMax ?? SheetFields::MAX_NUMBER, $max ?? SheetFields::MAX_NUMBER);
        if ($lowest > $highest) {
            throw InvalidReleaseContent::at($path, \sprintf('"%s" allows %s..%s, no value fits the budget bounds %s..%s', $key, $fieldMin ?? 'any', $fieldMax ?? 'any', $min ?? 'any', $max ?? 'any'));
        }

        return $lowest;
    }
}
