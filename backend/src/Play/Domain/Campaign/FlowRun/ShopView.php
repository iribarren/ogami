<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\Sheet\Currency;

/**
 * What a shop step a FlowRun waits on trades (ADR 0045): the entries on sale now, in catalog order
 * (its stock, or its whole offer), the percent of the cost it pays for a unit sold (null when it
 * does not buy) and the currency it trades in.
 */
final readonly class ShopView
{
    /**
     * @param list<GearEntry> $onSale
     */
    public function __construct(
        public array $onSale,
        public ?int $sellRate,
        public Currency $currency,
    ) {
    }
}
