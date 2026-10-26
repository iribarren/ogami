<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Formula\Domain\Formula;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\ReferenceKind;
use App\Studio\Domain\Release\Version2\Placeholders;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;

/**
 * Where a draft document names a key: an oracle (a table or a likelihood oracle, one namespace), the
 * entry of a table, a tracker, a Scene Type, a tag or a fact slot. The draft may be invalid
 * elsewhere, so the document is read defensively: anything that is not the expected array or string
 * is skipped, and nothing throws.
 *
 * Paths read like the contract's error paths and name the place that holds the reference, e.g.
 * "flows[0].phases[1].sceneOpening[2]" (a step) or "flows[0].phases[1].sceneOpening[2].effects[0]"
 * (one of its effects). A place naming a key twice is listed once.
 */
final class DraftReferences
{
    private const string TRACKER = 'tracker';
    private const string SCENE_TYPE = 'sceneType';
    private const string TAG = 'tag';
    private const string FACT_SLOT = 'factSlot';

    /** The step fields whose objects are outcomes: they may carry "effects". */
    private const array OUTCOME_LISTS = ['bands', 'options', 'outcomes', 'branches'];

    /** The fields of an effect that may hold placeholders. */
    private const array EFFECT_TEXTS = ['title', 'name', 'agenda', 'text'];

    /**
     * The places that name this oracle key: Scene Type and flow "oracles", the table of an "oracle"
     * phase selection, "oracle" and "table" steps (the default table and each table of
     * "tableBySceneType"), "prompt" inspiration, a likelihood oracle's random event table and the
     * nested table of a table entry.
     *
     * @param array<string, mixed> $document
     * @param string|null          $exceptTable a table whose own entries do not count (the one being replaced or deleted)
     *
     * @return list<string>
     */
    public static function oraclePathsTo(array $document, string $key, ?string $exceptTable = null): array
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

            if ('table' === $kind) {
                foreach (self::tablesBySceneType($step) as $sceneType => $table) {
                    if ($key === $table) {
                        $paths[] = \sprintf('%s.tableBySceneType.%s', $path, $sceneType);
                    }
                }
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
     * The branches of "table" steps that name this entry of this table. A step with
     * "tableBySceneType" has no branches (ADR 0042), so the map adds nothing here.
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
     * The places that name this tracker: flow "trackers", "tracker" effects and their values, a
     * condition's tracker, band bounds, check step difficulties, {tracker:key} placeholders, a
     * likelihood oracle's chaos, table entry effects, derived sheet fields and checks (modifier,
     * pool count formula, bands and the effects of a push).
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function trackerPathsTo(array $document, string $key): array
    {
        return self::pathsOf($document, self::TRACKER, $key);
    }

