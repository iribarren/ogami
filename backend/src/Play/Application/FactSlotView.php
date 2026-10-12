<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\FactSlot;

/**
 * A Fact slot of the campaign's pinned release: what it asks for ("text", "npc" or "thread") and
 * the hint players see, if any.
 */
final readonly class FactSlotView
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public ?string $hint,
    ) {
    }

    public static function of(FactSlot $slot): self
    {
        return new self($slot->key, $slot->label, $slot->type->value, $slot->hint);
    }
}
