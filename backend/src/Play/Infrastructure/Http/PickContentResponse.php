<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\PickContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What a pick step of a Flow picked.
 */
#[OA\Schema(required: ['kind', 'of', 'tags', 'picked'])]
final readonly class PickContentResponse
{
    /**
     * @param list<string>          $tags
     * @param list<ItemRefResponse> $picked
     */
    private function __construct(
        #[OA\Property(enum: [PickContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The kind of item picked.', example: 'npc', enum: ['npc', 'thread', 'character'])]
        public string $of,
        #[OA\Property(
            description: 'The keys of the release Tags the step asked for, as they were stored; empty when it asked for none.',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'leader'),
        )]
        public array $tags,
        #[OA\Property(
            description: 'The items picked, in the order picked, named as they were at pick time; empty when nothing matched.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ItemRefResponse::class)),
        )]
        public array $picked,
    ) {
    }

    public static function of(PickContent $content): self
    {
        return new self(
            PickContent::KIND,
            $content->itemKind()->value,
            $content->tags(),
            array_map(static fn (array $item): ItemRefResponse => ItemRefResponse::of($item['id'], $item['name']), $content->picked()),
        );
    }
}
