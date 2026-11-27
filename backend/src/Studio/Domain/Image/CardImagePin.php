<?php

declare(strict_types=1);

namespace App\Studio\Domain\Image;

/**
 * An image a GameSystem uses in one slot.
 */
final readonly class CardImagePin
{
    public function __construct(
        public CardImageSlot $slot,
        public CardImage $image,
    ) {
    }
}
