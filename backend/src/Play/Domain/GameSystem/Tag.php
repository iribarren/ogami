<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

use App\Play\Domain\GameSystem\Flow\ItemKind;

/**
 * A Tag of a GameSystem release: a key the release's flows use to pick or count NPCs and Threads,
 * with the label and optional hint players see. It may be limited to some entity kinds.
 */
final readonly class Tag
{
    /**
     * @param list<ItemKind> $appliesTo the entity kinds that may carry the Tag; empty: every kind
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint = null,
        public array $appliesTo = [],
    ) {
    }

    public function isFor(ItemKind $kind): bool
    {
        return [] === $this->appliesTo || \in_array($kind, $this->appliesTo, true);
    }
}
