<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Lists\Place;

/**
 * A Place of a campaign: its notes, release Tag keys, free tags, weight and status ("open" or "closed").
 */
final readonly class PlaceView
{
    /**
     * @param list<string> $tags     release Tag keys
     * @param list<string> $freeTags
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $notes,
        public array $tags,
        public array $freeTags,
        public int $weight,
        public string $status,
    ) {
    }

    public static function of(Place $place): self
    {
        return new self($place->id()->toString(), $place->name(), $place->notes(), $place->tags(), $place->freeTags(), $place->weight(), $place->status()->value);
    }
}
