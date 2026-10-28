<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * One line of a shop step's transaction (ADR 0045): units of a catalog entry the Character buys,
 * or sells from a stack of one of its inventory fields. ShopTrade checks the line.
 */
final readonly class ShopLine
{
    private function __construct(
        public ?string $field,
        public string $gear,
        public int $quantity,
    ) {
    }

    public static function buy(string $gear, int $quantity): self
    {
        return new self(null, $gear, $quantity);
    }

    /**
     * @param string $field the inventory field holding the stack sold
     */
    public static function sell(string $field, string $gear, int $quantity): self
    {
        return new self($field, $gear, $quantity);
    }
}
