<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\TagView;
use OpenApi\Attributes as OA;

/**
 * A Tag of the campaign's pinned release, which NPCs and Threads carry by key.
 */
#[OA\Schema(required: ['key', 'label', 'hint'])]
final readonly class TagResponse
{
    private function __construct(
        #[OA\Property(example: 'leader')]
        public string $key,
        #[OA\Property(description: 'What players see for the Tag.', example: 'Leader')]
        public string $label,
        #[OA\Property(description: 'Shown as a tooltip where Tags are chosen; null when the Tag has none.', example: 'Runs a faction or a crew.', nullable: true)]
        public ?string $hint,
    ) {
    }

    public static function fromView(TagView $view): self
    {
        return new self($view->key, $view->label, $view->hint);
    }
}
