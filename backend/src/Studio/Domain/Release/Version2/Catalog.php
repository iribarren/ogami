<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

/**
 * The keys a schema version 2 release declares, so steps, effects, bands and selections can check
 * that what they name exists.
 *
 * The shape of a check, as steps see it: the difficulty range it declares (null: no difficulty) and its band keys.
 *
 * @phpstan-type CheckShape array{difficulty: array{min: int|null, max: int|null}|null, bands: array<string, true>}
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
     * @param array<string, CheckShape>                              $checks           what steps may name of each check, by check key
     */
    public function __construct(
        public array $trackers,
        public array $tableEntryKeys,
        public array $likelihoodLevels,
        public array $sceneTypes,
        public array $tags = [],
        public array $factSlots = [],
        public array $checks = [],
    ) {
    }

    /**
     * @param array<string, CheckShape> $checks
     */
    public function withChecks(array $checks): self
    {
        return new self($this->trackers, $this->tableEntryKeys, $this->likelihoodLevels, $this->sceneTypes, $this->tags, $this->factSlots, $checks);
    }

    /**
     * @return CheckShape|null
     */
    public function check(string $key): ?array
    {
        return $this->checks[$key] ?? null;
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
