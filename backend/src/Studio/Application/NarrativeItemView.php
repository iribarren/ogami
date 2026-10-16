<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * One tracker, tag, fact slot, Scene Type or flow of a draft.
 */
final readonly class NarrativeItemView
{
    /**
     * @param array<mixed> $definition the item as stored, release document shape
     * @param list<string> $usedBy     where the draft references it outside the item, e.g. "flows[0].phases[1].selection"
     */
    public function __construct(
        public string $key,
        public array $definition,
        public array $usedBy,
    ) {
    }
}
