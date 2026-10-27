<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

/**
 * The keys a schema version 2 release declares, so steps, effects, bands and selections can check
 * that what they name exists.
 *
 * The shape of a check, as steps see it: the difficulty range it declares (null: no difficulty), its band keys
 * and the special results it can produce (ADR 0044).
 *
 * @phpstan-type CheckShape array{difficulty: array{min: int|null, max: int|null}|null, bands: array<string, true>, specials: array<string, true>}
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
     * @param array<string, list<string>>                            $tagScopes        the entity kinds of each tag limited to some (a tag with no entry applies to all)
     * @param array<string, array<string, true>>                     $conditionOptions the option keys of each conditions field, by field key
     * @param array<string, string>                                  $fieldTypes       the type of every sheet field by key (number, text, list, track, derived, conditions or inventory)
     * @param array<string, string|null>                             $gear             the category of each gear catalog entry by key, null without one (ADR 0045)
     * @param array<string, list<string>|null>                       $inventories      the categories each inventory field accepts, by field key in sheet order (null: every entry)
     * @param string|null                                            $currency         the sheet's currency field, null without one
     */
    public function __construct(
        public array $trackers,
        public array $tableEntryKeys,
        public array $likelihoodLevels,
        public array $sceneTypes,
        public array $tags = [],
        public array $factSlots = [],
        public array $checks = [],
        public array $fieldTypes = [],
        public array $tagScopes = [],
        public array $conditionOptions = [],
        public array $gear = [],
        public array $inventories = [],
        public ?string $currency = null,
    ) {
    }

    /**
     * @param array<string, CheckShape> $checks
     */
    public function withChecks(array $checks): self
    {
        return new self($this->trackers, $this->tableEntryKeys, $this->likelihoodLevels, $this->sceneTypes, $this->tags, $this->factSlots, $checks, $this->fieldTypes, $this->tagScopes, $this->conditionOptions, $this->gear, $this->inventories, $this->currency);
    }

    /**
     * @return CheckShape|null
     */
    public function check(string $key): ?array
    {
        return $this->checks[$key] ?? null;
    }

    /**
     * The type of a sheet field of the release, or null when there is none by that key.
     */
    public function fieldType(string $key): ?string
    {
        return $this->fieldTypes[$key] ?? null;
    }

    /**
     * Whether a conditions field of the release declares an option by that key.
     */
    public function hasConditionOption(string $field, string $option): bool
    {
        return isset($this->conditionOptions[$field][$option]);
    }

    /**
     * The categories of the gear catalog.
     *
     * @return array<string, true>
     */
    public function gearCategories(): array
    {
        return array_fill_keys(array_filter($this->gear, static fn (?string $category): bool => null !== $category), true);
    }

    /**
     * Whether some inventory field of the sheet accepts gear of this category (null: none).
     */
    public function hasInventoryFor(?string $category): bool
    {
        return array_any($this->inventories, static fn (?array $accepted): bool => Gear::accepts($accepted, $category));
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

    /**
     * Whether the tag may be carried by an entity of this kind (npc, thread or character).
     */
    public function tagAppliesTo(string $key, string $kind): bool
    {
        return !isset($this->tagScopes[$key]) || \in_array($kind, $this->tagScopes[$key], true);
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
