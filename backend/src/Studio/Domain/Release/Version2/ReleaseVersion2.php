<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;
use App\Studio\Domain\Release\ReleaseOracles;

/**
 * Validates the parts schema version 2 adds or changes (docs/contracts/gamesystem-release.md):
 * oracle table entry and chaos extensions, trackers, fact slots, tags, the sheet, checks, Scene Types and flows,
 * then the references inside each flow. Returns them in canonical form; ReleaseContent handles the parts
 * every version shares.
 *
 * @internal used by ReleaseContent only
 */
final class ReleaseVersion2
{
    public const int MAX_TRACKERS = 50;
    public const int MAX_FACT_SLOTS = 100;
    public const int MAX_TAGS = 50;
    public const int MAX_TAG_LABEL_LENGTH = 50;
    public const int MAX_SCENE_TYPES = 100;
    public const int MAX_FLOWS = 20;
    public const int MAX_PHASES = 20;
    public const int MAX_SELECTION_SCENE_TYPES = 20;
    public const int MAX_ORACLE_SHORTCUTS = 70;
    public const int MAX_TRACKER_VALUE = 1000;
    public const int MAX_SEGMENTS = 20;
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_HINT_LENGTH = 500;
    public const int MAX_PURPOSE_LENGTH = 500;
    public const int MAX_TEXT_LENGTH = 2000;
    public const int MAX_INTRODUCTION_LENGTH = 5000;
    public const int MAX_CREDITS_NOTICE_LENGTH = 2000;
    public const int MAX_CREDITS_LICENSE_LENGTH = 200;
    public const int MAX_CREDITS_URL_LENGTH = 500;

    /** The step lists of a Scene Type, and the hooks of a phase, in schema order. */
    public const array SCENE_TYPE_PARTS = ['setup', 'play', 'closing'];
    public const array PHASE_HOOKS = ['sessionOpening', 'sessionClosing', 'phaseOpening', 'phaseClosing', 'sceneOpening', 'sceneClosing', 'worldTurn'];

    /** The curated Themes a release may name (docs/contracts/gamesystem-release.md, "Theme"). */
    public const array THEMES = ['parchment', 'neon', 'noir', 'eldritch', 'starship'];

    /** Fields of "gameSystem" in schema version 2, in schema order: name => required. */
    public const array GAME_SYSTEM = ['key' => true, 'name' => true, 'description' => false, 'credits' => false];

    private const array CREDITS = ['notice' => true, 'license' => false, 'url' => false];

    /** Top-level fields of schema version 2, in schema order: name => required. */
    public const array RELEASE = [
        'schemaVersion' => true, 'gameSystem' => true, 'theme' => false, 'oracles' => true, 'trackers' => true, 'factSlots' => true, 'tags' => false,
        'sceneTypes' => true, 'flows' => true, 'sheet' => true, 'checks' => true,
    ];

    private const array ENTRY = [...ReleaseOracles::ENTRY, 'key' => false, 'sceneType' => false, 'effects' => false];
    private const array CHAOS = [...ReleaseOracles::CHAOS, 'tracker' => false];
    private const array LIKELIHOOD = [...ReleaseOracles::LIKELIHOOD, 'randomEvent' => false];
    private const array TRACKERS = [
        'counter' => ['key' => true, 'name' => true, 'hint' => false, 'kind' => true, 'min' => true, 'max' => true, 'initial' => true, 'levels' => false],
        'clock' => ['key' => true, 'name' => true, 'hint' => false, 'kind' => true, 'segments' => true],
    ];
    private const array FACT_SLOT = ['key' => true, 'label' => true, 'type' => true, 'hint' => false];
    private const array TAG = ['key' => true, 'label' => true, 'hint' => false];
    private const array SCENE_TYPE = ['key' => true, 'name' => true, 'purpose' => true, 'tips' => false, 'oracles' => true, 'setup' => true, 'play' => true, 'closing' => true];
    private const array FLOW = [
        'key' => true, 'name' => true, 'description' => false, 'introduction' => false, 'default' => false, 'defaultView' => true,
        'oracles' => true, 'trackers' => true, 'phases' => true,
    ];
    private const array PHASE = [
        'key' => true, 'name' => true, 'act' => false, 'description' => false, 'mode' => true, 'selection' => true,
        'sessionOpening' => false, 'sessionClosing' => false, 'phaseOpening' => false, 'phaseClosing' => false,
        'sceneOpening' => false, 'sceneClosing' => false, 'worldTurn' => false,
    ];
    private const array SELECTIONS = [
        'sequence' => ['rule' => true, 'sceneTypes' => true],
        'player' => ['rule' => true, 'sceneTypes' => true],
        'oracle' => ['rule' => true, 'table' => true],
    ];

