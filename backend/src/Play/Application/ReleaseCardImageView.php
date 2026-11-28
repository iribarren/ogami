<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * An image the campaign's pinned release pinned, in the slot it is used for (ADR 0053).
 */
final readonly class ReleaseCardImageView
{
    public function __construct(
        public string $slot,
        public string $sha256,
        public string $mediaType,
        public int $width,
        public int $height,
    ) {
    }
}
