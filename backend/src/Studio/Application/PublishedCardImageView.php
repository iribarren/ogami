<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * An image a published release pinned, as other contexts see it: what to show and where it goes,
 * with no Studio Domain types (ADR 0053).
 */
final readonly class PublishedCardImageView
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
