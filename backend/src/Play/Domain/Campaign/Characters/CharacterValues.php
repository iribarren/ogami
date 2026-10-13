<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;

/**
 * The values of a Character against the sheet of the pinned release (ADR 0022). A number is an
 * integer, a text a string, a list a list of strings, a track {current, max}. Nothing is coerced
 * ("3" is not a number) and nothing is clamped; a value that breaks its field fails with
 * InvalidCharacter.
 *
 * resolve() checks given values against the template on every write and completes the map with the
 * field defaults, in sheet field order. A derived field holds no value: it is neither given nor stored. assertWellFormed() is the template-free check of the shape
 * and the contract's absolute limits, which a stored Character read back is held to.
 */
final class CharacterValues
{
    public const int MAX_ITEM_LENGTH = 200;

    public const int MAX_LIST_ITEMS = 100;

    public const int MAX_TEXT_LENGTH = 2000;

    public const int MAX_TRACK = 999;

    /**
     * @param array<mixed> $given field key to value; a missing key takes the field default
     *
     * @return array<string, int|string|list<string>|array{current: int, max: int}> one value per stored field of the sheet
     *
     * @throws InvalidCharacter on an unknown key, a value for a derived field, a value of the wrong type or out of bounds
     */
    public static function resolve(SheetTemplate $sheet, array $given): array
    {
        foreach (array_keys($given) as $key) {
            $field = $sheet->field((string) $key);
            if (!$field instanceof Field) {
                throw InvalidCharacter::because(\sprintf('The sheet has no field "%s".', $key));
            }

            if ($field instanceof DerivedField) {
                throw InvalidCharacter::because(\sprintf('Field "%s" is derived; it holds no value.', $key));
            }
        }

        $values = [];
        foreach ($sheet->fields() as $field) {
            if ($field instanceof DerivedField) {
                continue;
            }

            $values[$field->key] = \array_key_exists($field->key, $given) ? self::checked($field, $given[$field->key]) : self::initial($field);
        }

        return $values;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, int|string|list<string>|array{current: int, max: int}>
     *
     * @throws InvalidCharacter when a value has no valid shape
     */
    public static function assertWellFormed(array $values): array
    {
        $checked = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $checked[$key] = match (true) {
                \is_int($value) => self::number($key, $value, -NumberField::LIMIT, NumberField::LIMIT),
                \is_string($value) => self::text($key, $value, self::MAX_TEXT_LENGTH, true),
                \is_array($value) && array_is_list($value) => self::items($key, $value, self::MAX_LIST_ITEMS),
                \is_array($value) => self::track($key, $value, null),
                default => throw InvalidCharacter::because(\sprintf('Field "%s" holds a value of no field type.', $key)),
            };
        }

        return $checked;
    }

    /**
     * @return int|string|list<string>|array{current: int, max: int}
     */
    private static function checked(Field $field, mixed $value): int|string|array
    {
        return match (true) {
            $field instanceof NumberField => self::number($field->key, $value, $field->min ?? -NumberField::LIMIT, $field->max ?? NumberField::LIMIT),
            $field instanceof TextField => self::text($field->key, $value, $field->limit(), $field->multiline),
            $field instanceof ListField => self::items($field->key, $value, $field->limit()),
            $field instanceof TrackField => self::track($field->key, $value, $field->max),
            default => throw new \LogicException(\sprintf('Unknown field type %s.', $field::class)),
        };
    }

    /**
     * What a Character holds in a field it was given no value for.
     *
     * @return int|string|list<string>|array{current: int, max: int}
     */
    public static function initial(Field $field): int|string|array
    {
        return match (true) {
            $field instanceof NumberField => $field->initial(),
            $field instanceof TextField => '',
            $field instanceof ListField => [],
            $field instanceof TrackField => ['current' => $field->max ?? 0, 'max' => $field->max ?? 0],
            default => throw new \LogicException(\sprintf('Unknown field type %s.', $field::class)),
        };
    }

    private static function number(string $key, mixed $value, int $min, int $max): int
    {
        if (!\is_int($value)) {
            throw InvalidCharacter::because(\sprintf('Field "%s" must be an integer.', $key));
        }

        if ($value < $min || $value > $max) {
            throw InvalidCharacter::because(\sprintf('Field "%s" must be %d to %d, got %d.', $key, $min, $max, $value));
        }

        return $value;
    }

    private static function text(string $key, mixed $value, int $limit, bool $multiline): string
    {
        if (!\is_string($value)) {
            throw InvalidCharacter::because(\sprintf('Field "%s" must be a string.', $key));
        }

        $value = trim($value);
        if (mb_strlen($value) > $limit) {
            throw InvalidCharacter::because(\sprintf('Field "%s" must be at most %d characters, got %d.', $key, $limit, mb_strlen($value)));
        }

        if (!$multiline && 1 === preg_match('/[\r\n]/', $value)) {
            throw InvalidCharacter::because(\sprintf('Field "%s" is a single line.', $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function items(string $key, mixed $value, int $limit): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidCharacter::because(\sprintf('Field "%s" must be a list of strings.', $key));
        }

        if (\count($value) > $limit) {
            throw InvalidCharacter::because(\sprintf('Field "%s" holds at most %d items, got %d.', $key, $limit, \count($value)));
        }

        $items = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw InvalidCharacter::because(\sprintf('Field "%s" must be a list of strings.', $key));
            }

            $item = trim($item);
            $length = mb_strlen($item);
            if (0 === $length || $length > self::MAX_ITEM_LENGTH) {
                throw InvalidCharacter::because(\sprintf('An item of field "%s" must be 1 to %d characters, got %d.', $key, self::MAX_ITEM_LENGTH, $length));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param ?int $fixedMax the max the field fixes, null when each Character sets its own
     *
     * @return array{current: int, max: int}
     */
    private static function track(string $key, mixed $value, ?int $fixedMax): array
    {
        if (!\is_array($value) || [] !== array_diff(array_keys($value), ['current', 'max'])) {
            throw InvalidCharacter::because(\sprintf('Field "%s" must be {current, max}.', $key));
        }

        // An explicit null max is refused, not read as the fixed max
        $max = \array_key_exists('max', $value) ? $value['max'] : $fixedMax;
        if (!\is_int($max) || !\is_int($value['current'] ?? null)) {
            throw InvalidCharacter::because(\sprintf('Field "%s" must be {current, max} with integers.', $key));
        }

        if (null !== $fixedMax && $max !== $fixedMax) {
            throw InvalidCharacter::because(\sprintf('Field "%s" has a fixed max of %d, got %d.', $key, $fixedMax, $max));
        }

        if ($max < 0 || $max > self::MAX_TRACK) {
            throw InvalidCharacter::because(\sprintf('The max of field "%s" must be 0 to %d, got %d.', $key, self::MAX_TRACK, $max));
        }

        if ($value['current'] < 0 || $value['current'] > $max) {
            throw InvalidCharacter::because(\sprintf('The current of field "%s" must be 0 to %d, got %d.', $key, $max, $value['current']));
        }

        return ['current' => $value['current'], 'max' => $max];
    }
}
