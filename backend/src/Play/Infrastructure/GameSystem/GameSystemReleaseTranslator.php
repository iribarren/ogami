<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\Band;
use App\Play\Domain\GameSystem\Flow\ChoiceOption;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\Effect;
use App\Play\Domain\GameSystem\Flow\EndPhaseEffect;
use App\Play\Domain\GameSystem\Flow\NextSceneEffect;
use App\Play\Domain\GameSystem\Flow\OracleBranches;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SceneTitleEffect;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\Flow\SwitchSceneTypeEffect;
use App\Play\Domain\GameSystem\Flow\TableBranch;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\Flow\TrackerReference;
use App\Play\Domain\GameSystem\FlowStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\TableEntryMetadata;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\TrackerLevel;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Studio\Application\PublishedReleaseView;

/**
 * Play's anti-corruption layer over Studio's Published Language: turns a PublishedReleaseView into
 * a GameSystemSnapshot. It reads only the documented contract (docs/contracts/gamesystem-release.md),
 * lists the schema versions it supports explicitly, and fails with Play errors, never Studio or
 * Randomness ones. Schema version 2 flows are not read yet (slice 8): such a release has none.
 */
final readonly class GameSystemReleaseTranslator
{
    /** @var list<int> */
    public const array SUPPORTED_SCHEMA_VERSIONS = [1, 2];

    /**
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function translate(PublishedReleaseView $view): GameSystemSnapshot
    {
        if (!\in_array($view->schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw UnsupportedReleaseSchemaVersion::of($view->gameSystemKey, $view->version, $view->schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS);
        }

        return 1 === $view->schemaVersion ? $this->fromSchemaVersion1($view) : $this->fromSchemaVersion2($view);
    }

    private function fromSchemaVersion1(PublishedReleaseView $view): GameSystemSnapshot
    {
        $content = $view->content;
        $gameSystem = $this->object($view, $content['gameSystem'] ?? null, 'gameSystem');
        $oracles = $this->object($view, $content['oracles'] ?? null, 'oracles');
        $flow = $this->object($view, $content['flow'] ?? null, 'flow');

        return new GameSystemSnapshot(
            $view->gameSystemKey,
            $this->string($view, $gameSystem['name'] ?? null, 'gameSystem.name'),
            $view->version,
            $this->oracleTables($view, $this->list($view, $oracles['tables'] ?? null, 'oracles.tables')),
            $this->likelihoodOracles($view, $this->list($view, $oracles['likelihood'] ?? null, 'oracles.likelihood')),
            $this->flowSteps($view, $this->list($view, $flow['steps'] ?? null, 'flow.steps')),
        );
    }

    private function fromSchemaVersion2(PublishedReleaseView $view): GameSystemSnapshot
    {
        $content = $view->content;
        $gameSystem = $this->object($view, $content['gameSystem'] ?? null, 'gameSystem');
        $oracles = $this->object($view, $content['oracles'] ?? null, 'oracles');
        $tables = $this->list($view, $oracles['tables'] ?? null, 'oracles.tables');
        $entries = [];
        foreach ($tables as $index => $table) {
            $path = \sprintf('oracles.tables[%d]', $index);
            $table = $this->object($view, $table, $path);
            $key = $this->string($view, $table['key'] ?? null, $path.'.key');
            $tableEntries = $this->list($view, $table['entries'] ?? null, $path.'.entries');
            foreach ($tableEntries as $entryIndex => $entry) {
                $entryPath = \sprintf('%s.entries[%d]', $path, $entryIndex);
                $entry = $this->object($view, $entry, $entryPath);
                $entries[$key][] = new TableEntryMetadata(
                    $this->optionalString($view, $entry['key'] ?? null, $entryPath.'.key'),
                    $this->optionalString($view, $entry['sceneType'] ?? null, $entryPath.'.sceneType'),
                    $this->effects($view, $entry['effects'] ?? [], $entryPath.'.effects'),
                );
                // The Randomness definition knows only the version 1 entry fields.
                unset($entry['key'], $entry['sceneType'], $entry['effects']);
                $tableEntries[$entryIndex] = $entry;
            }

            $tables[$index] = ['entries' => $tableEntries] + $table;
        }

        return new GameSystemSnapshot(
            $view->gameSystemKey,
            $this->string($view, $gameSystem['name'] ?? null, 'gameSystem.name'),
            $view->version,
            $this->oracleTables($view, $tables),
            $this->likelihoodOracles($view, $this->list($view, $oracles['likelihood'] ?? null, 'oracles.likelihood')),
            [],
            $this->trackers($view, $this->list($view, $content['trackers'] ?? null, 'trackers')),
            $this->factSlots($view, $this->list($view, $content['factSlots'] ?? null, 'factSlots')),
            $this->sceneTypes($view, $this->list($view, $content['sceneTypes'] ?? null, 'sceneTypes')),
            $entries,
        );
    }

    /**
     * @param list<mixed> $trackers
     *
     * @return list<Tracker>
     */
    private function trackers(PublishedReleaseView $view, array $trackers): array
    {
        $snapshots = [];
        foreach ($trackers as $index => $tracker) {
            $path = \sprintf('trackers[%d]', $index);
            $tracker = $this->object($view, $tracker, $path);
            $key = $this->string($view, $tracker['key'] ?? null, $path.'.key');
            $name = $this->string($view, $tracker['name'] ?? null, $path.'.name');
            $hint = $this->optionalString($view, $tracker['hint'] ?? null, $path.'.hint');
            $snapshots[] = match ($kind = $this->string($view, $tracker['kind'] ?? null, $path.'.kind')) {
                'counter' => Tracker::counter(
                    $key,
                    $name,
                    $hint,
                    $this->int($view, $tracker['min'] ?? null, $path.'.min'),
                    $this->int($view, $tracker['max'] ?? null, $path.'.max'),
                    $this->int($view, $tracker['initial'] ?? null, $path.'.initial'),
                    $this->levels($view, $tracker['levels'] ?? [], $path.'.levels'),
                ),
                'clock' => Tracker::clock($key, $name, $hint, $this->int($view, $tracker['segments'] ?? null, $path.'.segments')),
                default => throw $this->invalid($view, $path.'.kind', \sprintf('unknown tracker kind "%s".', $kind)),
            };
        }

        return $snapshots;
    }

    /**
     * @return list<TrackerLevel>
     */
    private function levels(PublishedReleaseView $view, mixed $levels, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $levels, $path) as $index => $level) {
            $levelPath = \sprintf('%s[%d]', $path, $index);
            $level = $this->object($view, $level, $levelPath);
            $upTo = $level['upTo'] ?? null;
            $snapshots[] = new TrackerLevel(
                null === $upTo ? null : $this->int($view, $upTo, $levelPath.'.upTo'),
                $this->string($view, $level['label'] ?? null, $levelPath.'.label'),
            );
        }

        return $snapshots;
    }

    /**
     * @param list<mixed> $slots
     *
     * @return list<FactSlot>
     */
    private function factSlots(PublishedReleaseView $view, array $slots): array
    {
        $snapshots = [];
        foreach ($slots as $index => $slot) {
            $path = \sprintf('factSlots[%d]', $index);
            $slot = $this->object($view, $slot, $path);
            $type = $this->string($view, $slot['type'] ?? null, $path.'.type');
            $snapshots[] = new FactSlot(
                $this->string($view, $slot['key'] ?? null, $path.'.key'),
                $this->string($view, $slot['label'] ?? null, $path.'.label'),
                FactSlotType::tryFrom($type) ?? throw $this->invalid($view, $path.'.type', \sprintf('unknown fact slot type "%s".', $type)),
            );
        }

        return $snapshots;
    }

    /**
     * @param list<mixed> $sceneTypes
     *
     * @return list<SceneType>
     */
    private function sceneTypes(PublishedReleaseView $view, array $sceneTypes): array
    {
        $snapshots = [];
        foreach ($sceneTypes as $index => $sceneType) {
            $path = \sprintf('sceneTypes[%d]', $index);
            $sceneType = $this->object($view, $sceneType, $path);
            $snapshots[] = new SceneType(
                $this->string($view, $sceneType['key'] ?? null, $path.'.key'),
                $this->string($view, $sceneType['name'] ?? null, $path.'.name'),
                $this->string($view, $sceneType['purpose'] ?? null, $path.'.purpose'),
                $this->optionalString($view, $sceneType['tips'] ?? null, $path.'.tips'),
                array_map(fn (mixed $key): string => $this->string($view, $key, $path.'.oracles'), $this->list($view, $sceneType['oracles'] ?? [], $path.'.oracles')),
                $this->steps($view, $sceneType['setup'] ?? [], $path.'.setup'),
                $this->steps($view, $sceneType['play'] ?? [], $path.'.play'),
                $this->steps($view, $sceneType['closing'] ?? [], $path.'.closing'),
            );
        }

        return $snapshots;
    }

    private function steps(PublishedReleaseView $view, mixed $steps, string $path): StepList
    {
        $snapshots = [];
        $keys = [];
        foreach ($this->list($view, $steps, $path) as $index => $step) {
            $stepPath = \sprintf('%s[%d]', $path, $index);
            $step = $this->object($view, $step, $stepPath);
            $key = $this->string($view, $step['key'] ?? null, $stepPath.'.key');
            if (isset($keys[$key])) {
                throw $this->invalid($view, $stepPath.'.key', \sprintf('duplicate key "%s".', $key));
            }

            $keys[$key] = true;
            $common = [
                'key' => $key,
                'title' => $this->string($view, $step['title'] ?? null, $stepPath.'.title'),
                'prompt' => $this->optionalString($view, $step['prompt'] ?? null, $stepPath.'.prompt'),
                'tip' => $this->optionalString($view, $step['tip'] ?? null, $stepPath.'.tip'),
                'mandatory' => true === ($step['mandatory'] ?? false),
                'next' => $this->optionalString($view, $step['next'] ?? null, $stepPath.'.next'),
                'effects' => $this->effects($view, $step['effects'] ?? [], $stepPath.'.effects'),
            ];
            $snapshots[] = match ($kind = $this->string($view, $step['kind'] ?? null, $stepPath.'.kind')) {
                'prompt' => new PromptStep(...$common),
                'oracle' => new OracleStep(
                    ...$common,
                    oracle: $this->string($view, $step['oracle'] ?? null, $stepPath.'.oracle'),
                    likelihood: $this->optionalString($view, $step['likelihood'] ?? null, $stepPath.'.likelihood'),
                    branches: $this->oracleBranches($view, $step['branches'] ?? [], $stepPath.'.branches'),
                ),
                'table' => new TableStep(
                    ...$common,
                    table: $this->string($view, $step['table'] ?? null, $stepPath.'.table'),
                    branches: $this->tableBranches($view, $step['branches'] ?? [], $stepPath.'.branches'),
                    otherwise: null === ($step['otherwise'] ?? null) ? null : $this->outcome($view, $step['otherwise'], $stepPath.'.otherwise'),
                ),
                'roll' => new RollStep(...$common, dice: $this->string($view, $step['dice'] ?? null, $stepPath.'.dice'), bands: $this->bands($view, $step['bands'] ?? [], $stepPath.'.bands')),
                'choice' => new ChoiceStep(
                    ...$common,
                    options: $this->options($view, $step['options'] ?? null, $stepPath.'.options'),
                    skip: $this->optionalString($view, $step['skip'] ?? null, $stepPath.'.skip'),
                ),
                'condition' => new ConditionStep(
                    ...$common,
                    tracker: $this->string($view, $step['tracker'] ?? null, $stepPath.'.tracker'),
                    bands: $this->bands($view, $step['bands'] ?? null, $stepPath.'.bands'),
                ),
                default => throw $this->invalid($view, $stepPath.'.kind', \sprintf('unknown step kind "%s".', $kind)),
            };
        }

        return new StepList($snapshots);
    }

    private function outcome(PublishedReleaseView $view, mixed $outcome, string $path): Outcome
    {
        $outcome = $this->object($view, $outcome, $path);

        return new Outcome(
            $this->optionalString($view, $outcome['next'] ?? null, $path.'.next'),
            $this->effects($view, $outcome['effects'] ?? [], $path.'.effects'),
        );
    }

    private function oracleBranches(PublishedReleaseView $view, mixed $branches, string $path): OracleBranches
    {
        $branches = $this->object($view, $branches, $path);
        $outcomes = [];
        foreach (['yes', 'no', 'exceptionalYes', 'exceptionalNo'] as $answer) {
            $outcomes[$answer] = null === ($branches[$answer] ?? null) ? null : $this->outcome($view, $branches[$answer], $path.'.'.$answer);
        }

        return new OracleBranches(...$outcomes);
    }

    /**
     * @return list<TableBranch>
     */
    private function tableBranches(PublishedReleaseView $view, mixed $branches, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $branches, $path) as $index => $branch) {
            $branchPath = \sprintf('%s[%d]', $path, $index);
            $entry = $this->object($view, $branch, $branchPath)['entry'] ?? null;
            $snapshots[] = new TableBranch($this->string($view, $entry, $branchPath.'.entry'), $this->outcome($view, $branch, $branchPath));
        }

        return $snapshots;
    }

    /**
     * @return list<ChoiceOption>
     */
    private function options(PublishedReleaseView $view, mixed $options, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $options, $path) as $index => $option) {
            $optionPath = \sprintf('%s[%d]', $path, $index);
            $fields = $this->object($view, $option, $optionPath);
            $snapshots[] = new ChoiceOption(
                $this->string($view, $fields['key'] ?? null, $optionPath.'.key'),
                $this->string($view, $fields['label'] ?? null, $optionPath.'.label'),
                $this->outcome($view, $option, $optionPath),
            );
        }

        return $snapshots;
    }

    /**
     * @return list<Band>
     */
    private function bands(PublishedReleaseView $view, mixed $bands, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $bands, $path) as $index => $band) {
            $bandPath = \sprintf('%s[%d]', $path, $index);
            $upTo = $this->object($view, $band, $bandPath)['upTo'] ?? null;
            $snapshots[] = new Band(null === $upTo ? null : $this->value($view, $upTo, $bandPath.'.upTo'), $this->outcome($view, $band, $bandPath));
        }

        return $snapshots;
    }

    /**
     * @return list<Effect>
     */
    private function effects(PublishedReleaseView $view, mixed $effects, string $path): array
    {
        $snapshots = [];
        foreach ($this->list($view, $effects, $path) as $index => $effect) {
            $effectPath = \sprintf('%s[%d]', $path, $index);
            $effect = $this->object($view, $effect, $effectPath);
            $sceneType = fn (): string => $this->string($view, $effect['sceneType'] ?? null, $effectPath.'.sceneType');
            $snapshots[] = match ($kind = $this->string($view, $effect['kind'] ?? null, $effectPath.'.kind')) {
                'tracker' => new TrackerEffect(
                    $this->string($view, $effect['tracker'] ?? null, $effectPath.'.tracker'),
                    TrackerOperation::tryFrom($op = $this->string($view, $effect['op'] ?? null, $effectPath.'.op'))
                        ?? throw $this->invalid($view, $effectPath.'.op', \sprintf('unknown tracker operation "%s".', $op)),
                    $this->value($view, $effect['value'] ?? null, $effectPath.'.value'),
                ),
                'nextScene' => new NextSceneEffect($sceneType()),
                'switchSceneType' => new SwitchSceneTypeEffect($sceneType()),
                'endPhase' => new EndPhaseEffect(),
                'sceneTitle' => new SceneTitleEffect($this->string($view, $effect['title'] ?? null, $effectPath.'.title')),
                default => throw $this->invalid($view, $effectPath.'.kind', \sprintf('unknown effect kind "%s".', $kind)),
            };
        }

        return $snapshots;
    }

    /**
     * A literal or {"tracker": key}.
     */
    private function value(PublishedReleaseView $view, mixed $value, string $path): int|TrackerReference
    {
        if (\is_int($value)) {
            return $value;
        }

        $reference = \is_array($value) ? $value['tracker'] ?? null : null;
        if (!\is_string($reference)) {
            throw $this->invalid($view, $path, 'must be an integer or {"tracker": key}.');
        }

        return new TrackerReference($reference);
    }

    /**
     * @param list<mixed> $tables
     */
    private function oracleTables(PublishedReleaseView $view, array $tables): ?OracleTableSet
    {
        // An empty set is valid content, but OracleTableSet requires at least one table.
        if ([] === $tables) {
            return null;
        }

        try {
            return OracleTableSet::fromArray($tables);
        } catch (InvalidOracleTable $exception) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, 'oracles.tables', $exception->getMessage(), $exception);
        }
    }

    /**
     * @param list<mixed> $oracles
     *
     * @return list<SnapshotLikelihoodOracle>
     */
    private function likelihoodOracles(PublishedReleaseView $view, array $oracles): array
    {
        $snapshots = [];
        foreach ($oracles as $index => $oracle) {
            $path = \sprintf('oracles.likelihood[%d]', $index);
            $definition = $this->object($view, $oracle, $path);
            $key = $this->string($view, $definition['key'] ?? null, $path.'.key');
            $name = $this->string($view, $definition['name'] ?? null, $path.'.name');
            // key, name and the chaos tracker belong to the GameSystem; the rest is the Randomness definition.
            unset($definition['key'], $definition['name']);
            $chaosTracker = null;
            if (\is_array($definition['chaos'] ?? null) && \array_key_exists('tracker', $definition['chaos'])) {
                $chaosTracker = $this->optionalString($view, $definition['chaos']['tracker'], $path.'.chaos.tracker');
                unset($definition['chaos']['tracker']);
            }

            try {
                $snapshots[] = new SnapshotLikelihoodOracle($key, $name, LikelihoodOracle::fromArray($definition), $chaosTracker);
            } catch (InvalidLikelihoodOracle $exception) {
                throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, $exception->getMessage(), $exception);
            }
        }

        return $snapshots;
    }

    /**
     * @param list<mixed> $steps
     *
     * @return list<FlowStep>
     */
    private function flowSteps(PublishedReleaseView $view, array $steps): array
    {
        $flowSteps = [];
        foreach ($steps as $index => $step) {
            $path = \sprintf('flow.steps[%d]', $index);
            $step = $this->object($view, $step, $path);
            $prompt = $step['prompt'] ?? null;

            $flowSteps[] = new FlowStep(
                $this->string($view, $step['key'] ?? null, $path.'.key'),
                $this->string($view, $step['title'] ?? null, $path.'.title'),
                null === $prompt ? null : $this->string($view, $prompt, $path.'.prompt'),
            );
        }

        return $flowSteps;
    }

    /**
     * @return array<mixed>
     */
    private function object(PublishedReleaseView $view, mixed $value, string $path): array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, 'must be an object.');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(PublishedReleaseView $view, mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, 'must be a list.');
        }

        return $value;
    }

    private function string(PublishedReleaseView $view, mixed $value, string $path): string
    {
        if (!\is_string($value)) {
            throw InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, 'must be a string.');
        }

        return $value;
    }

    private function optionalString(PublishedReleaseView $view, mixed $value, string $path): ?string
    {
        return null === $value ? null : $this->string($view, $value, $path);
    }

    private function int(PublishedReleaseView $view, mixed $value, string $path): int
    {
        return \is_int($value) ? $value : throw $this->invalid($view, $path, 'must be an integer.');
    }

    private function invalid(PublishedReleaseView $view, string $path, string $reason): InvalidGameSystemRelease
    {
        return InvalidGameSystemRelease::of($view->gameSystemKey, $view->version, $path, $reason);
    }
}
