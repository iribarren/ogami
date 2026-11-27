<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;
use App\Studio\Domain\Image\CardImagePin;

/**
 * Lists the images the owner's GameSystem uses; none for a GameSystem that never had any.
 *
 * @implements Query<list<CardImagePin>>
 */
final readonly class ListCardImages implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
