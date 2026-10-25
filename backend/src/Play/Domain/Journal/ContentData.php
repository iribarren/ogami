<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * Typed reads of a stored content array. Every mismatch is an InvalidJournalEntryContent.
 *
 * @internal used by the content value objects to rebuild themselves
 */
final class ContentData
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value)) {
            throw InvalidJournalEntryContent::malformed($field, 'a string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $field): ?string
    {
        if (!\array_key_exists($field, $data)) {
            throw InvalidJournalEntryContent::malformed($field, 'a string or null');
        }

        return null === $data[$field] ? null : self::string($data, $field);
    }

    /**
     * A string or null for a field older entries do not store: absent reads as null.
     *
     * @param array<mixed> $data
     */
    public static function optionalString(array $data, string $field): ?string
    {
        return null === ($data[$field] ?? null) ? null : self::string($data, $field);
    }

    /**
     * An object for a field older entries do not store: absent or null reads as null.
     *
     * @param array<mixed> $data
     *
     * @return ?array<mixed>
     */
    public static function optionalArray(array $data, string $field): ?array
    {
        $value = $data[$field] ?? null;
        if (null !== $value && (!\is_array($value) || array_is_list($value) && [] !== $value)) {
            throw InvalidJournalEntryContent::malformed($field, 'an object or null');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $field): int
    {
        $value = $data[$field] ?? null;
        if (!\is_int($value)) {
            throw InvalidJournalEntryContent::malformed($field, 'an integer');
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableInt(array $data, string $field): ?int
    {
        if (!\array_key_exists($field, $data)) {
            throw InvalidJournalEntryContent::malformed($field, 'an integer or null');
        }

        return null === $data[$field] ? null : self::int($data, $field);
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $field): bool
    {
        $value = $data[$field] ?? null;
        if (!\is_bool($value)) {
            throw InvalidJournalEntryContent::malformed($field, 'a boolean');
        }

        return $value;
    }

    /**
     * A list of arrays, e.g. the dice groups of a roll.
     *
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function listOfArrays(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidJournalEntryContent::malformed($field, 'a list');
        }

        $items = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                throw InvalidJournalEntryContent::malformed($field, 'a list of objects');
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * A list of arrays for a field older entries do not store: absent reads as an empty list.
     *
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function optionalListOfArrays(array $data, string $field): array
    {
        return \array_key_exists($field, $data) ? self::listOfArrays($data, $field) : [];
    }

    /**
     * A list of strings, e.g. the ids a note attaches.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function listOfStrings(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidJournalEntryContent::malformed($field, 'a list of strings');
        }

        $items = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw InvalidJournalEntryContent::malformed($field, 'a list of strings');
            }

            $items[] = $item;
        }

        return $items;
    }
}
