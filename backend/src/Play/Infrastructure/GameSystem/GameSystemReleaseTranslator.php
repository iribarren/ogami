<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\FlowStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
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
 * Randomness ones.
 */
final readonly class GameSystemReleaseTranslator
{
    /** @var list<int> */
    public const array SUPPORTED_SCHEMA_VERSIONS = [1];

    /**
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function translate(PublishedReleaseView $view): GameSystemSnapshot
    {
        if (!\in_array($view->schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw UnsupportedReleaseSchemaVersion::of($view->gameSystemKey, $view->version, $view->schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS);
        }

        return $this->fromSchemaVersion1($view);
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
            // key and name belong to the GameSystem; the rest is the Randomness definition.
            unset($definition['key'], $definition['name']);

            try {
                $snapshots[] = new SnapshotLikelihoodOracle($key, $name, LikelihoodOracle::fromArray($definition));
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
}
