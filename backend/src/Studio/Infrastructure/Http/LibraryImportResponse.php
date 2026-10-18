<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\LibraryImportView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * An item the draft imported from the library and where it stands against its pack.
 */
#[OA\Schema(required: ['section', 'key', 'label', 'pack', 'status', 'editedLocally'])]
final readonly class LibraryImportResponse
{
    private function __construct(
        #[OA\Property(example: 'scene-types', enum: ['oracles', 'trackers', 'tags', 'fact-slots', 'scene-types'])]
        public string $section,
        #[OA\Property(example: 'tavern')]
        public string $key,
        #[OA\Property(description: 'What the draft\'s item is called now.', example: 'Tavern')]
        public string $label,
        #[OA\Property(ref: new Model(type: LibraryImportPackResponse::class), description: 'The pack the item came from; null when the pack is gone.', nullable: true)]
        public ?LibraryImportPackResponse $pack,
        #[OA\Property(description: 'current: the pack item is the one imported; changed: it differs; removed: the pack or its item is gone.', example: 'current', enum: ['current', 'changed', 'removed'])]
        public string $status,
        #[OA\Property(description: 'Whether the draft\'s item differs from the one imported.', example: false)]
        public bool $editedLocally,
    ) {
    }

    public static function fromView(LibraryImportView $view): self
    {
        return new self(
            $view->section->value,
            $view->key,
            $view->label,
            null === $view->pack ? null : LibraryImportPackResponse::fromArray($view->pack),
            $view->status->value,
            $view->editedLocally,
        );
    }
}
