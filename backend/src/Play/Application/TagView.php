<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * A Tag of the campaign's pinned release: the key NPCs and Threads carry, with the label players
 * see and an optional hint.
 */
final readonly class TagView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
    ) {
    }
}
