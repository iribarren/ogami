<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One item of a release section that has another content than in the previous release.
 */
#[OA\Schema(required: ['key', 'name', 'previousName', 'fields'])]
final readonly class ReleaseChangesItemResponse
{
    /**
     * @param list<string> $fields
     */
    private function __construct(
        #[OA\Property(description: 'The item\'s key in its section; it matches the item of the previous release.', example: 'heist-scenes')]
        public string $key,
        #[OA\Property(description: 'The item\'s display name in this release (its name, or its label for a sheet field, fact slot, tag or check); null when it has none.', example: 'Heist scenes', nullable: true)]
        public ?string $name,
        #[OA\Property(description: 'The item\'s display name in the previous release; null when it had none. It differs from the name when the item was renamed.', example: 'Heists', nullable: true)]
        public ?string $previousName,
        #[OA\Property(description: 'The top-level properties of the item whose values differ, one only of the two releases has included, sorted by name; empty when the item is not an object.', type: 'array', items: new OA\Items(type: 'string'), example: ['bands', 'dice'])]
        public array $fields,
    ) {
    }

    /**
     * @param array{key: string, name: string|null, previousName: string|null, fields: list<string>} $item
     */
    public static function fromItem(array $item): self
    {
        return new self($item['key'], $item['name'], $item['previousName'], $item['fields']);
    }
}
