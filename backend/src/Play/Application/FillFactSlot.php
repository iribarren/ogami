<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Fills (or fills again) a Fact slot of one of the player's campaigns by hand with exactly one of
 * a text, an existing NPC or Thread (the kind comes from the slot's type), or the name of a new
 * NPC or Thread to create and fill the slot with. Several or none of them is refused.
 */
final readonly class FillFactSlot implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $slot,
        public ?string $text,
        public ?string $subject,
        public ?string $name,
    ) {
    }
}
