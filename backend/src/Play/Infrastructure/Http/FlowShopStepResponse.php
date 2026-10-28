<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\ShopView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What a shop step trades (ADR 0045): the gear on sale now, what it pays for a unit sold, and the
 * currency of the sheet it trades in.
 */
#[OA\Schema(required: ['onSale', 'sellRate', 'currency'])]
final readonly class FlowShopStepResponse
{
    /**
     * @param list<GearEntryResponse> $onSale
     */
    private function __construct(
        #[OA\Property(
            description: 'The catalog entries on sale, in catalog order: the stock drawn when the step was reached, or the whole offer of a shop without a stock; empty when the stock drew none. A purchase names one by its key.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: GearEntryResponse::class)),
        )]
        public array $onSale,
        #[OA\Property(description: 'The percent of the cost the shop pays for one unit sold, rounded down; null when the shop does not buy.', example: 50, nullable: true)]
        public ?int $sellRate,
        #[OA\Property(ref: new Model(type: CurrencyResponse::class), description: 'The currency prices are in and the Character pays with.')]
        public CurrencyResponse $currency,
    ) {
    }

    public static function fromView(ShopView $view): self
    {
        return new self(
            array_map(GearEntryResponse::fromEntry(...), $view->onSale),
            $view->sellRate,
            CurrencyResponse::fromCurrency($view->currency) ?? throw new \LogicException('A shop trades in the sheet currency.'),
        );
    }
}
