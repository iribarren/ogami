<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;
use App\Studio\Domain\Image\CardImageSlot;

/**
 * Empties a slot of the owner's GameSystem; an empty slot stays empty.
 */
final readonly class RemoveCardImage implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public CardImageSlot $slot,
    ) {
    }
}
