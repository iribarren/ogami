<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\LibraryPackCatalogSection;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The items of one section of a library pack.
 */
#[OA\Schema(required: ['section', 'items'])]
final readonly class LibraryPackCatalogSectionResponse
{
    /**
     * @param list<LibraryPackCatalogItemResponse> $items
     */
    private function __construct(
        #[OA\Property(example: 'scene-types', enum: ['oracles', 'trackers', 'tags', 'fact-slots', 'scene-types'])]
        public string $section,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: LibraryPackCatalogItemResponse::class)))]
        public array $items,
    ) {
    }

    public static function fromSection(LibraryPackCatalogSection $section): self
    {
        return new self($section->section->value, array_map(LibraryPackCatalogItemResponse::fromItem(...), $section->items));
    }
}
