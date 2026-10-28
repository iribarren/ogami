<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\ShopContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What a Character traded at a shop step (ADR 0045), with the currency before and after.
 */
#[OA\Schema(required: ['kind', 'characterId', 'characterName', 'bought', 'sold', 'before', 'after'])]
final readonly class ShopContentResponse
{
    /**
     * @param list<ShopLineResponse> $bought
     * @param list<ShopLineResponse> $sold
     */
    private function __construct(
        #[OA\Property(enum: [ShopContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The Character who traded.')]
        public string $characterId,
        #[OA\Property(description: 'The name of the Character when the step completed.', example: 'Vi')]
        public string $characterName,
        #[OA\Property(
            description: 'The gear bought, in the order the player listed it; empty when nothing was bought. Its field is null.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ShopLineResponse::class)),
        )]
        public array $bought,
        #[OA\Property(
            description: 'The gear sold, in the order the player listed it, with the inventory field it left; empty when nothing was sold.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ShopLineResponse::class)),
        )]
        public array $sold,
        #[OA\Property(description: 'The currency of the Character before the transaction.', example: 50)]
        public int $before,
        #[OA\Property(description: 'The currency of the Character after it.', example: 43)]
        public int $after,
    ) {
    }

    public static function of(ShopContent $content): self
    {
        return new self(
            ShopContent::KIND,
            $content->characterId(),
            $content->characterName(),
            array_map(static fn (array $line): ShopLineResponse => ShopLineResponse::of(null, $line['gear'], $line['name'], $line['quantity'], $line['price']), $content->bought()),
            array_map(static fn (array $line): ShopLineResponse => ShopLineResponse::of($line['field'], $line['gear'], $line['name'], $line['quantity'], $line['price']), $content->sold()),
            $content->before(),
            $content->after(),
        );
    }
}
