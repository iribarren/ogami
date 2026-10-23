<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\TagView;
use OpenApi\Attributes as OA;

/**
 * A Tag of the campaign's pinned release, which NPCs and Threads carry by key.
 */
#[OA\Schema(required: ['key', 'label', 'hint', 'appliesTo'])]
final readonly class TagResponse
{
    /**
     * @param ?list<string> $appliesTo
     */
    private function __construct(
        #[OA\Property(example: 'leader')]
        public string $key,
        #[OA\Property(description: 'What players see for the Tag.', example: 'Leader')]
        public string $label,
        #[OA\Property(description: 'Shown as a tooltip where Tags are chosen; null when the Tag has none.', example: 'Runs a faction or a crew.', nullable: true)]
        public ?string $hint,
        #[OA\Property(description: 'The entity kinds that may carry the Tag; null when it applies to every kind.', type: 'array', items: new OA\Items(type: 'string', enum: ['character', 'npc', 'thread']), example: ['npc'], nullable: true)]
        public ?array $appliesTo,
    ) {
    }

    public static function fromView(TagView $view): self
    {
        return new self($view->key, $view->label, $view->hint, $view->appliesTo);
    }
}
