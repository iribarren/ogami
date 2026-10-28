<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

use App\Play\Domain\GameSystem\GearEntry;
use App\Randomness\Domain\DiceExpression;

/**
 * Sells gear of its offer for the sheet currency and, with a sell rate, buys catalog gear back at
 * that percent of its cost, rounded down (ADR 0045). The offer is resolved from the catalog keys
 * and categories the release lists, in catalog order. A stock puts only that many distinct entries
 * of the offer on sale, drawn when the FlowRun reaches the step; without one the whole offer is on
 * sale. A shop is never skipped: leaving with an empty transaction is how a player passes it.
 */
final readonly class ShopStep extends Step
{
    public const int MAX_STOCK = 50;

    public const int MAX_SELL_RATE = 100;

    /**
     * @param list<Effect>            $effects
     * @param list<GearEntry>         $offer    the entries offered, in catalog order; at least one
     * @param int|DiceExpression|null $stock    how many distinct entries are on sale: a count, dice rolled when the step is reached, or null for the whole offer
     * @param ?int                    $sellRate the percent of the cost paid per unit sold; null when the shop does not buy
     *
     * @throws \InvalidArgumentException when the offer is empty or repeats an entry, a count stock is outside 1..the offer's size (at most 50), the sell rate is outside 1..100, or the step is mandatory
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public array $offer,
        public int|DiceExpression|null $stock = null,
        public ?int $sellRate = null,
    ) {
        $keys = array_map(static fn (GearEntry $entry): string => $entry->key, $offer);
        if ([] === $offer || \count(array_unique($keys)) !== \count($keys)) {
            throw new \InvalidArgumentException(\sprintf('Shop step "%s" offers at least one entry, each once.', $key));
        }

        if (\is_int($stock) && ($stock < 1 || $stock > min(self::MAX_STOCK, \count($offer)))) {
            throw new \InvalidArgumentException(\sprintf('Shop step "%s" puts 1 to %d entries on sale, got %d.', $key, min(self::MAX_STOCK, \count($offer)), $stock));
        }

        if (null !== $sellRate && ($sellRate < 1 || $sellRate > self::MAX_SELL_RATE)) {
            throw new \InvalidArgumentException(\sprintf('Shop step "%s" buys at 1 to %d percent, got %d.', $key, self::MAX_SELL_RATE, $sellRate));
        }

        if ($mandatory) {
            throw new \InvalidArgumentException(\sprintf('Shop step "%s" is never mandatory.', $key));
        }

        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }

    /**
     * The offered entry with this key; null when the shop does not offer it.
     */
    public function offered(string $key): ?GearEntry
    {
        return array_find($this->offer, static fn (GearEntry $entry): bool => $entry->key === $key);
    }

    /**
     * What the shop pays for one unit of an entry: the sell rate's percent of its cost, rounded down.
     *
     * @throws \LogicException when the shop does not buy
     */
    public function salePrice(GearEntry $entry): int
    {
        return intdiv($entry->cost * ($this->sellRate ?? throw new \LogicException(\sprintf('Shop step "%s" does not buy.', $this->key))), 100);
    }
}
