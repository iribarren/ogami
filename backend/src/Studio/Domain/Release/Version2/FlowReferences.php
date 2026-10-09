<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * The references inside each flow (docs/contracts/gamesystem-release.md, "References inside a
 * flow"). A Scene Type is reachable from a flow through its phase selections, nextScene and
 * switchSceneType effects, and the "sceneType" of the entries of the tables the flow rolls (its
 * oracle selection tables, the tables of its "table" steps and the tables those nest). Everything
 * the flow reaches uses only the flow's trackers, every reachable Scene Type's oracles are among the
 * flow's, and placeholders name what is in scope: the flow's trackers and steps, or the release's
 * for what no flow reaches.
 *
 * @internal
 */
final class FlowReferences
{
    /**
     * @param list<array<string, mixed>> $tables     canonical oracle tables
     * @param list<array<string, mixed>> $trackers   canonical trackers
     * @param list<array<string, mixed>> $sceneTypes canonical Scene Types
     * @param list<array<string, mixed>> $flows      canonical flows
     */
    public static function check(array $tables, array $trackers, array $sceneTypes, array $flows): void
    {
        $tablesByKey = self::byKey($tables);
        $sceneTypesByKey = self::byKey($sceneTypes);

        $allLists = self::sceneTypeLists($sceneTypes, array_keys($sceneTypes));
        foreach ($flows as $flowIndex => $flow) {
            $allLists = [...$allLists, ...self::phaseLists($flow, $flowIndex)];
        }

        /** @var list<string> $trackerKeys */
        $trackerKeys = array_column($trackers, 'key');
        $releaseTrackers = array_fill_keys($trackerKeys, true);
        self::checkPlaceholders($allLists, array_keys($tables), $tables, $releaseTrackers, self::stepKeys($allLists), null);

        foreach ($flows as $flowIndex => $flow) {
            /** @var string $flowKey */
            $flowKey = $flow['key'];
            self::checkOracleSelections($flow, $flowIndex, $tables, $tablesByKey);

            [$reachedSceneTypes, $rolledTables] = self::reach($flow, $flowIndex, $tables, $tablesByKey, $sceneTypes, $sceneTypesByKey);
            $lists = [...self::phaseLists($flow, $flowIndex), ...self::sceneTypeLists($sceneTypes, $reachedSceneTypes)];

            /** @var list<string> $flowTrackerKeys */
            $flowTrackerKeys = $flow['trackers'];
            $flowTrackers = array_fill_keys($flowTrackerKeys, true);
            self::checkTrackers($lists, $rolledTables, $tables, $flowTrackers, $flowKey);
            self::checkSceneTypeOracles($flow, $flowKey, $sceneTypes, $reachedSceneTypes);
            self::checkPlaceholders($lists, $rolledTables, $tables, $flowTrackers, self::stepKeys($lists), $flowKey);
        }
    }

    /**
     * Every entry of an oracle selection table names the Scene Type it selects.
     *
     * @param array<string, mixed>       $flow
     * @param list<array<string, mixed>> $tables
     * @param array<string, int>         $tablesByKey
     */
    private static function checkOracleSelections(array $flow, int $flowIndex, array $tables, array $tablesByKey): void
    {
        foreach (self::phases($flow) as $phaseIndex => $phase) {
            $table = self::selectionTable($phase);
            if (null === $table) {
                continue;
            }

            $tableIndex = $tablesByKey[$table];
            foreach (self::entries($tables[$tableIndex]) as $entryIndex => $entry) {
                if (!isset($entry['sceneType'])) {
                    throw InvalidReleaseContent::at(\sprintf('flows[%d].phases[%d].selection.table', $flowIndex, $phaseIndex), \sprintf('every entry of table "%s" must name a sceneType, oracles.tables[%d].entries[%d] does not', $table, $tableIndex, $entryIndex));
                }
            }
        }
    }

    /**
     * The Scene Types (indexes) and tables (indexes) a flow reaches, in release order.
     *
     * @param array<string, mixed>       $flow
     * @param list<array<string, mixed>> $tables
     * @param array<string, int>         $tablesByKey
     * @param list<array<string, mixed>> $sceneTypes
     * @param array<string, int>         $sceneTypesByKey
     *
     * @return array{list<int>, list<int>}
     */
    private static function reach(array $flow, int $flowIndex, array $tables, array $tablesByKey, array $sceneTypes, array $sceneTypesByKey): array
    {
        $reachedSceneTypes = [];
        $rolledTables = [];
        $pendingSceneTypes = [];
        $pendingTables = [];
        $reachSceneType = static function (?string $key) use (&$reachedSceneTypes, &$pendingSceneTypes, $sceneTypesByKey): void {
            if (null !== $key && isset($sceneTypesByKey[$key]) && !isset($reachedSceneTypes[$sceneTypesByKey[$key]])) {
                $reachedSceneTypes[$sceneTypesByKey[$key]] = true;
                $pendingSceneTypes[] = $sceneTypesByKey[$key];
            }
        };
        $rollTable = static function (?string $key) use (&$rolledTables, &$pendingTables, $tablesByKey): void {
            if (null !== $key && isset($tablesByKey[$key]) && !isset($rolledTables[$tablesByKey[$key]])) {
                $rolledTables[$tablesByKey[$key]] = true;
                $pendingTables[] = $tablesByKey[$key];
            }
        };
        $followSteps = static function (iterable $lists) use ($reachSceneType, $rollTable): void {
            /** @var iterable<string, list<array<string, mixed>>> $lists */
            foreach ($lists as $path => $steps) {
                foreach ($steps as $index => $step) {
                    $rollTable(StepParts::rolledTable($step));
                    foreach (StepParts::effects($step, \sprintf('%s[%d]', $path, $index)) as $effect) {
                        $reachSceneType(StepParts::effectSceneType($effect));
                    }
                }
            }
        };

        foreach (self::phases($flow) as $phase) {
            /** @var array<string, mixed> $selection */
            $selection = $phase['selection'];
            /** @var list<string> $selected */
            $selected = $selection['sceneTypes'] ?? [];
            array_map($reachSceneType, $selected);
            $rollTable(self::selectionTable($phase));
        }

        $followSteps(self::phaseLists($flow, $flowIndex));

        while ([] !== $pendingSceneTypes || [] !== $pendingTables) {
            $tableIndex = array_shift($pendingTables);
            if (null !== $tableIndex) {
                foreach (self::entries($tables[$tableIndex]) as $entry) {
                    $reachSceneType(\is_string($entry['sceneType'] ?? null) ? $entry['sceneType'] : null);
                    $rollTable(\is_string($entry['table'] ?? null) ? $entry['table'] : null);
                    foreach (StepParts::effectList($entry, '') as $effect) {
                        $reachSceneType(StepParts::effectSceneType($effect));
                    }
                }

                continue;
            }

            $sceneTypeIndex = array_shift($pendingSceneTypes);
            if (null !== $sceneTypeIndex) {
                $followSteps(self::sceneTypeLists($sceneTypes, [$sceneTypeIndex]));
            }
        }

        $reached = array_keys($reachedSceneTypes);
        $rolled = array_keys($rolledTables);
        sort($reached);
        sort($rolled);

        return [$reached, $rolled];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $lists
     * @param list<int>                                 $rolledTables
     * @param list<array<string, mixed>>                $tables
     * @param array<string, true>                       $flowTrackers
     */
    private static function checkTrackers(array $lists, array $rolledTables, array $tables, array $flowTrackers, string $flowKey): void
    {
        $references = [];
        foreach ($lists as $path => $steps) {
            foreach ($steps as $index => $step) {
                $references = [...$references, ...StepParts::trackers($step, \sprintf('%s[%d]', $path, $index))];
            }
        }

        foreach (self::entryEffects($rolledTables, $tables) as $effectPath => $effect) {
            $references = [...$references, ...StepParts::effectTrackers($effect, $effectPath)];
        }

        foreach ($references as $path => $tracker) {
            if (!isset($flowTrackers[$tracker])) {
                throw InvalidReleaseContent::at($path, \sprintf('tracker "%s" is not one of the trackers of flow "%s"', $tracker, $flowKey));
            }
        }
    }

    /**
     * @param array<string, mixed>       $flow
     * @param list<array<string, mixed>> $sceneTypes
     * @param list<int>                  $reachedSceneTypes
     */
    private static function checkSceneTypeOracles(array $flow, string $flowKey, array $sceneTypes, array $reachedSceneTypes): void
    {
        /** @var list<string> $flowOracleKeys */
        $flowOracleKeys = $flow['oracles'];
        $flowOracles = array_fill_keys($flowOracleKeys, true);
        foreach ($reachedSceneTypes as $sceneTypeIndex) {
            /** @var list<string> $oracles */
            $oracles = $sceneTypes[$sceneTypeIndex]['oracles'];
            foreach ($oracles as $oracleIndex => $oracle) {
                if (!isset($flowOracles[$oracle])) {
                    throw InvalidReleaseContent::at(\sprintf('sceneTypes[%d].oracles[%d]', $sceneTypeIndex, $oracleIndex), \sprintf('oracle "%s" is not one of the oracles of flow "%s"', $oracle, $flowKey));
                }
            }
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $lists
     * @param list<int>                                 $rolledTables
     * @param list<array<string, mixed>>                $tables
     * @param array<string, true>                       $trackers
     * @param array<string, true>                       $steps
     */
    private static function checkPlaceholders(array $lists, array $rolledTables, array $tables, array $trackers, array $steps, ?string $flowKey): void
    {
        foreach ($lists as $path => $list) {
            foreach ($list as $index => $step) {
                foreach (StepParts::texts($step, \sprintf('%s[%d]', $path, $index)) as $textPath => [$text, $effectText]) {
                    Placeholders::check($text, $textPath, $effectText, $trackers, $steps, $flowKey);
                }
            }
        }

        foreach (self::entryEffects($rolledTables, $tables) as $effectPath => $effect) {
            foreach (StepParts::effectTexts($effect, $effectPath) as $textPath => [$text, $effectText]) {
                Placeholders::check($text, $textPath, $effectText, $trackers, $steps, $flowKey);
            }
        }
    }

    /**
     * @param list<int>                  $tableIndexes
     * @param list<array<string, mixed>> $tables
     *
     * @return iterable<string, array<string, mixed>> path => effect
     */
    private static function entryEffects(array $tableIndexes, array $tables): iterable
    {
        foreach ($tableIndexes as $tableIndex) {
            foreach (self::entries($tables[$tableIndex]) as $entryIndex => $entry) {
                yield from StepParts::effectList($entry, \sprintf('oracles.tables[%d].entries[%d]', $tableIndex, $entryIndex));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $sceneTypes
     * @param list<int>                  $indexes
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function sceneTypeLists(array $sceneTypes, array $indexes): array
    {
        $lists = [];
        foreach ($indexes as $index) {
            $lists = [...$lists, ...StepParts::lists($sceneTypes[$index], \sprintf('sceneTypes[%d]', $index), ReleaseVersion2::SCENE_TYPE_PARTS)];
        }

        return $lists;
    }

    /**
     * @param array<string, mixed> $flow
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function phaseLists(array $flow, int $flowIndex): array
    {
        $lists = [];
        foreach (self::phases($flow) as $phaseIndex => $phase) {
            $lists = [...$lists, ...StepParts::lists($phase, \sprintf('flows[%d].phases[%d]', $flowIndex, $phaseIndex), ReleaseVersion2::PHASE_HOOKS)];
        }

        return $lists;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $lists
     *
     * @return array<string, true>
     */
    private static function stepKeys(array $lists): array
    {
        $keys = [];
        foreach ($lists as $steps) {
            foreach ($steps as $step) {
                /** @var string $key */
                $key = $step['key'];
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $flow
     *
     * @return list<array<string, mixed>>
     */
    private static function phases(array $flow): array
    {
        /** @var list<array<string, mixed>> $phases */
        $phases = $flow['phases'];

        return $phases;
    }

    /**
     * @param array<string, mixed> $phase
     */
    private static function selectionTable(array $phase): ?string
    {
        /** @var array<string, mixed> $selection */
        $selection = $phase['selection'];

        return 'oracle' === $selection['rule'] && \is_string($selection['table']) ? $selection['table'] : null;
    }

    /**
     * @param array<string, mixed> $table
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(array $table): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $table['entries'];

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, int> key => index
     */
    private static function byKey(array $items): array
    {
        /** @var list<string> $keys */
        $keys = array_column($items, 'key');

        return array_flip($keys);
    }
}
