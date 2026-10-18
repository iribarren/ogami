<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\LibraryPackCatalogItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One item of a library pack in its catalog.
 */
#[OA\Schema(required: ['key', 'label', 'kind', 'dependsOn'])]
final readonly class LibraryPackCatalogItemResponse
{
    /**
     * @param list<LibraryItemRefResponse> $dependsOn
     */
    private function __construct(
        #[OA\Property(example: 'tavern')]
        public string $key,
        #[OA\Property(description: 'The item\'s name or label; its key when it has none.', example: 'Tavern')]
        public string $label,
        #[OA\Property(description: 'What an oracle is; null for the other sections.', nullable: true, enum: ['table', 'likelihood'])]
        public ?string $kind,
        #[OA\Property(description: 'The other items of the pack this one names directly, not their closure: importing it copies them too unless the draft has their keys.', type: 'array', items: new OA\Items(ref: new Model(type: LibraryItemRefResponse::class)))]
        public array $dependsOn,
    ) {
    }

    public static function fromItem(LibraryPackCatalogItem $item): self
    {
        return new self($item->key, $item->label, $item->kind, array_map(LibraryItemRefResponse::fromRef(...), $item->dependsOn));
    }
}
