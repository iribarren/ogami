<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * The character sheet template of schema version 2 (ADR 0022): an optional list of fields of four
 * types, each with its own closed set of properties. The canonical sheet is [] without fields, so
 * a release that never used the sheet keeps its content hash.
 *
 * @internal
 */
final class SheetFields
{
    public const int MAX_FIELDS = 100;
    public const int MAX_LABEL_LENGTH = 100;
    public const int MAX_GROUP_LENGTH = 60;
    public const int MAX_HINT_LENGTH = 500;
    public const int MAX_NUMBER = 1_000_000;
    public const int MAX_TEXT_LENGTH = 2000;
    public const int MAX_ITEM_LABEL_LENGTH = 60;
    public const int MAX_LIST_ITEMS = 100;
    public const int MAX_TRACK = 999;

    private const array SHEET = ['fields' => false];
    private const array COMMON = ['key' => true, 'label' => true, 'hint' => false, 'group' => false, 'type' => true];

    /** The fields each type adds to COMMON, in schema order: name => required. */
    private const array TYPES = [
        'number' => ['min' => false, 'max' => false, 'default' => false],
        'text' => ['multiline' => false, 'maxLength' => false],
        'list' => ['itemLabel' => false, 'maxItems' => false],
        'track' => ['max' => false],
    ];

    /**
     * @return array{fields?: list<array<string, mixed>>} no "fields" key when the sheet has none
     */
    public static function validate(mixed $value): array
    {
        $sheet = ReleaseFields::object($value, 'sheet', self::SHEET);
        $fields = [];
        $keys = [];
        foreach (ReleaseFields::sizedList($sheet['fields'] ?? [], 'sheet.fields', 0, self::MAX_FIELDS, 'fields') as $index => $field) {
            $path = \sprintf('sheet.fields[%d]', $index);
            $field = self::field($field, $path);
            /** @var string $key */
            $key = $field['key'];
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate field key "%s"', $key));
            }

            $keys[$key] = true;
            $fields[] = $field;
        }

        return [] === $fields ? [] : ['fields' => $fields];
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(mixed $value, string $path): array
    {
        $type = ReleaseFields::tag($value, $path, 'type', array_keys(self::TYPES));
        $field = ReleaseFields::object($value, $path, [...self::COMMON, ...self::TYPES[$type]]);

        ReleaseFields::key($field['key'], $path.'.key');
        ReleaseFields::text($field['label'], $path.'.label', 1, self::MAX_LABEL_LENGTH);
        if (isset($field['hint'])) {
            ReleaseFields::text($field['hint'], $path.'.hint', 0, self::MAX_HINT_LENGTH);
        }

        if (isset($field['group'])) {
            ReleaseFields::text($field['group'], $path.'.group', 1, self::MAX_GROUP_LENGTH);
        }

        $field = match ($type) {
            'number' => self::number($field, $path),
            'text' => self::text($field, $path),
            'list' => self::list($field, $path),
            'track' => self::track($field, $path),
        };

        return array_filter($field, static fn (mixed $part): bool => false !== $part);
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private static function number(array $field, string $path): array
    {
        $bounds = [];
        foreach (['min', 'max', 'default'] as $name) {
            if (isset($field[$name])) {
                $bounds[$name] = ReleaseFields::integer($field[$name], $path.'.'.$name, -self::MAX_NUMBER, self::MAX_NUMBER);
            }
        }

        ['min' => $min, 'max' => $max, 'default' => $default] = $bounds + ['min' => null, 'max' => null, 'default' => null];
        if (null !== $min && null !== $max && $max < $min) {
            throw InvalidReleaseContent::at($path.'.max', \sprintf('must be at least min (%d), %d given', $min, $max));
        }

        if (null !== $default && null !== $min && $default < $min) {
            throw InvalidReleaseContent::at($path.'.default', \sprintf('must be within min..max, %d is below min (%d)', $default, $min));
        }

        if (null !== $default && null !== $max && $default > $max) {
            throw InvalidReleaseContent::at($path.'.default', \sprintf('must be within min..max, %d is above max (%d)', $default, $max));
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private static function text(array $field, string $path): array
    {
        if (isset($field['multiline'])) {
            ReleaseFields::boolean($field['multiline'], $path.'.multiline');
        }

        if (isset($field['maxLength'])) {
            ReleaseFields::integer($field['maxLength'], $path.'.maxLength', 1, self::MAX_TEXT_LENGTH);
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private static function list(array $field, string $path): array
    {
        if (isset($field['itemLabel'])) {
            ReleaseFields::text($field['itemLabel'], $path.'.itemLabel', 1, self::MAX_ITEM_LABEL_LENGTH);
        }

        if (isset($field['maxItems'])) {
            ReleaseFields::integer($field['maxItems'], $path.'.maxItems', 1, self::MAX_LIST_ITEMS);
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private static function track(array $field, string $path): array
    {
        if (isset($field['max'])) {
            ReleaseFields::integer($field['max'], $path.'.max', 1, self::MAX_TRACK);
        }

        return $field;
    }
}
