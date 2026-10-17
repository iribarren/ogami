<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * The layout of the character sheet (ADR 0031): groups declared once, in order, laid out in a grid.
 * The sheet has 1 or 2 columns of groups; a group has 1 to 4 columns of fields and may span the
 * sheet's columns; a field may span its group's columns. Defaults (1) and empty parts are left out of
 * the canonical form, so a release without layout keeps its content hash.
 *
 * @internal
 */
final class SheetLayout
{
    public const int MAX_COLUMNS = 2;
    public const int MAX_GROUPS = 20;
    public const int MAX_GROUP_COLUMNS = 4;
    public const int MAX_GROUP_SPAN = 2;
    public const int MAX_FIELD_SPAN = 4;

    private const array GROUP = ['name' => true, 'columns' => false, 'span' => false];

    /**
     * Checks "columns" and "groups" of a sheet, which ReleaseFields::object() already limited to its
     * own properties.
     *
     * @param array<string, mixed> $sheet
     *
     * @return array{array{columns?: int, groups?: list<array<string, mixed>>}, array<string, int>} the canonical layout, and the columns of each declared group by name (empty without groups)
     */
    public static function validate(array $sheet): array
    {
        $groups = ReleaseFields::sizedList($sheet['groups'] ?? [], 'sheet.groups', 0, self::MAX_GROUPS, 'groups');
        $columns = isset($sheet['columns']) ? ReleaseFields::integer($sheet['columns'], 'sheet.columns', 1, self::MAX_COLUMNS) : null;
        if ([] === $groups) {
            if (null !== $columns) {
                throw InvalidReleaseContent::at('sheet.columns', 'needs declared groups, declare sheet.groups first');
            }

            return [[], []];
        }

        $declared = [];
        $canonical = [];
        foreach ($groups as $index => $group) {
            $path = \sprintf('sheet.groups[%d]', $index);
            $group = ReleaseFields::object($group, $path, self::GROUP);
            /** @var string $name */
            $name = $group['name'];
            ReleaseFields::text($name, $path.'.name', 1, SheetFields::MAX_GROUP_LENGTH);
            if (isset($declared[$name])) {
                throw InvalidReleaseContent::at($path.'.name', \sprintf('duplicate group name "%s"', $name));
            }

            $groupColumns = isset($group['columns']) ? ReleaseFields::integer($group['columns'], $path.'.columns', 1, self::MAX_GROUP_COLUMNS) : 1;
            $span = isset($group['span']) ? ReleaseFields::integer($group['span'], $path.'.span', 1, self::MAX_GROUP_SPAN) : 1;
            if ($span > ($columns ?? 1)) {
                throw InvalidReleaseContent::at($path.'.span', \sprintf('must be at most the columns of the sheet (%d), %d given', $columns ?? 1, $span));
            }

            $declared[$name] = $groupColumns;
            $canonical[] = ['name' => $name] + (1 === $groupColumns ? [] : ['columns' => $groupColumns]) + (1 === $span ? [] : ['span' => $span]);
        }

        return [(null === $columns || 1 === $columns ? [] : ['columns' => $columns]) + ['groups' => $canonical], $declared];
    }

    /**
     * A field against the declared groups: without groups it has no span; with groups it names one of
     * them and spans at most that group's columns.
     *
     * @param array<string, int>   $groupColumns the columns of each declared group by name, from validate()
     * @param array<string, mixed> $field        a field whose shape is already checked
     */
    public static function checkField(array $groupColumns, array $field, string $path): void
    {
        if ([] === $groupColumns) {
            if (isset($field['span'])) {
                throw InvalidReleaseContent::at($path.'.span', 'needs declared groups, declare sheet.groups first');
            }

            return;
        }

        $group = $field['group'] ?? null;
        if (!\is_string($group)) {
            throw InvalidReleaseContent::at($path.'.group', 'required, the sheet declares groups so every field names one');
        }

        if (!isset($groupColumns[$group])) {
            throw InvalidReleaseContent::at($path.'.group', \sprintf('unknown group "%s", declare it in sheet.groups', $group));
        }

        $span = $field['span'] ?? 1;
        if (\is_int($span) && $span > $groupColumns[$group]) {
            throw InvalidReleaseContent::at($path.'.span', \sprintf('must be at most the columns of group "%s" (%d), %d given', $group, $groupColumns[$group], $span));
        }
    }
}
