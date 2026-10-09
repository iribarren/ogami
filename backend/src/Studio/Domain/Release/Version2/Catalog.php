<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

/**
 * The keys a schema version 2 release declares, so steps, effects, bands and selections can check
 * that what they name exists.
 *
 * @internal
 */
final readonly class Catalog
{
    /**
     * @param array<string, array{kind: string, min: int, max: int}> $trackers         clocks range 0..segments
     * @param array<string, array<string, true>>                     $tableEntryKeys   table key => its entry keys
     * @param array<string, array<string, true>>                     $likelihoodLevels likelihood oracle key => its level keys
     * @param array<string, true>                                    $sceneTypes
     */
    public function __construct(
        public array $trackers,
        public array $tableEntryKeys,
        public array $likelihoodLevels,
        public array $sceneTypes,
    ) {
    }

    public function hasTracker(string $key): bool
    {
        return isset($this->trackers[$key]);
    }

    public function hasTable(string $key): bool
    {
        return isset($this->tableEntryKeys[$key]);
    }

    public function hasLikelihood(string $key): bool
    {
        return isset($this->likelihoodLevels[$key]);
    }

    public function hasSceneType(string $key): bool
    {
        return isset($this->sceneTypes[$key]);
    }
}
