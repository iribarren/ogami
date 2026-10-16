<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The narrative sections of a GameSystem draft as stored, in order, with the draft version a client
 * sends back as the expected version of its next narrative command. The draft may be invalid, so
 * what is not an item with a string key is left out. A schema version 1 draft has none of them.
 */
final readonly class GameSystemNarrativeView
{
    /**
     * @param list<NarrativeItemView> $trackers
     * @param list<NarrativeItemView> $tags
     * @param list<NarrativeItemView> $factSlots
     * @param list<NarrativeItemView> $sceneTypes
     * @param list<NarrativeItemView> $flows
     */
    public function __construct(
        public int $version,
        public int $schemaVersion,
        public array $trackers,
        public array $tags,
        public array $factSlots,
        public array $sceneTypes,
        public array $flows,
        public NarrativeCatalogView $catalog,
    ) {
    }
}
