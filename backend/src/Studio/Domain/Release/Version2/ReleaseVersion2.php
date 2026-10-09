<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;
use App\Studio\Domain\Release\ReleaseOracles;

/**
 * Validates the parts schema version 2 adds or changes (docs/contracts/gamesystem-release.md):
 * oracle table entry and chaos extensions, trackers, fact slots and Scene Types with their steps.
 * Returns them in canonical form; ReleaseContent handles the parts every version shares.
 *
 * Flows are not validated yet: until a later slice of feature play-flow-run they must be empty.
 *
 * @internal used by ReleaseContent only
 */
final class ReleaseVersion2
{
    public const int MAX_TRACKERS = 50;
    public const int MAX_FACT_SLOTS = 100;
    public const int MAX_SCENE_TYPES = 100;
    public const int MAX_ORACLE_SHORTCUTS = 70;
    public const int MAX_TRACKER_VALUE = 1000;
    public const int MAX_SEGMENTS = 20;
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_HINT_LENGTH = 500;
    public const int MAX_PURPOSE_LENGTH = 500;
    public const int MAX_TEXT_LENGTH = 2000;

    /** The step lists of a Scene Type, in schema order. */
    public const array SCENE_TYPE_PARTS = ['setup', 'play', 'closing'];

    /** Top-level fields of schema version 2, in schema order: name => required. */
    public const array RELEASE = [
        'schemaVersion' => true, 'gameSystem' => true, 'oracles' => true, 'trackers' => true, 'factSlots' => true,
        'sceneTypes' => true, 'flows' => true, 'sheet' => true, 'checks' => true,
    ];

    private const string NOT_SUPPORTED_YET = 'not supported yet';
    private const array ENTRY = [...ReleaseOracles::ENTRY, 'key' => false, 'sceneType' => false, 'effects' => false];
    private const array CHAOS = [...ReleaseOracles::CHAOS, 'tracker' => false];
    private const array TRACKERS = [
        'counter' => ['key' => true, 'name' => true, 'hint' => false, 'kind' => true, 'min' => true, 'max' => true, 'initial' => true, 'levels' => false],
        'clock' => ['key' => true, 'name' => true, 'hint' => false, 'kind' => true, 'segments' => true],
    ];
    private const array FACT_SLOT = ['key' => true, 'label' => true, 'type' => true];
    private const array SCENE_TYPE = ['key' => true, 'name' => true, 'purpose' => true, 'tips' => false, 'oracles' => true, 'setup' => true, 'play' => true, 'closing' => true];

    /**
     * @param array<string, mixed> $release the checked top-level object (RELEASE fields)
     *
     * @return array{oracles: array<string, mixed>, trackers: list<array<string, mixed>>, factSlots: list<array<string, mixed>>, sceneTypes: list<array<string, mixed>>, flows: list<array<string, mixed>>}
     */
    public static function validate(array $release): array
    {
        [$trackers, $trackerRanges] = self::trackers($release['trackers']);
        $oracles = ReleaseOracles::validate($release['oracles'], self::ENTRY, self::CHAOS);
        self::chaosTrackers($oracles['likelihood'], $trackerRanges);
        $factSlots = self::factSlots($release['factSlots']);

        [$sceneTypes, $sceneTypeKeys] = self::sceneTypes($release['sceneTypes'], $oracles);
        $catalog = new Catalog($trackerRanges, self::tableEntryKeys($oracles['tables']), self::likelihoodLevels($oracles['likelihood']), $sceneTypeKeys);

        $oracles['tables'] = self::tableEntries($oracles['tables'], $catalog);
        foreach ($sceneTypes as $index => $sceneType) {
            foreach (self::SCENE_TYPE_PARTS as $part) {
                $sceneTypes[$index][$part] = Steps::list($sceneType[$part], \sprintf('sceneTypes[%d].%s', $index, $part), $catalog);
            }
        }

        $flows = self::notSupportedYet($release['flows'], 'flows');

        return ['oracles' => $oracles, 'trackers' => $trackers, 'factSlots' => $factSlots, 'sceneTypes' => $sceneTypes, 'flows' => $flows];
    }

