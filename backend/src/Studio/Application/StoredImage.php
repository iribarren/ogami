<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Image\CardImage;

/**
 * A card image with its bytes: what the normalizer produces and the store keeps.
 */
final readonly class StoredImage
{
    public function __construct(
        public CardImage $image,
        public string $bytes,
    ) {
    }
}
