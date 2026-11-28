<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The bytes of a card image, with the media type they were stored with.
 */
final readonly class CardImageBytes
{
    public function __construct(
        public string $mediaType,
        public string $bytes,
    ) {
    }
}