    /**
     * @param array<string, mixed> $release the checked top-level object (RELEASE fields)
     *
     * @return array{theme?: string, oracles: array<string, mixed>, trackers: list<array<string, mixed>>, factSlots: list<array<string, mixed>>, tags?: list<array<string, mixed>>, sceneTypes: list<array<string, mixed>>, flows: list<array<string, mixed>>, sheet: array<string, mixed>, checks: list<array<string, mixed>>} "theme" and "tags" are left out when absent or empty
     */
    public static function validate(array $release): array
    {
        [$trackers, $trackerRanges] = self::trackers($release['trackers']);
        $oracles = ReleaseOracles::validate($release['oracles'], self::ENTRY, self::CHAOS, self::LIKELIHOOD);
        self::chaosTrackers($oracles['likelihood'], $trackerRanges);
        [$factSlots, $factSlotTypes] = self::factSlots($release['factSlots']);
        [$tags, $tagKeys] = self::tags($release['tags'] ?? null);

        [$sceneTypes, $sceneTypeKeys] = self::sceneTypes($release['sceneTypes'], $oracles);

        // Checks and sheet effects read the sheet, and flow steps read the checks, so both come before the steps.
        $sheet = SheetFields::validate($release['sheet'], array_keys($trackerRanges));
        /** @var array<string, string> $fieldTypes */
        $fieldTypes = array_column($sheet['fields'] ?? [], 'type', 'key');
        $catalog = new Catalog($trackerRanges, self::tableEntryKeys($oracles['tables']), self::likelihoodLevels($oracles['likelihood']), $sceneTypeKeys, $tagKeys, $factSlotTypes, fieldTypes: $fieldTypes);
        [$checks, $checkShapes] = Checks::validate($release['checks'], $fieldTypes, $catalog);
        $catalog = $catalog->withChecks($checkShapes);

        $oracles['tables'] = self::tableEntries($oracles['tables'], $catalog);
        foreach ($sceneTypes as $index => $sceneType) {
            foreach (self::SCENE_TYPE_PARTS as $part) {
                $sceneTypes[$index][$part] = Steps::list($sceneType[$part], \sprintf('sceneTypes[%d].%s', $index, $part), $catalog);
            }
        }

        $flows = self::flows($release['flows'], $oracles, $catalog);
        FlowReferences::check($oracles['tables'], $trackers, $sceneTypes, $flows, $factSlotTypes);

        $parts = [];
        if (isset($release['theme'])) {
            $parts['theme'] = ReleaseFields::oneOf($release['theme'], 'theme', self::THEMES);
        }

        $parts = [...$parts, 'oracles' => $oracles, 'trackers' => $trackers, 'factSlots' => $factSlots];
        if ([] !== $tags) {
            $parts['tags'] = $tags;
        }

        return [...$parts, 'sceneTypes' => $sceneTypes, 'flows' => $flows, 'sheet' => $sheet, 'checks' => $checks];
    }

