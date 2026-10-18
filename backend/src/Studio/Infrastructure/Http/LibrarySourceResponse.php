<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * A library item the draft had imported when a release was published.
 */
#[OA\Schema(required: ['section', 'key', 'pack'])]
final readonly class LibrarySourceResponse
{
    private function __construct(
        #[OA\Property(description: 'Which section the item is in: oracles, trackers, tags, fact slots or Scene Types.', enum: ['oracles', 'trackers', 'tags', 'fact-slots', 'scene-types'])]
        public string $section,
        #[OA\Property(description: 'The item\'s key in the draft.', example: 'npc-name')]
        public string $key,
        #[OA\Property(description: 'The key of the library pack it came from.', example: 'fantasy-basics')]
        public string $pack,
    ) {
    }

    /**
     * @param array{section: string, key: string, pack: string} $source
     */
    public static function fromSource(array $source): self
    {
        return new self($source['section'], $source['key'], $source['pack']);
    }
}
