<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;
use App\Studio\Domain\Image\CardImageSlot;

/**
 * Puts an uploaded image in a slot of the owner's GameSystem. It is not part of the draft document,
 * so it never needs the draft's version and never changes it. The handler leaves the stored image in
 * the receipt, because a command bus returns nothing.
 */
final readonly class UploadCardImage implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public CardImageSlot $slot,
        public string $upload,
        public UploadedCardImage $receipt,
    ) {
    }
}
