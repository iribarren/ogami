<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * Play's own, immutable reading of one published GameSystem release: what a campaign plays with.
 * Built by Play's anti-corruption layer; it holds no Studio types. A schema version 1 release has
 * no trackers, fact slots, Scene Types or table entry metadata.
 */
final readonly class GameSystemSnapshot
{
    /** @var array<string, SnapshotLikelihoodOracle> */
    private array $likelihoodOracles;

    /** @var array<string, Tracker> */
    private array $trackers;

    /** @var array<string, SceneType> */
    private array $sceneTypes;

    /**
     * @param ?OracleTableSet                         $oracleTables      null when the release has no oracle tables
     * @param list<SnapshotLikelihoodOracle>          $likelihoodOracles in definition order, unique keys
     * @param list<FlowStep>                          $flowSteps         in flow order, unique keys
     * @param list<Tracker>                           $trackers          in definition order, unique keys
     * @param list<FactSlot>                          $factSlots         in definition order
     * @param list<SceneType>                         $sceneTypes        in definition order, unique keys
     * @param array<string, list<TableEntryMetadata>> $tableEntries      per table key, one per entry in entry order
     *
     * @throws InvalidGameSystemRelease when two likelihood oracles, flow steps, trackers or Scene Types share a key
     */
    public function __construct(
        private string $gameSystemKey,
        private string $name,
        private int $releaseVersion,
        private ?OracleTableSet $oracleTables,
        array $likelihoodOracles,
        private array $flowSteps,
        array $trackers = [],
        private array $factSlots = [],
        array $sceneTypes = [],
        private array $tableEntries = [],
    ) {
        $byKey = [];
        foreach ($likelihoodOracles as $index => $oracle) {
            $this->assertUnique($oracle->key(), $byKey, \sprintf('oracles.likelihood[%d].key', $index));
            $byKey[$oracle->key()] = $oracle;
        }

        $stepKeys = [];
        foreach ($flowSteps as $index => $step) {
            $this->assertUnique($step->key(), $stepKeys, \sprintf('flow.steps[%d].key', $index));
            $stepKeys[$step->key()] = true;
        }

        $this->likelihoodOracles = $byKey;
        $this->trackers = $this->byKey($trackers, 'trackers');
        $this->sceneTypes = $this->byKey($sceneTypes, 'sceneTypes');
    }

    public function gameSystemKey(): string
    {
        return $this->gameSystemKey;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function releaseVersion(): int
    {
        return $this->releaseVersion;
    }

    /**
     * @return list<string> in definition order
     */
    public function oracleTableKeys(): array
    {
        return $this->oracleTables?->keys() ?? [];
    }

    /**
     * @return array<string, string> table name by key, in definition order
     */
    public function oracleTableNames(): array
    {
        $names = [];
        foreach ($this->oracleTableKeys() as $key) {
            $names[$key] = $this->oracleTables?->table($key)->name() ?? $key;
        }

        return $names;
    }

    public function hasOracleTable(string $key): bool
    {
        return \in_array($key, $this->oracleTableKeys(), true);
    }

    /**
     * Rolls on the oracle table with this key, then on the tables it nests.
     *
     * @throws UnknownGameSystemOracle when no oracle table has this key
     */
    public function resolveOracleTable(string $key, RandomNumberGenerator $random): OracleTableResult
    {
        if (!$this->oracleTables instanceof OracleTableSet || !$this->hasOracleTable($key)) {
            throw UnknownGameSystemOracle::table($this->gameSystemKey, $this->releaseVersion, $key);
        }

        return $this->oracleTables->resolve($key, $random);
    }

    /**
     * @return list<SnapshotLikelihoodOracle> in definition order
     */
    public function likelihoodOracles(): array
    {
        return array_values($this->likelihoodOracles);
    }

    public function hasLikelihoodOracle(string $key): bool
    {
        return isset($this->likelihoodOracles[$key]);
    }

    /**
     * @throws UnknownGameSystemOracle when no likelihood oracle has this key
     */
    public function likelihoodOracle(string $key): SnapshotLikelihoodOracle
    {
        return $this->likelihoodOracles[$key]
            ?? throw UnknownGameSystemOracle::likelihood($this->gameSystemKey, $this->releaseVersion, $key);
    }

    /**
     * @return list<FlowStep> in flow order
     */
    public function flowSteps(): array
    {
        return $this->flowSteps;
    }

    /**
     * @return list<Tracker> in definition order
     */
    public function trackers(): array
    {
        return array_values($this->trackers);
    }

    public function tracker(string $key): ?Tracker
    {
        return $this->trackers[$key] ?? null;
    }

    /**
     * @return list<FactSlot> in definition order
     */
    public function factSlots(): array
    {
        return $this->factSlots;
    }

    /**
     * @return list<SceneType> in definition order
     */
    public function sceneTypes(): array
    {
        return array_values($this->sceneTypes);
    }

    public function sceneType(string $key): ?SceneType
    {
        return $this->sceneTypes[$key] ?? null;
    }

    /**
     * The key, Scene Type and Effects of the entry a table roll selected; null without metadata.
     */
    public function rolledEntry(OracleTableStep $step): ?TableEntryMetadata
    {
        if (!$this->oracleTables instanceof OracleTableSet || !$this->hasOracleTable($step->tableKey())) {
            return null;
        }

        $table = $this->oracleTables->table($step->tableKey());
        $weight = 0;
        foreach ($table->entries() as $index => $entry) {
            $weight += $entry->weight() ?? 0;
            if ($table->isRanged() ? $entry->covers($step->total()) : $step->total() <= $weight) {
                return $this->tableEntries[$step->tableKey()][$index] ?? null;
            }
        }

        return null;
    }

    /**
     * @template T of Tracker|SceneType
     *
     * @param list<T> $parts
     *
     * @return array<string, T>
     */
    private function byKey(array $parts, string $path): array
    {
        $byKey = [];
        foreach ($parts as $index => $part) {
            $this->assertUnique($part->key, $byKey, \sprintf('%s[%d].key', $path, $index));
            $byKey[$part->key] = $part;
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $seen keys met so far
     */
    private function assertUnique(string $key, array $seen, string $path): void
    {
        if (\array_key_exists($key, $seen)) {
            throw InvalidGameSystemRelease::of($this->gameSystemKey, $this->releaseVersion, $path, \sprintf('duplicate key "%s".', $key));
        }
    }
}
