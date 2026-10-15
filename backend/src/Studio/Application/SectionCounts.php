<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * What the sections of a release document hold, read defensively because a draft may be invalid.
 *
 * Schema version 2: oracleTables (oracles.tables), likelihoodOracles (oracles.likelihood), trackers,
 * factSlots, tags, sceneTypes, flows, sheetFields (sheet.fields) and checks. Schema version 1:
 * oracles (tables and likelihood together) and flowSteps (flow.steps).
 */
final class SectionCounts
{
    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, int> by section name
     */
    public static function of(array $document, int $schemaVersion): array
    {
        if (1 === $schemaVersion) {
            return [
                'oracles' => self::count($document, 'oracles', 'tables') + self::count($document, 'oracles', 'likelihood'),
                'flowSteps' => self::count($document, 'flow', 'steps'),
            ];
        }

        return [
            'oracleTables' => self::count($document, 'oracles', 'tables'),
            'likelihoodOracles' => self::count($document, 'oracles', 'likelihood'),
            'trackers' => self::count($document, 'trackers'),
            'factSlots' => self::count($document, 'factSlots'),
            'tags' => self::count($document, 'tags'),
            'sceneTypes' => self::count($document, 'sceneTypes'),
            'flows' => self::count($document, 'flows'),
            'sheetFields' => self::count($document, 'sheet', 'fields'),
            'checks' => self::count($document, 'checks'),
        ];
    }

    /**
     * The number of items of the list at the path, 0 when there is none there (or something else).
     *
     * @param array<string, mixed> $document
     */
    private static function count(array $document, string ...$path): int
    {
        $node = $document;
        foreach ($path as $segment) {
            $node = \is_array($node) ? ($node[$segment] ?? null) : null;
        }

        return \is_array($node) ? \count($node) : 0;
    }
}
