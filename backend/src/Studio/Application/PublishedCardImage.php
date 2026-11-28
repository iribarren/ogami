<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The bytes of an image a published release pinned, with their media type.
 */
final readonly class PublishedCardImage
{
    public function __construct(
        public string $mediaType,
        public string $bytes,
    ) {
    }
}
