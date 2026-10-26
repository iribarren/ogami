<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * The point budgets of a sheet (ADR 0040, 0043): totals a Character spends across number fields,
 * with creation-only bounds for them. A sheet declares the legacy single `pointBudget` or a list
 * `pointBudgets`; one budget in the list canonicalizes to the legacy shape, so a one-budget release
 * keeps its shape and hash. A budget that cannot be spent is refused here, so a published release
 * never has one.
 *
 * @internal
 */
final class PointBudget
{
    public const int MAX_LABEL_LENGTH = 60;
    public const int MAX_TOTAL = 1_000_000;
    public const int MAX_BUDGETS = 10;

    private const array PROPERTIES = ['label' => false, 'total' => true, 'fields' => true, 'min' => false, 'max' => false];
    private const array LISTED_PROPERTIES = ['key' => true, 'label' => true, 'total' => true, 'fields' => true, 'min' => false, 'max' => false];

    /**
     * @param array<string, mixed> $sheet  the sheet, which ReleaseFields::object() already limited to its own properties
     * @param array<string, mixed> $fields the validated fields of the sheet by key
     *
     * @return array{pointBudget?: array<string, mixed>, pointBudgets?: list<array<string, mixed>>} the canonical budgets; nothing without one
     */
    public static function validate(array $sheet, array $fields): array
    {
        $legacy = $sheet['pointBudget'] ?? null;
        $listed = $sheet['pointBudgets'] ?? null;
        if (null !== $legacy && null !== $listed && [] !== $listed) {
            throw InvalidReleaseContent::at('sheet.pointBudgets', 'a sheet declares pointBudget or pointBudgets, not both');
        }

        if (null !== $listed && [] !== $listed) {
            return self::validateList($listed, $fields);
        }

        if (null === $legacy) {
            return [];
        }

        $budget = ReleaseFields::object($legacy, 'sheet.pointBudget', self::PROPERTIES);
        if (isset($budget['label'])) {
            ReleaseFields::text($budget['label'], 'sheet.pointBudget.label', 1, self::MAX_LABEL_LENGTH);
        }

        return ['pointBudget' => self::budget($budget, 'sheet.pointBudget', $fields, [])];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{pointBudget?: array<string, mixed>, pointBudgets?: list<array<string, mixed>>}
     */
    private static function validateList(mixed $list, array $fields): array
    {
        $budgets = [];
        /** @var array<string, string> $owners */
        $owners = [];
        $keys = [];
        foreach (ReleaseFields::sizedList($list, 'sheet.pointBudgets', 1, self::MAX_BUDGETS, 'point budgets') as $index => $value) {
            $path = \sprintf('sheet.pointBudgets[%d]', $index);
            $budget = ReleaseFields::object($value, $path, self::LISTED_PROPERTIES);
            $key = ReleaseFields::key($budget['key'], $path.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate budget key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($budget['label'], $path.'.label', 1, self::MAX_LABEL_LENGTH);
            $budget = self::budget($budget, $path, $fields, $owners);
            /** @var list<string> $budgetFields */
            $budgetFields = $budget['fields'];
            foreach ($budgetFields as $field) {
                $owners[$field] = $key;
            }

            $budgets[] = $budget;
        }

        if (1 === \count($budgets)) {
            return ['pointBudget' => array_diff_key($budgets[0], ['key' => true])];
        }

        return ['pointBudgets' => $budgets];
    }

    /**
     * One budget against the fields of the sheet: its limits, and that its own bounds can be spent.
     *
     * @param array<string, mixed>  $budget the budget as ReleaseFields::object() returned it
     * @param array<string, mixed>  $fields the validated fields of the sheet by key
     * @param array<string, string> $owners the budget key each field of an earlier budget is in
     *
     * @return array<string, mixed> the budget with its field keys in order
     */
    private static function budget(array $budget, string $path, array $fields, array $owners): array
    {
        $total = ReleaseFields::integer($budget['total'], $path.'.total', 1, self::MAX_TOTAL);
        $min = isset($budget['min']) ? ReleaseFields::integer($budget['min'], $path.'.min', -SheetFields::MAX_NUMBER, SheetFields::MAX_NUMBER) : null;
        $max = isset($budget['max']) ? ReleaseFields::integer($budget['max'], $path.'.max', -SheetFields::MAX_NUMBER, SheetFields::MAX_NUMBER) : null;
        if (null !== $min && null !== $max && $max < $min) {
            throw InvalidReleaseContent::at($path.'.max', \sprintf('must be at least min (%d), %d given', $min, $max));
        }

        $lowest = 0;
        $keys = [];
        foreach (ReleaseFields::sizedList($budget['fields'], $path.'.fields', 1, SheetFields::MAX_FIELDS, 'fields') as $index => $key) {
            $fieldPath = \sprintf('%s.fields[%d]', $path, $index);
            $key = ReleaseFields::key($key, $fieldPath);
            if (isset($owners[$key])) {
                throw InvalidReleaseContent::at($fieldPath, \sprintf('field "%s" is already in budget "%s"', $key, $owners[$key]));
            }

            $lowest += self::lowestValue($key, $fields, $keys, $fieldPath, $min, $max);
            $keys[$key] = true;
        }

        if ($lowest > $total) {
            throw InvalidReleaseContent::at($path.'.total', \sprintf('%d is below %d, the sum of the lowest values the budget fields can take', $total, $lowest));
        }

        $budget['fields'] = array_keys($keys);

        return $budget;
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
