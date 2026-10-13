<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\NpcView;
use OpenApi\Attributes as OA;

/**
 * An NPC of a campaign.
 */
#[OA\Schema(required: ['id', 'name', 'agenda', 'tags', 'freeTags', 'weight', 'status', 'promotedTo'])]
final readonly class NpcResponse
{
    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Mira Vane')]
        public string $name,
        #[OA\Property(description: 'What the NPC wants; null when none was written.', example: 'Wants the ledger back.', nullable: true)]
        public ?string $agenda,
        #[OA\Property(description: 'The keys of the pinned release\'s Tags the NPC carries; flows pick and count by them.', type: 'array', items: new OA\Items(type: 'string'), example: ['ally'])]
        public array $tags,
        #[OA\Property(description: 'The player\'s own labels, as typed; flows never match them.', type: 'array', items: new OA\Items(type: 'string'), example: ['Fence'])]
        public array $freeTags,
        #[OA\Property(description: 'Favours the NPC in a random pick: 1 to 3.', example: 1)]
        public int $weight,
        #[OA\Property(description: 'A closed NPC is no longer picked or counted.', example: 'open', enum: ['open', 'closed'])]
        public string $status,
        #[OA\Property(description: 'The id of the Character this NPC was promoted to; null when it was not. A promoted NPC is closed for good.', format: 'uuid', nullable: true)]
        public ?string $promotedTo,
    ) {
    }

    public static function fromView(NpcView $view): self
    {
        return new self($view->id, $view->name, $view->agenda, $view->tags, $view->freeTags, $view->weight, $view->status, $view->promotedTo);
    }
}
