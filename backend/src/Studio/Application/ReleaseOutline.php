<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * What the sections of a release hold, item by item: the key and display name of each item in content
 * order, under the section names of SectionCounts (so each list is as long as its count), and the
 * gear catalog. Read
 * defensively like SectionCounts: an item without a key shows an empty one, and one without a display
 * name has null.
 *
 * Schema version 2 names a sheet field, a fact slot, a tag and a check by `label`, the other items by
 * `name`. The gear catalog (ADR 0045) is the one section SectionCounts has no count for; a release
 * without one has it empty. Schema version 1: oracles (tables, then likelihood oracles) by `name`, flowSteps by `title`.
 */
final class ReleaseOutline
{
    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, list<array{key: string, name: string|null}>> by section name
     */
    public static function of(array $document, int $schemaVersion): array
    {
        return array_map(
            static fn (array $entries): array => array_map(static fn (array $entry): array => ['key' => $entry['key'], 'name' => $entry['name']], $entries),
            self::entries($document, $schemaVersion),
        );
    }

    /**
     * The outline with each item as it is stored too, for what compares releases (ReleaseDiff).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, list<array{key: string, name: string|null, item: mixed}>> by section name
     */
    public static function entries(array $document, int $schemaVersion): array
    {
        if (1 === $schemaVersion) {
            return [
                'oracles' => [...self::items($document, 'name', 'oracles', 'tables'), ...self::items($document, 'name', 'oracles', 'likelihood')],
                'flowSteps' => self::items($document, 'title', 'flow', 'steps'),
            ];
        }

        return [
            'oracleTables' => self::items($document, 'name', 'oracles', 'tables'),
            'likelihoodOracles' => self::items($document, 'name', 'oracles', 'likelihood'),
            'trackers' => self::items($document, 'name', 'trackers'),
            'factSlots' => self::items($document, 'label', 'factSlots'),
            'tags' => self::items($document, 'label', 'tags'),
            'sceneTypes' => self::items($document, 'name', 'sceneTypes'),
            'flows' => self::items($document, 'name', 'flows'),
            'sheetFields' => self::items($document, 'label', 'sheet', 'fields'),
            'gear' => self::items($document, 'name', 'gear'),
            'checks' => self::items($document, 'label', 'checks'),
        ];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array{key: string, name: string|null, item: mixed}>
     */
    private static function items(array $document, string $nameField, string ...$path): array
    {
        $node = $document;
        foreach ($path as $segment) {
            $node = \is_array($node) ? ($node[$segment] ?? null) : null;
        }

        $items = [];
        foreach (\is_array($node) ? $node : [] as $item) {
            $key = \is_array($item) ? ($item['key'] ?? null) : null;
            $name = \is_array($item) ? ($item[$nameField] ?? null) : null;
            $items[] = [
                'key' => \is_string($key) ? $key : '',
                'name' => \is_string($name) && '' !== $name ? $name : null,
                'item' => $item,
            ];
        }

        return $items;
    }
}
