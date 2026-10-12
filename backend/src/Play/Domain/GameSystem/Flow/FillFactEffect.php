<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Fills the Fact slot with this key: a text slot with the rendered text (the answer when omitted),
 * an NPC or Thread slot with the first item of that kind that was picked or created. Text may hold
 * placeholders and is only set for a text slot.
 */
final readonly class FillFactEffect implements Effect
{
    public function __construct(
        public string $slot,
        public ?string $text = null,
    ) {
    }
}
