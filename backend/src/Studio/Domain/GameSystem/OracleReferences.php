<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Studio\Domain\Release\Version2\ReleaseVersion2;

/**
 * Where a draft document uses an oracle: the places an oracle key (a table or a likelihood oracle,
 * one namespace) or the entry key of a table is named. The draft may be invalid elsewhere, so the
 * document is read defensively: anything that is not the expected array or string is skipped, and
 * nothing throws.
 *
 * Paths read like the contract's error paths, e.g. "flows[0].phases[1].sceneOpening[2]".
 */
final class OracleReferences
{
    /**
     * The places that name this oracle key: Scene Type and flow "oracles", the table of an "oracle"
     * phase selection, "oracle" and "table" steps, "prompt" inspiration, a likelihood oracle's
     * random event table and the nested table of a table entry.
     *
     * @param array<string, mixed> $document
     * @param string|null          $exceptTable a table whose own entries do not count (the one being replaced or deleted)
     *
     * @return list<string>
     */
    public static function pathsTo(array $document, string $key, ?string $exceptTable = null): array
    {
        $paths = [];
        foreach (['sceneTypes', 'flows'] as $section) {
            foreach (self::objects($document[$section] ?? null) as $index => $owner) {
                if (self::names($owner['oracles'] ?? null, $key)) {
                    $paths[] = \sprintf('%s[%d].oracles', $section, $index);
                }
            }
        }

        foreach (self::phases($document) as $path => $phase) {
            $selection = $phase['selection'] ?? null;
            if (\is_array($selection) && 'oracle' === ($selection['rule'] ?? null) && $key === ($selection['table'] ?? null)) {
                $paths[] = $path.'.selection';
            }
        }

        foreach (self::steps($document) as $path => $step) {
            $kind = $step['kind'] ?? null;
            if (('oracle' === $kind && $key === ($step['oracle'] ?? null)) || ('table' === $kind && $key === ($step['table'] ?? null))) {
                $paths[] = $path;
            } elseif ('prompt' === $kind && self::names($step['inspiration'] ?? null, $key)) {
                $paths[] = $path.'.inspiration';
            }
        }

        $oracles = \is_array($document['oracles'] ?? null) ? $document['oracles'] : [];
        foreach (self::objects($oracles['likelihood'] ?? null) as $index => $oracle) {
            $randomEvent = $oracle['randomEvent'] ?? null;
            if (\is_array($randomEvent) && $key === ($randomEvent['table'] ?? null)) {
                $paths[] = \sprintf('oracles.likelihood[%d].randomEvent', $index);
            }
        }

        foreach (self::objects($oracles['tables'] ?? null) as $tableIndex => $table) {
            if (null !== $exceptTable && $exceptTable === ($table['key'] ?? null)) {
                continue;
            }

            foreach (self::objects($table['entries'] ?? null) as $entryIndex => $entry) {
                if ($key === ($entry['table'] ?? null)) {
                    $paths[] = \sprintf('oracles.tables[%d].entries[%d].table', $tableIndex, $entryIndex);
                }
            }
        }

        return $paths;
    }

    /**
     * The branches of "table" steps that name this entry of this table.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function entryPathsTo(array $document, string $table, string $entry): array
    {
        $paths = [];
        foreach (self::steps($document) as $path => $step) {
            if ('table' !== ($step['kind'] ?? null) || $table !== ($step['table'] ?? null)) {
                continue;
            }

            foreach (self::objects($step['branches'] ?? null) as $index => $branch) {
                if ($entry === ($branch['entry'] ?? null)) {
                    $paths[] = \sprintf('%s.branches[%d].entry', $path, $index);
                }
            }
        }

        return $paths;
    }

    /**
     * Every step of every Scene Type part and phase hook, by path.
     *
     * @param array<string, mixed> $document
     *
     * @return iterable<string, array<array-key, mixed>>
     */
    private static function steps(array $document): iterable
    {
        foreach (self::objects($document['sceneTypes'] ?? null) as $index => $sceneType) {
            yield from self::stepLists($sceneType, \sprintf('sceneTypes[%d]', $index), ReleaseVersion2::SCENE_TYPE_PARTS);
        }

        foreach (self::phases($document) as $path => $phase) {
            yield from self::stepLists($phase, $path, ReleaseVersion2::PHASE_HOOKS);
        }
    }

    /**
     * @param array<array-key, mixed> $owner
     * @param list<string>            $names
     *
     * @return iterable<string, array<array-key, mixed>>
     */
    private static function stepLists(array $owner, string $path, array $names): iterable
    {
        foreach ($names as $name) {
            foreach (self::objects($owner[$name] ?? null) as $index => $step) {
                yield \sprintf('%s.%s[%d]', $path, $name, $index) => $step;
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return iterable<string, array<array-key, mixed>>
     */
    private static function phases(array $document): iterable
    {
        foreach (self::objects($document['flows'] ?? null) as $flowIndex => $flow) {
            foreach (self::objects($flow['phases'] ?? null) as $phaseIndex => $phase) {
                yield \sprintf('flows[%d].phases[%d]', $flowIndex, $phaseIndex) => $phase;
            }
        }
    }

    /**
     * The array items of a list, by their index; anything else is skipped.
     *
     * @return iterable<int, array<array-key, mixed>>
     */
    private static function objects(mixed $value): iterable
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return;
        }

        foreach ($value as $index => $item) {
            if (\is_array($item)) {
                yield $index => $item;
            }
        }
    }

    private static function names(mixed $keys, string $key): bool
    {
        return \is_array($keys) && \in_array($key, $keys, true);
    }
}
