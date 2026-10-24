<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * What a release changed against the one before it, section by section, over the sections of
 * ReleaseOutline. Items match by their outline key (none, or not a string, is the empty key); a key
 * that repeats in a section counts by its first occurrence, as stored content is not trusted. Moving
 * an item is not changing it: a section only says whether the kept items changed relative order.
 *
 * Values compare canonically (map keys sorted recursively, list order kept), because the JSONB column
 * does not keep the property order of an object. Only the sections are compared, not the GameSystem's
 * own metadata.
 *
 * @phpstan-type ItemRef array{key: string, name: string|null}
 * @phpstan-type ChangedItem array{key: string, name: string|null, previousName: string|null, fields: list<string>}
 * @phpstan-type SectionChanges array{added: list<ItemRef>, removed: list<ItemRef>, changed: list<ChangedItem>, reordered: bool}
 */
final class ReleaseDiff
{
    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $previousDocument
     *
     * @return array<string, SectionChanges>|null by section name; null when the schema versions differ, as their sections do
     */
    public static function of(array $document, int $schemaVersion, array $previousDocument, int $previousSchemaVersion): ?array
    {
        if ($schemaVersion !== $previousSchemaVersion) {
            return null;
        }

        $previous = ReleaseOutline::entries($previousDocument, $previousSchemaVersion);
        $sections = [];
        foreach (ReleaseOutline::entries($document, $schemaVersion) as $section => $entries) {
            $sections[$section] = self::section(self::byKey($entries), self::byKey($previous[$section] ?? []));
        }

        return $sections;
    }

    /**
     * @param array<string, array{key: string, name: string|null, item: mixed}> $current
     * @param array<string, array{key: string, name: string|null, item: mixed}> $previous
     *
     * @return SectionChanges
     */
    private static function section(array $current, array $previous): array
    {
        $added = [];
        $changed = [];
        foreach ($current as $key => $entry) {
            if (!isset($previous[$key])) {
                $added[] = ['key' => $entry['key'], 'name' => $entry['name']];
                continue;
            }

            $fields = self::fields($entry['item'], $previous[$key]['item']);
            if (null !== $fields) {
                $changed[] = ['key' => $entry['key'], 'name' => $entry['name'], 'previousName' => $previous[$key]['name'], 'fields' => $fields];
            }
        }

        $removed = [];
        foreach ($previous as $key => $entry) {
            if (!isset($current[$key])) {
                $removed[] = ['key' => $entry['key'], 'name' => $entry['name']];
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'reordered' => array_keys(array_intersect_key($current, $previous)) !== array_keys(array_intersect_key($previous, $current)),
        ];
    }

    /**
     * The entries by key, in content order, each key by its first occurrence. PHP turns a numeric string
     * key into an int, so the keys are only used to look up and to compare orders, never read back.
     *
     * @param list<array{key: string, name: string|null, item: mixed}> $entries
     *
     * @return array<string, array{key: string, name: string|null, item: mixed}>
     */
    private static function byKey(array $entries): array
    {
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry['key']] ??= $entry;
        }

        return $byKey;
    }

    /**
     * The sorted names of the top-level properties whose values differ (one only a side has included),
     * null when the items are the same. A non-array item has no properties.
     *
     * @return list<string>|null
     */
    private static function fields(mixed $item, mixed $previousItem): ?array
    {
        if (self::canonical($item) === self::canonical($previousItem)) {
            return null;
        }

        $item = \is_array($item) ? $item : [];
        $previousItem = \is_array($previousItem) ? $previousItem : [];

        $fields = [];
        foreach (array_unique([...array_keys($item), ...array_keys($previousItem)]) as $field) {
            if (self::canonical($item[$field] ?? null) !== self::canonical($previousItem[$field] ?? null)
                || \array_key_exists($field, $item) !== \array_key_exists($field, $previousItem)) {
                $fields[] = (string) $field;
            }
        }
        sort($fields, \SORT_STRING);

        return $fields;
    }

    private static function canonical(mixed $value): mixed
    {
        // The content model keeps an empty JSON object as an object, the stored copy decodes it to an array.
        $value = $value instanceof \stdClass ? (array) $value : $value;
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }
}
