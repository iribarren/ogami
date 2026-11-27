<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Reads an image of the owner's GameSystem by its hash.
 *
 * @implements Query<StoredImage>
 */
final readonly class GetCardImage implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $sha256,
    ) {
    }
}