    /**
     * @return array{list<array<string, mixed>>, array<string, array{kind: string, min: int, max: int}>} the trackers and their ranges by key (a clock ranges 0..segments)
     */
    private static function trackers(mixed $value): array
    {
        $trackers = [];
        $ranges = [];
        foreach (ReleaseFields::sizedList($value, 'trackers', 0, self::MAX_TRACKERS, 'trackers') as $index => $tracker) {
            $path = \sprintf('trackers[%d]', $index);
            $kind = ReleaseFields::tag($tracker, $path, 'kind', array_keys(self::TRACKERS));
            $tracker = ReleaseFields::object($tracker, $path, self::TRACKERS[$kind]);
            $key = ReleaseFields::key($tracker['key'], $path.'.key');
            if (isset($ranges[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate tracker key "%s"', $key));
            }

            ReleaseFields::text($tracker['name'], $path.'.name', 1, self::MAX_NAME_LENGTH);
            if (isset($tracker['hint'])) {
                ReleaseFields::text($tracker['hint'], $path.'.hint', 0, self::MAX_HINT_LENGTH);
            }

            if ('clock' === $kind) {
                $ranges[$key] = ['kind' => $kind, 'min' => 0, 'max' => ReleaseFields::integer($tracker['segments'], $path.'.segments', 1, self::MAX_SEGMENTS)];
            } else {
                $min = ReleaseFields::integer($tracker['min'], $path.'.min', -self::MAX_TRACKER_VALUE, self::MAX_TRACKER_VALUE);
                $max = ReleaseFields::integer($tracker['max'], $path.'.max', -self::MAX_TRACKER_VALUE, self::MAX_TRACKER_VALUE);
                $initial = ReleaseFields::integer($tracker['initial'], $path.'.initial', -self::MAX_TRACKER_VALUE, self::MAX_TRACKER_VALUE);
                if ($max < $min) {
                    throw InvalidReleaseContent::at($path.'.max', \sprintf('must be at least min (%d), %d given', $min, $max));
                }

                if ($initial < $min || $initial > $max) {
                    throw InvalidReleaseContent::at($path.'.initial', \sprintf('must be within min..max (%d..%d), %d given', $min, $max, $initial));
                }

                $tracker['levels'] = Bands::levels($tracker['levels'] ?? null, $path.'.levels');
                $tracker = array_filter($tracker, static fn (mixed $field): bool => [] !== $field);
                $ranges[$key] = ['kind' => $kind, 'min' => $min, 'max' => $max];
            }

            $trackers[] = $tracker;
        }

        return [$trackers, $ranges];
    }

    /**
     * A likelihood oracle's chaos "tracker" is a counter with the chaos factor's range.
     *
     * @param list<array<string, mixed>>                             $likelihood
     * @param array<string, array{kind: string, min: int, max: int}> $trackers
     */
    private static function chaosTrackers(array $likelihood, array $trackers): void
    {
        foreach ($likelihood as $index => $oracle) {
            /** @var array<string, mixed>|null $chaos */
            $chaos = $oracle['chaos'] ?? null;
            if (!isset($chaos['tracker'])) {
                continue;
            }

            $path = \sprintf('oracles.likelihood[%d].chaos.tracker', $index);
            $key = ReleaseFields::key($chaos['tracker'], $path);
            $tracker = $trackers[$key] ?? throw InvalidReleaseContent::at($path, \sprintf('unknown tracker "%s"', $key));
            if ('counter' !== $tracker['kind']) {
                throw InvalidReleaseContent::at($path, \sprintf('must be a counter, "%s" is a %s', $key, $tracker['kind']));
            }

            if ($tracker['min'] !== $chaos['min'] || $tracker['max'] !== $chaos['max']) {
                throw InvalidReleaseContent::at($path, \sprintf('counter "%s" must range %s..%s like the chaos factor, %d..%d given', $key, ReleaseFields::describe($chaos['min']), ReleaseFields::describe($chaos['max']), $tracker['min'], $tracker['max']));
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function factSlots(mixed $value): array
    {
        $slots = [];
        $keys = [];
        foreach (ReleaseFields::sizedList($value, 'factSlots', 0, self::MAX_FACT_SLOTS, 'fact slots') as $index => $slot) {
            $path = \sprintf('factSlots[%d]', $index);
            $slot = ReleaseFields::object($slot, $path, self::FACT_SLOT);
            $key = ReleaseFields::key($slot['key'], $path.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate fact slot key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($slot['label'], $path.'.label', 1, self::MAX_NAME_LENGTH);
            ReleaseFields::oneOf($slot['type'], $path.'.type', ['text', 'npc', 'thread']);
            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * Scene Types without their steps, which need every Scene Type key first.
     *
     * @param array{tables: list<array<string, mixed>>, likelihood: list<array<string, mixed>>} $oracles
     *
     * @return array{list<array<string, mixed>>, array<string, true>}
     */
    private static function sceneTypes(mixed $value, array $oracles): array
    {
        $sceneTypes = [];
        $keys = [];
        /** @var list<string> $oracleKeys */
        $oracleKeys = [...array_column($oracles['tables'], 'key'), ...array_column($oracles['likelihood'], 'key')];
        foreach (ReleaseFields::sizedList($value, 'sceneTypes', 0, self::MAX_SCENE_TYPES, 'Scene Types') as $index => $sceneType) {
            $path = \sprintf('sceneTypes[%d]', $index);
            $sceneType = ReleaseFields::object($sceneType, $path, self::SCENE_TYPE);
            $key = ReleaseFields::key($sceneType['key'], $path.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate Scene Type key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($sceneType['name'], $path.'.name', 1, self::MAX_NAME_LENGTH);
            ReleaseFields::text($sceneType['purpose'], $path.'.purpose', 1, self::MAX_PURPOSE_LENGTH);
            if (isset($sceneType['tips'])) {
                ReleaseFields::text($sceneType['tips'], $path.'.tips', 0, self::MAX_TEXT_LENGTH);
            }

            self::keyList($sceneType['oracles'], $path.'.oracles', self::MAX_ORACLE_SHORTCUTS, 'oracles', 'oracle', array_fill_keys($oracleKeys, true));
            $sceneTypes[] = $sceneType;
        }

        return [$sceneTypes, $keys];
    }

    /**
     * Entry "sceneType" and "effects" name what exists; empty "effects" are omitted.
     *
     * @param list<array<string, mixed>> $tables
     *
     * @return list<array<string, mixed>>
     */
    private static function tableEntries(array $tables, Catalog $catalog): array
    {
        foreach ($tables as $tableIndex => $table) {
            /** @var list<array<string, mixed>> $entries */
            $entries = $table['entries'];
            foreach ($entries as $entryIndex => $entry) {
                $path = \sprintf('oracles.tables[%d].entries[%d]', $tableIndex, $entryIndex);
                if (isset($entry['sceneType'])) {
                    Effects::sceneType($entry['sceneType'], $path.'.sceneType', $catalog);
                }

                $entry['effects'] = Effects::list($entry['effects'] ?? null, $path.'.effects', $catalog);
                $entries[$entryIndex] = array_filter($entry, static fn (mixed $field): bool => [] !== $field);
            }

            $tables[$tableIndex]['entries'] = $entries;
        }

        return $tables;
    }

    /**
     * Entry keys by table; they are unique within their table.
     *
     * @param list<array<string, mixed>> $tables
     *
     * @return array<string, array<string, true>>
     */
    private static function tableEntryKeys(array $tables): array
    {
        $tableEntryKeys = [];
        foreach ($tables as $tableIndex => $table) {
            /** @var string $tableKey */
            $tableKey = $table['key'];
            $tableEntryKeys[$tableKey] = [];
            /** @var list<array<string, mixed>> $entries */
            $entries = $table['entries'];
            foreach ($entries as $entryIndex => $entry) {
                if (!isset($entry['key'])) {
                    continue;
                }

                $path = \sprintf('oracles.tables[%d].entries[%d].key', $tableIndex, $entryIndex);
                $key = ReleaseFields::key($entry['key'], $path);
                if (isset($tableEntryKeys[$tableKey][$key])) {
                    throw InvalidReleaseContent::at($path, \sprintf('duplicate entry key "%s"', $key));
                }

                $tableEntryKeys[$tableKey][$key] = true;
            }
        }

        return $tableEntryKeys;
    }

    /**
     * @param list<array<string, mixed>> $likelihood
     *
     * @return array<string, array<string, true>>
     */
    private static function likelihoodLevels(array $likelihood): array
    {
        $levels = [];
        foreach ($likelihood as $oracle) {
            /** @var string $key */
            $key = $oracle['key'];
            /** @var list<array{key: string}> $oracleLevels */
            $oracleLevels = $oracle['levels'];
            $levels[$key] = array_fill_keys(array_column($oracleLevels, 'key'), true);
        }

        return $levels;
    }

    /**
     * A list of unique keys, each in $known.
     *
     * @param array<string, mixed> $known
     */
    private static function keyList(mixed $value, string $path, int $max, string $noun, string $singular, array $known): void
    {
        $seen = [];
        foreach (ReleaseFields::sizedList($value, $path, 0, $max, $noun) as $index => $key) {
            $keyPath = \sprintf('%s[%d]', $path, $index);
            $key = ReleaseFields::key($key, $keyPath);
            if (!isset($known[$key])) {
                throw InvalidReleaseContent::at($keyPath, \sprintf('unknown %s "%s"', $singular, $key));
            }

            if (isset($seen[$key])) {
                throw InvalidReleaseContent::at($keyPath, \sprintf('duplicate %s "%s"', $singular, $key));
            }

            $seen[$key] = true;
        }
    }

    /**
     * @return list<array<string, mixed>> always empty
     */
    private static function notSupportedYet(mixed $value, string $path): array
    {
        if ([] !== ReleaseFields::list($value, $path)) {
            throw InvalidReleaseContent::at($path, self::NOT_SUPPORTED_YET);
        }

        return [];
    }
}
