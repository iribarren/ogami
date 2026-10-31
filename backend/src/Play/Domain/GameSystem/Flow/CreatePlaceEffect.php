<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Creates an open Place with these Tags; the name may hold placeholders. A missing name gets a
 * default when the Effect is applied. The Place becomes the item picked, never joins the Scene cast.
 */
final readonly class CreatePlaceEffect implements Effect
{
    /**
     * @param list<string> $tags Tag keys of the release
     */
    public function __construct(
        public ?string $name = null,
        public array $tags = [],
    ) {
    }
}
