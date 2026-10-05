<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * Play's own, immutable reading of one published GameSystem release: what a campaign plays with.
 * Built by Play's anti-corruption layer; it holds no Studio types.
 */
final readonly class GameSystemSnapshot
{
    /** @var array<string, SnapshotLikelihoodOracle> */
    private array $likelihoodOracles;

    /**
     * @param ?OracleTableSet                $oracleTables      null when the release has no oracle tables
     * @param list<SnapshotLikelihoodOracle> $likelihoodOracles in definition order, unique keys
     * @param list<FlowStep>                 $flowSteps         in flow order
     */
    public function __construct(
        private string $gameSystemKey,
        private string $name,
        private int $releaseVersion,
        private ?OracleTableSet $oracleTables,
        array $likelihoodOracles,
        private array $flowSteps,
    ) {
        $byKey = [];
        foreach ($likelihoodOracles as $oracle) {
            $byKey[$oracle->key()] = $oracle;
        }

        $this->likelihoodOracles = $byKey;
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
}
