<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One item of a release section, as the release view lists it.
 */
#[OA\Schema(required: ['key', 'name'])]
final readonly class ReleaseOutlineItemResponse
{
    private function __construct(
        #[OA\Property(description: 'The item\'s key in its section.', example: 'heist-scenes')]
        public string $key,
        #[OA\Property(description: 'The item\'s display name (its name, or its label for a sheet field, fact slot, tag or check); null when it has none.', example: 'Heist scenes', nullable: true)]
        public ?string $name,
    ) {
    }

    /**
     * @param array{key: string, name: string|null} $item
     */
    public static function fromItem(array $item): self
    {
        return new self($item['key'], $item['name']);
    }
}
