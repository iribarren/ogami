<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\LibraryPackCatalog;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A library pack as any game manager browses it: its metadata and its items by section.
 */
#[OA\Schema(required: ['key', 'name', 'description', 'mine', 'updatedAt', 'sections'])]
final readonly class LibraryPackCatalogResponse
{
    /**
     * @param list<LibraryPackCatalogSectionResponse> $sections
     */
    private function __construct(
        #[OA\Property(example: 'fantasy-basics')]
        public string $key,
        #[OA\Property(example: 'Fantasy basics')]
        public string $name,
        #[OA\Property(example: 'Names, rumours and a tavern.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'True when the signed-in game manager wrote the pack and may edit it.')]
        public bool $mine,
        #[OA\Property(format: 'date-time')]
        public string $updatedAt,
        #[OA\Property(description: 'Every section, in order, even when it has no items.', type: 'array', items: new OA\Items(ref: new Model(type: LibraryPackCatalogSectionResponse::class)))]
        public array $sections,
    ) {
    }

    public static function fromCatalog(LibraryPackCatalog $catalog): self
    {
        return new self(
            $catalog->key,
            $catalog->name,
            $catalog->description,
            $catalog->mine,
            $catalog->updatedAt->format(\DATE_ATOM),
            array_map(LibraryPackCatalogSectionResponse::fromSection(...), $catalog->sections),
        );
    }
}
