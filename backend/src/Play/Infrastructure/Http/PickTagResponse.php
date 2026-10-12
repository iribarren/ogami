<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Tag;
use OpenApi\Attributes as OA;

/**
 * A release Tag a pick step asks for, with the label players see.
 */
#[OA\Schema(required: ['key', 'label'])]
final readonly class PickTagResponse
{
    private function __construct(
        #[OA\Property(example: 'leader')]
        public string $key,
        #[OA\Property(example: 'Leader')]
        public string $label,
    ) {
    }

    public static function of(Tag $tag): self
    {
        return new self($tag->key, $tag->label);
    }
}
