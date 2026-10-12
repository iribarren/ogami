<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A Fact slot the release declares: a place for one Campaign fact (a text, an NPC or a Thread), with
 * the optional hint players see.
 */
final readonly class FactSlot
{
    public function __construct(
        public string $key,
        public string $label,
        public FactSlotType $type,
        public ?string $hint = null,
    ) {
    }
}
