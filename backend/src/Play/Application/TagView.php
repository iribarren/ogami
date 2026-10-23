<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * A Tag of the campaign's pinned release: the key NPCs and Threads carry, with the label players
 * see and an optional hint. It may be limited to some entity kinds.
 */
final readonly class TagView
{
    /**
     * @param ?list<string> $appliesTo the entity kinds (character, npc, thread) that may carry the Tag; null: every kind
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
        public ?array $appliesTo = null,
    ) {
    }
}