    /**
     * The places that name this Scene Type: "sequence" and "player" phase selections, nextScene and
     * switchSceneType effects and table entries.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function sceneTypePathsTo(array $document, string $key): array
    {
        return self::pathsOf($document, self::SCENE_TYPE, $key);
    }

    /**
     * The places that name this tag: createNpc and createThread effects, condition counts and pick steps.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function tagPathsTo(array $document, string $key): array
    {
        return self::pathsOf($document, self::TAG, $key);
    }

    /**
     * The places that name this fact slot: fillFact effects and {fact:key} placeholders.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function factSlotPathsTo(array $document, string $key): array
    {
        return self::pathsOf($document, self::FACT_SLOT, $key);
    }

    /**
     * The places that name this item of a narrative section from outside the item itself, which
     * is what blocks its rename or delete and what "used by" lists: a Scene Type's own steps naming
     * it do not count. Flow keys are named nowhere, so a flow has none.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function narrativePathsTo(array $document, NarrativeSection $section, string $key): array
    {
        $paths = match ($section) {
            NarrativeSection::Trackers => self::trackerPathsTo($document, $key),
            NarrativeSection::Tags => self::tagPathsTo($document, $key),
            NarrativeSection::FactSlots => self::factSlotPathsTo($document, $key),
            NarrativeSection::SceneTypes => self::sceneTypePathsTo($document, $key),
            NarrativeSection::Flows => [],
        };

        foreach (self::objects($document[$section->value] ?? null) as $index => $item) {
            if ($key === ($item['key'] ?? null)) {
                $own = \sprintf('%s[%d].', $section->value, $index);

                return array_values(array_filter($paths, static fn (string $path): bool => !str_starts_with($path, $own)));
            }
        }

        return $paths;
    }

    /**
     * The places that name this sheet field key: derived field formulas, start max formulas, the
     * point budget's field lists, check modifiers, pool count formulas and band
     * formulas reading {key} or {key.max}, and sheet effects whose "field" is the key or whose
     * {formula} value reads it, in steps, outcomes, table entries and the cost of a check's push. Flow formulas read trackers
     * only, so they never name a field.
     *
     * @param array<string, mixed> $document
     * @param string|null          $exceptField a field whose own formula does not count (the one being replaced or deleted)
     *
     * @return list<string>
     */
    public static function sheetFieldPathsTo(array $document, string $key, ?string $exceptField = null): array
    {
        $paths = [];
        $sheet = \is_array($document['sheet'] ?? null) ? $document['sheet'] : [];
        foreach (self::objects($sheet['fields'] ?? null) as $index => $field) {
            if (null === $exceptField || $exceptField !== ($field['key'] ?? null)) {
                $paths += self::sheetFormulaPaths($field['formula'] ?? null, $key, \sprintf('sheet.fields[%d].formula', $index));
                $startMax = $field['startMax'] ?? null;
                $paths += self::sheetFormulaPaths(\is_array($startMax) ? $startMax['formula'] ?? null : null, $key, \sprintf('sheet.fields[%d].startMax.formula', $index));
            }
        }

        $budget = \is_array($sheet['pointBudget'] ?? null) ? $sheet['pointBudget'] : [];
        foreach (\is_array($budget['fields'] ?? null) ? $budget['fields'] : [] as $index => $budgetField) {
            if ($key === $budgetField) {
                $paths[\sprintf('sheet.pointBudget.fields[%s]', $index)] = true;
            }
        }

        foreach (self::objects($sheet['pointBudgets'] ?? null) as $budgetIndex => $listed) {
            foreach (\is_array($listed['fields'] ?? null) ? $listed['fields'] : [] as $index => $budgetField) {
                if ($key === $budgetField) {
                    $paths[\sprintf('sheet.pointBudgets[%d].fields[%s]', $budgetIndex, $index)] = true;
                }
            }
        }

        foreach (self::objects($document['checks'] ?? null) as $index => $check) {
            $path = \sprintf('checks[%d]', $index);
            $paths += self::sheetFormulaPaths($check['modifier'] ?? null, $key, $path.'.modifier');
            $count = \is_array($check['pool'] ?? null) ? $check['pool']['count'] ?? null : null;
            $paths += self::sheetFormulaPaths(\is_array($count) ? $count['formula'] ?? null : null, $key, $path.'.pool.count.formula');
            foreach (self::objects($check['bands'] ?? null) as $bandIndex => $band) {
                $upTo = $band['upTo'] ?? null;
                $paths += self::sheetFormulaPaths(\is_array($upTo) ? $upTo['formula'] ?? null : null, $key, \sprintf('%s.bands[%d].upTo.formula', $path, $bandIndex));
            }
        }

        foreach (self::effectHolders($document) as $path => $holder) {
            foreach (self::objects($holder['effects'] ?? null) as $index => $effect) {
                if ('sheet' !== ($effect['kind'] ?? null)) {
                    continue;
                }

                $value = $effect['value'] ?? null;
                $effectPath = \sprintf('%s.effects[%d]', $path, $index);
                if ($key === ($effect['field'] ?? null)) {
                    $paths[$effectPath] = true;
                } else {
                    $paths += self::sheetFormulaPaths(\is_array($value) ? $value['formula'] ?? null : null, $key, $effectPath);
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * The sheet effects that mark or clear this option of a conditions field: every effect holder,
     * including the cost of a check's push, with "field" the key, "value" the option and op
     * `mark` or `clear` (a `clear` without a value clears all and names no option).
     *
     * @param array<string, mixed> $document
     * @param string|null          $option   null lists every `mark` and `clear` of the field, whatever its option
     *
     * @return list<string>
     */
    public static function conditionOptionPathsTo(array $document, string $field, ?string $option = null): array
    {
        $paths = [];
        foreach (self::effectHolders($document) as $path => $holder) {
            foreach (self::objects($holder['effects'] ?? null) as $index => $effect) {
                if ('sheet' === ($effect['kind'] ?? null) && $field === ($effect['field'] ?? null) && (null === $option || $option === ($effect['value'] ?? null)) && \in_array($effect['op'] ?? null, ['mark', 'clear'], true)) {
                    $paths[] = \sprintf('%s.effects[%d]', $path, $index);
                }
            }
        }

        return $paths;
    }

    /**
     * The check steps that name this check: the "check" of each or its place in "checks", in Scene
     * Type parts and flow phase hooks.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function checkPathsTo(array $document, string $key): array
    {
        $paths = [];
        foreach (self::steps($document) as $path => $step) {
            if ('check' !== ($step['kind'] ?? null)) {
                continue;
            }

            if ($key === ($step['check'] ?? null)) {
                $paths[] = $path.'.check';
            }

            foreach (\is_array($step['checks'] ?? null) ? $step['checks'] : [] as $index => $check) {
                if ($key === $check) {
                    $paths[] = \sprintf('%s.checks[%d]', $path, $index);
                }
            }
        }

        return $paths;
    }

    /**
     * The outcomes of check steps naming this check, alone or in a set, that name this band: their "band".
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public static function bandPathsTo(array $document, string $check, string $band): array
    {
        $paths = [];
        foreach (self::steps($document) as $path => $step) {
            if ('check' !== ($step['kind'] ?? null) || ($check !== ($step['check'] ?? null) && !\in_array($check, self::strings($step['checks'] ?? null), true))) {
                continue;
            }

            foreach (self::objects($step['outcomes'] ?? null) as $index => $outcome) {
                if ($band === ($outcome['band'] ?? null)) {
                    $paths[] = \sprintf('%s.outcomes[%d].band', $path, $index);
                }
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private static function pathsOf(array $document, string $kind, string $key): array
    {
        $paths = [];
        foreach (self::references($document) as [$referenceKind, $referenceKey, $path]) {
            if ($kind === $referenceKind && $key === $referenceKey) {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * Every tracker, Scene Type, tag and fact slot reference of the document: flows, steps, oracles,
     * the sheet and checks, in that order.
     *
     * @param array<string, mixed> $document
     *
     * @return iterable<array{string, string, string}> [kind, key, path]
     */
    private static function references(array $document): iterable
    {
        foreach (self::objects($document['flows'] ?? null) as $index => $flow) {
            foreach (self::strings($flow['trackers'] ?? null) as $tracker) {
                yield [self::TRACKER, $tracker, \sprintf('flows[%d].trackers', $index)];
            }
        }

        foreach (self::phases($document) as $path => $phase) {
            $selection = $phase['selection'] ?? null;
            foreach (self::strings(\is_array($selection) ? $selection['sceneTypes'] ?? null : null) as $sceneType) {
                yield [self::SCENE_TYPE, $sceneType, $path.'.selection'];
            }
        }

        foreach (self::steps($document) as $path => $step) {
            yield from self::stepReferences($step, $path);
        }

        $oracles = \is_array($document['oracles'] ?? null) ? $document['oracles'] : [];
        foreach (self::objects($oracles['likelihood'] ?? null) as $index => $oracle) {
            $chaos = $oracle['chaos'] ?? null;
            if (\is_array($chaos) && \is_string($chaos['tracker'] ?? null)) {
                yield [self::TRACKER, $chaos['tracker'], \sprintf('oracles.likelihood[%d].chaos', $index)];
            }
        }

        foreach (self::objects($oracles['tables'] ?? null) as $tableIndex => $table) {
            foreach (self::objects($table['entries'] ?? null) as $entryIndex => $entry) {
                $path = \sprintf('oracles.tables[%d].entries[%d]', $tableIndex, $entryIndex);
                if (\is_string($entry['sceneType'] ?? null)) {
                    yield [self::SCENE_TYPE, $entry['sceneType'], $path];
                }

                yield from self::effectsReferences($entry, $path);
            }
        }

        $sheet = \is_array($document['sheet'] ?? null) ? $document['sheet'] : [];
        foreach (self::objects($sheet['fields'] ?? null) as $index => $field) {
            yield from self::formulaReferences($field['formula'] ?? null, \sprintf('sheet.fields[%d]', $index));
        }

        foreach (self::objects($document['checks'] ?? null) as $index => $check) {
            $path = \sprintf('checks[%d]', $index);
            yield from self::formulaReferences($check['modifier'] ?? null, $path);
            $count = \is_array($check['pool'] ?? null) ? $check['pool']['count'] ?? null : null;
            yield from self::formulaReferences(\is_array($count) ? $count['formula'] ?? null : null, $path.'.pool.count.formula');
            if (\is_array($check['push'] ?? null)) {
                yield from self::effectsReferences($check['push'], $path.'.push');
            }
            foreach (self::objects($check['bands'] ?? null) as $bandIndex => $band) {
                yield from self::valueReferences($band['upTo'] ?? null, \sprintf('%s.bands[%d]', $path, $bandIndex));
            }
        }
    }

    /**
     * The references of a step: its texts and kind fields, then the effects of each of its outcomes.
     *
     * @param array<array-key, mixed> $step
     *
     * @return iterable<array{string, string, string}>
     */
    private static function stepReferences(array $step, string $path): iterable
    {
        foreach (['title', 'prompt', 'tip'] as $field) {
            yield from self::placeholderReferences($step[$field] ?? null, $path);
        }

        $kind = $step['kind'] ?? null;
        if ('condition' === $kind && \is_string($step['tracker'] ?? null)) {
            yield [self::TRACKER, $step['tracker'], $path];
        }

        $count = $step['count'] ?? null;
        $tags = 'pick' === $kind ? $step['tags'] ?? null : ('condition' === $kind && \is_array($count) ? $count['tags'] ?? null : null);
        foreach (self::strings($tags) as $tag) {
            yield [self::TAG, $tag, $path];
        }

        if ('check' === $kind) {
            yield from self::valueReferences($step['difficulty'] ?? null, $path);
        }

        if ('table' === $kind) {
            foreach (array_keys(self::tablesBySceneType($step)) as $sceneType) {
                yield [self::SCENE_TYPE, (string) $sceneType, \sprintf('%s.tableBySceneType.%s', $path, $sceneType)];
            }
        }

        yield from self::effectsReferences($step, $path);
        foreach (self::outcomes($step, $path) as $outcomePath => $outcome) {
            // Only band outcomes carry an "upTo" bound.
            if (str_starts_with($outcomePath, $path.'.bands')) {
                yield from self::valueReferences($outcome['upTo'] ?? null, $outcomePath);
            }

            yield from self::effectsReferences($outcome, $outcomePath);
        }

        if (\is_array($step['otherwise'] ?? null)) {
            yield from self::effectsReferences($step['otherwise'], $path.'.otherwise');
        }
    }

    /**
     * The references of the effects of an outcome or a table entry.
     *
     * @param array<array-key, mixed> $holder
     *
     * @return iterable<array{string, string, string}>
     */
    private static function effectsReferences(array $holder, string $path): iterable
    {
        foreach (self::objects($holder['effects'] ?? null) as $index => $effect) {
            $effectPath = \sprintf('%s.effects[%d]', $path, $index);
            $kind = $effect['kind'] ?? null;
            if ('tracker' === $kind && \is_string($effect['tracker'] ?? null)) {
                yield [self::TRACKER, $effect['tracker'], $effectPath];
            }

            if ('tracker' === $kind || 'sheet' === $kind) {
                yield from self::valueReferences($effect['value'] ?? null, $effectPath);
            }

            if (\in_array($kind, ['nextScene', 'switchSceneType'], true) && \is_string($effect['sceneType'] ?? null)) {
                yield [self::SCENE_TYPE, $effect['sceneType'], $effectPath];
            }

            foreach (\in_array($kind, ['createNpc', 'createThread'], true) ? self::strings($effect['tags'] ?? null) : [] as $tag) {
                yield [self::TAG, $tag, $effectPath];
            }

            if ('fillFact' === $kind && \is_string($effect['slot'] ?? null)) {
                yield [self::FACT_SLOT, $effect['slot'], $effectPath];
            }

            foreach (self::EFFECT_TEXTS as $field) {
                yield from self::placeholderReferences($effect[$field] ?? null, $effectPath);
            }
        }
    }

    /**
     * A value that may read a tracker: {tracker: key} or {formula: string}.
     *
     * @return iterable<array{string, string, string}>
     */
    private static function valueReferences(mixed $value, string $path): iterable
    {
        if (!\is_array($value)) {
            return;
        }

        if (\is_string($value['tracker'] ?? null)) {
            yield [self::TRACKER, $value['tracker'], $path];
        }

        yield from self::formulaReferences($value['formula'] ?? null, $path);
    }

    /**
     * The trackers a formula reads as {tracker:key}; a formula that does not parse reads nothing.
     *
     * @return iterable<array{string, string, string}>
     */
    private static function formulaReferences(mixed $formula, string $path): iterable
    {
        if (!\is_string($formula)) {
            return;
        }

        try {
            $reads = Formula::fromString($formula)->references();
        } catch (InvalidFormula) {
            return;
        }

        foreach ($reads as $read) {
            if (ReferenceKind::Tracker === $read->kind) {
                yield [self::TRACKER, $read->key, $path];
            }
        }
    }

    /**
     * The {tracker:key} and {fact:key} placeholders of a text.
     *
     * @return iterable<array{string, string, string}>
     */
    private static function placeholderReferences(mixed $text, string $path): iterable
    {
        if (!\is_string($text)) {
            return;
        }

        preg_match_all(Placeholders::PATTERN, $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $match[2] ?? '';
            if ('' === $key) {
                continue;
            }

            if ('tracker' === $match[1]) {
                yield [self::TRACKER, $key, $path];
            } elseif ('fact' === $match[1]) {
                yield [self::FACT_SLOT, $key, $path];
            }
        }
    }

    /**
     * The path of a formula when it reads the field as {key} or {key.max}, as the key of a map of
     * paths; none when it does not parse.
     *
     * @return array<string, true>
     */
    private static function sheetFormulaPaths(mixed $formula, string $key, string $path): array
    {
        if (!\is_string($formula)) {
            return [];
        }

        try {
            $reads = Formula::fromString($formula)->references();
        } catch (InvalidFormula) {
            return [];
        }

        foreach ($reads as $read) {
            if (\in_array($read->kind, [ReferenceKind::Field, ReferenceKind::TrackMax], true) && $key === $read->key) {
                return [$path => true];
            }
        }

        return [];
    }

    /**
     * Everything that may carry "effects", by path: each step, each of its outcomes, its
     * "otherwise", each table entry and the push of each check.
     *
     * @param array<string, mixed> $document
     *
     * @return iterable<string, array<array-key, mixed>>
     */
    private static function effectHolders(array $document): iterable
    {
        foreach (self::steps($document) as $path => $step) {
            yield $path => $step;
            yield from self::outcomes($step, $path);
            if (\is_array($step['otherwise'] ?? null)) {
                yield $path.'.otherwise' => $step['otherwise'];
            }
        }

        $oracles = \is_array($document['oracles'] ?? null) ? $document['oracles'] : [];
        foreach (self::objects($oracles['tables'] ?? null) as $tableIndex => $table) {
            foreach (self::objects($table['entries'] ?? null) as $entryIndex => $entry) {
                yield \sprintf('oracles.tables[%d].entries[%d]', $tableIndex, $entryIndex) => $entry;
            }
        }

        foreach (self::objects($document['checks'] ?? null) as $index => $check) {
            if (\is_array($check['push'] ?? null)) {
                yield \sprintf('checks[%d].push', $index) => $check['push'];
            }
        }
    }

    /**
     * The outcomes of a step (bands, options, outcomes, branches), by path.
     *
     * @param array<array-key, mixed> $step
     *
     * @return iterable<string, array<array-key, mixed>>
     */
    private static function outcomes(array $step, string $path): iterable
    {
        foreach (self::OUTCOME_LISTS as $list) {
            foreach (\is_array($step[$list] ?? null) ? $step[$list] : [] as $name => $outcome) {
                if (\is_array($outcome)) {
                    yield (\is_int($name) ? \sprintf('%s.%s[%d]', $path, $list, $name) : \sprintf('%s.%s.%s', $path, $list, $name)) => $outcome;
                }
            }
        }
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

    /**
     * The strings of a list; anything else is skipped.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) && array_is_list($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    /**
     * The "tableBySceneType" of a table step: Scene Type key => table key, string pairs only.
     *
     * @param array<array-key, mixed> $step
     *
     * @return array<string, string>
     */
    private static function tablesBySceneType(array $step): array
    {
        $map = $step['tableBySceneType'] ?? null;
        $tables = [];
        foreach (\is_array($map) ? $map : [] as $sceneType => $table) {
            if (\is_string($table)) {
                $tables[(string) $sceneType] = $table;
            }
        }

        return $tables;
    }

    private static function names(mixed $keys, string $key): bool
    {
        return \is_array($keys) && \in_array($key, $keys, true);
    }
}
