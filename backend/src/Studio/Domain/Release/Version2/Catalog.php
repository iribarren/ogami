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
     * @param array<string, true>                                    $tags             the tag keys of the release
     * @param array<string, string>                                  $factSlots        the fact slot types by key (text, npc or thread)
     */
    public function __construct(
        public array $trackers,
        public array $tableEntryKeys,
        public array $likelihoodLevels,
        public array $sceneTypes,
        public array $tags = [],
        public array $factSlots = [],
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

    public function hasTag(string $key): bool
    {
        return isset($this->tags[$key]);
    }

    public function hasFactSlot(string $key): bool
    {
        return isset($this->factSlots[$key]);
    }

    /**
     * The type of a fact slot of the release, or null when there is none by that key.
     */
    public function factSlotType(string $key): ?string
    {
        return $this->factSlots[$key] ?? null;
    }
}
