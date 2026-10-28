<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\ShopStep;
use App\Play\Domain\GameSystem\GearEntry;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * The entries a shop step with a stock has on sale (ADR 0045): drawn when the FlowRun reaches the
 * step and kept, by key in catalog order, until the step completes.
 */
final readonly class ShopStock
{
    /**
     * @param list<string> $gear the catalog keys on sale, in catalog order
     *
     * @throws \InvalidArgumentException when the step key is blank or a key is blank or repeated
     */
    public function __construct(
        public string $stepKey,
        public array $gear,
    ) {
        if ('' === $stepKey || \in_array('', $gear, true) || \count(array_unique($gear)) !== \count($gear)) {
            throw new \InvalidArgumentException(\sprintf('The stock of shop step "%s" lists distinct catalog keys.', $stepKey));
        }
    }

    /**
     * Draws the stock of a shop step: its count, or its dice rolled and clamped to 0..the offer's
     * size, of distinct entries of the offer, each as likely as the others. When the count covers
     * the offer, all of it is on sale and nothing more is drawn.
     */
    public static function draw(ShopStep $step, RandomNumberGenerator $random): self
    {
        $remaining = $step->offer;
        $count = $step->stock instanceof DiceExpression ? $step->stock->roll($random)->total() : (int) $step->stock;
        $count = max(0, min($count, \count($remaining)));
        $drawn = [];
        if ($count === \count($remaining)) {
            $drawn = $remaining;
        }

        for ($left = [] === $drawn ? $count : 0; $left > 0; --$left) {
            $index = $random->between(1, \count($remaining)) - 1;
            $drawn[] = $remaining[$index];
            array_splice($remaining, $index, 1);
        }

        $keys = array_map(static fn (GearEntry $entry): string => $entry->key, $drawn);

        return new self($step->key, array_values(array_filter(array_map(static fn (GearEntry $entry): string => $entry->key, $step->offer), static fn (string $key): bool => \in_array($key, $keys, true))));
    }
}