    /**
     * The optional credits of the GameSystem (ADR 0021): a notice and, when given, a license name
     * and an absolute https:// URL. Returned in schema order without null fields.
     *
     * @return array{notice: string, license?: string, url?: string}
     */
    public static function credits(mixed $value): array
    {
        $path = 'gameSystem.credits';
        $credits = ReleaseFields::object($value, $path, self::CREDITS);
        $checked = ['notice' => ReleaseFields::text($credits['notice'], $path.'.notice', 1, self::MAX_CREDITS_NOTICE_LENGTH)];
        if (isset($credits['license'])) {
            $checked['license'] = ReleaseFields::text($credits['license'], $path.'.license', 0, self::MAX_CREDITS_LICENSE_LENGTH);
        }

        if (isset($credits['url'])) {
            $url = ReleaseFields::text($credits['url'], $path.'.url', 0, self::MAX_CREDITS_URL_LENGTH);
            if (1 !== preg_match('~^https://[^\\s/?\#]+\\S*$~u', $url)) {
                throw InvalidReleaseContent::at($path.'.url', \sprintf('must be an absolute https:// URL, %s given', ReleaseFields::describe($url)));
            }

            $checked['url'] = $url;
        }

        return $checked;
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
     * @return array{list<array<string, mixed>>, array<string, string>} the fact slots and their types by key
     */
    private static function factSlots(mixed $value): array
    {
        $slots = [];
        $types = [];
        foreach (ReleaseFields::sizedList($value, 'factSlots', 0, self::MAX_FACT_SLOTS, 'fact slots') as $index => $slot) {
            $path = \sprintf('factSlots[%d]', $index);
            $slot = ReleaseFields::object($slot, $path, self::FACT_SLOT);
            $key = ReleaseFields::key($slot['key'], $path.'.key');
            if (isset($types[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate fact slot key "%s"', $key));
            }

            ReleaseFields::text($slot['label'], $path.'.label', 1, self::MAX_NAME_LENGTH);
            $types[$key] = ReleaseFields::oneOf($slot['type'], $path.'.type', ['text', 'npc', 'thread']);
            if (isset($slot['hint'])) {
                ReleaseFields::text($slot['hint'], $path.'.hint', 0, self::MAX_HINT_LENGTH);
            }

            $slots[] = $slot;
        }

        return [$slots, $types];
    }

    /**
     * @return array{list<array<string, mixed>>, array<string, true>} the tags and their keys
     */
    private static function tags(mixed $value): array
    {
        $tags = [];
        $keys = [];
        foreach (ReleaseFields::sizedList($value ?? [], 'tags', 0, self::MAX_TAGS, 'tags') as $index => $tag) {
            $path = \sprintf('tags[%d]', $index);
            $tag = ReleaseFields::object($tag, $path, self::TAG);
            $key = ReleaseFields::key($tag['key'], $path.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate tag key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($tag['label'], $path.'.label', 1, self::MAX_TAG_LABEL_LENGTH);
            if (isset($tag['hint'])) {
                ReleaseFields::text($tag['hint'], $path.'.hint', 0, self::MAX_HINT_LENGTH);
            }

            $tags[] = $tag;
        }

        return [$tags, $keys];
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
        $oracleKeys = self::oracleKeys($oracles);
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

            self::keyList($sceneType['oracles'], $path.'.oracles', self::MAX_ORACLE_SHORTCUTS, 'oracles', 'oracle', $oracleKeys);
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
     * @param array{tables: list<array<string, mixed>>, likelihood: list<array<string, mixed>>} $oracles
     *
     * @return list<array<string, mixed>>
     */
    private static function flows(mixed $value, array $oracles, Catalog $catalog): array
    {
        $flows = [];
        $keys = [];
        $default = null;
        $oracleKeys = self::oracleKeys($oracles);
        foreach (ReleaseFields::sizedList($value, 'flows', 0, self::MAX_FLOWS, 'flows') as $index => $flow) {
            $path = \sprintf('flows[%d]', $index);
            $flow = ReleaseFields::object($flow, $path, self::FLOW);
            $key = ReleaseFields::key($flow['key'], $path.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate flow key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($flow['name'], $path.'.name', 1, self::MAX_NAME_LENGTH);
            if (isset($flow['description'])) {
                ReleaseFields::text($flow['description'], $path.'.description', 0, self::MAX_TEXT_LENGTH);
            }

            if (isset($flow['introduction'])) {
                ReleaseFields::text($flow['introduction'], $path.'.introduction', 0, self::MAX_INTRODUCTION_LENGTH);
            }

            if (isset($flow['default']) && ReleaseFields::boolean($flow['default'], $path.'.default')) {
                if (null !== $default) {
                    throw InvalidReleaseContent::at($path.'.default', \sprintf('at most one flow is the default, "%s" already is', $default));
                }

                $default = $key;
            }

            ReleaseFields::oneOf($flow['defaultView'], $path.'.defaultView', ['focus', 'journal']);
            self::keyList($flow['oracles'], $path.'.oracles', self::MAX_ORACLE_SHORTCUTS, 'oracles', 'oracle', $oracleKeys);
            self::keyList($flow['trackers'], $path.'.trackers', self::MAX_TRACKERS, 'trackers', 'tracker', $catalog->trackers);

            $phases = [];
            $phaseKeys = [];
            foreach (ReleaseFields::sizedList($flow['phases'], $path.'.phases', 1, self::MAX_PHASES, 'phases') as $phaseIndex => $phase) {
                $phasePath = \sprintf('%s.phases[%d]', $path, $phaseIndex);
                $phase = self::phase($phase, $phasePath, $catalog);
                /** @var string $phaseKey */
                $phaseKey = $phase['key'];
                if (isset($phaseKeys[$phaseKey])) {
                    throw InvalidReleaseContent::at($phasePath.'.key', \sprintf('duplicate phase key "%s"', $phaseKey));
                }

                $phaseKeys[$phaseKey] = true;
                $phases[] = $phase;
            }

            $flow['phases'] = $phases;
            $flows[] = array_filter($flow, static fn (mixed $field): bool => false !== $field);
        }

        return $flows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function phase(mixed $value, string $path, Catalog $catalog): array
    {
        $phase = ReleaseFields::object($value, $path, self::PHASE);
        ReleaseFields::key($phase['key'], $path.'.key');
        ReleaseFields::text($phase['name'], $path.'.name', 1, self::MAX_NAME_LENGTH);
        if (isset($phase['act'])) {
            ReleaseFields::text($phase['act'], $path.'.act', 1, self::MAX_NAME_LENGTH);
        }

        if (isset($phase['description'])) {
            ReleaseFields::text($phase['description'], $path.'.description', 0, self::MAX_TEXT_LENGTH);
        }

        ReleaseFields::oneOf($phase['mode'], $path.'.mode', ['once', 'loop']);
        $phase['selection'] = self::selection($phase['selection'], $path.'.selection', $catalog);
        foreach (self::PHASE_HOOKS as $hook) {
            if (isset($phase[$hook])) {
                $phase[$hook] = Steps::list($phase[$hook], $path.'.'.$hook, $catalog);
            }
        }

        return array_filter($phase, static fn (mixed $field): bool => [] !== $field);
    }

    /**
     * @return array<string, mixed>
     */
    private static function selection(mixed $value, string $path, Catalog $catalog): array
    {
        $rule = ReleaseFields::tag($value, $path, 'rule', array_keys(self::SELECTIONS));
        $selection = ReleaseFields::object($value, $path, self::SELECTIONS[$rule]);
        if ('oracle' !== $rule) {
            $sceneTypes = ReleaseFields::sizedList($selection['sceneTypes'], $path.'.sceneTypes', 1, self::MAX_SELECTION_SCENE_TYPES, 'Scene Types');
            $seen = [];
            foreach ($sceneTypes as $index => $sceneType) {
                $sceneTypePath = \sprintf('%s.sceneTypes[%d]', $path, $index);
                $key = Effects::sceneType($sceneType, $sceneTypePath, $catalog);
                if ('player' === $rule && isset($seen[$key])) {
                    throw InvalidReleaseContent::at($sceneTypePath, \sprintf('duplicate Scene Type "%s"', $key));
                }

                $seen[$key] = true;
            }

            return $selection;
        }

        $table = ReleaseFields::key($selection['table'], $path.'.table');
        if (!$catalog->hasTable($table)) {
            throw InvalidReleaseContent::at($path.'.table', \sprintf('unknown oracle table "%s"', $table));
        }

        return $selection;
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
     * @param array{tables: list<array<string, mixed>>, likelihood: list<array<string, mixed>>} $oracles
     *
     * @return array<string, true>
     */
    private static function oracleKeys(array $oracles): array
    {
        /** @var list<string> $keys */
        $keys = [...array_column($oracles['tables'], 'key'), ...array_column($oracles['likelihood'], 'key')];

        return array_fill_keys($keys, true);
    }
}
