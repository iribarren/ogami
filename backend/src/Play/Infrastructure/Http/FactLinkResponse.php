<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\FactLinkView;
use OpenApi\Attributes as OA;

/**
 * What a Campaign fact points at (an NPC, a Thread or another fact), or the NPC or Thread a slot
 * fact holds as its subject. The name is read now, so a rename shows here.
 */
#[OA\Schema(required: ['kind', 'id', 'name'])]
final readonly class FactLinkResponse
{
    private function __construct(
        #[OA\Property(description: 'A subject is always an NPC, a Thread or a Place.', example: 'npc', enum: ['npc', 'thread', 'place', 'fact'])]
        public string $kind,
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(description: 'The NPC\'s, Thread\'s or Place\'s name, or the linked fact\'s label.', example: 'Mira Vane')]
        public string $name,
    ) {
    }

    public static function fromView(FactLinkView $view): self
    {
        return new self($view->kind, $view->id, $view->name);
    }
}
