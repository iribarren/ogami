<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Domain\GameSystem\LibraryItemRef;
use OpenApi\Attributes as OA;

/**
 * An item of a library pack: its section and its key.
 */
#[OA\Schema(required: ['section', 'key'])]
final readonly class LibraryItemRefResponse
{
    private function __construct(
        #[OA\Property(description: 'Oracle tables and likelihood oracles share the oracles section.', example: 'tags', enum: ['oracles', 'trackers', 'tags', 'fact-slots', 'scene-types'])]
        public string $section,
        #[OA\Property(example: 'social')]
        public string $key,
    ) {
    }

    /**
     * @param array{section: string, key: string} $ref
     */
    public static function fromArray(array $ref): self
    {
        return new self($ref['section'], $ref['key']);
    }

    public static function fromRef(LibraryItemRef $ref): self
    {
        return new self($ref->section->value, $ref->key);
    }
}
