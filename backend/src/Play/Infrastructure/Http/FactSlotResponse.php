<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\FactSlotView;
use OpenApi\Attributes as OA;

/**
 * A Fact slot of the campaign's pinned release: a place for one Campaign fact.
 */
#[OA\Schema(required: ['key', 'label', 'type', 'hint'])]
final readonly class FactSlotResponse
{
    private function __construct(
        #[OA\Property(example: 'prince')]
        public string $key,
        #[OA\Property(description: 'What players see for the slot.', example: 'The Prince')]
        public string $label,
        #[OA\Property(description: 'What the slot takes: a text, an NPC or a Thread.', example: 'npc', enum: ['text', 'npc', 'thread'])]
        public string $type,
        #[OA\Property(description: 'Shown where the slot is filled; null when the slot has none.', example: 'Who rules the city?', nullable: true)]
        public ?string $hint,
    ) {
    }

    public static function fromView(FactSlotView $view): self
    {
        return new self($view->key, $view->label, $view->type, $view->hint);
    }
}
