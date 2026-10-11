<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A Tag of a GameSystem release: a key the release's flows use to pick or count NPCs and Threads,
 * with the label and optional hint players see.
 */
final readonly class Tag
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint = null,
    ) {
    }
}
