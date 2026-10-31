<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\PlaceView;
use OpenApi\Attributes as OA;

/**
 * A Place of a campaign: a site (a town, a dungeon, a camp) still open or already closed.
 */
#[OA\Schema(required: ['id', 'name', 'tags', 'freeTags', 'weight', 'status', 'notes'])]
final readonly class PlaceResponse
{
    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Saltmarsh')]
        public string $name,
        #[OA\Property(description: 'The keys of the pinned release\'s Tags the Place carries; flows pick and count by them.', type: 'array', items: new OA\Items(type: 'string'), example: [])]
        public array $tags,
        #[OA\Property(description: 'The player\'s own labels, as typed; flows never match them.', type: 'array', items: new OA\Items(type: 'string'), example: ['Town'])]
        public array $freeTags,
        #[OA\Property(description: 'Favours the Place in a random pick: 1 to 3.', example: 1)]
        public int $weight,
        #[OA\Property(description: 'A closed Place is no longer picked or counted.', example: 'open', enum: ['open', 'closed'])]
        public string $status,
        #[OA\Property(description: 'Free text about the Place; null when there are none.', example: 'A fishing town on the marsh', nullable: true)]
        public ?string $notes,
    ) {
    }

    public static function fromView(PlaceView $view): self
    {
        return new self($view->id, $view->name, $view->tags, $view->freeTags, $view->weight, $view->status, $view->notes);
    }
}
