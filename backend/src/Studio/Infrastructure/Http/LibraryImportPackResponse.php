<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The pack an item was imported from.
 */
#[OA\Schema(required: ['key', 'name'])]
final readonly class LibraryImportPackResponse
{
    /**
     * @param array{key: string, name: string} $pack
     */
    public static function fromArray(array $pack): self
    {
        return new self($pack['key'], $pack['name']);
    }

    private function __construct(
        #[OA\Property(example: 'fantasy-basics')]
        public string $key,
        #[OA\Property(example: 'Fantasy basics')]
        public string $name,
    ) {
    }
}
