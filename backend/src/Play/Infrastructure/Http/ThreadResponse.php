<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\ThreadView;
use OpenApi\Attributes as OA;

/**
 * A Thread of a campaign: a story line still open or already closed.
 */
#[OA\Schema(required: ['id', 'name', 'tags', 'freeTags', 'weight', 'status'])]
final readonly class ThreadResponse
{
    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Rob the vault')]
        public string $name,
        #[OA\Property(description: 'The keys of the pinned release\'s Tags the Thread carries; flows pick and count by them.', type: 'array', items: new OA\Items(type: 'string'), example: ['heist'])]
        public array $tags,
        #[OA\Property(description: 'The player\'s own labels, as typed; flows never match them.', type: 'array', items: new OA\Items(type: 'string'), example: ['Vault'])]
        public array $freeTags,
        #[OA\Property(description: 'Favours the Thread in a random pick: 1 to 3.', example: 1)]
        public int $weight,
        #[OA\Property(description: 'A closed Thread is no longer picked or counted.', example: 'open', enum: ['open', 'closed'])]
        public string $status,
    ) {
    }

    public static function fromView(ThreadView $view): self
    {
        return new self($view->id, $view->name, $view->tags, $view->freeTags, $view->weight, $view->status);
    }
}
