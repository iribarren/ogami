<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the bytes of an image a published release pinned. Only that exact release counts: an
 * image pinned by another release of the GameSystem, or used by the draft alone, is not found
 * (ADR 0053). Checks no owner: the asking context decides who may see it.
 *
 * @implements Query<PublishedCardImage>
 */
final readonly class GetPublishedCardImage implements Query
{
    public function __construct(
        public string $gameSystemKey,
        public int $version,
        public string $sha256,
    ) {
    }
}
